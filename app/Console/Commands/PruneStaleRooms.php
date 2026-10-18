<?php

namespace App\Console\Commands;

use App\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PruneStaleRooms extends Command
{
    protected $signature   = 'rooms:prune';
    protected $description = 'Delete empty, finished, or inactive rooms from the database';

    public function handle(): int
    {
        $deleted = 0;

        // 1. Rooms with zero players (orphaned)
        $empty = Room::whereDoesntHave('players')->get();
        foreach ($empty as $room) {
            $this->cleanRoom($room);
            $deleted++;
        }

        // 2. Finished rooms older than 10 minutes
        $finished = Room::where('status', 'finished')
            ->where('finished_at', '<', now()->subMinutes(10))
            ->get();
        foreach ($finished as $room) {
            $this->cleanRoom($room);
            $deleted++;
        }

        // 3. Waiting rooms with no activity for 30 minutes (lobby ghost rooms)
        $staleWaiting = Room::where('status', 'waiting')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();
        foreach ($staleWaiting as $room) {
            $this->cleanRoom($room);
            $deleted++;
        }

        // 4. In-progress rooms with no activity for 15 minutes (server crash / all players disconnected)
        $staleActive = Room::whereIn('status', ['in_progress', 'countdown'])
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get();
        foreach ($staleActive as $room) {
            $this->cleanRoom($room);
            $deleted++;
        }

        $this->info("Pruned $deleted stale room(s).");
        return self::SUCCESS;
    }

    private function cleanRoom(Room $room): void
    {
        // Clear any cached tick timestamps / input buffers for this room
        Cache::forget("room_last_tick_time_{$room->id}");

        foreach ($room->players ?? [] as $player) {
            Cache::forget("snake_input_{$room->id}_{$player->id}");
        }

        $room->delete(); // cascades to players via DB foreign key or Eloquent
        $this->line(" Deleted room [{$room->code}] (status: {$room->status})");
    }
}
