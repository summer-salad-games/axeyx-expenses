<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use App\Security\AccountSessions;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AccountService
{
    public function __construct(private readonly AuditRecorder $audits) {}

    public function resolveUser(string $identifier): User
    {
        $identifier = trim($identifier);
        $user = ctype_digit($identifier)
            ? User::query()->find($identifier)
            : User::query()->where('email', mb_strtolower($identifier, 'UTF-8'))->first();

        if (! $user) {
            throw ValidationException::withMessages(['user' => 'Utilisateur introuvable.']);
        }

        return $user;
    }

    public static function passwordRules(): array
    {
        return ['required', 'string', Password::defaults(), 'confirmed'];
    }

    public function create(string $name, string $email, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $passwordConfirmation, Role $role = Role::Viewer): User
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email), 'UTF-8');
        Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => self::passwordRules(),
        ])->validate();
        $hash = Hash::make($password);

        return DB::transaction(function () use ($name, $email, $hash, $role): User {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => $hash,
                'role' => $role,
                'is_active' => true,
                'auth_version' => 1,
                'must_change_password' => true,
            ])->save();
            $this->audits->record('account.created', $user, ['role' => $role->value]);

            return $user;
        }, 3);
    }

    public function changeRole(User $subject, Role $role): User
    {
        return $this->mutate($subject, 'account.role_changed', function (User $user) use ($role): array {
            $metadata = ['from_role' => $user->role->value, 'to_role' => $role->value];
            $user->role = $role;

            return $metadata;
        });
    }

    public function disable(User $subject): User
    {
        return $this->mutate($subject, 'account.disabled', function (User $user): array {
            $user->is_active = false;

            return [];
        });
    }

    public function changePassword(User $subject, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $passwordConfirmation): User
    {
        Validator::make([
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], ['password' => self::passwordRules()])->validate();
        $hash = Hash::make($password);

        return $this->mutate($subject, 'account.password_changed', function (User $user) use ($hash): array {
            $user->password = $hash;
            $user->must_change_password = true;
            $user->onboarding_completed_at = null;

            return [];
        });
    }

    public function resetMfa(User $subject): User
    {
        return $this->mutate($subject, 'account.mfa_reset', function (User $user): array {
            $user->app_authentication_secret = null;
            $user->app_authentication_recovery_codes = null;
            $user->onboarding_completed_at = null;

            return [];
        });
    }

    private function mutate(User $subject, string $action, Closure $change): User
    {
        return DB::transaction(function () use ($subject, $action, $change): User {
            $user = User::query()->findOrFail($subject->getKey());
            $metadata = $change($user);
            app(AccountSessions::class)->revoke($user);
            $this->audits->record($action, $user, $metadata);

            return $user;
        }, 3);
    }
}
