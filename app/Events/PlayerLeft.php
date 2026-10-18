<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerLeft implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $data;

    public function __construct(string $roomCode, array $data)
    {
        $this->roomCode = $roomCode;
        $this->data = $data;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'player.left';
    }
}
