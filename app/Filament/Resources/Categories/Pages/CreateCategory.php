<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\MapsFormValidationErrors;
use App\Filament\Resources\Categories\CategoryResource;
use App\Services\CategoryService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    use MapsFormValidationErrors;

    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->withFormValidation(fn () => app(CategoryService::class)->create(Filament::auth()->user(), $data));
    }

    protected function getRedirectUrl(): string
    {
        return CategoryResource::getUrl();
    }
}
