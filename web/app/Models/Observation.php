<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Observation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'checked_at' => 'immutable_datetime'];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function hasStatusCheck(): bool
    {
        return $this->status !== null && ($this->checked_at !== null || $this->import_source !== null);
    }

    public function displayStatus(): string
    {
        return $this->hasStatusCheck() ? $this->status : ($this->check_result ?? 'unchecked');
    }

    public function displayLabel(): string
    {
        $status = $this->displayStatus();

        return $status === 'unchecked' ? 'Not checked' : str_replace('_', ' ', $status);
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function ticket()
    {
        return $this->hasOne(TicketRequest::class);
    }
}
