<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => env('PRIVATE_STORAGE_PATH', storage_path('app/private')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
        'attachments' => [
            'driver' => 'local',
            'root' => env('ATTACHMENTS_PATH', storage_path('app/private/attachments')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
    ],
    'links' => [],
];
