<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Room;
use App\Services\GameRoomService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SnakeGameLoopCommand extends Command
{
    protected $signature = 'snake:game-loop {--fps=10} {--room=} {--standalone-ws=}';
    protected $description = 'Run the server-authoritative Snake game loop engine';

    protected GameRoomService $roomService;
    protected bool $running = true;

    public function __construct(GameRoomService $roomService)
    {
        parent::__construct();
        $this->roomService = $roomService;
    }

    public function handle(): int
    {
        $fps = (int)$this->option('fps') ?: (int)config('game.tick_rate', 10);
        $tickIntervalUs = (int)(1000000 / max(1, min(30, $fps)));
        $specificRoom = $this->option('room');

        $this->info("🐍 Snake Authoritative Game Loop started at {$fps} ticks/sec.");
        if ($specificRoom) {
            $this->info("Targeting single room: {$specificRoom}");
        }

        // Acquire atomic lock to prevent duplicate worker loops
        $lockKey = 'snake_engine_worker_lock_' . ($specificRoom ?: 'global');
        $lock = Cache::lock($lockKey, 15);

        if (!$lock->get()) {
            $this->warn("Another game loop instance is already running for [{$lockKey}]. Exiting.");
            return 0;
        }

        // Register signal handlers for graceful shutdown if pcntl is available
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use ($lock) {
                $this->info("Received SIGINT. Releasing lock and shutting down gracefully...");
                $this->running = false;
                $lock->release();
            });
            pcntl_signal(SIGTERM, function () use ($lock) {
                $this->info("Received SIGTERM. Releasing lock and shutting down gracefully...");
                $this->running = false;
                $lock->release();
            });
        }

        try {
            $iteration = 0;
            while ($this->running) {
                $start = microtime(true);

                // Renew lock periodically
                if ($iteration % 50 === 0) {
                    $lock->release();
                    if (!$lock->get()) {
                        $this->warn("Lost engine lock. Exiting.");
                        break;
                    }
                }

                // Query rooms currently in countdown or in_progress
                $query = Room::whereIn('status', ['countdown', 'in_progress']);
                if ($specificRoom) {
                    $query->where('code', $specificRoom);
                }
                $activeRooms = $query->get();

                foreach ($activeRooms as $room) {
                    // Collect buffered inputs for players in this room
                    $bufferedInputs = [];
                    $playerIds = Player::where('room_id', $room->id)->pluck('id');

                    foreach ($playerIds as $pid) {
                        $cacheKey = "snake_input_{$room->id}_{$pid}";
                        $input = Cache::get($cacheKey);
                        if ($input) {
                            $bufferedInputs[$pid] = $input;
                        }
                    }

                    // Process tick
                    $this->roomService->processTick($room, $bufferedInputs);
                }

                $iteration++;
                $elapsedUs = (int)((microtime(true) - $start) * 1000000);
                $sleepUs = $tickIntervalUs - $elapsedUs;

                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }
        } finally {
            $lock->release();
        }

        $this->info("Game loop terminated.");
        return 0;
    }
}
