<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {--name= : Display name}';

    protected $description = 'Create (or reset the password of) the single administrator account';

    public function handle(): int
    {
        $email = config('job_intel.admin_email');

        if (blank($email)) {
            $this->error('Set ADMIN_EMAIL in .env first. Only that address can ever sign in.');

            return self::FAILURE;
        }

        $password = $this->secret('Choose a password (at least 12 characters)');

        if (strlen((string) $password) < 12) {
            $this->error('The password must be at least 12 characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Type it again')) {
            $this->error('The two passwords do not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $user->name = $this->option('name') ?: ($user->name ?: 'Administrator');
        $user->password = $password; // hashed by the model cast
        $user->save();

        $this->info($existed
            ? "Password updated for {$email}. Two-factor sign-in was left as it was."
            : "Administrator {$email} created. On first sign-in you will be asked to set up two-factor sign-in.");

        return self::SUCCESS;
    }
}
