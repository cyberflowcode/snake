<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    */

    'default' => env('BROADCAST_DRIVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', 'snake-app-key'),
            'secret' => env('REVERB_APP_SECRET', 'snake-app-secret'),
            'app_id' => env('REVERB_APP_ID', 'snake-app-id'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY', env('REVERB_APP_KEY', 'snake-app-key')),
            'secret' => env('PUSHER_APP_SECRET', env('REVERB_APP_SECRET', 'snake-app-secret')),
            'app_id' => env('PUSHER_APP_ID', env('REVERB_APP_ID', 'snake-app-id')),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host' => env('REVERB_HOST', env('PUSHER_HOST', '127.0.0.1')),
                'port' => env('REVERB_PORT', env('PUSHER_PORT', 8080)),
                'scheme' => env('REVERB_SCHEME', env('PUSHER_SCHEME', 'http')),
                'encrypted' => env('REVERB_SCHEME', 'http') === 'https',
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
