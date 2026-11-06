<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetTwoFactorCommand extends Command
{
    protected $signature = 'admin:reset-two-factor';

    protected $description = 'Remove the administrator\'s authenticator and recovery codes (for a lost phone). Setup is asked again at the next sign-in.';

    public function handle(): int
    {
        $email = config('job_intel.admin_email');
        $user = filled($email) ? User::where('email', $email)->first() : null;

        if (! $user) {
            $this->error('No administrator account found. Create one with admin:create.');

            return self::FAILURE;
        }

        if (! $this->confirm("Remove two-factor sign-in for {$user->email}? Anyone with the password could then sign in until it is set up again.")) {
            return self::SUCCESS;
        }

        $user->forceFill([
            'app_authentication_secret' => null,
            'app_authentication_recovery_codes' => null,
            'app_authentication_last_step' => null,
        ])->save();

        $this->info('Two-factor removed. Sign in with your password and set it up again straight away.');

        return self::SUCCESS;
    }
}
