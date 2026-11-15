<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'permissions' => ['file' => ['private' => 0640], 'dir' => ['private' => 02770]],
            'root' => env('PRIVATE_STORAGE_PATH', storage_path('app/private')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
        'attachments' => [
            'driver' => 'local',
            'permissions' => ['file' => ['private' => 0640], 'dir' => ['private' => 02770]],
            'root' => env('ATTACHMENTS_PATH', storage_path('app/private/attachments')),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
        ],
    ],
    'links' => [],
];
