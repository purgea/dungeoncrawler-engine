<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $guarded = [];

    protected $appends = [
        'path_url',
    ];

    public function getPathUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return route('assets.show', ['asset' => $this->path], false);
    }
}
