<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAttachmentUploadAccess;
use App\Models\User;
use App\Security\AccountSessions;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use DatabaseMigrations;

    public function test_html_and_error_pages_receive_compatible_security_headers(): void
    {
        foreach (['/login', '/not-a-route'] as $path) {
            $response = $this->get($path);
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
            $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
            $this->assertStringNotContainsString('localhost:5173', $response->headers->get('Content-Security-Policy'));
            $response->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_upload_limit_is_per_authenticated_user_and_blocks_before_processing_files(): void
    {
        $user = User::factory()->create(['role' => 'editor', 'app_authentication_secret' => 'TESTONLYSECRET']);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);
        $key = 'attachment-upload:'.$user->id;
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($key, 60);
        }
        // Test the authenticated middleware directly so signed Livewire transport does not mask the limit.
        $request = Request::create('/livewire/upload-file', 'POST');
        $request->setUserResolver(fn () => $user);
        try {
            app(EnsureAttachmentUploadAccess::class)->handle($request, fn () => $this->fail('Upload should be throttled.'));
            $this->fail('Expected rate limit.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }
        RateLimiter::clear($key);
    }

    public function test_new_passwords_reject_a_local_common_password_list_without_composition_rules(): void
    {
        $entries = file(resource_path('security/common-passwords.txt'), FILE_IGNORE_NEW_LINES);
        $this->assertCount(3000, array_unique($entries));
        $this->assertSame(3000, count(array_filter($entries, fn ($value) => mb_strlen($value) >= 12)));
        foreach (['en' => 'This password is too common.', 'fr' => 'Ce mot de passe est trop courant.'] as $locale => $message) {
            app()->setLocale($locale);
            $common = $entries[0];
            $validator = Validator::make(['password' => $common, 'password_confirmation' => $common], ['password' => AccountService::passwordRules()]);
            $this->assertTrue($validator->fails());
            $this->assertStringContainsString($message, $validator->errors()->first('password'));
        }
        $passphrase = 'une phrase privée et assez longue';
        $this->assertTrue(Validator::make(['password' => $passphrase, 'password_confirmation' => $passphrase], ['password' => AccountService::passwordRules()])->passes());
    }

    public function test_logout_requests_origin_storage_cleanup_without_clearing_other_subdomain_cookies(): void
    {
        $user = User::factory()->create(['app_authentication_secret' => 'TESTONLYSECRET']);
        $this->actingAs($user);
        app(AccountSessions::class)->establish($user, true);
        $this->get('/')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/logout')->assertRedirect('/login')->assertHeader('Clear-Site-Data', '"cache", "storage"');
        $this->assertGuest();
    }

    public function test_untrusted_forwarded_headers_do_not_enable_hsts_or_https(): void
    {
        config(['app.hsts_enabled' => true]);
        $this->withHeader('X-Forwarded-Proto', 'https')->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
