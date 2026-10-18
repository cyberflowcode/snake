<?php

use App\Models\Player;
use App\Models\Room;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. Private channels authenticate guests using their
| hashed session token, ensuring only joined players can subscribe.
|
*/

Broadcast::channel('room.{roomCode}', function ($user = null, string $roomCode = null) {
    $request = request();
    $rawToken = $request->header('X-Guest-Token') ?? $request->input('guest_token');

    if (empty($rawToken)) {
        return false;
    }

    $room = Room::where('code', strtoupper(trim($roomCode)))->first();
    if (!$room) {
        return false;
    }

    $tokenHash = Player::hashToken($rawToken);

    return Player::where('room_id', $room->id)
        ->where('token_hash', $tokenHash)
        ->exists();
});
