<?php

use App\Models\Expense;
use App\Services\AttachmentFiles;
use App\Services\AuditRecorder;
use App\Support\StorageConsistencyLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Own the commit boundary: physical files must be removed only after commit.
    public $withinTransaction = false;

    public function up(): void
    {
        app(StorageConsistencyLock::class)->run(function (): void {
            // A crash after the DDL commit may precede Laravel's migration-log write.
            if (! Schema::hasColumn('expenses', 'deleted_at')) {
                return;
            }
            $paths = DB::transaction(function (): array {
                $paths = [];
                // Owner explicitly authorized purging previously deleted development data.
                foreach (Expense::query()->whereNotNull('deleted_at')->get() as $expense) {
                    foreach ($expense->attachments()->get() as $attachment) {
                        $paths[] = $attachment->path;
                        app(AuditRecorder::class)->record('attachment.removed', $attachment);
                        $attachment->delete();
                    }
                    app(AuditRecorder::class)->record('expense.deleted', $expense);
                    $expense->delete();
                }
                Schema::table('expenses', function (Blueprint $table): void {
                    $table->dropIndex('expenses_deleted_at_expense_date_index');
                    $table->dropColumn('deleted_at');
                    $table->index('expense_date');
                });

                return $paths;
            }, 3);
            foreach ($paths as $path) {
                app(AttachmentFiles::class)->removeUnreferenced($path);
            }
        });
    }

    public function down(): void
    {
        // Schema rollback cannot recover permanently deleted records or documents.
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropIndex('expenses_expense_date_index');
            $table->timestamp('deleted_at')->nullable();
            $table->index(['deleted_at', 'expense_date']);
        });
    }
};
