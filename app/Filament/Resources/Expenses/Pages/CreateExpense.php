<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormValidation(fn () => app(ExpenseService::class)->create(Filament::auth()->user(), $data));
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
