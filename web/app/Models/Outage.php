<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'restored_at' => 'immutable_datetime'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
