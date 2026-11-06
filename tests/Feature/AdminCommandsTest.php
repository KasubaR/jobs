<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['job_intel.admin_email' => 'me@example.com']);
    }

    public function test_creates_the_administrator_with_a_hashed_password_and_no_second_factor_yet(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Choose a password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Type it again', 'a-long-enough-password')
            ->assertSuccessful();

        $user = User::where('email', 'me@example.com')->first();
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
        $this->assertNotSame('a-long-enough-password', $user->password);
        $this->assertFalse($user->hasTwoFactor());
        $this->assertTrue($user->isAdmin());
    }

    public function test_refuses_short_or_mismatched_passwords_and_a_missing_admin_email(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Choose a password (at least 12 characters)', 'short')
            ->assertFailed();

        $this->artisan('admin:create')
            ->expectsQuestion('Choose a password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Type it again', 'something-different-1')
            ->assertFailed();

        config(['job_intel.admin_email' => null]);
        $this->artisan('admin:create')->expectsOutputToContain('Set ADMIN_EMAIL')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_running_it_again_changes_the_password_but_keeps_two_factor(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'old-password-123', 'app_authentication_secret' => 'JBSWY3DPEHPK3PXP']);

        $this->artisan('admin:create')
            ->expectsQuestion('Choose a password (at least 12 characters)', 'brand-new-password-1')
            ->expectsQuestion('Type it again', 'brand-new-password-1')
            ->assertSuccessful();

        $user = User::where('email', 'me@example.com')->first();
        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('brand-new-password-1', $user->password));
        $this->assertTrue($user->hasTwoFactor());
    }

    public function test_reset_two_factor_asks_first_and_then_clears_everything(): void
    {
        $user = User::factory()->create([
            'email' => 'me@example.com',
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => ['abc'],
            'app_authentication_last_step' => 123,
        ]);

        $this->artisan('admin:reset-two-factor')->expectsConfirmation(
            'Remove two-factor sign-in for me@example.com? Anyone with the password could then sign in until it is set up again.', 'no'
        )->assertSuccessful();
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->artisan('admin:reset-two-factor')->expectsConfirmation(
            'Remove two-factor sign-in for me@example.com? Anyone with the password could then sign in until it is set up again.', 'yes'
        )->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull($fresh->app_authentication_recovery_codes);
        $this->assertNull($fresh->app_authentication_last_step);
    }

    public function test_reset_fails_cleanly_when_there_is_no_administrator(): void
    {
        $this->artisan('admin:reset-two-factor')->expectsOutputToContain('No administrator account')->assertFailed();
    }
}
