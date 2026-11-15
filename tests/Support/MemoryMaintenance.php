<?php

namespace Tests\Support;

use Illuminate\Contracts\Foundation\MaintenanceMode;

final class MemoryMaintenance implements MaintenanceMode
{
    private array $payload = [];

    public function activate(array $payload): void
    {
        $this->payload = $payload;
    }

    public function deactivate(): void
    {
        $this->payload = [];
    }

    public function active(): bool
    {
        return $this->payload !== [];
    }

    public function data(): array
    {
        return $this->payload;
    }
}
