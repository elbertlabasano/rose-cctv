<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Followup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snoozed_until' => 'date:Y-m-d'];
    }
}
