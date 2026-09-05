<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('world_stage_id')->constrained('world_stages')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('slug', 64);
            $table->json('data');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['world_stage_id', 'kind', 'slug']);
            $table->index(['world_stage_id', 'kind', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_definitions');
    }
};
