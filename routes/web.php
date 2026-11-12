<?php

use Illuminate\Support\Facades\Route;

// The shared Filament panel owns the root URL.
Route::redirect('/multi-factor-authentication/set-up', '/setup');
