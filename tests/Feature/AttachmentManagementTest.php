<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\AttachmentsRelationManager;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\AttachmentFiles;
use App\Services\AttachmentService;
use App\Services\ExpenseService;
use App\Support\StorageConsistencyLock;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class AttachmentManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        Storage::disk('attachments')->deleteDirectory('objects');
        Storage::disk('local')->deleteDirectory('livewire-tmp');
        Storage::disk('local')->deleteDirectory('test-inputs');
    }

    private function login(string $role = 'administrator'): User
    {
        $user = User::factory()->create(['role' => Role::from($role), 'app_authentication_secret' => 'TESTONLYSECRET']);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }

    private function upload(string $extension = 'png', ?string $bytes = null, ?string $name = null): UploadedFile
    {
        if ($bytes === null) {
            if ($extension === 'pdf') {
                $bytes = PdfFixture::bytes();
            } else {
                $image = imagecreatetruecolor(8, 8);
                ob_start();
                match ($extension) {
                    'jpg', 'jpeg' => imagejpeg($image),
                    'webp' => imagewebp($image),
                    default => imagepng($image),
                };
                $bytes = ob_get_clean();
            }
        }
        $relative = 'test-inputs/'.bin2hex(random_bytes(8));
        Storage::disk('local')->put($relative, $bytes);

        return new File($name ?? 'facture.'.$extension, fopen(Storage::disk('local')->path($relative), 'r+'));
    }

    private function data(array $uploads = []): array
    {
        return ['expense_date' => '2026-11-13', 'amount' => '45,123', 'category_id' => Category::factory()->create()->id, 'new_attachments' => $uploads];
    }

    private function create(User $actor, array $uploads): Expense
    {
        return app(ExpenseService::class)->create($actor, $this->data($uploads));
    }

    public static function formats(): array
    {
        return [['png'], ['jpg'], ['jpeg'], ['webp'], ['pdf']];
    }

    #[DataProvider('formats')]
    public function test_supported_formats_are_private_generated_and_streamed_safely(string $extension): void
    {
        $actor = $this->login();
        $upload = $this->upload($extension, name: 'réçu "<test>".'.$extension);
        $expense = $this->create($actor, [$upload]);
        $file = $expense->attachments()->sole();
        $this->assertMatchesRegularExpression('/\Aobjects\/[a-f0-9]{32}\.'.preg_quote($extension).'\z/', $file->path);
        $this->assertSame(hash_file('sha256', $upload->getRealPath()), $file->sha256);
        $this->assertSame($actor->id, $file->uploaded_by);
        $this->assertSame(0600, fileperms(Storage::disk('attachments')->path($file->path)) & 0777);
        $response = $this->get(route('attachments.show', $file));
        $response->assertOk()->assertHeader('Content-Type', AttachmentFiles::TYPES[$extension])->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $inline = $this->get(route('attachments.show', ['attachment' => $file, 'mode' => 'view']));
        $inline->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $inline->headers->get('Content-Security-Policy'));
        $this->get('/attachments/'.$file->id.'/preview')->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.added', 'subject_id' => (string) $file->id, 'actor_id' => $actor->id]);
        $this->assertSame([], AuditLog::query()->where('action', 'attachment.added')->sole()->metadata);
    }

    public static function maliciousFiles(): array
    {
        return [
            ['svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['png', '<html><script>alert(1)</script></html>'],
            ['php', '<?php echo 1;'], ['pdf', "%PDF-1.7\nnot a complete file"],
            ['png', "\x89PNG\r\n\x1a\ninvalid"], ['pdf', ''], ['pdf', "%PDF-1.7\nnot a PDF document\n%%EOF\n"],
        ];
    }

    public function test_readable_pdf_with_whitespace_after_version_header_is_accepted(): void
    {
        $actor = $this->login();
        $upload = $this->upload('pdf', PdfFixture::bytes("%PDF-1.2 \n"));
        $expense = $this->create($actor, [$upload]);
        $file = $expense->attachments()->sole();
        $this->assertSame('application/pdf', $file->mime_type);
        $this->get(route('attachments.show', $file))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    #[DataProvider('maliciousFiles')]
    public function test_malformed_or_spoofed_files_never_create_records(string $extension, string $bytes): void
    {
        $actor = $this->login();
        try {
            $this->create($actor, [$this->upload($extension, $bytes)]);
            $this->fail('Upload accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('new_attachments', $exception->errors());
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('attachments')->files('objects'));
    }

    public function test_extension_mismatch_client_paths_and_pixel_bombs_are_rejected(): void
    {
        $actor = $this->login();
        $png = $this->upload();
        foreach ([$png->getRealPath(), 'objects/'.str_repeat('a', 32).'.png', $this->upload('jpg', file_get_contents($png->getRealPath()))] as $forged) {
            try {
                $this->create($actor, [$forged]);
                $this->fail('Forged upload accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('expenses', 0);
            }
        }
        config(['expenses.attachments.image_pixels' => 32]);
        $this->expectException(ValidationException::class);
        $this->create($actor, [$png]);
    }

    public function test_limits_include_retained_files_and_failed_edits_preserve_files(): void
    {
        $actor = $this->login();
        $upload = $this->upload();
        $size = filesize($upload->getRealPath());
        config(['expenses.attachments.count' => 2, 'expenses.attachments.total_bytes' => $size * 2, 'expenses.attachments.file_bytes' => $size]);
        $expense = $this->create($actor, [$upload]);
        $data = $this->data([$this->upload()]);
        app(ExpenseService::class)->update($actor, $expense, $data);
        foreach ([['expenses.attachments.count' => 2], ['expenses.attachments.count' => 10], ['expenses.attachments.file_bytes' => $size - 1]] as $limits) {
            config($limits);
            try {
                app(ExpenseService::class)->update($actor, $expense, $this->data([$this->upload()]));
                $this->fail('Limit exceeded.');
            } catch (ValidationException) {
                $this->assertSame(2, $expense->attachments()->count());
                $this->assertCount(2, Storage::disk('attachments')->files('objects'));
            }
        }
        app(ExpenseService::class)->update($actor, $expense, $this->data());
        $this->assertSame(2, $expense->attachments()->count());
    }

    public static function accessMatrix(): array
    {
        return [['administrator', false, true], ['editor', true, true], ['editor', false, false], ['viewer', true, false]];
    }

    #[DataProvider('accessMatrix')]
    public function test_all_roles_can_read_but_only_authorized_writers_can_mutate(string $role, bool $own, bool $canWrite): void
    {
        $admin = $this->login();
        $expense = $this->create($admin, [$this->upload()]);
        $actor = $this->login($role);
        if ($own) {
            $expense->forceFill(['created_by' => $actor->id])->save();
        }
        $file = $expense->attachments()->sole();
        $this->get(route('attachments.show', $file))->assertOk();
        if (! $canWrite) {
            foreach (['add', 'remove'] as $operation) {
                try {
                    $operation === 'add'
                        ? app(ExpenseService::class)->update($actor, $expense, $this->data([$this->upload()]))
                        : app(AttachmentService::class)->remove($actor, $file);
                    $this->fail('Unauthorized mutation.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('attachments', 1);
                }
            }
        } else {
            app(ExpenseService::class)->update($actor, $expense, $this->data([$this->upload('pdf')]));
            app(AttachmentService::class)->remove($actor, $file);
            $this->assertDatabaseMissing('attachments', ['id' => $file->id]);
            $this->assertFileDoesNotExist(Storage::disk('attachments')->path($file->path));
            $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.removed', 'subject_id' => (string) $file->id]);
        }
    }

    public function test_anonymous_incomplete_revoked_and_deleted_expense_access_is_denied(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload()]);
        $file = $expense->attachments()->sole();
        auth()->logout();
        $this->get(route('attachments.show', $file))->assertRedirect('/login');
        $this->actingAs($actor);
        app(AccountSessions::class)->establish($actor, false);
        $this->get(route('attachments.show', $file))->assertForbidden();
        app(AccountSessions::class)->establish($actor, true);
        $actor->forceFill(['must_change_password' => true])->save();
        $this->get(route('attachments.show', $file))->assertForbidden();
        $actor->forceFill(['must_change_password' => false])->save();
        app(ExpenseService::class)->delete($actor, $expense);
        $this->get(route('attachments.show', $file))->assertNotFound();
        $this->artisan('attachments:reconcile', ['--delete' => true])->assertSuccessful();
        $this->assertFileDoesNotExist(Storage::disk('attachments')->path($file->path));
        $actor->forceFill(['is_active' => false])->save();
        $this->get('/expenses')->assertRedirect('/login');
    }

    public function test_unsafe_stored_paths_symlinks_and_mime_types_are_not_served(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload()]);
        $file = $expense->attachments()->sole();
        $original = $file->path;
        foreach (['../.env', '/etc/passwd', 'objects/../secret.png', 'objects/evil.html'] as $path) {
            $file->forceFill(['path' => $path])->save();
            $this->get(route('attachments.show', $file))->assertNotFound();
        }
        $file->forceFill(['path' => $original, 'mime_type' => 'text/html'])->save();
        $this->get(route('attachments.show', $file))->assertNotFound();
        $file->forceFill(['mime_type' => 'image/png'])->save();
        $real = Storage::disk('attachments')->path($original);
        unlink($real);
        symlink($this->upload()->getRealPath(), $real);
        try {
            $this->get(route('attachments.show', $file))->assertNotFound();
        } finally {
            unlink($real);
        }
    }

    public function test_audit_failure_rolls_back_rows_and_compensates_all_staged_files(): void
    {
        $actor = $this->login();
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit): void {
            if ($audit->action === 'attachment.added') {
                throw new RuntimeException('Injected audit failure');
            }
        });
        try {
            $this->create($actor, [$this->upload(), $this->upload('pdf')]);
            $this->fail('Failure not injected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame([], Storage::disk('attachments')->files('objects'));
    }

    public function test_partial_copy_is_compensated_without_open_database_transaction(): void
    {
        $actor = $this->login();
        $files = new class extends AttachmentFiles
        {
            public function stage(array $file): void
            {
                if (DB::transactionLevel() !== 0) {
                    throw new RuntimeException('Copy inside transaction');
                }
                parent::stage($file);
                throw new RuntimeException('Injected interrupted copy');
            }
        };
        $this->app->instance(AttachmentFiles::class, $files);
        try {
            $this->create($actor, [$this->upload()]);
            $this->fail('Failure not injected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected interrupted copy', $exception->getMessage());
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame([], Storage::disk('attachments')->files('objects'));
    }

    public function test_reconciliation_preserves_recent_and_live_references_and_retries_failed_cleanup(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload()]);
        $file = $expense->attachments()->sole();
        $originalFiles = app(AttachmentFiles::class);
        $this->app->instance(AttachmentFiles::class, new class extends AttachmentFiles
        {
            public function removeUnreferenced(string $relative): bool
            {
                return false; // Simulated filesystem failure after a committed removal.
            }
        });
        app(AttachmentService::class)->remove($actor, $file);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertFileExists($originalFiles->disk()->path($file->path));
        $this->app->instance(AttachmentFiles::class, $originalFiles);
        $kept = $this->create($actor, [$this->upload()]);
        $keptFile = $kept->attachments()->sole();
        foreach ([$file, $keptFile] as $old) {
            touch($originalFiles->disk()->path($old->path), now()->subDays(2)->timestamp);
        }
        $recent = $originalFiles->inspect($this->upload());
        $originalFiles->stage($recent);
        Storage::disk('local')->put('livewire-tmp/abandoned.json', '{}');
        touch(Storage::disk('local')->path('livewire-tmp/abandoned.json'), now()->subDays(2)->timestamp);
        $this->artisan('attachments:reconcile')->assertSuccessful();
        $this->assertFileExists($originalFiles->disk()->path($file->path));
        $this->artisan('attachments:reconcile', ['--delete' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($originalFiles->disk()->path($file->path));
        $this->assertFileExists($originalFiles->disk()->path($keptFile->path));
        $this->assertFileExists($originalFiles->disk()->path($recent['path']));
        Storage::disk('local')->assertMissing('livewire-tmp/abandoned.json');
        unlink($originalFiles->disk()->path($keptFile->path));
        $this->artisan('attachments:reconcile')->assertFailed();
    }

    public function test_filament_upload_edit_preservation_and_confirmed_removal_use_services(): void
    {
        $actor = $this->login('editor');
        $data = $this->data([$this->upload()]);
        Livewire::test(CreateExpense::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $expense = Expense::query()->sole();
        $file = $expense->attachments()->sole();
        Livewire::test(EditExpense::class, ['record' => $expense->id])->fillForm(['description' => 'Conservé'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('attachments', ['id' => $file->id]);
        Livewire::test(AttachmentsRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
            ->assertCanSeeTableRecords([$file])->callAction(TestAction::make('delete')->table($file))->assertHasNoActionErrors();
        $this->assertDatabaseCount('attachments', 0);
        $this->assertFileDoesNotExist(Storage::disk('attachments')->path($file->path));
    }

    public function test_temp_upload_endpoint_requires_writer_mfa_rate_limits_and_single_file_requests(): void
    {
        $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinute(), [], absolute: false);
        $this->post($url, ['files' => [$this->upload()]])->assertRedirect('/login');
        $this->login('viewer');
        $this->post($url, ['files' => [$this->upload()]])->assertForbidden();
        $actor = $this->login('editor');
        app(AccountSessions::class)->establish($actor, false);
        $this->post($url, ['files' => [$this->upload()]])->assertForbidden();
        app(AccountSessions::class)->establish($actor, true);
        $this->post($url, ['files' => [$this->upload(), $this->upload()]])->assertUnprocessable();
        $this->post($url, ['files' => [$this->upload('svg', '<svg/>')]])->assertSessionHasErrors('files.0');
        $response = $this->post($url, ['files' => [$this->upload()]]);
        $response->assertOk()->assertJsonStructure(['paths']);
        for ($i = 0; $i < 29; $i++) {
            $this->post($url, ['files' => [$this->upload()]]);
        }
        $this->post($url, ['files' => [$this->upload()]])->assertStatus(429);
    }

    public function test_filename_metadata_is_sanitized(): void
    {
        $this->assertSame('réçu.pdf', AttachmentFiles::displayName("../../réçu\r\n.pdf"));
        $this->assertSame('evil.pdf', AttachmentFiles::displayName('C:\\docs\\evil.pdf'));
    }

    public function test_expense_delete_audit_failure_preserves_every_row_and_file(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload(), $this->upload('pdf')]);
        $attachments = $expense->attachments()->get();
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit): void {
            if ($audit->action === 'expense.deleted') {
                throw new RuntimeException('Injected delete audit failure');
            }
        });
        try {
            app(ExpenseService::class)->delete($actor, $expense);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected delete audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
        $this->assertSame(2, $expense->attachments()->count());
        foreach ($attachments as $attachment) {
            $this->assertFileExists(Storage::disk('attachments')->path($attachment->path));
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'attachment.removed']);
    }

    public function test_expense_deletion_commits_rows_before_file_cleanup_and_retries_failures(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload(), $this->upload('pdf')]);
        $attachments = $expense->attachments()->get();
        $originalFiles = app(AttachmentFiles::class);
        $files = new class extends AttachmentFiles
        {
            public int $calls = 0;

            public function removeUnreferenced(string $relative): bool
            {
                if (DB::transactionLevel() !== 0 || Attachment::query()->where('path', $relative)->exists() || Expense::query()->exists()) {
                    throw new RuntimeException('Cleanup preceded database commit');
                }
                $this->calls++;

                return false;
            }
        };
        $this->app->instance(AttachmentFiles::class, $files);
        app(ExpenseService::class)->delete($actor, $expense);
        $this->assertSame(2, $files->calls);
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
        $this->assertDatabaseCount('attachments', 0);
        foreach ($attachments as $attachment) {
            $this->assertFileExists($originalFiles->disk()->path($attachment->path));
            touch($originalFiles->disk()->path($attachment->path), now()->subDays(2)->timestamp);
        }
        $this->app->instance(AttachmentFiles::class, $originalFiles);
        $this->artisan('attachments:reconcile', ['--delete' => true])->assertSuccessful();
        $this->assertSame([], $originalFiles->disk()->files('objects'));
    }

    public static function concurrentLimits(): array
    {
        return [['count'], ['total']];
    }

    #[DataProvider('concurrentLimits')]
    public function test_two_real_processes_cannot_exceed_the_same_expense_limit(string $limit): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, []);
        $input = $this->upload()->getRealPath();
        $jobs = [];
        app(StorageConsistencyLock::class)->run(function () use ($actor, $expense, $input, $limit, &$jobs): void {
            for ($i = 0; $i < 2; $i++) {
                $path = Storage::disk('local')->path('test-inputs/job'.$i.'.json');
                file_put_contents($path, json_encode(['actor' => $actor->id, 'expense' => $expense->id, 'category' => $expense->category_id, 'upload' => $input, 'count' => $limit === 'count' ? 1 : 10, 'total' => $limit === 'total' ? filesize($input) : 1000000]));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/attachment-writer.php'), $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                $jobs[] = [$process, $pipes, $path];
            }
            $deadline = microtime(true) + 3;
            while ((! file_exists($jobs[0][2].'.ready') || ! file_exists($jobs[1][2].'.ready')) && microtime(true) < $deadline) {
                usleep(20000);
            }
        });
        $results = [];
        foreach ($jobs as [$process, $pipes, $path]) {
            $results[] = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(1, $expense->attachments()->count());
        $this->assertCount(1, Storage::disk('attachments')->files('objects'));
    }

    public function test_ambient_transactions_are_rejected_before_files_are_changed(): void
    {
        $actor = $this->login();
        $expense = $this->create($actor, [$this->upload()]);
        $file = $expense->attachments()->sole();
        try {
            DB::transaction(fn () => app(AttachmentService::class)->remove($actor, $file));
            $this->fail('Unsafe nested transaction accepted.');
        } catch (\LogicException) {
            $this->assertDatabaseHas('attachments', ['id' => $file->id]);
            $this->assertFileExists(Storage::disk('attachments')->path($file->path));
        }
    }
}
