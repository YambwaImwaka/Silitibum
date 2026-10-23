package com.retrytech.retrytech_plugin.camera

import android.Manifest.permission.CAMERA
import android.annotation.SuppressLint
import android.app.Activity
import android.content.pm.PackageManager
import android.os.Environment
import android.util.Log
import android.util.Rational
import android.view.LayoutInflater
import android.view.View
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.FocusMeteringAction
import androidx.camera.core.FocusMeteringAction.FLAG_AE
import androidx.camera.core.FocusMeteringAction.FLAG_AF
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCapture.OutputFileOptions
import androidx.camera.core.ImageCaptureException
import androidx.camera.core.Preview
import androidx.camera.core.UseCaseGroup
import androidx.camera.core.ViewPort
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.video.FileOutputOptions
import androidx.camera.video.Recorder
import androidx.camera.video.Recording
import androidx.camera.video.VideoCapture
import androidx.camera.video.VideoRecordEvent
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import androidx.core.content.PermissionChecker
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleObserver
import androidx.lifecycle.LifecycleOwner
import com.retrytech.retrytech_plugin.R
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import io.flutter.plugin.platform.PlatformView
import java.io.File
import java.util.concurrent.TimeUnit


internal class CameraXView(
    private val context: Activity?,
    id: Int,
    creationParams: Map<String?, Any?>?,
    private val channel: MethodChannel
) :
    PlatformView, MethodChannel.MethodCallHandler {
    private var camera: Camera? = null
    private var imageCapture: ImageCapture? = null

    private var videoCapture: VideoCapture<Recorder>? = null
    private var recording: Recording? = null
    private lateinit var viewFinder: PreviewView

    // The Dart call waiting for the recording to finish ("stop"). Every other
    // call is answered straight away; this one is answered when CameraX
    // finalizes the file, with its path, or null if nothing usable was saved.
    private var pendingStop: MethodChannel.Result? = null

    // A recording CameraX ended on its own (the app went to the background)
    // before "stop" arrived; "stop" then hands this file over.
    private var finishedVideoPath: String? = null

    private var cameraStarted = false
    private var disposed = false

    companion object {
        private const val TAG = "CameraXApp"

        // A recording that ended for one of these reasons still left a
        // playable file behind.
        private val USABLE_FINALIZE_ERRORS = setOf(
            VideoRecordEvent.Finalize.ERROR_SOURCE_INACTIVE,
            VideoRecordEvent.Finalize.ERROR_FILE_SIZE_LIMIT_REACHED,
            VideoRecordEvent.Finalize.ERROR_DURATION_LIMIT_REACHED
        )
    }

    private var view1: View? = null
    override fun getView(): View {
        if (view1 != null) {
            return view1!!
        }
        view1 = LayoutInflater.from(context).inflate(R.layout.item_camera, null, false)
        channel.setMethodCallHandler(this)
        viewFinder = view1!!.findViewById(R.id.viewFinder)

        // No permission dialog from here: the camera screen asks for camera
        // and microphone first and then sends "init", which starts the camera.
        // Requesting here as well raced that request, and Android cancels the
        // second of two concurrent requests.
        if (hasCameraPermission()) {
            startCamera()
        }
        return view1!!
    }

    override fun onFlutterViewAttached(flutterView: View) {
        super.onFlutterViewAttached(flutterView)
    }

    override fun onFlutterViewDetached() {
        super.onFlutterViewDetached()
    }

    @SuppressLint("RestrictedApi", "MissingPermission")
    private fun captureVideo() {
        val videoCapture = videoCapture ?: return
        val activity = context ?: return
        if (recording != null) return

        finishedVideoPath = null
        val outputFile = getOutputMediaFile()
        recording = videoCapture.output
            .prepareRecording(activity, FileOutputOptions.Builder(outputFile).build())
            .apply {
                if (PermissionChecker.checkSelfPermission(
                        activity,
                        android.Manifest.permission.RECORD_AUDIO
                    ) ==
                    PermissionChecker.PERMISSION_GRANTED
                ) {
                    withAudioEnabled()
                }
            }
            .start(ContextCompat.getMainExecutor(activity)) { recordEvent ->
                if (recordEvent is VideoRecordEvent.Finalize) {
                    onRecordingFinalized(recordEvent, outputFile)
                }
            }
    }

    private fun onRecordingFinalized(event: VideoRecordEvent.Finalize, outputFile: File) {
        recording = null
        val usable = !event.hasError() ||
                (event.error in USABLE_FINALIZE_ERRORS && outputFile.length() > 0)
        val path = if (usable) outputFile.absolutePath else null
        if (event.hasError()) {
            Log.e(TAG, "Video capture ended with error ${event.error}", event.cause)
        } else {
            Log.d(TAG, "Video capture succeeded: ${event.outputResults.outputUri}")
        }
        if (path != null) {
            channel.invokeMethod("url_path", path)
        }

        val waiting = pendingStop
        pendingStop = null
        if (waiting != null) {
            waiting.success(path)
        } else {
            finishedVideoPath = path
        }
    }

    private fun getOutputMediaFile(): File {
        val state: String = Environment.getExternalStorageState()
        val filesDir: File? = if (Environment.MEDIA_MOUNTED == state) {
            // We can read and write the media
            context?.getExternalFilesDir(null)
        } else {
            // Load another directory, probably local memory
            context?.filesDir
        }
        val file = File(filesDir, "finalvideo.mp4")
        if (!file.exists()) {
            file.createNewFile()
        }
        return file
    }

    var isFlashOn: Boolean = false
    var isFrontCamera: Boolean = false

    /** The activity's own lifecycle, so CameraX releases the camera when the
     * app goes to the background and reopens it on return. A lifecycle that
     * was always "started" kept the camera claimed in the background, where
     * Android takes it away — and the preview could come back black. */
    private fun lifecycleOwner(): LifecycleOwner =
        context as? LifecycleOwner ?: object : LifecycleOwner {
            override val lifecycle: Lifecycle
                get() = object : Lifecycle() {
                    override val currentState: State
                        get() = State.STARTED

                    override fun addObserver(observer: LifecycleObserver) {
                    }

                    override fun removeObserver(observer: LifecycleObserver) {
                    }

                }

        }

    @SuppressLint("ClickableViewAccessibility", "RestrictedApi")
    private fun startCamera() {
        val activity = context ?: return
        cameraStarted = true
        viewFinder.implementationMode = PreviewView.ImplementationMode.COMPATIBLE

        val cameraProviderFuture = ProcessCameraProvider.getInstance(activity)
        // Waiting for the provider with get() blocked the main thread while
        // the camera service started.
        cameraProviderFuture.addListener({
            if (disposed) return@addListener
            try {
                bindUseCases(activity, cameraProviderFuture.get())
            } catch (exc: Exception) {
                cameraStarted = false
                Log.e(TAG, "Use case binding failed", exc)
            }
        }, ContextCompat.getMainExecutor(activity))
    }

    @SuppressLint("ClickableViewAccessibility", "RestrictedApi")
    private fun bindUseCases(activity: Activity, cameraProvider: ProcessCameraProvider) {
        // Preview
        val preview = Preview.Builder()

            .build().also {
                it.setSurfaceProvider(viewFinder.surfaceProvider)
            }


        // Select back camera as a default
        val cameraSelector =
            if (isFrontCamera) CameraSelector.DEFAULT_FRONT_CAMERA else CameraSelector.DEFAULT_BACK_CAMERA

        val recorder = Recorder.Builder()
            .build()
        videoCapture = VideoCapture.withOutput(recorder)
        imageCapture = ImageCapture.Builder().build()

        val useCaseGroup = UseCaseGroup.Builder()
            .addUseCase(preview)
            .addUseCase(videoCapture!!)
            .addUseCase(imageCapture!!)

        // Before the first layout the window has no size, and a 0:0 view
        // port is rejected; CameraX then uses the full sensor frame.
        val decorView = activity.window.decorView
        if (decorView.width > 0 && decorView.height > 0) {
            val viewPort = ViewPort.Builder(
                Rational(decorView.width, decorView.height),
                decorView.rotation.toInt()
            ).build()
            useCaseGroup.setViewPort(viewPort)
        }

        cameraProvider.unbindAll()

        val boundCamera = cameraProvider.bindToLifecycle(
            lifecycleOwner(), cameraSelector, useCaseGroup.build()
        )
        camera = boundCamera
        if (isFlashOn) {
            boundCamera.cameraControl.enableTorch(true)
        }


        viewFinder.setOnTouchListener { _, motionEvent ->
            val meteringPoint = viewFinder.meteringPointFactory
                .createPoint(motionEvent!!.x, motionEvent.y)
            val action = FocusMeteringAction.Builder(meteringPoint)
                .addPoint(meteringPoint, FLAG_AF or FLAG_AE)
                .setAutoCancelDuration(3, TimeUnit.SECONDS)
                .build()

            boundCamera.cameraControl.startFocusAndMetering(action)

            true
        }
    }

    private fun hasCameraPermission(): Boolean {
        val activity = context ?: return false
        return ContextCompat.checkSelfPermission(activity, CAMERA) ==
                PackageManager.PERMISSION_GRANTED
    }

    private fun releaseCamera() {
        val activity = context ?: return
        try {
            recording?.stop()
            recording = null
            // The provider is long initialised by now, so get() returns at once.
            ProcessCameraProvider.getInstance(activity).get().unbindAll()
        } catch (e: Exception) {
            Log.e(TAG, "Releasing the camera failed", e)
        }
        cameraStarted = false
        camera = null
    }

    override fun dispose() {
        if (disposed) return
        disposed = true
        releaseCamera()
        pendingStop?.success(null)
        pendingStop = null
    }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        Log.d(TAG, "onMethodCall: " + call.method)
        if (disposed) {
            // A call that reaches a closed camera: answer so nothing waits.
            if (call.method == "capture_image") {
                result.error("CAMERA_CLOSED", "The camera is closed", null)
            } else {
                result.success(null)
            }
            return
        }
        when (call.method) {
            "init" -> {
                // Sent once the camera screen has the permissions it asked for.
                if (!cameraStarted && hasCameraPermission()) {
                    startCamera()
                }
                result.success(null)
            }

            "start" -> {
                captureVideo()
                result.success(null)
            }

            "pause" -> {
                recording?.pause()
                result.success(null)
            }

            "resume" -> {
                recording?.resume()
                result.success(null)
            }

            "stop" -> {
                val curRecording = recording
                if (curRecording != null) {
                    // A second "stop" before the file is ready gets nothing.
                    pendingStop?.success(null)
                    pendingStop = result
                    curRecording.stop()
                } else {
                    result.success(finishedVideoPath)
                    finishedVideoPath = null
                }
            }

            "toggle" -> {
                isFrontCamera = !isFrontCamera
                if (hasCameraPermission()) {
                    startCamera()
                }
                result.success(null)
            }

            "flash" -> {
                isFlashOn = !isFlashOn
                camera?.cameraControl?.enableTorch(isFlashOn)
                result.success(null)
            }

            "capture_image" -> {
                captureImage(result)
            }

            "dispose" -> {
                releaseCamera()
                result.success(true)
            }

            else -> {
                result.notImplemented()
            }
        }
    }

    private fun captureImage(result: MethodChannel.Result) {
        val activity = context
        val capture = imageCapture
        if (activity == null || capture == null) {
            result.error("CAMERA_NOT_READY", "The camera is not open", null)
            return
        }
        val photoFile = File.createTempFile("image" + System.currentTimeMillis(), ".jpg", activity.cacheDir)

        capture.takePicture(OutputFileOptions.Builder(photoFile).build(), ContextCompat.getMainExecutor(activity), object : ImageCapture.OnImageSavedCallback {
            override fun onImageSaved(outputFileResults: ImageCapture.OutputFileResults) {
                result.success(photoFile.absolutePath)
                Log.d(TAG, "onImageSaved: " + photoFile.absolutePath)
            }

            override fun onError(exception: ImageCaptureException) {
                Log.d(TAG, "onError: " + exception.message)
                result.error(exception.message ?: "", exception.message, null)
            }
        })
    }


}
