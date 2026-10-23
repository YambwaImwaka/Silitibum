package com.retrytech.retrytech_plugin

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Canvas
import android.graphics.ColorMatrix
import android.graphics.ColorMatrixColorFilter
import android.graphics.Matrix
import android.graphics.Paint
import android.media.ExifInterface
import android.media.MediaCodec
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.media.MediaMuxer
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.net.toUri
import androidx.media3.common.Effect
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.util.UnstableApi
import androidx.media3.effect.BitmapOverlay
import androidx.media3.effect.OverlayEffect
import androidx.media3.effect.StaticOverlaySettings
import androidx.media3.transformer.Composition
import androidx.media3.transformer.DefaultMuxer
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.EditedMediaItemSequence
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.Transformer
import com.retrytech.retrytech_plugin.camera.NativeViewFactory
import com.retrytech.retrytech_plugin.filter.RgbFilter
import io.flutter.embedding.engine.plugins.FlutterPlugin
import io.flutter.embedding.engine.plugins.activity.ActivityAware
import io.flutter.embedding.engine.plugins.activity.ActivityPluginBinding
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.common.MethodChannel.MethodCallHandler
import io.flutter.plugin.common.MethodChannel.Result
import java.io.File
import java.io.FileOutputStream
import java.nio.ByteBuffer
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors


/** RetrytechPlugin */
open class RetrytechPlugin : FlutterPlugin, MethodCallHandler, ActivityAware {
    /// The MethodChannel that will the communication between Flutter and native Android
    ///
    /// This local reference serves to register the plugin with the Flutter Engine and unregister it
    /// when the Flutter Engine is detached from the Activity
    private lateinit var channel: MethodChannel
    private lateinit var cameraChannel: MethodChannel
    private lateinit var appContext: Context
    var context: Activity? = null

    // Decoding, filtering and remuxing take seconds on a low-end phone. On the
    // platform (main) thread that froze the app and risked an ANR, so that
    // work runs here, one job at a time — several full-size photos decoded at
    // once would run out of memory.
    private val worker: ExecutorService = Executors.newSingleThreadExecutor()
    private val mainHandler = Handler(Looper.getMainLooper())

    companion object {
        private const val TAG = "RetrytechPlugin"

        // Longest side, in pixels, a photo is decoded at for filtering. Feeds
        // show images at most ~1080 px wide, and a 12 MP photo at full size
        // needs ~48 MB per bitmap (this pipeline holds three).
        private const val MAX_FILTER_IMAGE_SIDE = 2048

        // The watermark is a small logo; only an oversized upload is reduced.
        private const val MAX_WATERMARK_SIDE = 2048
    }

    override fun onAttachedToEngine(flutterPluginBinding: FlutterPlugin.FlutterPluginBinding) {
        appContext = flutterPluginBinding.applicationContext
        channel = MethodChannel(
            flutterPluginBinding.binaryMessenger,
            "retrytech_plugin"
        )
        channel.setMethodCallHandler(this)

        cameraChannel = MethodChannel(flutterPluginBinding.binaryMessenger, "retrytech_camera")
        cameraChannel.setMethodCallHandler(this)
        flutterPluginBinding.platformViewRegistry.registerViewFactory(
            "retrytech_camera_view",
            NativeViewFactory(cameraChannel)
        )
    }

    @UnstableApi
    override fun onMethodCall(call: MethodCall, result: Result) {
        when (call.method) {

            "shareToInstagram" -> {
                shareToInstagram(call.arguments.toString(), result)
            }

            "applyFilterAndAudioToVideo" -> {
                applyFilterAndAudioToVideo(call.arguments as Map<String, Any?>, result)
            }

            "addWaterMarkInVideo" -> {
                addWatermarkToVideo(call.arguments as Map<String, String?>, result)
            }

            "extractAudio" -> {
                extractAudio(call.arguments as Map<String, String?>, result)
            }

            "applyFilterToImage" -> {
                applyFilterOnImage(call.arguments as Map<String, Any?>, result)
            }

            "hasAudio" -> {
                checkAudioTrack(call.arguments as Map<String, Any?>, result)
            }

            "createVideoFromImage" -> {
                createVideoFromImage(call.arguments as Map<String, Any?>, result)

            }

            // Camera calls that reach the plugin before the camera view exists
            // (the view takes over the camera channel once it is created).
            // Answer them so the Dart side never waits forever.
            "init", "dispose", "start", "pause", "resume", "toggle", "flash", "stop" -> {
                result.success(null)
            }

            "capture_image" -> {
                result.error("CAMERA_NOT_READY", "The camera is not open", null)
            }

            else -> {
                result.notImplemented()
            }
        }
    }

