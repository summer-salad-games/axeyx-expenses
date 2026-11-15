<?php

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Services\TransactionService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTransaction extends CreateRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = TransactionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormValidation(fn () => app(TransactionService::class)->create(Filament::auth()->user(), $data));
    }

    protected function getRedirectUrl(): string
    {
        return TransactionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
