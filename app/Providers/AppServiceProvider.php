<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAttachmentUploadAccess;
use App\Models\User;
use App\Operations\Backups\BackupTransport;
use App\Operations\Backups\RcloneBackupTransport;
use App\Security\AuthenticationFailures;
use App\Security\UncommonPassword;
use App\Support\TestIsolation;
use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
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
        $this->app->bind(BackupTransport::class, RcloneBackupTransport::class);
        TestIsolation::configure($this->app);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep CLI validation in English and restore the web locale after nested commands.
        $consoleLocales = [];
        Event::listen(CommandStarting::class, function () use (&$consoleLocales): void {
            $consoleLocales[] = $this->app->getLocale();
            $this->app->setLocale('en');
        });
        Event::listen(CommandFinished::class, function () use (&$consoleLocales): void {
            $this->app->setLocale(array_pop($consoleLocales) ?? 'fr');
        });

        FilePreviewController::$middleware = ['web', 'auth', EnsureAttachmentUploadAccess::class];
        // Keep the temporary endpoint aligned with the final configurable byte limit.
        config(['livewire.temporary_file_upload.rules.2' => 'max:'.intdiv(config('transactions.attachments.file_bytes'), 1024)]);
        Password::defaults(fn () => Password::min(12)->rules([new UncommonPassword]));
        Event::listen(Failed::class, fn (Failed $event) => app(AuthenticationFailures::class)
            ->record('password', $event->user instanceof User ? $event->user : null));
    }
}
