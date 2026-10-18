<?php

namespace App\Http\Controllers;

use App\Models\Leaderboard;
use App\Models\Player;
use App\Models\Room;
use App\Services\GameRoomService;
use App\Services\SnakeSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class GameRoomController extends Controller
{
    protected GameRoomService $roomService;

    public function __construct(GameRoomService $roomService)
    {
        $this->roomService = $roomService;
    }

    /**
     * Create private room.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nickname'    => ['required', 'string', 'min:2', 'max:25'],
            'color'       => ['nullable', 'string', Rule::in(array_keys(SnakeSimulationService::PALETTE))],
            'max_players' => ['nullable', 'integer', 'min:2', 'max:16'],
        ]);

        $nickname = strip_tags(trim($validated['nickname']));
        $color = $validated['color'] ?? 'cyan';
        $maxPlayers = $validated['max_players'] ?? 8;

        $result = $this->roomService->createRoom($nickname, $color, $maxPlayers);

        return response()->json([
            'success'     => true,
            'room'        => $this->roomService->getLobbySnapshot($result['room']),
            'player'      => $result['player'],
            'guest_token' => $result['guest_token'],
        ], 201);
    }

    /**
     * Join (or create) a public global room — auto-fills to 20, then starts a new one.
     */
    public function joinGlobal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'min:2', 'max:25'],
            'color'    => ['nullable', 'string'],
        ]);

        $nickname = strip_tags(trim($validated['nickname']));
        $color    = $validated['color'] ?? 'cyan';

        $result = $this->roomService->findOrCreateGlobalRoom($nickname, $color);

        $lobby = $this->roomService->getLobbySnapshot($result['room']);

        return response()->json([
            'success'     => true,
            'room'        => $lobby,
            'player'      => $result['player'],
            'guest_token' => $result['guest_token'],
            'state'       => $result['state'] ?? null,
            'is_host'     => $result['is_host'] ?? false,
        ]);
    }

    /**
     * Join existing room.
     */
    public function join(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'min:2', 'max:25'],
            'color'    => ['nullable', 'string', Rule::in(array_keys(SnakeSimulationService::PALETTE))],
        ]);

        $nickname = strip_tags(trim($validated['nickname']));
        $color = $validated['color'] ?? null;

        $result = $this->roomService->joinRoom($code, $nickname, $color);

        return response()->json([
            'success'     => true,
            'room'        => $this->roomService->getLobbySnapshot($result['room']),
            'player'      => $result['player'],
            'guest_token' => $result['guest_token'],
            'state'       => $result['state'] ?? $result['room']->game_state,
        ]);
    }

    /**
     * Get sanitized room details.
     */
    public function show(string $code): JsonResponse
    {
        $room = Room::with('players')->where('code', strtoupper(trim($code)))->first();

        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        return response()->json([
            'room'  => $this->roomService->getLobbySnapshot($room),
            'state' => $room->game_state,
        ]);
    }

    /**
     * Start match (host only).
     */
    public function start(Request $request, string $code): JsonResponse
    {
        $room = Room::where('code', strtoupper(trim($code)))->first();
        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if (!$player || (int)$player->id !== (int)$room->host_player_id) {
            return response()->json(['error' => 'Unauthorized. Only host can start match.'], 403);
        }

        $state = $this->roomService->startMatch($room, $player);

        return response()->json([
            'success' => true,
            'state'   => $state,
        ]);
    }

    /**
     * Submit steering direction and boost state.
     */
    public function direction(Request $request, string $code): JsonResponse
    {
        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if (!$player) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'angle'         => ['required', 'numeric'],
            'boost'         => ['nullable', 'boolean'],
            'eaten_foods'   => ['nullable', 'array'],
            'eaten_foods.*' => ['string'],
        ]);

        $angle = (float)$validated['angle'];
        $boost = (bool)($validated['boost'] ?? false);
        $eatenFoods = $validated['eaten_foods'] ?? [];

        // Store into fast cache buffer for the game tick loop
        Cache::put("snake_input_{$player->room_id}_{$player->id}", [
            'angle'       => $angle,
            'boost'       => $boost,
            'eaten_foods' => $eatenFoods,
        ], 10);

        // Keep player activity timestamp fresh every 5s without overloading DB
        $seenKey = "player_last_seen_touch_{$player->id}";
        if (!Cache::has($seenKey)) {
            $player->update(['last_seen_at' => now()]);
            Cache::put($seenKey, true, 5);
        }

        // Advance simulation if due
        $room = $player->room;
        $state = null;
        if ($room) {
            $state = $this->roomService->tickRoomIfNeeded($room);
        }

        return response()->json([
            'success' => true,
            'state'   => $state,
        ]);
    }

    /**
     * Get authoritative game snapshot (for recovery/polling).
     */
    public function snapshot(Request $request, string $code): JsonResponse
    {
        $room = Room::with('players')->where('code', strtoupper(trim($code)))->first();
        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        // Advance simulation if due (ensures match countdown and gameplay tick smoothly)
        $state = $this->roomService->tickRoomIfNeeded($room);

        return response()->json([
            'room'  => $this->roomService->getLobbySnapshot($room),
            'state' => $state,
        ]);
    }

    /**
     * Request rematch.
     */
    public function rematch(Request $request, string $code): JsonResponse
    {
        $room = Room::with('players')->where('code', strtoupper(trim($code)))->first();
        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if (!$player) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $result = $this->roomService->requestRematch($room, $player);

        return response()->json([
            'success' => true,
            'room'    => $result['lobby'] ?? $result,
            'state'   => $result['state'] ?? null,
        ]);
    }

    /**
     * Leave room.
     */
    public function leave(Request $request, string $code): JsonResponse
    {
        $room = Room::where('code', strtoupper(trim($code)))->first();
        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if ($player) {
            $this->roomService->leaveRoom($room, $player);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Respawn eliminated player in room.
     */
    public function respawn(Request $request, string $code): JsonResponse
    {
        $room = Room::where('code', strtoupper(trim($code)))->first();
        if (!$room) {
            return response()->json(['error' => 'Room not found'], 404);
        }

        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if (!$player) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $state = $this->roomService->respawnPlayer($room, $player);

        return response()->json([
            'success' => true,
            'state'   => $state,
        ]);
    }

    /**
     * Reconnect guest with stored credential.
     */
    public function reconnect(Request $request, string $code): JsonResponse
    {
        $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');
        $player = $this->roomService->authenticateGuest($code, $token);

        if (!$player) {
            return response()->json(['error' => 'Invalid or expired session'], 401);
        }

        $room = Room::with('players')->where('id', $player->room_id)->first();
        $player->update(['last_seen_at' => now()]);

        return response()->json([
            'success' => true,
            'player'  => $player,
            'room'    => $this->roomService->getLobbySnapshot($room),
            'state'   => $room->game_state,
        ]);
    }

    /**
     * Channel authorization for guest players.
     */
    public function authChannel(Request $request): JsonResponse
    {
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        // Channel format: private-room.{roomCode}
        if (preg_match('/^private-room\.([A-Za-z0-9]+)$/', $channelName, $matches)) {
            $roomCode = $matches[1];
            $token = $request->header('X-Guest-Token') ?? $request->input('guest_token');

            $player = $this->roomService->authenticateGuest($roomCode, $token);
            if ($player) {
                // Authorize channel
                $authKey = config('broadcasting.connections.reverb.key') ?? config('broadcasting.connections.pusher.key') ?? 'snake-key';
                $authSecret = config('broadcasting.connections.reverb.secret') ?? config('broadcasting.connections.pusher.secret') ?? 'snake-secret';

                $signature = hash_hmac('sha256', "{$socketId}:{$channelName}", $authSecret);

                return response()->json([
                    'auth' => "{$authKey}:{$signature}",
                ]);
            }
        }

        return response()->json(['error' => 'Forbidden channel subscription'], 403);
    }

    /**
     * Global high score leaderboard.
     */
    public function leaderboard(): JsonResponse
    {
        $leaders = Leaderboard::orderBy('high_score', 'desc')
            ->take(10)
            ->get();

        return response()->json(['leaderboard' => $leaders]);
    }
}
