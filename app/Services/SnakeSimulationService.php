<?php

namespace App\Services;

class SnakeSimulationService
{
    public const DEFAULT_WIDTH = 2400;
    public const DEFAULT_HEIGHT = 2400;
    public const DEFAULT_TICK_RATE = 10;
    public const BASE_SPEED = 9.0;
    public const BOOST_SPEED = 16.0;
    public const MAX_TURN_PER_TICK = 0.55; // ~31.5 degrees per tick to prevent instant 180 reversal while keeping steering responsive
    public const HEAD_RADIUS = 14.0;
    public const BODY_RADIUS = 12.0;
    public const FOOD_RADIUS = 8.0;
    public const SEGMENT_DISTANCE = 9.0;
    public const TARGET_FOOD_COUNT = 120;
    public const MIN_STARTING_LENGTH = 14;

    /**
     * Available vibrant snake colors matching reference
     */
    public const PALETTE = [
        'cyan'   => '#00e5ff',
        'yellow' => '#ffd600',
        'red'    => '#ff1744',
        'purple' => '#d500f9',
        'green'  => '#00e676',
        'blue'   => '#2979ff',
    ];

    /**
     * Initialize fresh game state for a room.
     */
    public function initializeState(int $width, int $height, array $players): array
    {
        $snakes = [];
        $playerCount = count($players);
        $radiusFromCenter = min($width, $height) * 0.28;
        $centerX = $width / 2.0;
        $centerY = $height / 2.0;

        $colorKeys = array_keys(self::PALETTE);
        $idx = 0;

        foreach ($players as $player) {
            $angleAroundCenter = ($playerCount > 1) ? ($idx * (2 * M_PI / $playerCount)) : (M_PI / 4.0);
            $spawnX = $centerX + cos($angleAroundCenter) * $radiusFromCenter;
            $spawnY = $centerY + sin($angleAroundCenter) * $radiusFromCenter;
            $facingAngle = $angleAroundCenter + M_PI; // Face towards arena center

            $color = $player['color'] ?? $colorKeys[$idx % count($colorKeys)];

            // Generate initial straight body trailing behind facingAngle
            $body = [];
            for ($s = 0; $s < self::MIN_STARTING_LENGTH; $s++) {
                $body[] = [
                    'x' => round($spawnX - cos($facingAngle) * ($s * self::SEGMENT_DISTANCE), 1),
                    'y' => round($spawnY - sin($facingAngle) * ($s * self::SEGMENT_DISTANCE), 1),
                ];
            }

            $snakes[$player['id']] = [
                'id'            => $player['id'],
                'nickname'      => $player['nickname'],
                'color'         => $color,
                'x'             => round($spawnX, 1),
                'y'             => round($spawnY, 1),
                'angle'         => round($facingAngle, 3),
                'target_angle'  => round($facingAngle, 3),
                'speed'         => self::BASE_SPEED,
                'is_boosting'   => false,
                'is_alive'      => true,
                'length'        => self::MIN_STARTING_LENGTH,
                'score'         => 0,
                'body'          => $body,
                'rank'          => null,
                'eliminated_at' => null,
            ];
            $idx++;
        }

        $state = [
            'tick'            => 0,
            'status'          => 'countdown',
            'countdown'       => 3,
            'countdown_ticks' => 30,
            'width'           => $width,
            'height'          => $height,
            'snakes'          => $snakes,
            'foods'           => [],
            'eliminations'    => [],
            'winner_id'       => null,
            'finished_at'     => null,
        ];

        // Populate initial food
        $state['foods'] = $this->spawnFoodBatch($state, self::TARGET_FOOD_COUNT);

        return $state;
    }

