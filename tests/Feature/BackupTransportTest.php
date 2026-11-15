<?php

namespace Tests\Feature;

use App\Operations\Backups\BackupPaths;
use App\Operations\Backups\RcloneBackupTransport;
use RuntimeException;
use Tests\TestCase;

class BackupTransportTest extends TestCase
{
    private string $root;

    private string $configFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = base_path('.data/testing/rclone-fixture');
        BackupPaths::remove($this->root);
        BackupPaths::directory($this->root);
        $binary = $this->root.'/rclone';
        file_put_contents($binary, "#!/usr/bin/env php\n<?php require ".var_export(base_path('tests/Support/fake-rclone.php'), true).";\n");
        chmod($binary, 0700);
        $this->configFile = $this->root.'/config.json';
        BackupPaths::json($this->configFile, ['root' => $this->root]);
        config(['backups.rclone' => $binary, 'backups.rclone_config' => $this->configFile,
            'backups.remote' => 'fixture:backups', 'backups.retain' => 2]);
    }

    private function published(string $id): void
    {
        $source = BackupPaths::directory($this->root.'/upload-'.$id);
        file_put_contents($source.'/backup.tar.gz', 'fixture archive');
        BackupPaths::json($source.'/manifest.json', ['fixture' => true]);
        (new RcloneBackupTransport)->publish($id, $source);
    }

    public function test_completion_requires_content_verification_and_read_back_and_download_checks_hashes(): void
    {
        $id = BackupPaths::id();
        $this->published($id);
        $transport = new RcloneBackupTransport;
        $this->assertSame([$id], $transport->attempts());
        $target = BackupPaths::directory($this->root.'/download');
        $this->assertSame(['fixture' => true], $transport->download($id, $target));
        $commands = file_get_contents($this->root.'/commands.log');
        $this->assertStringContainsString('--download', $commands);
        $this->assertStringContainsString('complete.json', $commands);
        file_put_contents($this->root.'/backups/'.$id.'/backup.tar.gz', 'altered content');
        $this->expectException(RuntimeException::class);
        $transport->download($id, $target);
    }

    public function test_failed_verification_never_publishes_completion_or_prunes_previous_backups(): void
    {
        $id = BackupPaths::id();
        BackupPaths::json($this->configFile, ['root' => $this->root, 'fail' => 'check']);
        try {
            $this->published($id);
            $this->fail('Expected verification failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private provider', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->root.'/backups/'.$id.'/complete.json');
        $this->assertStringNotContainsString('purge', file_get_contents($this->root.'/commands.log'));
    }

    public function test_retention_is_scoped_and_preserves_newest_successes_and_unrelated_directories(): void
    {
        $ids = [];
        for ($day = 1; $day <= 3; $day++) {
            $id = '2026010'.$day.'T031500Z-'.str_repeat((string) $day, 16);
            $this->published($id);
            $ids[] = $id;
        }
        $unrelated = BackupPaths::directory($this->root.'/backups/unrelated');
        file_put_contents($unrelated.'/keep', 'preserve');
        (new RcloneBackupTransport)->prune($ids[2]);
        $this->assertDirectoryDoesNotExist($this->root.'/backups/'.$ids[0]);
        $this->assertDirectoryExists($this->root.'/backups/'.$ids[1]);
        $this->assertDirectoryExists($this->root.'/backups/'.$ids[2]);
        $this->assertFileExists($unrelated.'/keep');
    }
}
