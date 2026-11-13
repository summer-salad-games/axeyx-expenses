<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Categories\CategoryResource;
use App\Services\CategoryService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = CategoryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withFormValidation(fn () => app(CategoryService::class)->update(Filament::auth()->user(), $record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return CategoryResource::getUrl();
    }

    protected function getHeaderActions(): array
    {
        return [CategoryResource::deleteAction()->successRedirectUrl(CategoryResource::getUrl())];
    }
}
