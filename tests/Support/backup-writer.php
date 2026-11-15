<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Support\TestIsolation;

$base = dirname(__DIR__, 2);
$database = TestIsolation::prepareDatabase($base, 'testing');
$id = (int) ($argv[1] ?? 0);
$ready = $base.'/.data/testing/backup-writer.ready';
$db = new SQLite3($database);
$db->enableExceptions(true);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=FULL');
$db->exec('PRAGMA foreign_keys=ON');
file_put_contents($ready, 'ready');
for ($i = 1; $i <= 500; $i++) {
    $db->exec('BEGIN IMMEDIATE');
    $statement = $db->prepare('UPDATE users SET name=:name WHERE id=:id');
    $statement->bindValue(':id', $id, SQLITE3_INTEGER);
    $statement->bindValue(':name', 'Writer '.$i, SQLITE3_TEXT);
    $statement->execute();
    $statement->close();
    $statement = $db->prepare("INSERT INTO audit_logs (actor_kind, action, subject_type, subject_id, metadata, created_at)
        VALUES ('operator', 'fixture.counter', 'counter', :counter, '{}', '2026-11-15 00:00:00')");
    $statement->bindValue(':counter', (string) $i);
    $statement->execute();
    $statement->close();
    $db->exec('COMMIT');
    usleep(1000);
}
$db->close();
