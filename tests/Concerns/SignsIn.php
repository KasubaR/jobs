<?php

namespace Tests\Concerns;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

trait SignsIn
{
    protected string $totpSecret = 'JBSWY3DPEHPK3PXP';

    /** The administrator, fully signed in (password and authenticator code). */
    protected function signIn(): User
    {
        config(['job_intel.admin_email' => 'me@example.com']);

        $user = User::factory()->create([
            'email' => 'me@example.com',
            'app_authentication_secret' => $this->totpSecret,
        ]);

        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    protected function currentCode(?string $secret = null): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret ?? $this->totpSecret);
    }
}
