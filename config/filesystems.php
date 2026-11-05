<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Fotostation: In Laravel Cloud werden diese beiden Disks automatisch
        | aus den angehängten Buckets konfiguriert (LARAVEL_CLOUD_DISK_CONFIG)
        | und überschreiben die Werte hier. Lokal zeigen sie auf MinIO
        | (docker-compose.dev.yml).
        */

        'photos-private' => [
            'driver' => 's3',
            'key' => env('PHOTOS_KEY', 'fotostation'),
            'secret' => env('PHOTOS_SECRET', 'fotostation-secret'),
            'region' => env('PHOTOS_REGION', 'auto'),
            'bucket' => env('PHOTOS_PRIVATE_BUCKET', 'photos-private'),
            'endpoint' => env('PHOTOS_ENDPOINT', 'http://localhost:9000'),
            'use_path_style_endpoint' => (bool) env('PHOTOS_PATH_STYLE', true),
            'throw' => true,
            'report' => false,
        ],

        'photos-public' => [
            'driver' => 's3',
            'key' => env('PHOTOS_KEY', 'fotostation'),
            'secret' => env('PHOTOS_SECRET', 'fotostation-secret'),
            'region' => env('PHOTOS_REGION', 'auto'),
            'bucket' => env('PHOTOS_PUBLIC_BUCKET', 'photos-public'),
            'url' => env('PHOTOS_PUBLIC_URL', 'http://localhost:9000/photos-public'),
            'endpoint' => env('PHOTOS_ENDPOINT', 'http://localhost:9000'),
            'use_path_style_endpoint' => (bool) env('PHOTOS_PATH_STYLE', true),
            'throw' => true,
            'report' => false,
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
