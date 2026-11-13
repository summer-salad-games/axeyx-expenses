<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Shared by attachment mutations/reconciliation and future backup staging. */
final class StorageConsistencyLock
{
    public function run(Closure $operation): mixed
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('locks');
        $path = $disk->path('locks/consistency.lock');
        if (is_link($path) || ! ($handle = fopen($path, 'c'))) {
            throw new RuntimeException('Cannot open storage consistency lock.');
        }
        chmod($path, 0600);
        $deadline = microtime(true) + 5;
        try {
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        return $operation();
                    } finally {
                        flock($handle, LOCK_UN);
                    }
                }
                usleep(25000);
            } while (microtime(true) < $deadline);

            throw ValidationException::withMessages(['new_attachments' => 'Le stockage est occupé. Réessayez dans quelques instants.']);
        } finally {
            fclose($handle);
        }
    }
}
