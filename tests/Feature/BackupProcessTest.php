<?php

namespace Tests\Feature;

use App\Operations\Backups\BackupPaths;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupProcessTest extends TestCase
{
    private string $fixture;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = base_path('.data/testing/backup-process');
        BackupPaths::remove($this->fixture);
        BackupPaths::directory($this->fixture.'/scripts');
        BackupPaths::directory($this->fixture.'/app/Operations/Backups');
        BackupPaths::directory($this->fixture.'/state');
        BackupPaths::directory($this->fixture.'/work');
        foreach (['backup-run.php', 'backup-finalize.php'] as $file) {
            copy(base_path('scripts/'.$file), $this->fixture.'/scripts/'.$file);
        }
        foreach (['BackupPaths.php', 'BackupAttempt.php'] as $file) {
            copy(base_path('app/Operations/Backups/'.$file), $this->fixture.'/app/Operations/Backups/'.$file);
        }
        $this->environment = ['BACKUP_STATE_PATH' => $this->fixture.'/state', 'BACKUP_WORKSPACE' => $this->fixture.'/work'];
    }

    public function test_bootstrap_failure_is_recorded_without_leaking_exception_text(): void
    {
        file_put_contents($this->fixture.'/artisan', "<?php throw new RuntimeException('fixture-private-secret');");
        $process = new Process([PHP_BINARY, $this->fixture.'/scripts/backup-run.php'], $this->fixture, $this->environment);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringNotContainsString('fixture-private-secret', $process->getOutput().$process->getErrorOutput());
        $files = glob($this->fixture.'/state/attempts/*.json');
        $this->assertCount(1, $files);
        $status = BackupPaths::readJson($files[0]);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('bootstrap', $status['stage']);
    }

    public function test_killed_wrapper_is_marked_failed_and_its_financial_staging_is_cleaned(): void
    {
        file_put_contents($this->fixture.'/artisan', <<<'PHP'
<?php
$id = substr($argv[2], strlen('--attempt='));
$work = getenv('BACKUP_WORKSPACE').'/'.$id;
mkdir($work, 0700, true);
file_put_contents($work.'/private-data', 'financial fixture');
file_put_contents(getenv('BACKUP_STATE_PATH').'/child.pid', (string) getmypid());
while (true) { usleep(100000); }
PHP);
        $process = new Process([PHP_BINARY, $this->fixture.'/scripts/backup-run.php'], $this->fixture, $this->environment, timeout: 10);
        $process->start();
        $pidPath = $this->fixture.'/state/child.pid';
        $deadline = microtime(true) + 5;
        while (! file_exists($pidPath) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($pidPath);
        $child = (int) file_get_contents($pidPath);
        try {
            $process->signal(SIGKILL);
            posix_kill($child, SIGKILL);
            $process->wait();
            $finalizer = new Process([PHP_BINARY, $this->fixture.'/scripts/backup-finalize.php'], $this->fixture, $this->environment);
            $finalizer->mustRun();
            $files = glob($this->fixture.'/state/attempts/*.json');
            $this->assertSame('failed', BackupPaths::readJson($files[0])['status']);
            $this->assertSame([], glob($this->fixture.'/work/*'));
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
            @posix_kill($child, SIGKILL);
        }
    }
}
