<?php

namespace App\Rules;

use App\Support\TndAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class TndAmountRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Saisissez le montant sous forme décimale.');
            }
            TndAmount::parse($value, config('expenses.max_amount_millimes'));
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }
}
