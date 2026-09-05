<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutVite();
    }

    public function test_the_root_route_renders_the_main_menu(): void
    {
        $this->get('/')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page->component('Home')->where('firstLevelUrl', '/game?new=1'));
    }

    public function test_the_game_route_generates_and_passes_a_dungeon_layout(): void
    {
        $this->get('/game/1-1?seed=1234')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Game')
                ->has('dungeon')
                ->where('dungeon.schemaVersion', 1)
                ->where('dungeon.width', 63)
                ->where('dungeon.height', 63)
                ->has('dungeon.grid', 63)
                ->has('dungeon.spawn')
                ->has('dungeon.enemies')
                ->has('dungeon.pickups')
                ->has('dungeon.traps')
                ->has('dungeon.exit')
                ->where('dungeon.requiredSigils', 3)
                ->where('campaign.seed', 1234)
                ->where('campaign.nextLevelUrl', '/game/1-2?seed=1234')
                ->where('campaign.firstLevelUrl', '/game/1-1?seed=1234')
            );
    }

    public function test_campaign_keeps_one_run_seed_and_has_a_final_chapter(): void
    {
        $levelSeeds = [];
        foreach (range(1, 4) as $number) {
            $response = $this->get('/game/1-'.$number.'?seed=7654321')
                ->assertSuccessful()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Game')
                    ->where('campaign.levelNumber', $number)
                    ->where('campaign.totalLevels', 4)
                    ->where('campaign.seed', 7654321)
                    ->where('campaign.nextLevelUrl', $number < 4 ? '/game/1-'.($number + 1).'?seed=7654321' : null)
                );
            $levelSeeds[] = $response->inertiaProps('dungeon.seed');
        }
        $this->assertCount(4, array_unique($levelSeeds));
        $first = $this->get('/game/1-1')->assertSuccessful();
        $this->assertSame(7654321, $first->inertiaProps('campaign.seed'));
        $this->assertSame($levelSeeds[0], $first->inertiaProps('dungeon.seed'));
        $retry = $this->get('/game/1-1?seed=7654321')->assertSuccessful();
        $this->assertSame($first->inertiaProps('dungeon'), $retry->inertiaProps('dungeon'));
    }

    public function test_game_shortcut_preserves_a_run_code_and_unknown_levels_return_404(): void
    {
        $this->get('/game?new=1')->assertRedirect('/game/1-1?new=1');
        $this->get('/game?seed=2468')->assertRedirect('/game/1-1?seed=2468');
        $this->get('/game/unknown')->assertNotFound();
    }
}
