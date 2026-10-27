<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketRequest extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processing_at' => 'immutable_datetime', 'payload' => 'array'];
    }

    public function observation()
    {
        return $this->belongsTo(Observation::class);
    }
}
