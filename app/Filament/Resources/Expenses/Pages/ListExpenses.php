<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Components\Attributes\Exposed;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

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
