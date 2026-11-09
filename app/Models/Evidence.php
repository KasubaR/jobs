<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A source for one claim about a directory row, with when it was retrieved and verified. */
class Evidence extends Model
{
    protected $table = 'evidence';

    protected $guarded = [];

    protected $casts = ['retrieved_on' => 'date', 'verified_at' => 'datetime'];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
