<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        $database = $this->app?->make('db');

        try {
            parent::tearDown();
        } finally {
            // File-backed WAL connections must not survive into the next application.
            foreach ($database?->getConnections() ?? [] as $name => $connection) {
                $database->purge($name);
            }
        }
    }
}
