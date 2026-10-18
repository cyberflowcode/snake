<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameOver implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $results;

    public function __construct(string $roomCode, array $results)
    {
        $this->roomCode = $roomCode;
        $this->results = $results;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'game.over';
    }
}
