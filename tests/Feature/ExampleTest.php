<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_route_renders_the_loading_page(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->component('Loading'));
    }

    public function test_the_game_route_generates_and_passes_a_dungeon_layout(): void
    {
        $this->get('/game')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Game')
                ->has('dungeon')
                ->where('dungeon.schemaVersion', 1)
                ->where('dungeon.width', 63)
                ->where('dungeon.height', 63)
                ->has('dungeon.grid', 63)
                ->has('dungeon.spawn')
                );
    }
}