    /**
     * Spawn a new snake for a player joining an active or waiting arena.
     */
    public function createSnakeForPlayer(int $width, int $height, array $player, array $existingSnakes = []): array
    {
        $colorKeys = array_keys(self::PALETTE);
        $centerX = $width / 2.0;
        $centerY = $height / 2.0;

        $angle = (mt_rand(0, 6283) / 1000.0);
        $radius = min($width, $height) * (0.18 + (mt_rand(0, 15) / 100.0));

        $spawnX = $centerX + cos($angle) * $radius;
        $spawnY = $centerY + sin($angle) * $radius;
        $facingAngle = $this->normalizeAngle($angle + M_PI);

        $color = $player['color'] ?? $colorKeys[array_rand($colorKeys)];

        $body = [];
        for ($s = 0; $s < self::MIN_STARTING_LENGTH; $s++) {
            $body[] = [
                'x' => round($spawnX - cos($facingAngle) * ($s * self::SEGMENT_DISTANCE), 1),
                'y' => round($spawnY - sin($facingAngle) * ($s * self::SEGMENT_DISTANCE), 1),
            ];
        }

        return [
            'id'            => $player['id'],
            'nickname'      => $player['nickname'],
            'color'         => $color,
            'x'             => round($spawnX, 1),
            'y'             => round($spawnY, 1),
            'angle'         => round($facingAngle, 3),
            'target_angle'  => round($facingAngle, 3),
            'speed'         => self::BASE_SPEED,
            'is_boosting'   => false,
            'is_alive'      => true,
            'length'        => self::MIN_STARTING_LENGTH,
            'score'         => 0,
            'body'          => $body,
            'rank'          => null,
            'eliminated_at' => null,
        ];
    }

