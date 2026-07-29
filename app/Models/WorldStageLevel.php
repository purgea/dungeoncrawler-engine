<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorldStageLevel extends Model
{
    protected $guarded = [];

    public function stage()
    {
        return $this->belongsTo(WorldStage::class);
    }
}
