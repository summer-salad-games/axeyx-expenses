<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

trait MapsFormValidationErrors
{
    protected function withFormValidation(Closure $write): Model
    {
        try {
            return $write();
        } catch (ValidationException $exception) {
            $prefix = $this->form->getStatePath();
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field) => [$prefix.'.'.$field => $messages])->all());
        }
    }
}
