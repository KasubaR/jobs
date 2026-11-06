<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.admin_email' => 'me@example.com']);
        RateLimiter::clear('x');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['email' => 'me@example.com', 'password' => 'correct-horse-battery']);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (['/', '/sources', '/runs', '/sources/create'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_login_page_is_noindex_and_robots_blocks_everything(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_there_is_no_registration_or_password_reset(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/password-reset/request')->assertNotFound();
    }

    public function test_wrong_password_and_wrong_email_give_the_same_message(): void
    {
        $this->user();

        $a = $this->from('/login')->post('/login', ['email' => 'me@example.com', 'password' => 'nope']);
        $b = $this->from('/login')->post('/login', ['email' => 'someone@else.com', 'password' => 'nope']);

        $a->assertRedirect('/login')->assertSessionHasErrors('email');
        $b->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), 'These details do not match.');
        $this->assertGuest();
    }

    public function test_only_the_admin_address_can_sign_in_even_with_a_correct_password(): void
    {
        User::factory()->create(['email' => 'other@example.com', 'password' => 'correct-horse-battery']);

        $this->post('/login', ['email' => 'other@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_nobody_can_sign_in_when_admin_email_is_unset(): void
    {
        config(['job_intel.admin_email' => null]);
        $this->user();

        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_correct_password_goes_to_setup_when_no_second_factor_exists_yet(): void
    {
        $this->user();

        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->get('/')->assertRedirect('/two-factor/setup');
    }

    public function test_a_password_alone_never_opens_the_app(): void
    {
        $this->user(['app_authentication_secret' => $this->totpSecret]);

        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery']);

        foreach (['/', '/sources', '/runs'] as $path) {
            $this->get($path)->assertRedirect('/two-factor');
        }
    }

    public function test_a_valid_authenticator_code_opens_the_app(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);

        $this->actingAs($user)->post('/two-factor', ['code' => $this->currentCode()])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);

        $this->actingAs($user)->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->get('/')->assertRedirect('/two-factor');
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);
        $code = $this->currentCode();

        $this->assertTrue(app(TwoFactor::class)->verify($user, $this->totpSecret, $code));
        $this->assertFalse(app(TwoFactor::class)->verify($user->fresh(), $this->totpSecret, $code));
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);
        $codes = app(TwoFactor::class)->issueRecoveryCodes($user);

        $this->actingAs($user)->post('/two-factor', ['code' => $codes[0]])->assertRedirect('/');
        $this->assertNotContains(hash('sha256', $codes[0]), $user->fresh()->app_authentication_recovery_codes);
        $this->assertStringNotContainsString($codes[0], json_encode($user->fresh()->getRawOriginal()));

        $this->flushSession();
        $this->actingAs($user->fresh())->post('/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
    }

    public function test_setup_needs_a_working_code_and_then_shows_recovery_codes_once(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $page = $this->get('/two-factor/setup')->assertOk();
        $page->assertSee('<svg', false);
        $secret = session('two_factor_pending_secret');
        $this->assertNotEmpty($secret);

        $this->post('/two-factor/setup', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->app_authentication_secret);

        $this->post('/two-factor/setup', ['code' => $this->currentCode($secret)])->assertRedirect('/two-factor/recovery-codes');
        $this->assertSame($secret, $user->fresh()->app_authentication_secret);

        $this->get('/two-factor/recovery-codes')->assertOk()->assertSee('I have saved them');
        $this->get('/two-factor/recovery-codes')->assertRedirect('/'); // gone after the first view
        $this->get('/')->assertOk();
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);

        $this->assertNotSame($this->totpSecret, $user->fresh()->getRawOriginal('app_authentication_secret'));
        $this->assertSame($this->totpSecret, $user->fresh()->app_authentication_secret);
    }

    public function test_setup_cannot_be_redone_once_two_factor_is_on(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);

        $this->actingAs($user)->get('/two-factor/setup')->assertRedirect('/two-factor');
    }

    public function test_another_signed_in_user_gets_403_everywhere(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']));

        foreach (['/', '/sources', '/two-factor', '/two-factor/setup'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_login_is_throttled_after_five_tries(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'me@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'me@example.com', 'password' => 'correct-horse-battery'])->assertStatus(429);
    }

    public function test_the_code_form_is_throttled_after_five_tries(): void
    {
        $user = $this->user(['app_authentication_secret' => $this->totpSecret]);
        $this->actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor', ['code' => '000000']);
        }

        $this->post('/two-factor', ['code' => $this->currentCode()])->assertStatus(429);
    }

    public function test_signing_out_ends_the_session_and_asks_for_the_code_again_next_time(): void
    {
        $this->signIn();
        $this->get('/')->assertOk();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_pages_carry_the_noindex_header_once_signed_in(): void
    {
        $this->signIn();

        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
