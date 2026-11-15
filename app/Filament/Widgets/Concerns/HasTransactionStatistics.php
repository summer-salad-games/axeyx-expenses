<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\Dashboard;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Reactive;

trait HasTransactionStatistics
{
    #[Reactive]
    public array $statistics = [];

    protected static ?bool $isLazyLoaded = false;

    protected ?string $pollingInterval = null;

    #[Unexposed]
    public static function canView(): bool
    {
        return Dashboard::canAccess();
    }
}
