<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Exposed;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->action(null)];
    }

    #[Exposed]
    public function applyTableFilters(): void
    {
        $this->getTableFiltersForm()->getState();
        parent::applyTableFilters();
    }
}
