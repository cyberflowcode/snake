<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $initialState;

    public function __construct(string $roomCode, array $initialState)
    {
        $this->roomCode = $roomCode;
        $this->initialState = $initialState;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }
}
