<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retain_raw' => 'boolean',
            'last_collection_at' => 'datetime',
            'access_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A source may only be collected from once access has been approved.
        static::saving(function (Source $source) {
            if ($source->is_active && $source->access_status !== 'approved') {
                throw new \DomainException(
                    "Source '{$source->name}' cannot be activated while access_status is '{$source->access_status}'."
                );
            }
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }
}
