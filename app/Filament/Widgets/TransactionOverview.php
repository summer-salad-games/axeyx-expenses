<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasTransactionStatistics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TransactionOverview extends StatsOverviewWidget
{
    use HasTransactionStatistics;

    protected function getStats(): array
    {
        return [
            Stat::make('Recettes', $this->statistics['revenue_formatted'] ?? '—')->extraAttributes(['data-testid' => 'statistics-revenue', 'class' => 'transaction-statistics-money']),
            Stat::make('Dépenses', $this->statistics['expense_formatted'] ?? '—')->extraAttributes(['data-testid' => 'statistics-expense', 'class' => 'transaction-statistics-money']),
            Stat::make('Net de la période', $this->statistics['net_formatted'] ?? '—')->extraAttributes(['data-testid' => 'statistics-net', 'class' => 'transaction-statistics-money']),
            Stat::make('Nombre de transactions', $this->statistics['count'] ?? '—')->extraAttributes(['data-testid' => 'statistics-count']),
        ];
    }
}
