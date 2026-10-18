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
];
