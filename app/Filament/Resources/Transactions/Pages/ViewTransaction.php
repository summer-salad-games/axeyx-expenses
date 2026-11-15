<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\TransactionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected static ?string $title = 'Détail de la transaction';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->action(null)->authorize(fn () => TransactionResource::canEdit($this->getRecord()))
                ->url(fn () => TransactionResource::getUrl('edit', ['record' => $this->getRecord()])),
            TransactionResource::deleteAction()->successRedirectUrl(TransactionResource::getUrl()),
        ];
    }
}
