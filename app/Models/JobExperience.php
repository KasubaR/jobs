<?php

namespace App\Models;

use App\Models\Concerns\IsExtractedItem;
use Illuminate\Database\Eloquent\Model;

/** One item the ad asks for, with how strongly (see IsExtractedItem). */
class JobExperience extends Model
{
    use IsExtractedItem;

    protected $table = 'job_experiences';
}
