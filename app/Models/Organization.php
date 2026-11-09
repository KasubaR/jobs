<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An awarding body, professional body, university, college, regulator or exam board. */
class Organization extends Model
{
    protected $guarded = [];

    protected $casts = ['aliases' => 'array'];
}
