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
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Livewire temporary uploads. Kept on a fixed root that the per-tenant
        // filesystem switch (SwitchTenantFilesystemTask) never reroutes, so a
        // temp file written during upload is found again at validation time
        // regardless of which tenant is current. Final files still land on the
        // tenant-scoped `public`/`local` disks.
        'livewire-tmp' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
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
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    /*
     * Deliberately empty, so `storage:link` creates nothing.
     *
     * The shipped default links public/storage to storage/app/public, which serves
     * every company's uploads straight off the web root and bypasses
     * TenantFileController's membership check — a company's files live at
     * storage/app/public/tenants/{id}/…, so /storage/tenants/2/payslips/… would
     * answer to anybody. That directory existed once and had made four employees'
     * payslips public.
     *
     * Leaving the mapping here would mean one `php artisan storage:link` — the
     * standard command, present in most hosts' default deploy recipes — quietly
     * putting it back. Uploads are addressed through TenantStorage::urlRoot()
     * instead, which needs no symlink at all.
     */
    'links' => [],

];
