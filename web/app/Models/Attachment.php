<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function observation()
    {
        return $this->belongsTo(Observation::class);
    }

    public function incidentLog()
    {
        return $this->belongsTo(IncidentLog::class);
    }
}
