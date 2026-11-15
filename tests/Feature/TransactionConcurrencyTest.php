<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\TransactionStatistics;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransactionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_twenty_independent_clients_write_and_read_consistent_wal_reports(): void
    {
        $directory = base_path('.data/testing/private/test-inputs');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $barrier = $directory.'/clients.go';
        @unlink($barrier);
        $categories = [
            'expense' => Category::factory()->create(),
            'revenue' => Category::factory()->create(['type' => 'revenue']),
        ];
        $processes = [];
        $paths = [];
        try {
            for ($i = 0; $i < 20; $i++) {
                $actor = User::factory()->create(['role' => 'editor', 'app_authentication_secret' => 'TESTONLYSECRET']);
                $type = $i % 2 ? 'revenue' : 'expense';
                $path = $directory.'/client-'.$i.'.json';
                @unlink($path.'.ready');
                file_put_contents($path, json_encode(['actor' => $actor->id, 'category' => $categories[$type]->id, 'type' => $type, 'barrier' => $barrier]));
                $paths[] = $path;
                $process = new Process([PHP_BINARY, base_path('tests/Support/transaction-client.php'), $path], base_path(), timeout: 40);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 25;
            do {
                $ready = count(array_filter($paths, fn ($path) => file_exists($path.'.ready')));
                if ($ready === 20) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(20, $ready, 'All clients should reach the common start barrier.');
            file_put_contents($barrier, 'go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertMatchesRegularExpression('/^[0-9]+$/', $process->getOutput());
            }
            $this->assertSame(20, Transaction::query()->count());
            $this->actingAs($actor);
            app(AccountSessions::class)->establish($actor, true);
            $report = app(TransactionStatistics::class)->report($actor, ['preset' => 'custom', 'from' => '2026-11-15', 'until' => '2026-11-15']);
            $this->assertSame(['10010', '10010', '0', 20], [$report['expense_millimes'], $report['revenue_millimes'], $report['net_millimes'], $report['count']]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($paths as $path) {
                @unlink($path);
                @unlink($path.'.ready');
            }
            @unlink($barrier);
        }
    }
}
