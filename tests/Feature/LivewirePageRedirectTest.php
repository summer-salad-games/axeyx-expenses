<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\AccountSessions;
use App\Services\AccountService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LivewirePageRedirectTest extends TestCase
{
    use DatabaseMigrations;

    public function test_redirected_get_profile_with_livewire_header_renders_password_onboarding(): void
    {
        $this->authenticate(true);
        $this->withHeader('X-Livewire', 'true')->get('/setup/password')->assertOk()->assertSee('Choisir votre mot de passe');
    }

    public function test_redirected_get_dashboard_with_livewire_header_renders_normal_navigation(): void
    {
        $this->authenticate(false);
        $this->withHeader('X-Livewire', 'true')->get('/')->assertOk()->assertSee('Tableau de bord');
    }

    public function test_redirected_head_request_renders_normally(): void
    {
        $this->authenticate(false);
        $this->withHeader('X-Livewire', 'true')->head('/profile')->assertOk();
    }

    public function test_redirected_page_headers_do_not_bypass_mfa_or_revoked_sessions(): void
    {
        $user = $this->authenticate(true);
        app(AccountSessions::class)->establish($user, false);
        $this->withHeader('X-Livewire', 'true')->get('/profile')->assertForbidden();
        app(AccountSessions::class)->establish($user, true);
        app(AccountService::class)->disable($user);
        $this->withHeader('X-Livewire', 'true')->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_post_updates_still_require_transport_headers_and_csrf(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
        $payload = ['components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5),
            'updates' => [], 'calls' => [],
        ]]];
        // Exercise the real CSRF middleware against the already-isolated test paths.
        $this->app->detectEnvironment(fn (): string => 'local');
        $endpoint = route('default-livewire.update');
        $token = str_repeat('t', 40);
        $this->withSession(['_token' => $token])->postJson($endpoint, $payload, [
            'X-Livewire' => 'true', 'X-CSRF-TOKEN' => 'invalid',
        ])->assertStatus(419);
        $this->postJson($endpoint, $payload, ['X-CSRF-TOKEN' => $token])->assertNotFound();
        $this->postJson($endpoint, $payload, [
            'X-Livewire' => 'true', 'X-CSRF-TOKEN' => $token,
        ])->assertOk()->assertJsonCount(1, 'components');
    }

    public function test_get_normalization_preserves_livewire_navigate_header(): void
    {
        Route::get('/test-redirect-header', fn (Request $request) => [
            'livewire' => $request->hasHeader('X-Livewire'),
            'navigate' => $request->header('X-Livewire-Navigate'),
        ]);
        $this->withHeaders(['X-Livewire' => 'true', 'X-Livewire-Navigate' => 'true'])
            ->get('/test-redirect-header')->assertOk()->assertExactJson(['livewire' => false, 'navigate' => 'true']);
    }

    public function test_local_method_error_logs_exclude_query_and_request_data(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        Log::spy();
        $this->get('/logout?password=never-log-this')->assertStatus(405);
        Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $context): bool {
            return $event === 'http.method_not_allowed'
                && $context['method'] === 'GET' && $context['path'] === '/logout'
                && $context['allowed_methods'] === 'POST'
                && ! str_contains(json_encode($context), 'never-log-this');
        })->once();
    }

    public function test_method_error_diagnostics_are_local_only(): void
    {
        Log::spy();
        $this->get('/logout')->assertStatus(405);
        Log::shouldNotHaveReceived('warning');
    }

    private function authenticate(bool $mustChangePassword): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $user = User::factory()->create([
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'must_change_password' => $mustChangePassword,
        ]);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);

        return $user;
    }
}
