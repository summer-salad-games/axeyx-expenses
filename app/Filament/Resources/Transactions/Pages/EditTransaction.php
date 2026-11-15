<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Services\TransactionService;
use App\Support\TndAmount;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTransaction extends EditRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = TransactionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withFormValidation(fn () => app(TransactionService::class)->update(Filament::auth()->user(), $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return TransactionResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = TndAmount::decimal($this->getRecord()->amount_millimes);
        $data['new_attachments'] = [];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [TransactionResource::deleteAction()->successRedirectUrl(TransactionResource::getUrl())];
    }
}
