<?php

namespace App\Filament\Widgets;

use Filament\Support\Components\Attributes\Unexposed;

class TransactionTimeline extends TransactionChart
{
    protected string $group = 'timeline';

    protected int|string|array $columnSpan = 'full';

    #[Unexposed]
    public function getHeading(): string
    {
        return ($this->statistics['granularity'] ?? 'month') === 'day' ? 'Transactions par jour' : 'Transactions par mois';
    }
}