    /**
     * Advance game state by 1 tick.
     *
     * @param array $state Current game state
     * @param array $inputs Associative array [player_id => ['angle' => float, 'boost' => bool]]
     * @return array Updated game state
     */
    public function tick(array $state, array $inputs = []): array
    {
        // If countdown phase
        if ($state['status'] === 'countdown') {
            $state['countdown_ticks'] = ($state['countdown_ticks'] ?? (($state['countdown'] ?? 3) * 10)) - 1;
            $state['countdown'] = (int)ceil(max(0, $state['countdown_ticks']) / 10);
            if ($state['countdown_ticks'] <= 0) {
                $state['status'] = 'in_progress';
                $state['countdown'] = 0;
            }
            $state['tick']++;
            return $state;
        }

        if ($state['status'] !== 'in_progress') {
            return $state;
        }

        $state['tick']++;
        $state['eliminations'] = [];

        $width = $state['width'];
        $height = $state['height'];
        $snakes = &$state['snakes'];

        // Auto-revive eliminated snakes in global public rooms after 3 seconds (30 ticks)
        if (!empty($state['is_public'])) {
            foreach ($snakes as $id => &$snake) {
                if (!$snake['is_alive'] && empty($snake['left_voluntarily']) && isset($snake['respawn_tick'])) {
                    if ($state['tick'] >= $snake['respawn_tick']) {
                        $freshSnake = $this->createSnakeForPlayer($width, $height, [
                            'id'       => $id,
                            'nickname' => $snake['nickname'],
                            'color'    => $snake['color'],
                        ], $snakes);
                        $snake = $freshSnake;
                    }
                }
            }
            unset($snake);
        }

        // 1. Process inputs and update angles
        foreach ($snakes as $id => &$snake) {
            if (!$snake['is_alive']) {
                continue;
            }

            if (isset($inputs[$id])) {
                $input = $inputs[$id];
                if (isset($input['angle']) && is_numeric($input['angle'])) {
                    $snake['target_angle'] = $this->normalizeAngle((float)$input['angle']);
                }
                if (isset($input['boost'])) {
                    $snake['is_boosting'] = (bool)$input['boost'] && ($snake['length'] > 12);
                }
            }

            // Smooth turning: clamp angular delta to MAX_TURN_PER_TICK (prevents 180° reversal into neck)
            $diff = $this->angleDiff($snake['target_angle'], $snake['angle']);
            if (abs($diff) > self::MAX_TURN_PER_TICK) {
                $diff = ($diff > 0 ? 1 : -1) * self::MAX_TURN_PER_TICK;
            }
            $snake['angle'] = $this->normalizeAngle($snake['angle'] + $diff);

            // Speed & boosting cost
            if ($snake['is_boosting'] && $snake['length'] > 12) {
                $snake['speed'] = self::BOOST_SPEED;
                // Boost penalty: periodically drop food particle
                if ($state['tick'] % 5 === 0) {
                    $snake['length'] = max(10, $snake['length'] - 1);
                    $tail = end($snake['body']);
                    if ($tail) {
                        $state['foods'][] = [
                            'id'    => 'boost_' . $state['tick'] . '_' . $id,
                            'x'     => $tail['x'],
                            'y'     => $tail['y'],
                            'value' => 100,
                            'color' => '#ffffff',
                        ];
                    }
                }
            } else {
                $snake['speed'] = self::BASE_SPEED;
                $snake['is_boosting'] = false;
            }
        }
        unset($snake);

        // 2. Advance snake head and body segments
        // We calculate new head positions and updated bodies
        $newBodies = [];
        $newHeads = [];

        foreach ($snakes as $id => &$snake) {
            if (!$snake['is_alive']) {
                continue;
            }

            $newX = $snake['x'] + cos($snake['angle']) * $snake['speed'];
            $newY = $snake['y'] + sin($snake['angle']) * $snake['speed'];

            $newHeads[$id] = ['x' => $newX, 'y' => $newY];

            // Reconstruct body with segment spacing
            $oldBody = $snake['body'];
            $updatedBody = [['x' => round($newX, 1), 'y' => round($newY, 1)]];

            // Follow the leader for each segment using Inverse Kinematics
            $prev = ['x' => $newX, 'y' => $newY];
            $desiredCount = $snake['length'];

            for ($i = 0; $i < $desiredCount - 1; $i++) {
                $seg = $oldBody[$i] ?? $prev;
                $dx = $seg['x'] - $prev['x'];
                $dy = $seg['y'] - $prev['y'];
                $dist = sqrt($dx * $dx + $dy * $dy);

                if ($dist > 0.001) {
                    $ratio = self::SEGMENT_DISTANCE / $dist;
                    $nx = $prev['x'] + $dx * $ratio;
                    $ny = $prev['y'] + $dy * $ratio;
                } else {
                    $nx = $prev['x'];
                    $ny = $prev['y'];
                }
                $updatedBody[] = ['x' => round($nx, 1), 'y' => round($ny, 1)];
                $prev = ['x' => $nx, 'y' => $ny];
            }

            $newBodies[$id] = $updatedBody;
        }
        unset($snake);

        // 3. Collision Detection (Server Authoritative)
        $eliminatedThisTick = [];

        foreach ($snakes as $id => &$snake) {
            if (!$snake['is_alive']) {
                continue;
            }

            $head = $newHeads[$id];

            // A. Wall collisions
            if (
                $head['x'] < self::HEAD_RADIUS ||
                $head['x'] > ($width - self::HEAD_RADIUS) ||
                $head['y'] < self::HEAD_RADIUS ||
                $head['y'] > ($height - self::HEAD_RADIUS)
            ) {
                $eliminatedThisTick[$id] = 'wall';
                continue;
            }

            // In Slither.io, snakes do not collide with their own body (coiling/turning is permitted)

            // C. Head-on collision and body collision against other snakes
            foreach ($snakes as $otherId => $otherSnake) {
                if ($otherId === $id || !$otherSnake['is_alive']) {
                    continue;
                }

                $otherHead = $newHeads[$otherId];
                $headDistSq = ($head['x'] - $otherHead['x']) ** 2 + ($head['y'] - $otherHead['y']) ** 2;

                // Simultaneous head-on collision
                if ($headDistSq < (self::HEAD_RADIUS * 2.0) ** 2) {
                    // Both heads collided simultaneously
                    $eliminatedThisTick[$id] = 'head_on';
                    $eliminatedThisTick[$otherId] = 'head_on';
                    continue;
                }

                // Check against other snake's body segments
                // Using newBodies ensures tails that moved away are correctly handled
                $otherBody = $newBodies[$otherId];
                for ($m = 1; $m < count($otherBody); $m++) {
                    $seg = $otherBody[$m];
                    $segDistSq = ($head['x'] - $seg['x']) ** 2 + ($head['y'] - $seg['y']) ** 2;
                    if ($segDistSq < (self::HEAD_RADIUS + self::BODY_RADIUS) ** 2) {
                        $eliminatedThisTick[$id] = 'body';
                        break;
                    }
                }

                if (isset($eliminatedThisTick[$id])) {
                    break;
                }
            }
        }
        unset($snake);

        // 4. Apply movements for surviving snakes
        foreach ($snakes as $id => &$snake) {
            if (!$snake['is_alive']) {
                continue;
            }

            if (!isset($eliminatedThisTick[$id])) {
                $snake['x'] = round($newHeads[$id]['x'], 1);
                $snake['y'] = round($newHeads[$id]['y'], 1);
                $snake['body'] = $newBodies[$id];
            }
        }
        unset($snake);

        // 5. Handle eliminations & drop food
        $aliveCountBefore = 0;
        foreach ($snakes as $s) {
            if ($s['is_alive']) {
                $aliveCountBefore++;
            }
        }

        foreach ($eliminatedThisTick as $id => $reason) {
            $snake = &$snakes[$id];
            $snake['is_alive'] = false;
            $snake['eliminated_at'] = $state['tick'];
            $snake['rank'] = $aliveCountBefore;
            if (!empty($state['is_public'])) {
                // Auto-respawn after 3 seconds (30 ticks at 10 ticks/s)
                $snake['respawn_tick'] = $state['tick'] + 30;
            }
            $state['eliminations'][] = [
                'player_id' => $id,
                'nickname'  => $snake['nickname'],
                'reason'    => $reason,
                'score'     => $snake['score'],
                'length'    => $snake['length'],
            ];

            // Drop body segments as collectible glowing food orbs along the path!
            $dropInterval = max(1, (int)ceil(count($snake['body']) / 18));
            foreach ($snake['body'] as $k => $seg) {
                if ($k % $dropInterval === 0) {
                    $dropVal = $this->getRandomFoodValue();
                    $state['foods'][] = [
                        'id'    => 'drop_' . $state['tick'] . '_' . $id . '_' . $k,
                        'x'     => $seg['x'],
                        'y'     => $seg['y'],
                        'value' => $dropVal,
                        'color' => self::PALETTE[$snake['color']] ?? '#ffeb3b',
                    ];
                }
            }
            $aliveCountBefore--;
        }
        unset($snake);

        // 6. Food collection by surviving snakes
        $remainingFoods = [];
        $eatenFoodIds = [];

        foreach ($state['foods'] as $food) {
            $eaten = false;
            foreach ($snakes as $id => &$snake) {
                if (!$snake['is_alive']) {
                    continue;
                }

                $dx = $snake['x'] - $food['x'];
                $dy = $snake['y'] - $food['y'];
                $distSq = $dx * $dx + $dy * $dy;

                if ($distSq < (self::HEAD_RADIUS + self::FOOD_RADIUS + 4.0) ** 2) {
                    $foodVal = (int)($food['value'] ?? 100);
                    $snake['score'] += $foodVal;
                    $snake['length'] += $this->getGrowthForScore($foodVal);
                    $eaten = true;
                    $eatenFoodIds[] = $food['id'];
                    break;
                }
            }
            unset($snake);

            if (!$eaten) {
                $remainingFoods[] = $food;
            }
        }

        // 7. Replenish food back to target count (safe placement not overlapping snakes)
        $neededFood = self::TARGET_FOOD_COUNT - count($remainingFoods);
        if ($neededFood > 0) {
            $newBatch = $this->spawnFoodBatch($state, $neededFood);
            $remainingFoods = array_merge($remainingFoods, $newBatch);
        }
        $state['foods'] = $remainingFoods;

        // 8. Check victory conditions
        // In global public rooms (continuous MMO arena), the game never finishes when a player crashes.
        // Surviving players keep playing, and new/respawned players drop right in!
        if (!empty($state['is_public'])) {
            $activeSnakes = array_filter($snakes, fn($s) => empty($s['left_voluntarily']));
            if (count($activeSnakes) === 0) {
                $state['status'] = 'finished';
                $state['finished_at'] = now()->toIso8601String();
                $state['winner_id'] = null;
            }
            return $state;
        }

        // Only count snakes that are still competing (not voluntarily left)
        $activeSnakes = array_filter($snakes, fn($s) => empty($s['left_voluntarily']));
        $alivePlayers = [];
        foreach ($activeSnakes as $id => $snake) {
            if ($snake['is_alive']) {
                $alivePlayers[] = $id;
            }
        }

        $totalActive = count($activeSnakes);
        if ($totalActive > 1) {
            // Multiplayer: finish when 1 or 0 active players remain alive
            if (count($alivePlayers) <= 1) {
                $state['status'] = 'finished';
                $state['finished_at'] = now()->toIso8601String();
                if (count($alivePlayers) === 1) {
                    $winnerId = $alivePlayers[0];
                    $state['winner_id'] = $winnerId;
                    $snakes[$winnerId]['rank'] = 1;
                } else {
                    $state['winner_id'] = null;
                }
            }
        } elseif ($totalActive === 1) {
            // Last active player — let them play out their snake freely (no forced end)
            if (count($alivePlayers) === 0) {
                $state['status'] = 'finished';
                $state['finished_at'] = now()->toIso8601String();
                $state['winner_id'] = null;
            }
        } elseif ($totalActive === 0) {
            // Everyone left voluntarily — end quietly with no winner
            $state['status'] = 'finished';
            $state['finished_at'] = now()->toIso8601String();
            $state['winner_id'] = null;
        }

        return $state;
    }

