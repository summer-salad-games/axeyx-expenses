<?php

namespace App\Security;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class UncommonPassword implements ValidationRule
{
    private static ?array $blocked = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        self::$blocked ??= array_fill_keys(file(resource_path('security/common-passwords.txt'), FILE_IGNORE_NEW_LINES), true);
        if (is_string($value) && isset(self::$blocked[$value])) {
            $fail('validation.password.common')->translate();
        }
    }
}
