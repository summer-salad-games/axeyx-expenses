<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Security\AccountSessions;
use App\Security\AuthenticationFailures;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class Login extends BaseLogin
{
    #[Locked]
    public ?int $challengeVersion = null;

    #[Exposed]
    public function authenticate(): ?LoginResponse
    {
        $ipKey = 'auth.login.ip:'.hash('sha256', (string) request()->ip());
        if (RateLimiter::tooManyAttempts($ipKey, 60)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(self::class, 'authenticate', request()->ip(), RateLimiter::availableIn($ipKey)))?->send();

            return null;
        }
        RateLimiter::hit($ipKey, 60);

        if ($this->challengeVersion !== null) {
            $user = $this->getUserUndertakingMultiFactorAuthentication();
            if (! $user instanceof User || $user->auth_version !== $this->challengeVersion || ! $user->is_active) {
                $this->userUndertakingMultiFactorAuthentication = null;
                $this->challengeVersion = null;
                $this->throwFailureValidationException();
            }
        }

        $expectedChallengeVersion = $this->challengeVersion;
        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            foreach (array_keys($exception->errors()) as $field) {
                if (str_starts_with($field, 'data.multiFactor.')) {
                    app(AuthenticationFailures::class)->record('mfa', $this->getUserUndertakingMultiFactorAuthentication());
                    break;
                }
            }
            throw $exception;
        }
        if ($response) {
            $user = Filament::auth()->user()->fresh();
            if (! $user || ! $user->is_active || ($expectedChallengeVersion !== null && $user->auth_version !== $expectedChallengeVersion)) {
                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();
                $this->throwFailureValidationException();
            }
            Filament::auth()->setUser($user);
            app(AccountSessions::class)->establish($user, filled($user->getAppAuthenticationSecret()));
            $this->data = [];
            $this->userUndertakingMultiFactorAuthentication = null;
            $this->challengeVersion = null;
        } elseif ($user = $this->getUserUndertakingMultiFactorAuthentication()) {
            $this->challengeVersion = $user->auth_version;
        }

        return $response;
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->components([$this->getEmailFormComponent(), $this->getPasswordFormComponent()]);
    }

    protected function getRateLimitKey($method, $component = null)
    {
        $email = $this->data['email'] ?? '';

        return 'auth.login:'.hash('sha256', (is_string($email) ? mb_strtolower(trim($email)) : '').'|'.request()->ip());
    }

    protected function getCredentialsFromFormData(#[\SensitiveParameter] array $data): array
    {
        return ['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'is_active' => true];
    }
}
