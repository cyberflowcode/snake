<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public array $state;

    public function __construct(string $roomCode, array $state)
    {
        $this->roomCode = $roomCode;
        $this->state = $state;
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'game.tick';
    }

    public function broadcastWith(): array
    {
        return [
            'tick'         => $this->state['tick'],
            'status'       => $this->state['status'],
            'countdown'    => $this->state['countdown'] ?? 0,
            'snakes'       => $this->state['snakes'],
            'foods'        => $this->state['foods'],
            'eliminations' => $this->state['eliminations'] ?? [],
            'winner_id'    => $this->state['winner_id'] ?? null,
        ];
    }
}
