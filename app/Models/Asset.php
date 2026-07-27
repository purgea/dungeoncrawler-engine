<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    protected $guarded = [];

    protected $appends = [
        'path_url',
    ];

    public function getPathUrlAttribute(): string
    {
        return url('/extras/' . ltrim($this->path, '/'));
    }
}
