<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldStage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'lighting' => 'array',
    ];

    public function world()
    {
        return $this->belongsTo(World::class);
    }

    public function levels()
    {
        return $this->hasMany(WorldStageLevel::class);
    }
}
