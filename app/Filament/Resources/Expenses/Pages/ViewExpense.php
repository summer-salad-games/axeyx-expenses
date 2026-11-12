<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected static ?string $title = 'Détail de la dépense';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->action(null)->authorize(fn () => ExpenseResource::canEdit($this->getRecord()))
                ->url(fn () => ExpenseResource::getUrl('edit', ['record' => $this->getRecord()])),
            ExpenseResource::deleteAction()->successRedirectUrl(ExpenseResource::getUrl()),
        ];
    }
}