    override fun onDetachedFromEngine(binding: FlutterPlugin.FlutterPluginBinding) {
        channel.setMethodCallHandler(null)
        cameraChannel.setMethodCallHandler(null)
        worker.shutdown()
    }

    // No permission request here. This used to ask for camera, microphone and
    // storage the moment the app started — on Android 13+ on every start,
    // because the storage permissions can no longer be granted there — and
    // while that dialog was up Android cancelled any other permission request,
    // such as the notification prompt. The camera screen asks for what it
    // needs when it opens.
    override fun onAttachedToActivity(binding: ActivityPluginBinding) {
        context = binding.activity
    }

    override fun onDetachedFromActivityForConfigChanges() {
        context = null
    }

    override fun onReattachedToActivityForConfigChanges(binding: ActivityPluginBinding) {
        context = binding.activity
    }

    override fun onDetachedFromActivity() {
        context = null
    }

    /** Runs [work] off the main thread and replies on it; a failure (an
     * OutOfMemoryError included) answers [fallback] instead of crashing. */
    private fun <T> runInBackground(result: Result, fallback: T, work: () -> T) {
        worker.execute {
            val value = try {
                work()
            } catch (e: Throwable) {
                Log.e(TAG, "Background job failed", e)
                fallback
            }
            mainHandler.post { result.success(value) }
        }
    }

    /** Runs [block] on the main thread; Transformer must be built and started
     * on the looper it reports to. */
    private fun onMain(result: Result, block: () -> Unit) {
        mainHandler.post {
            try {
                block()
            } catch (e: Exception) {
                Log.e(TAG, "Export could not start", e)
                result.success(false)
            }
        }
    }

    /** Decodes [path] with its longest side at most about [maxSide] pixels:
     * reads the dimensions first, then subsamples by a power of two. */
    private fun decodeSampledBitmap(path: String, maxSide: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null

        val longest = maxOf(bounds.outWidth, bounds.outHeight)
        var sampleSize = 1
        while (longest / sampleSize > maxSide) {
            sampleSize *= 2
        }
        val options = BitmapFactory.Options().apply { inSampleSize = sampleSize }
        return BitmapFactory.decodeFile(path, options)
    }

    private fun filterValuesOf(arguments: Map<String, Any?>): FloatArray? =
        (arguments["filter_values"] as? List<*>)
            ?.mapNotNull { (it as? Number)?.toFloat() }
            ?.takeIf { it.isNotEmpty() }
            ?.toFloatArray()

