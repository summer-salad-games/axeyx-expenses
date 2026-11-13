<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use App\Support\TndAmount;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExpense extends EditRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = ExpenseResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withFormValidation(fn () => app(ExpenseService::class)->update(Filament::auth()->user(), $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['amount'] = TndAmount::decimal($this->getRecord()->amount_millimes);
        $data['new_attachments'] = [];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [ExpenseResource::deleteAction()->successRedirectUrl(ExpenseResource::getUrl())];
    }
}
