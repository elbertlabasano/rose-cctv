<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolution' => 'array', 'due_at' => 'immutable_datetime', 'restored_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function logs()
    {
        return $this->hasMany(IncidentLog::class);
    }
}
