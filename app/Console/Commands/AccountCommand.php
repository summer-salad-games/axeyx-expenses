<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

abstract class AccountCommand extends Command
{
    protected function safely(Closure $action): int
    {
        try {
            $action();

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }
        } catch (ModelNotFoundException) {
            $this->error('User not found.');
        } catch (QueryException) {
            // Never print SQL, bindings or credential-bearing exception context.
            $this->error('The change failed. Check database availability and account data.');
        }

        return self::FAILURE;
    }

    protected function selectedRole(string $value): Role
    {
        $role = Role::tryFrom($value);
        if (! $role) {
            throw ValidationException::withMessages(['role' => 'Invalid role: administrator, editor or viewer.']);
        }

        return $role;
    }

    /** @return array{string, string} */
    protected function passwordInput(): array
    {
        return [
            (string) $this->secret('Password', fallback: false),
            (string) $this->secret('Confirm password', fallback: false),
        ];
    }
}