    @UnstableApi
    fun createVideoFromImage(arguments: Map<String, Any?>, onComplete: Result) {
        val inputPath = (arguments["input_path"] ?: "").toString().toUri()
        val audioPath = (arguments["audio_path"] ?: "").toString().toUri()
        val outputPath = (arguments["output_path"] ?: "").toString()
        val filterValues = filterValuesOf(arguments)
        val audioStartTimeInMs = (arguments["audio_start_time_in_ms"] as? Number)?.toDouble()
        val videoTotalDurationInSec = (arguments["video_total_duration_in_sec"] as? Number)?.toDouble()

        val imageMediaItem = MediaItem.Builder().setUri(inputPath)
            .setImageDurationMs(10000 * (videoTotalDurationInSec?.toLong() ?: 1))
            .build()
        val videoItemBuilder = EditedMediaItem.Builder(imageMediaItem)
            .setDurationUs(1000000 * (videoTotalDurationInSec?.toLong() ?: 1))
            .setFrameRate(30)
        if (filterValues != null) {
            val videoEffects = mutableListOf<Effect>()
            val rgbFilter = RgbFilter(filterValues)
            videoEffects.add(rgbFilter)
            videoItemBuilder.setEffects(Effects(listOf(), videoEffects))
        }

        val editedImage = videoItemBuilder.build()


        val clippedAudioMediaItem = MediaItem.Builder()
            .setUri(audioPath)
            .setClippingConfiguration(
                MediaItem.ClippingConfiguration.Builder()
                    .setStartPositionMs(audioStartTimeInMs?.toLong() ?: 0)
                    .setEndPositionMs(
                        (audioStartTimeInMs?.toLong() ?: 0) + ((videoTotalDurationInSec?.toLong()
                            ?: 0) * 1000)
                    )
                    .build()
            )
            .build()
        val audioItem = EditedMediaItem.Builder(clippedAudioMediaItem)
            .build()
        val imageSequence = EditedMediaItemSequence.Builder(listOf(editedImage)).build()
        val audioSequence = EditedMediaItemSequence.Builder(listOf(audioItem)).build()

        val composition = Composition.Builder(listOf(imageSequence, audioSequence)).build()

        val transformer = Transformer.Builder(appContext)
            .setVideoMimeType(MimeTypes.VIDEO_H264)
            .setPortraitEncodingEnabled(true)
            .addListener(object : Transformer.Listener {
                override fun onCompleted(composition: Composition, result: ExportResult) {
                    onComplete.success(true)
                }

                override fun onError(
                    composition: Composition,
                    result: ExportResult,
                    exception: ExportException
                ) {
                    Log.e("ImageToVideo", "Export error: ${exception.message}", exception)
                    // false, not true: the story uploader falls back to the
                    // photo on false, and used to post the missing video.
                    onComplete.success(false)
                }
            })
            .build()

        transformer.start(composition, outputPath)
    }

    fun shareToInstagram(url: String, result: Result) {

        val shareIntent = Intent(Intent.ACTION_SEND)
        shareIntent.type = "text/plain"
        shareIntent.putExtra(Intent.EXTRA_TEXT, url)
        shareIntent.setPackage("com.instagram.android")
        shareIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_DOCUMENT)

