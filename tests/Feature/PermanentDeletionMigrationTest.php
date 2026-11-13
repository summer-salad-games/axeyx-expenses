<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Support\StorageConsistencyLock;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class PermanentDeletionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_13_000000_make_expense_deletion_permanent.php');
    }

    private function fixture(): array
    {
        $expense = Expense::factory()->create();
        $path = 'objects/'.bin2hex(random_bytes(16)).'.pdf';
        $bytes = PdfFixture::bytes();
        Storage::disk('attachments')->put($path, $bytes);
        $attachment = Attachment::factory()->create([
            'expense_id' => $expense->id, 'path' => $path,
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ]);

        return [$expense, $attachment];
    }

    public function test_migration_purges_previous_soft_deletions_and_files_without_changing_live_data(): void
    {
        [$live, $liveFile] = $this->fixture();
        [$deleted, $deletedFile] = $this->fixture();
        $migration = $this->migration();
        $migration->down();
        DB::table('expenses')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        $migration->up();
        $migration->up(); // Safe retry after a committed migration with no log entry yet.
        $this->assertFalse(Schema::hasColumn('expenses', 'deleted_at'));
        $this->assertDatabaseHas('expenses', ['id' => $live->id]);
        $this->assertDatabaseHas('attachments', ['id' => $liveFile->id]);
        $this->assertFileExists(Storage::disk('attachments')->path($liveFile->path));
        $this->assertDatabaseMissing('expenses', ['id' => $deleted->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $deletedFile->id]);
        $this->assertFileDoesNotExist(Storage::disk('attachments')->path($deletedFile->path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.deleted', 'subject_id' => (string) $deleted->id, 'actor_kind' => 'operator']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app(StorageConsistencyLock::class)->run(fn () => true));
    }

    public function test_migration_audit_failure_rolls_back_schema_rows_and_keeps_files(): void
    {
        [$expense, $file] = $this->fixture();
        $migration = $this->migration();
        $migration->down();
        DB::table('expenses')->where('id', $expense->id)->update(['deleted_at' => now()]);
        Event::listen('eloquent.creating: '.AuditLog::class, function (): void {
            throw new RuntimeException('Injected purge failure');
        });
        try {
            try {
                $migration->up();
                $this->fail('Expected migration failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected purge failure', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('expenses', 'deleted_at'));
            $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
            $this->assertDatabaseHas('attachments', ['id' => $file->id]);
            $this->assertFileExists(Storage::disk('attachments')->path($file->path));
            $this->assertDatabaseMissing('audit_logs', ['action' => 'expense.deleted']);
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
            $migration->up();
        }
    }
}
