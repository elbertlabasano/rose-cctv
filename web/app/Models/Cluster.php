<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cluster extends Model
{
    protected $guarded = ['id'];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
}
