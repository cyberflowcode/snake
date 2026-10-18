<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server Authoritative Snake Game Configuration
    |--------------------------------------------------------------------------
    */

    'tick_rate' => env('GAME_TICK_RATE', 10),

    'width' => env('GAME_ARENA_WIDTH', 2400),
    'height' => env('GAME_ARENA_HEIGHT', 2400),

    'max_players' => env('GAME_MAX_PLAYERS', 8),

    'food_count' => env('GAME_FOOD_COUNT', 120),

    'room_expire_minutes' => env('GAME_ROOM_EXPIRE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Random Food Values & Snake Growth Configuration
    |--------------------------------------------------------------------------
    | Food items spawn with random authoritative point values (100, 200, 500).
    | 'score_per_growth' determines the ratio of points to body length growth.
    | Ratio 100:
    |   100-pt food => +1 segment length
    |   200-pt food => +2 segment length
    |   500-pt food => +5 segment length
    */
    'food_values' => [100, 200, 500],

    'score_per_growth' => env('GAME_SCORE_PER_GROWTH', 100),

    /*
    |--------------------------------------------------------------------------
    | Food Respawn Delay & Spawn Protection
    |--------------------------------------------------------------------------
    | 'food_respawn_delay_seconds' defines authoritative delay before replacement
    | food orbs spawn into the arena after being eaten (default 40s).
    | 'spawn_protection_seconds' grants invulnerability to newly spawned snakes (default 2.5s).
    */
    'food_respawn_delay_seconds' => env('GAME_FOOD_RESPAWN_DELAY_SECONDS', 40),

    'spawn_protection_seconds' => env('GAME_SPAWN_PROTECTION_SECONDS', 2.5),
];
