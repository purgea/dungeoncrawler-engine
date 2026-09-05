<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\ExtraAssets;

class World extends Model
{
    protected $table = 'worlds';

    protected $guarded = [];

    protected $appends = ['image_url', 'background_url', 'music_url'];

    public function stages()
    {
        return $this->hasMany(WorldStage::class);
    }

    public function getImageUrlAttribute()
    {
        if (! $this->image) {
            return null;
        }

        $path = ExtraAssets::disk()->path($this->image);

        return str_replace('\\', '/', 'file:///' . $path);
    }

    public function getBackgroundUrlAttribute()
    {
        if (! $this->background) {
            return null;
        }

        $path = ExtraAssets::disk()->path($this->background);

        return str_replace('\\', '/', 'file:///' . $path);
    }

    public function getMusicUrlAttribute()
    {
        if (! $this->music) {
            return null;
        }

        $path = ExtraAssets::disk()->path($this->music);

        return str_replace('\\', '/', 'file:///' . $path);
    }
}
