<?php

declare(strict_types=1);

namespace App\Services\Dungeon;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    public function int(int $min, int $max): int { 
        return $this->randomizer->getInt($min, $max); 
    }

    public function pick(array $values): mixed { 
        return $values[$this->int(0, count($values) - 1)]; 
    }

    public function shuffle(array $values): array { 
        return $this->randomizer->shuffleArray($values); 
    }
}
