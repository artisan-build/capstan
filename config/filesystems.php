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
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Public CLI release artifacts (capstan-cli), published by the release
         * pipeline under `cli/<version>/<file>`. Separate from the default disk,
         * which is the Cloud-managed bucket holding app artifact blobs.
         *
         * The bucket itself stays PRIVATE — there is no `url` and no public
         * `visibility`, so nothing here is reachable except through the app's
         * own gatekeeper route (`cli.download`), which streams the object.
         *
         * `throw => false` keeps a missing object a clean 404 rather than a 500;
         * `report => true` still logs a genuine read-access failure (endpoint,
         * credentials, path style, region) so it is not silently invisible.
         */
        'downloads' => [
            'driver' => 's3',
            'key' => env('CAPSTAN_DOWNLOADS_KEY'),
            'secret' => env('CAPSTAN_DOWNLOADS_SECRET'),
            'region' => env('CAPSTAN_DOWNLOADS_REGION', 'auto'),
            'bucket' => env('CAPSTAN_DOWNLOADS_BUCKET'),
            'endpoint' => env('CAPSTAN_DOWNLOADS_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => true,
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
