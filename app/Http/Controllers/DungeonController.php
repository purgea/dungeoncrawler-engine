<?php

namespace App\Http\Controllers;

use App\Models\WorldStageLevel;
use App\Services\Dungeon\DungeonGenerator;
use App\Services\Dungeon\SeededRandom;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DungeonController extends Controller
{
    public function start(Request $request)
    {
        $level = WorldStageLevel::query()->orderBy('world_stage_id')->orderBy('id')->first();

        // The menu is also the first-run bootstrap in a fresh browser checkout.
        // NativePHP normally seeds during app boot, but a browser dev server can
        // reach this route before that hook has run.
        if (! $level) {
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\DatabaseSeeder',
                '--force' => true,
            ]);
            $level = WorldStageLevel::query()->orderBy('world_stage_id')->orderBy('id')->first();
        }

        abort_unless($level, 404, 'No dungeon chapters have been seeded.');

        return redirect()->route('game', ['level' => $level->slug, ...$request->only('seed', 'new')]);
    }

    public function show(Request $request, WorldStageLevel $level, DungeonGenerator $generator)
    {
        $stage = $level->stage;

        $definitions = $stage->gameDefinitions
            ->groupBy('kind')
            ->map(fn ($entries): array => $entries->map(fn ($entry): array => $entry->toPayload())->values()->all())
            ->all();

        $validated = $request->validate(['seed' => ['sometimes', 'integer', 'min:1', 'max:'.SeededRandom::MAX_SEED]]);
        $runSeed = isset($validated['seed'])
            ? (int) $validated['seed']
            : ($request->boolean('new') ? null : $request->session()->get('dungeon.run_seed'));
        $runSeed ??= random_int(1, SeededRandom::MAX_SEED);
        $request->session()->put('dungeon.run_seed', $runSeed);

        $levels = WorldStageLevel::query()
            ->whereHas('stage', fn ($query) => $query->where('world_id', $stage->world_id))
            ->orderBy('world_stage_id')->orderBy('id')->get();
        $index = $levels->search(fn (WorldStageLevel $candidate): bool => $candidate->id === $level->id);
        $nextLevel = $levels->get($index + 1);
        // A shared run code recreates the campaign, with a distinct map per chapter.
        $seed = (int) (hexdec(substr(hash('sha256', $runSeed.':'.$level->slug), 0, 8)) % SeededRandom::MAX_SEED) + 1;
        $levelUrl = fn (WorldStageLevel $candidate): string => route('game', ['level' => $candidate->slug, 'seed' => $runSeed], false);

        return Inertia::render('Game', [
            'dungeon' => $generator->generate(
                $level->data ?? [],
                $seed,
                $stage->lighting ?? [],
                $definitions,
            ),
            'campaign' => [
                'levelId' => $level->id,
                'levelSlug' => $level->slug,
                'levelName' => $level->name,
                'stageSlug' => $stage->slug,
                'stageName' => $stage->name,
                'levelNumber' => $index + 1,
                'totalLevels' => $levels->count(),
                'nextLevelUrl' => $nextLevel ? $levelUrl($nextLevel) : null,
                'firstLevelUrl' => $levelUrl($levels->first()),
                'seed' => $runSeed,
            ],
        ]);
    }
}
