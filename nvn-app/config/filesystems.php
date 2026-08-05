<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        // Private disk for sensitive files: credentials, IDs, uploaded documents,
        // notary assets, and final notarized PDFs. Never web-accessible directly;
        // served via authorized controller routes only.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'serve' => true,
            'throw' => false,
        ],

        /*
         * Site logo and icon.
         *
         * Deliberately rooted inside public/ rather than storage/, unlike every
         * other upload: these are the two files that must load on the login page
         * of a site nobody can log into yet. Going through storage/ would make
         * them depend on the `storage` symlink surviving a host move — and on
         * cPanel that symlink is exactly the thing that does not survive.
         *
         * Nothing sensitive is ever written here. Documents, IDs and notarial
         * assets stay on the private disk.
         */
        'brand' => [
            'driver'     => 'local',
            'root'       => public_path('brand'),
            'url'        => '/brand',
            'visibility' => 'public',
            'throw'      => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
