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
         * Product images and verification photographs are kept apart deliberately, and
         * this separation is not tidiness.
         *
         * A product image belongs to the canonical record and is kept indefinitely. A
         * verification photograph is deleted the moment verification concludes, whether
         * it passed or failed, and is never shown to anyone. Those are opposite
         * lifecycles, and a cleanup job that deletes from the wrong location would
         * destroy catalogue images that nothing can restore.
         *
         * Two disks make that mistake impossible to make quietly, because the cleanup
         * job can only reach one of them.
         */
        'product_images' => [
            'driver' => env('PRODUCT_IMAGE_DRIVER', 'local'),
            'root' => storage_path('app/public/product-images'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage/product-images',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Private, and it stays private. Nothing serves a URL from this disk: the
         * contract forbids any response carrying a verification photograph path, and a
         * publicly readable disk would make that promise depend on nobody writing the
         * wrong resource class.
         */
        'verification_photos' => [
            'driver' => env('VERIFICATION_PHOTO_DRIVER', 'local'),
            'root' => storage_path('app/private/verification-photos'),
            'visibility' => 'private',
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

    ],

    /*
    |--------------------------------------------------------------------------
    | Named disks for the platform's two image lifecycles
    |--------------------------------------------------------------------------
    |
    | Read through these keys rather than naming a disk in application code, so
    | moving either lifecycle to object storage is a configuration change and not a
    | search through the codebase for string literals.
    |
    */

    'product_images' => env('PRODUCT_IMAGE_DISK', 'product_images'),

    'verification_photos' => env('VERIFICATION_PHOTO_DISK', 'verification_photos'),

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
