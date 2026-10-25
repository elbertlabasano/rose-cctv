<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status_observed_at' => 'immutable_datetime', 'installed_at' => 'date:Y-m-d', 'warranty_at' => 'date:Y-m-d', 'storage_tb' => 'float', 'channel' => 'integer', 'channels' => 'integer'];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function observations()
    {
        return $this->hasMany(Observation::class);
    }
}
