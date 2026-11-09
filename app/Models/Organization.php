<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An awarding body, professional body, university, college, regulator or exam board. */
class Organization extends Model
{
    protected $guarded = [];

    protected $casts = ['aliases' => 'array'];

    public function roles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrganizationRole::class);
    }

    /** @return list<string> every role this organization has */
    public function roleNames(): array
    {
        return $this->roles->pluck('role')->all();
    }
}