    /**
     * Spawn food particles safely away from any active snakes.
     */
    public function spawnFoodBatch(array $state, int $count): array
    {
        $foods = [];
        $width = $state['width'] ?? self::DEFAULT_WIDTH;
        $height = $state['height'] ?? self::DEFAULT_HEIGHT;
        $margin = 60;
        $paletteColors = ['#00e5ff', '#ffd600', '#ff1744', '#d500f9', '#00e676', '#ff9100', '#e040fb'];

        for ($i = 0; $i < $count; $i++) {
            $attempts = 0;
            $safeX = 0;
            $safeY = 0;

            do {
                $safeX = round(rand($margin, $width - $margin), 1);
                $safeY = round(rand($margin, $height - $margin), 1);
                $isSafe = true;

                // Ensure distance from active snakes
                if (!empty($state['snakes'])) {
                    foreach ($state['snakes'] as $snake) {
                        if (!$snake['is_alive']) {
                            continue;
                        }
                        $distSq = ($safeX - $snake['x']) ** 2 + ($safeY - $snake['y']) ** 2;
                        if ($distSq < 70 ** 2) {
                            $isSafe = false;
                            break;
                        }
                    }
                }
                $attempts++;
            } while (!$isSafe && $attempts < 15);

            $foodVal = $this->getRandomFoodValue();
            $foods[] = [
                'id'    => 'food_' . ($state['tick'] ?? 0) . '_' . uniqid(),
                'x'     => $safeX,
                'y'     => $safeY,
                'value' => $foodVal,
                'color' => $paletteColors[array_rand($paletteColors)],
            ];
        }

        return $foods;
    }

    /**
     * Normalize angle between -PI and PI
     */
    public function normalizeAngle(float $angle): float
    {
        while ($angle > M_PI) {
            $angle -= 2 * M_PI;
        }
        while ($angle < -M_PI) {
            $angle += 2 * M_PI;
        }
        return $angle;
    }

    /**
     * Angular difference from current to target angle (-PI to PI)
     */
    public function angleDiff(float $target, float $current): float
    {
        $diff = $target - $current;
        return $this->normalizeAngle($diff);
    }

    /**
     * Get a random authoritative food score value (100, 200, or 500).
     */
    public function getRandomFoodValue(): int
    {
        $values = config('game.food_values', [100, 200, 500]);
        if (!is_array($values) || empty($values)) {
            $values = [100, 200, 500];
        }
        return (int)$values[array_rand($values)];
    }

    /**
     * Calculate body growth segments for a given food point value.
     * Configurable via config('game.score_per_growth', 100).
     */
    public function getGrowthForScore(int $value): int
    {
        $ratio = (int)config('game.score_per_growth', 100);
        if ($ratio <= 0) {
            $ratio = 100;
        }
        return max(1, (int)round($value / $ratio));
    }
}
