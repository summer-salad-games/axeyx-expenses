<?php

use App\Http\Middleware\EnsureAttachmentUploadAccess;

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'directory' => 'livewire-tmp',
        'rules' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf'],
        'middleware' => ['web', 'auth', EnsureAttachmentUploadAccess::class, 'throttle:30,1'],
        'preview_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
