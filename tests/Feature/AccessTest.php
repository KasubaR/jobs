<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_is_noindex_and_robots_blocks_everything(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_there_is_no_registration_or_password_reset(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/password-reset/request')->assertNotFound();
    }

    public function test_only_the_admin_email_can_enter_the_panel(): void
    {
        config(['job_intel.admin_email' => 'me@example.com']);
        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertTrue(User::factory()->make(['email' => 'ME@example.com'])->canAccessPanel($panel));
        $this->assertFalse(User::factory()->make(['email' => 'other@example.com'])->canAccessPanel($panel));
    }

    public function test_panel_is_closed_to_everyone_when_admin_email_is_unset(): void
    {
        config(['job_intel.admin_email' => null]);
        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertFalse(User::factory()->make(['email' => 'me@example.com'])->canAccessPanel($panel));
    }

    public function test_non_admin_user_gets_forbidden_even_when_logged_in(): void
    {
        config(['job_intel.admin_email' => 'me@example.com']);

        $this->actingAs(User::factory()->create(['email' => 'intruder@example.com']))
            ->get('/')
            ->assertForbidden();
    }
}
