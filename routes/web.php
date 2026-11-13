<?php

use App\Http\Controllers\AttachmentController;
use Illuminate\Support\Facades\Route;

// The shared Filament panel owns the root URL.
Route::redirect('/multi-factor-authentication/set-up', '/setup');

Route::get('/attachments/{attachment}/{mode?}', AttachmentController::class)
    ->middleware('auth')->whereNumber('attachment')->whereIn('mode', ['download', 'view'])->name('attachments.show');
