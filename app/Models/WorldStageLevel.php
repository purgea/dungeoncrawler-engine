<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldStageLevel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array'
    ];

    public function stage()
    {
        return $this->belongsTo(WorldStage::class, 'world_stage_id');
    }
}
