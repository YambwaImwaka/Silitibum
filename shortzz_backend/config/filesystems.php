<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'chunk_size' => 1024 * 1024 * 5, // 5MB chunks
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_ITEM_BASE_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Sent with every write to this disk (infra/aws/media-cdn.yaml).
            'options' => [
                // The bucket has ACLs disabled ("bucket owner enforced") and is
                // read only through CloudFront. S3 then rejects any upload
                // carrying an ACL other than bucket-owner-full-control with
                // AccessControlListNotSupported, and Flysystem always sends one:
                // public-read for the 'public' visibility every caller here
                // passes, private otherwise. Pinning it on the disk overrides
                // both, so no call site has to know which backend it is on.
                'ACL' => 'bucket-owner-full-control',
                // Every key is written once and never overwritten (timestamped
                // uploads, hls/{postId}/...), so CloudFront edges and the
                // players' own caches can keep each object for a year instead
                // of re-fetching it — which is most of what makes playback
                // start fast on a repeat view.
                'CacheControl' => 'public, max-age=31536000, immutable',
            ],
        ],

        'digitalocean' => [
            'driver' => 's3',
            'key' => env('DO_SPACE_ACCESS_KEY_ID'),
            'secret' => env('DO_SPACE_SECRET_ACCESS_KEY'),
            'region' => env('DO_SPACE_REGION'),
            'bucket' => env('DO_SPACE_BUCKET'),
            'url' => env('DO_SPACE_URL'),
            'endpoint' => env('DO_SPACE_ENDPOINT'),
            'bucket_endpoint' => true,
            'visibility' => 'public',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
