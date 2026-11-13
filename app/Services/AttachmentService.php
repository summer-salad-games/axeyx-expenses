<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Expense;
use App\Models\User;
use App\Support\StorageConsistencyLock;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AttachmentService
{
    /** Expense and attachment metadata/audits commit together. Bytes are staged before the short transaction. */
    public function save(User $actor, ?Expense $expense, mixed $uploads, Closure $saveExpense): Expense
    {
        $this->assertNoTransaction();
        Gate::forUser($actor)->authorize($expense ? 'update' : 'create', $expense ?: Expense::class);
        if (! is_array($uploads) || count($uploads) > config('expenses.attachments.count')) {
            throw ValidationException::withMessages(['new_attachments' => 'Trop de fichiers ou sélection invalide.']);
        }
        $files = app(AttachmentFiles::class);
        $inspected = array_map($files->inspect(...), $uploads);

        return app(StorageConsistencyLock::class)->run(function () use ($actor, $saveExpense, $files, $inspected): Expense {
            $staged = [];
            try {
                // An orphan from a partial write is tracked before the first byte is copied.
                foreach ($inspected as $file) {
                    $staged[] = $file['path'];
                    $files->stage($file);
                }

                return DB::transaction(function () use ($actor, $saveExpense, $inspected): Expense {
                    $record = $saveExpense();
                    $retained = $record->attachments()->get(['size_bytes']);
                    if ($retained->count() + count($inspected) > config('expenses.attachments.count')
                        || $retained->sum('size_bytes') + array_sum(array_column($inspected, 'size_bytes')) > config('expenses.attachments.total_bytes')) {
                        throw ValidationException::withMessages(['new_attachments' => 'Limite de pièces jointes dépassée (nombre ou taille totale).']);
                    }
                    foreach ($inspected as $file) {
                        $attachment = new Attachment;
                        unset($file['source']);
                        $attachment->forceFill($file + ['expense_id' => $record->id, 'uploaded_by' => $actor->id])->save();
                        app(AuditRecorder::class)->record('attachment.added', $attachment, actor: $actor);
                    }

                    return $record;
                }, 3);
            } finally {
                // On rollback/failed staging, compensate; on success, live references protect every file.
                foreach ($staged as $path) {
                    $files->removeUnreferenced($path);
                }
            }
        });
    }

    public function remove(User $actor, Attachment $attachment): bool
    {
        $this->assertNoTransaction();

        return app(StorageConsistencyLock::class)->run(function () use ($actor, $attachment): bool {
            $path = DB::transaction(function () use ($actor, $attachment): string {
                $record = Attachment::query()->findOrFail($attachment->id);
                Gate::forUser($actor)->authorize('delete', $record);
                app(AuditRecorder::class)->record('attachment.removed', $record, actor: $actor);
                $record->delete();

                return $record->path;
            }, 3);
            app(AttachmentFiles::class)->removeUnreferenced($path);

            return true;
        });
    }

    private function assertNoTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Attachment operations must own the outermost transaction and consistency lock.');
        }
    }

    public function removeExpense(User $actor, Expense $expense): bool
    {
        $this->assertNoTransaction();

        return app(StorageConsistencyLock::class)->run(function () use ($actor, $expense): bool {
            $paths = DB::transaction(function () use ($actor, $expense): array {
                $record = Expense::query()->findOrFail($expense->getKey());
                Gate::forUser($actor)->authorize('delete', $record);
                $attachments = $record->attachments()->get();
                foreach ($attachments as $attachment) {
                    app(AuditRecorder::class)->record('attachment.removed', $attachment, actor: $actor);
                    $attachment->delete();
                }
                app(AuditRecorder::class)->record('expense.deleted', $record, actor: $actor);
                $record->delete();

                return $attachments->pluck('path')->all();
            }, 3);
            foreach ($paths as $path) {
                app(AttachmentFiles::class)->removeUnreferenced($path);
            }

            return true;
        });
    }
}
