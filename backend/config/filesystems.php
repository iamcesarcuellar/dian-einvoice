<?php

// Merged with the framework defaults: only the disks added here are listed.
return [

    'disks' => [

        // The framework's local disk, without the routes that would serve it over HTTP (storage/{path}):
        // the API serves files only through its own endpoints, after authorization.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        // Files of electronic documents. Private: there is no URL; the API serves them after authorization.
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'visibility' => 'private',
            'throw' => true,
        ],

    ],

];
