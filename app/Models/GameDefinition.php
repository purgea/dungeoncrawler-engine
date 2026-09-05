<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public function stage()
    {
        return $this->belongsTo(WorldStage::class, 'world_stage_id');
    }

    public function toPayload(): array
    {
        $payload = [
            'id' => $this->slug,
            ...($this->data ?? []),
        ];

        if (! empty($payload['path']) && empty($payload['path_url'])) {
            $payload['path_url'] = route('assets.show', ['asset' => $payload['path']], false);
        }

        return $payload;
    }
}