        val activity = context
        try {
            if (activity != null) {
                activity.startActivity(shareIntent)
            } else {
                shareIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                appContext.startActivity(shareIntent)
            }
            result.success(true)
        } catch (e: ActivityNotFoundException) {
            // The share sheet opens Instagram's Play Store page on an error.
            result.error("ACTIVITY_NOT_FOUND", "Instagram is not installed", null)
        } catch (e: Exception) {
            Log.e(TAG, "shareToInstagram failed", e)
            result.success(false)
        }
    }

    @UnstableApi
    fun addWatermarkToVideo(arguments: Map<String, String?>, result: Result) {
        val inputPath = arguments["input_path"]
        val thumbnailPath = arguments["thumbnail_path"]
        val outputPath = arguments["output_path"]

        if (inputPath.isNullOrEmpty() || outputPath.isNullOrEmpty()) {
            Log.e("addWatermark", "Missing input or output path.")
            result.success(false)
            return
        }

        worker.execute {
            try {
                val bitmap = thumbnailPath?.takeIf { it.isNotEmpty() }?.let {
                    decodeSampledBitmap(it, MAX_WATERMARK_SIDE)
                }
                if (bitmap == null) {
                    Log.e("addWatermark", "Failed to decode thumbnail bitmap.")
                    mainHandler.post { result.success(false) }
                    return@execute
                }

                val (videoWidth, videoHeight) = readVideoSize(inputPath)

                if (videoWidth == 0 || videoHeight == 0) {
                    Log.e("addWatermark", "Could not retrieve video dimensions.")
                    mainHandler.post { result.success(false) }
                    return@execute
                }

                onMain(result) {
                    startWatermarkExport(inputPath, outputPath, bitmap, videoWidth, videoHeight, result)
                }
            } catch (e: Throwable) {
                Log.e("addWatermark", "Unexpected error: ${e.message}", e)
                mainHandler.post { result.success(false) }
            }
        }
    }

    @UnstableApi
    private fun startWatermarkExport(
        inputPath: String,
        outputPath: String,
        bitmap: Bitmap,
        videoWidth: Int,
        videoHeight: Int,
        result: Result
    ) {
        Log.d("addWatermark", "Video resolution: ${videoWidth}x${videoHeight}")

        val imageScaleFactor = when {
            videoWidth > 1080 -> .3f
            videoWidth > 480 -> .2f
            else -> 0.5f
        }
        val y = when {
            videoHeight > 1080 -> -.9f
            videoHeight > 480 -> -.85f
            else -> -0.5f
        }

        val imageOverlay = BitmapOverlay.createStaticBitmapOverlay(
            bitmap,
            StaticOverlaySettings.Builder()
                .setAlphaScale(1f)
                .setScale(imageScaleFactor, imageScaleFactor)
                .setBackgroundFrameAnchor(0.75f, y) // Fixed Y anchor
                .build()
        )

        val effects = Effects(
            emptyList(), // No video effects
            listOf(OverlayEffect(listOf(imageOverlay)))
        )

        val editedItem = EditedMediaItem.Builder(MediaItem.fromUri(inputPath.toUri()))
            .setEffects(effects)
            .build()

        val composition = Composition.Builder(
            listOf(
                EditedMediaItemSequence.Builder(listOf(editedItem)).build()
            )
        ).build()

        Transformer.Builder(appContext)
            .setPortraitEncodingEnabled(true)
            .addListener(object : Transformer.Listener {
                override fun onCompleted(composition: Composition, exportResult: ExportResult) {
                    Log.d("addWatermark", "Transformation completed")
                    result.success(true)
                }

                override fun onError(
                    composition: Composition,
                    exportResult: ExportResult,
                    exportException: ExportException
                ) {
                    Log.e(
                        "addWatermark",
                        "Transformation failed: ${exportException.message}",
                        exportException
                    )
                    result.success(false)
                }
            })
            .build()
            .start(composition, outputPath.toUri().path!!)

        Log.d("addWatermark", "Transformation started")
    }


    @UnstableApi
    private fun extractAudio(arguments: Map<String, String?>, result: Result) {
        val videoUri = arguments["input_path"]?.toUri()
        val outputUri = arguments["output_path"]?.toUri()

        runInBackground(result, false) {
            val extractor = MediaExtractor()
            var muxer: MediaMuxer? = null
            try {
                extractor.setDataSource(videoUri?.path ?: "")

                var audioTrackIndex = -1
                for (i in 0 until extractor.trackCount) {
                    val format = extractor.getTrackFormat(i)
                    val mime = format.getString(MediaFormat.KEY_MIME)
                    if (mime?.startsWith("audio/") == true) {
                        audioTrackIndex = i
                        break
                    }
                }

                if (audioTrackIndex == -1) {
                    return@runInBackground false
                }

                extractor.selectTrack(audioTrackIndex)

                muxer = MediaMuxer(outputUri?.path ?: "", MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
                val format = extractor.getTrackFormat(audioTrackIndex)
                val muxerTrackIndex = muxer.addTrack(format)
                muxer.start()

                val bufferSize = 1024 * 1024
                val buffer = ByteBuffer.allocate(bufferSize)
                val bufferInfo = MediaCodec.BufferInfo()

                extractor.seekTo(0, MediaExtractor.SEEK_TO_CLOSEST_SYNC)

                while (true) {
                    bufferInfo.offset = 0
                    bufferInfo.size = extractor.readSampleData(buffer, 0)

                    if (bufferInfo.size < 0) break

                    bufferInfo.presentationTimeUs = extractor.sampleTime
                    bufferInfo.flags = extractor.sampleFlags
                    muxer.writeSampleData(muxerTrackIndex, buffer, bufferInfo)
                    extractor.advance()
                }

                muxer.stop()
                true
            } finally {
                try {
                    muxer?.release()
                } catch (e: Exception) {
                    Log.e(TAG, "MediaMuxer release failed", e)
                }
                extractor.release()
            }
        }
    }

    private fun applyFilterOnImage(arguments: Map<String, Any?>, result: Result) {
        val inputFilePath = arguments["input_path"]?.toString()
        val outputFilePath = arguments["output_path"]?.toString()
        val filterValues = filterValuesOf(arguments)

        if (inputFilePath.isNullOrEmpty() || outputFilePath.isNullOrEmpty()) {
            result.success(false)
            return
        }

        runInBackground(result, false) {
            filterImage(inputFilePath, outputFilePath, filterValues)
        }
    }

    private fun filterImage(inputFilePath: String, outputFilePath: String, filterValues: FloatArray?): Boolean {
        val originalBitmap = decodeSampledBitmap(inputFilePath, MAX_FILTER_IMAGE_SIDE) ?: return false

        // Read EXIF and rotate if needed
        val rotatedBitmap = try {
            val exif = ExifInterface(inputFilePath)
            val orientation = exif.getAttributeInt(
                ExifInterface.TAG_ORIENTATION,
                ExifInterface.ORIENTATION_NORMAL
            )
            val matrix = Matrix()
            when (orientation) {
                ExifInterface.ORIENTATION_ROTATE_90 -> matrix.postRotate(90f)
                ExifInterface.ORIENTATION_ROTATE_180 -> matrix.postRotate(180f)
                ExifInterface.ORIENTATION_ROTATE_270 -> matrix.postRotate(270f)
            }
            if (matrix.isIdentity) {
                originalBitmap
            } else {
                Bitmap.createBitmap(
                    originalBitmap,
                    0,
                    0,
                    originalBitmap.width,
                    originalBitmap.height,
                    matrix,
                    true
                ).also { if (it !== originalBitmap) originalBitmap.recycle() }
            }
        } catch (e: Exception) {
            originalBitmap // fallback
        }

        val paint = Paint().apply {
            filterValues?.let {
                colorFilter = ColorMatrixColorFilter(ColorMatrix(it))
            }
        }

        val outputBitmap = Bitmap.createBitmap(
            rotatedBitmap.width,
            rotatedBitmap.height,
            rotatedBitmap.config ?: Bitmap.Config.ARGB_8888
        )
        val canvas = Canvas(outputBitmap)
        canvas.drawBitmap(rotatedBitmap, 0f, 0f, paint)
        rotatedBitmap.recycle()

        try {
            val file = File(outputFilePath)
            file.parentFile?.mkdirs()
            FileOutputStream(file).use { out ->
                outputBitmap.compress(Bitmap.CompressFormat.JPEG, 90, out)
                out.flush()
            }
            return true
        } catch (e: Exception) {
            Log.e(TAG, "Saving the filtered image failed", e)
            return false
        } finally {
            outputBitmap.recycle()
        }
    }


    @UnstableApi
    private fun applyFilterAndAudioToVideo(arguments: Map<String, Any?>, result: Result) {
        val videoUri = Uri.fromFile(File(arguments["input_path"].toString()))
        val audioUri = arguments["audio_path"]?.toString()?.toUri()
        val outputPath = arguments["output_path"]?.toString()?.toUri()?.path
        val filterValues = filterValuesOf(arguments)
        val shouldAddBothMusics = arguments["should_add_both_musics"] as? Boolean ?: false
        val audioStartTimeInMs = (arguments["audio_start_time_in_ms"] as? Number)?.toDouble()

        if (outputPath.isNullOrEmpty()) {
            result.success(false)
            return
        }

        worker.execute {
            // Reading the duration opens the file: off the main thread.
            val videoDurationMs = try {
                if (audioUri != null) getVideoDurationMs(videoUri) else 0L
            } catch (e: Exception) {
                Log.e(TAG, "Could not read the video duration", e)
                0L
            }
            onMain(result) {
                startFilterAndAudioExport(
                    videoUri, audioUri, outputPath, filterValues,
                    shouldAddBothMusics, audioStartTimeInMs, videoDurationMs, result
                )
            }
        }
    }

    @UnstableApi
    private fun startFilterAndAudioExport(
        videoUri: Uri,
        audioUri: Uri?,
        outputPath: String,
        filterValues: FloatArray?,
        shouldAddBothMusics: Boolean,
        audioStartTimeInMs: Double?,
        videoDurationMs: Long,
        result: Result
    ) {
        val videoItemBuilder = EditedMediaItem.Builder(MediaItem.fromUri(videoUri))
            .setRemoveAudio(!shouldAddBothMusics)
        if (filterValues != null) {
            val videoEffects = mutableListOf<Effect>()
            val rgbFilter = RgbFilter(filterValues)
            videoEffects.add(rgbFilter)
            videoItemBuilder.setEffects(Effects(listOf(), videoEffects))
        }
        val videoItem = videoItemBuilder.build()
        val mediaItemSequences: ArrayList<EditedMediaItemSequence> = ArrayList()
        val videoSequence = EditedMediaItemSequence.Builder(listOf(videoItem))
            .build()
        mediaItemSequences.add(videoSequence)
        if (audioUri != null) {
            val clippedAudioMediaItem = MediaItem.Builder()
                .setUri(audioUri)
                .setClippingConfiguration(
                    MediaItem.ClippingConfiguration.Builder()
                        .setStartPositionMs(audioStartTimeInMs?.toLong() ?: 0L)
                        .setEndPositionMs((audioStartTimeInMs?.toLong() ?: 0L) + videoDurationMs)
                        .build()
                )
                .build()
            val audioItem = EditedMediaItem.Builder(clippedAudioMediaItem)
                .build()
            val audioSequence = EditedMediaItemSequence.Builder(listOf(audioItem))
                .build()
            mediaItemSequences.add(audioSequence)
        }
        val composition = Composition.Builder(mediaItemSequences).build()
        val transformer = Transformer.Builder(appContext)
            .setMuxerFactory(DefaultMuxer.Factory())
            .setPortraitEncodingEnabled(true)
            .addListener(object : Transformer.Listener {
                override fun onError(
                    composition: Composition,
                    exportResult: ExportResult,
                    exportException: ExportException
                ) {
                    result.success(false)
                    Log.d("TAG", "onMethodCall: " + exportException.message)
                    super.onError(composition, exportResult, exportException)
                }

                override fun onCompleted(composition: Composition, exportResult: ExportResult) {
                    super.onCompleted(composition, exportResult)
                    result.success(true)
                }
            })
            .build()
        transformer.start(composition, outputPath)
    }

    /** Width and height of the video at [path]; 0 when unreadable. */
    private fun readVideoSize(path: String): Pair<Int, Int> {
        val retriever = MediaMetadataRetriever()
        try {
            retriever.setDataSource(path)
            val width = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)
                ?.toIntOrNull() ?: 0
            val height = retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)
                ?.toIntOrNull() ?: 0
            return Pair(width, height)
        } finally {
            retriever.release()
        }
    }

    private fun getVideoDurationMs(videoUri: Uri): Long {
        val retriever = MediaMetadataRetriever()
        try {
            retriever.setDataSource(appContext, videoUri)
            return retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)
                ?.toLongOrNull() ?: 0L
        } finally {
            retriever.release()
        }
    }

    private fun checkAudioTrack(arguments: Map<String, Any?>, result: Result) {
        val videoPath = arguments["input_path"]?.toString()?.toUri()
        // Unknown counts as "has audio", which keeps the original sound.
        runInBackground(result, true) {
            val retriever = MediaMetadataRetriever()
            try {
                retriever.setDataSource(appContext, videoPath)
                "yes" == retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_HAS_AUDIO)
            } finally {
                retriever.release()
            }
        }
    }
}
