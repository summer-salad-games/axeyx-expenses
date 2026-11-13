<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAttachmentUploadAccess;
use App\Models\User;
use App\Policies\DatabaseNotificationPolicy;
use App\Security\AuthenticationFailures;
use App\Support\TestIsolation;
use Illuminate\Auth\Events\Failed;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Features\SupportFileUploads\FilePreviewController;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        TestIsolation::configure($this->app);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FilePreviewController::$middleware = ['web', 'auth', EnsureAttachmentUploadAccess::class];
        // Keep the temporary endpoint aligned with the final configurable byte limit.
        config(['livewire.temporary_file_upload.rules.2' => 'max:'.intdiv(config('expenses.attachments.file_bytes'), 1024)]);
        Password::defaults(fn () => Password::min(12));
        Gate::policy(DatabaseNotification::class, DatabaseNotificationPolicy::class);
        Event::listen(Failed::class, fn (Failed $event) => app(AuthenticationFailures::class)
            ->record('password', $event->user instanceof User ? $event->user : null));
    }
}
