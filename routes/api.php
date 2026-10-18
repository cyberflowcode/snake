<?php

use App\Http\Controllers\GameRoomController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check endpoint for Railway and container orchestration
Route::get('/health', function () {
    $dbOk = true;
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $dbOk = false;
    }

    return response()->json([
        'status'    => $dbOk ? 'ok' : 'degraded',
        'database'  => $dbOk ? 'connected' : 'disconnected',
        'timestamp' => now()->toIso8601String(),
    ], $dbOk ? 200 : 503);
});

// Broadcasting channel authorization for guest players
Route::post('/broadcasting/auth', [GameRoomController::class, 'authChannel']);

// Room management & gameplay routes
Route::prefix('rooms')->group(function () {
    Route::post('/',              [GameRoomController::class, 'create']);
    Route::post('/global/join',   [GameRoomController::class, 'joinGlobal']);  // Public matchmaking
    Route::post('/{code}/join',   [GameRoomController::class, 'join']);
    Route::get('/{code}',         [GameRoomController::class, 'show']);
    Route::post('/{code}/reconnect', [GameRoomController::class, 'reconnect']);
    Route::post('/{code}/start',     [GameRoomController::class, 'start']);
    Route::post('/{code}/direction', [GameRoomController::class, 'direction']);
    Route::get('/{code}/snapshot',   [GameRoomController::class, 'snapshot']);
    Route::post('/{code}/rematch',   [GameRoomController::class, 'rematch']);
    Route::post('/{code}/leave',     [GameRoomController::class, 'leave']);
});

// Global leaderboard
Route::get('/leaderboard', [GameRoomController::class, 'leaderboard']);
