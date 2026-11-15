<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Services\AttachmentFiles;
use App\Support\StorageConsistencyLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class ReconcileAttachments extends Command
{
    protected $signature = 'attachments:reconcile {--delete : Delete orphaned files after the grace period}';

    protected $description = 'Verify attachment references and safely clean up abandoned files (dry run by default)';

    public function handle(AttachmentFiles $files, StorageConsistencyLock $lock): int
    {
        return $lock->run(function () use ($files): int {
            $missing = 0;
            Attachment::query()->eachById(function (Attachment $attachment) use ($files, &$missing): void {
                $path = $files->readPath($attachment->path);
                if (! $path || filesize($path) !== $attachment->size_bytes || hash_file('sha256', $path) !== $attachment->sha256) {
                    $this->error('Missing or altered attachment reference: #'.$attachment->id);
                    $missing++;
                }
            });
            $cutoff = now()->subHours(max(24, config('transactions.attachments.orphan_grace_hours')))->timestamp;
            $orphans = 0;
            foreach ($files->disk()->files('objects') as $relative) {
                if (! $files->readPath($relative) || Attachment::query()->where('path', $relative)->exists()
                    || $files->disk()->lastModified($relative) >= $cutoff) {
                    continue;
                }
                $orphans++;
                if ($this->option('delete')) {
                    $files->removeUnreferenced($relative);
                }
            }
            // Livewire's request-triggered cleanup is supplemented for idle installations.
            $temporary = Storage::disk('local');
            foreach (['livewire-tmp', 'validation-tmp'] as $directory) {
                if (is_link($temporary->path($directory))) {
                    continue;
                }
                foreach ($temporary->files($directory) as $relative) {
                    if (! preg_match('/\A(?:livewire-tmp|validation-tmp)\/[a-zA-Z0-9._=-]+\z/', $relative) || is_link($temporary->path($relative))) {
                        continue;
                    }
                    if ($temporary->lastModified($relative) < $cutoff && $this->option('delete')) {
                        $temporary->delete($relative);
                    }
                }
            }
            $this->info($orphans.' old orphaned file(s). '.($this->option('delete') ? 'Cleanup completed.' : 'Dry run; no files deleted.'));

            return $missing ? self::FAILURE : self::SUCCESS;
        });
    }
}
