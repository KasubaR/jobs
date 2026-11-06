<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Single-operator app: only the one address in ADMIN_EMAIL may enter.
     * Fails closed when ADMIN_EMAIL is not set.
     */
    public function isAdmin(): bool
    {
        $admin = config('job_intel.admin_email');

        return filled($admin) && hash_equals(strtolower($admin), strtolower((string) $this->email));
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
