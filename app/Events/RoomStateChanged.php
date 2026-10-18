<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $lobbyData;

    public function __construct(string $roomCode, array $lobbyData)
    {
        $this->roomCode = $roomCode;
        $this->lobbyData = $lobbyData;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'room.state';
    }
}
