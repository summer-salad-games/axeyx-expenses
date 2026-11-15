<?php

return [
    'workspace' => env('BACKUP_WORKSPACE', storage_path('app/backup-work')),
    'state' => env('BACKUP_STATE_PATH', storage_path('app/backup-state')),
    'remote' => env('BACKUP_REMOTE', ''),
    'rclone' => env('RCLONE_BINARY', 'rclone'),
    'rclone_config' => env('RCLONE_CONFIG', ''),
    'release' => env('APP_RELEASE', 'development'),
    'retain' => 30,
    'incomplete_days' => 7,
    'timeout' => 1800,
    'archive_bytes' => 10 * 1024 ** 3,
    'expanded_bytes' => 30 * 1024 ** 3,
    'entries' => 100000,
];
