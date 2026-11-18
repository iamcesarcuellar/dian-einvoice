<?php

// Merged with the framework defaults: only the disks added here are listed.
return [

    'disks' => [

        // Files of electronic documents. Private: there is no URL; the API serves them after authorization.
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'visibility' => 'private',
            'throw' => true,
        ],

    ],

];
