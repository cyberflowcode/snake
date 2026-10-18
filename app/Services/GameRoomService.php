<?php

namespace App\Services;

use App\Events\GameOver;
use App\Events\GameStarted;
use App\Events\GameStateUpdated;
use App\Events\PlayerJoined;
use App\Events\PlayerLeft;
use App\Events\RoomStateChanged;
use App\Models\Leaderboard;
use App\Models\MatchRecord;
use App\Models\Player;
use App\Models\Room;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameRoomService
{
    protected SnakeSimulationService $simulation;

    public function __construct(SnakeSimulationService $simulation)
    {
        $this->simulation = $simulation;
    }

    /**
     * Create a new private room with the host player.
     */
    public function createRoom(string $nickname, ?string $color = 'cyan', int $maxPlayers = 8): array
    {
        return DB::transaction(function () use ($nickname, $color, $maxPlayers) {
            $code = Room::generateUniqueCode();
            $room = Room::create([
                'code'        => $code,
                'name'        => "{$nickname}'s Arena",
                'status'      => 'waiting',
                'max_players' => min(16, max(2, $maxPlayers)),
                'tick_rate'   => config('game.tick_rate', 10),
                'width'       => config('game.width', 2400),
                'height'      => config('game.height', 2400),
            ]);

            $creds = Player::generateCredentials();
            $player = Player::create([
                'room_id'      => $room->id,
                'nickname'     => $nickname,
                'token_hash'   => $creds['token_hash'],
                'color'        => $color ?? 'cyan',
                'is_host'      => true,
                'is_ready'     => true,
                'is_alive'     => true,
                'score'        => 0,
                'length'       => SnakeSimulationService::MIN_STARTING_LENGTH,
                'last_seen_at' => now(),
            ]);

            $room->update(['host_player_id' => $player->id]);

            return [
                'room'        => $room->fresh(['players']),
                'player'      => $player,
                'guest_token' => $creds['raw_token'],
            ];
        });
    }

    /**
     * Find an open public (global) room or create a new one.
     * Rooms fill up to 20 players; a new room is spawned when full.
     * There is NO HOST in global rooms — players join and play directly!
     */
    public function findOrCreateGlobalRoom(string $nickname, ?string $color = 'cyan'): array
    {
        return DB::transaction(function () use ($nickname, $color) {
            // Find a public room with available slots (< 20 players)
            $globalRoom = Room::where('is_public', true)
                ->where('max_players', 20)
                ->withCount('players')
                ->having('players_count', '<', 20)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $creds = Player::generateCredentials();

            if (!$globalRoom) {
                // No open global room — create a fresh continuous arena
                $code = Room::generateUniqueCode();
                $globalRoom = Room::create([
                    'code'           => $code,
                    'name'           => 'Global Arena',
                    'status'         => 'in_progress',
                    'is_public'      => true,
                    'max_players'    => 20,
                    'tick_rate'      => config('game.tick_rate', 10),
                    'width'          => config('game.width', 3200),
                    'height'         => config('game.height', 3200),
                    'host_player_id' => null, // NO HOST in global rooms
                    'started_at'     => now(),
                ]);
            } else {
                if ($globalRoom->status !== 'in_progress') {
                    $globalRoom->status = 'in_progress';
                }
                if ($globalRoom->host_player_id !== null) {
                    $globalRoom->host_player_id = null;
                }
            }

            // Pick a distinct color
            $existingColors   = $globalRoom->players()->pluck('color')->toArray();
            $availableColors  = array_keys(SnakeSimulationService::PALETTE);
            $diff             = array_diff($availableColors, $existingColors);
            $selectedColor    = ($color && !in_array($color, $existingColors)) ? $color : (reset($diff) ?: $availableColors[array_rand($availableColors)]);

            $player = Player::create([
                'room_id'      => $globalRoom->id,
                'nickname'     => $nickname,
                'token_hash'   => $creds['token_hash'],
                'color'        => $selectedColor,
                'is_host'      => false, // NEVER host in global rooms
                'is_ready'     => true,
                'is_alive'     => true,
                'score'        => 0,
                'length'       => SnakeSimulationService::MIN_STARTING_LENGTH,
                'last_seen_at' => now(),
            ]);

            $gameState = null;

            if (!$globalRoom->game_state) {
                // Initialize game state with this player's snake directly in_progress
                $initialState = $this->simulation->initializeState(
                    $globalRoom->width,
                    $globalRoom->height,
                    [$player->toArray()]
                );
                $initialState['status'] = 'in_progress';
                $initialState['countdown'] = 0;
                $initialState['countdown_ticks'] = 0;
                $initialState['is_public'] = true;
                $globalRoom->game_state = $initialState;
                $globalRoom->status = 'in_progress';
                $globalRoom->save();
                $gameState = $initialState;
            } else {
                // Spawn this player's snake directly into the active arena
                $state = $globalRoom->game_state;
                $state['is_public'] = true;
                $state['status'] = 'in_progress';
                $newSnake = $this->simulation->createSnakeForPlayer(
                    $state['width'] ?? 3200,
                    $state['height'] ?? 3200,
                    $player->toArray(),
                    $state['snakes'] ?? []
                );
                $state['snakes'][$player->id] = $newSnake;
                $globalRoom->game_state = $state;
                $globalRoom->save();
                $gameState = $state;
            }

            // Broadcast that someone joined with their snake
            try {
                broadcast(new PlayerJoined($globalRoom->code, $player->toArray()))->toOthers();
                broadcast(new RoomStateChanged($globalRoom->code, $this->getLobbySnapshot($globalRoom->fresh(['players']))));
                if ($gameState) {
                    broadcast(new \App\Events\GameStateUpdated($globalRoom->code, $gameState));
                }
            } catch (\Throwable $e) {}

            return [
                'room'        => $globalRoom->fresh(['players']),
                'player'      => $player,
                'guest_token' => $creds['raw_token'],
                'state'       => $gameState,
                'is_host'     => false,
            ];
        });
    }

    /**
     * Join an existing private room.
     */
    public function joinRoom(string $roomCode, string $nickname, ?string $color = 'yellow'): array
    {
        return DB::transaction(function () use ($roomCode, $nickname, $color) {
            $room = Room::where('code', strtoupper(trim($roomCode)))
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw ValidationException::withMessages(['room_code' => ['Room not found. Please check your room code.']]);
            }

            if ($room->isFull()) {
                throw ValidationException::withMessages(['room_code' => ['This room is full. Maximum players reached.']]);
            }

            // If room match was finished, reset to waiting so new players and host can play rematch
            if ($room->status === 'finished') {
                $room->status = 'waiting';
                $room->game_state = null;
                $room->save();
            }

            // Assign distinct color if duplicate or not provided
            $existingColors = $room->players()->pluck('color')->toArray();
            $availableColors = array_keys(SnakeSimulationService::PALETTE);
            $diff = array_diff($availableColors, $existingColors);
            $fallbackColor = !empty($diff) ? reset($diff) : $availableColors[array_rand($availableColors)];

            $selectedColor = $color ?: $fallbackColor;
            if (in_array($selectedColor, $existingColors)) {
                $selectedColor = $fallbackColor;
            }

            $creds = Player::generateCredentials();
            $player = Player::create([
                'room_id'      => $room->id,
                'nickname'     => $nickname,
                'token_hash'   => $creds['token_hash'],
                'color'        => $selectedColor,
                'is_host'      => false,
                'is_ready'     => true,
                'is_alive'     => true,
                'score'        => 0,
                'length'       => SnakeSimulationService::MIN_STARTING_LENGTH,
                'last_seen_at' => now(),
            ]);

            // If match is already in progress or countdown, spawn player's snake directly into arena!
            if (($room->status === 'in_progress' || $room->status === 'countdown') && $room->game_state) {
                $state = $room->game_state;
                $width = $state['width'] ?? 2400;
                $height = $state['height'] ?? 2400;
                $newSnake = $this->simulation->createSnakeForPlayer($width, $height, $player->toArray(), $state['snakes'] ?? []);
                $state['snakes'][$player->id] = $newSnake;
                $room->game_state = $state;
                $room->save();
            }

            $roomData = $room->fresh(['players']);

            // Broadcast player joined to room subscribers
            try {
                broadcast(new PlayerJoined($room->code, $player->toArray()))->toOthers();
                broadcast(new RoomStateChanged($room->code, $this->getLobbySnapshot($roomData)));
                if ($room->game_state) {
                    broadcast(new GameStateUpdated($room->code, $room->game_state));
                }
            } catch (\Throwable $e) {
                // Non-fatal if broadcast driver is log or offline in test
            }

            return [
                'room'        => $roomData,
                'player'      => $player,
                'guest_token' => $creds['raw_token'],
                'state'       => $room->game_state,
            ];
        });
    }

    /**
     * Authenticate guest player via raw session token.
     */
    public function authenticateGuest(string $roomCode, ?string $rawToken): ?Player
    {
        if (empty($rawToken)) {
            return null;
        }

        $tokenHash = Player::hashToken($rawToken);
        $room = Room::where('code', strtoupper(trim($roomCode)))->first();

        if (!$room) {
            return null;
        }

        return Player::where('room_id', $room->id)
            ->where('token_hash', $tokenHash)
            ->first();
    }

    /**
     * Host starts the match.
     */
    public function startMatch(Room $room, Player $hostPlayer): array
    {
        if ($room->host_player_id !== $hostPlayer->id) {
            throw ValidationException::withMessages(['auth' => ['Only the room host can start the match.']]);
        }

        if ($room->status === 'in_progress') {
            return $room->game_state ?? [];
        }

        $players = $room->players()->get()->map(function ($p) {
            return [
                'id'       => $p->id,
                'nickname' => $p->nickname,
                'color'    => $p->color,
            ];
        })->toArray();

        $initialState = $this->simulation->initializeState($room->width, $room->height, $players);

        $room->update([
            'status'     => 'countdown',
            'started_at' => now(),
            'game_state' => $initialState,
        ]);

        try {
            broadcast(new GameStarted($room->code, $initialState));
        } catch (\Throwable $e) {
            // Log broadcast error if any
        }

        return $initialState;
    }

    /**
     * Process 1 game tick on a room.
     */
    public function processTick(Room $room, array $bufferedInputs = []): array
    {
        $state = $room->game_state;
        if (!$state || ($room->status !== 'countdown' && $room->status !== 'in_progress')) {
            return [];
        }

        $updatedState = $this->simulation->tick($state, $bufferedInputs);

        $statusChanged = ($updatedState['status'] !== $room->status);
        $room->status = $updatedState['status'];
        $room->game_state = $updatedState;

        // If match finished this tick, persist results
        if ($updatedState['status'] === 'finished' && $statusChanged) {
            $room->finished_at = now();
            $room->winner_id = $updatedState['winner_id'];
            $room->save();

            $this->persistMatchResults($room, $updatedState);

            try {
                broadcast(new GameOver($room->code, [
                    'winner_id'  => $updatedState['winner_id'],
                    'scoreboard' => $this->buildScoreboard($room, $updatedState),
                ]));
            } catch (\Throwable $e) {}
        } else {
            $room->save();
        }

        try {
            broadcast(new GameStateUpdated($room->code, $updatedState));
        } catch (\Throwable $e) {}

        return $updatedState;
    }

    /**
     * Advance the room by the number of ticks elapsed since last tick.
     * This guarantees continuous smooth authoritative gameplay even when
     * running in environments without a separate background worker terminal.
     */
    public function tickRoomIfNeeded(Room $room): array
    {
        if (!$room->game_state || ($room->status !== 'countdown' && $room->status !== 'in_progress')) {
            return $room->game_state ?? [];
        }

        $tickRate = $room->tick_rate ?: 10;
        $tickInterval = 1.0 / $tickRate;
        $cacheKey = "room_last_tick_time_{$room->id}";
        $now = microtime(true);
        $lastTickTime = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($lastTickTime === null) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $now, 30);
            return $this->processTick($room, $this->collectInputsForRoom($room));
        }

        $elapsed = $now - (float)$lastTickTime;
        $ticksDue = (int)floor($elapsed / $tickInterval);

        if ($ticksDue <= 0) {
            return $room->game_state;
        }

        $ticksToRun = min(15, $ticksDue);

        // Advance the time accumulator by the exact number of ticks processed
        // to prevent simulation time drift and jitter
        $newTickTime = ($elapsed > 2.0)
            ? $now
            : ((float)$lastTickTime + ($ticksToRun * $tickInterval));
        \Illuminate\Support\Facades\Cache::put($cacheKey, $newTickTime, 30);

        $state = $room->game_state;
        $inputs = $this->collectInputsForRoom($room);
        $statusChanged = false;

        for ($i = 0; $i < $ticksToRun; $i++) {
            $state = $this->simulation->tick($state, $inputs);
            if ($state['status'] !== $room->status) {
                $statusChanged = true;
                $room->status = $state['status'];
            }
            if ($state['status'] === 'finished') {
                break;
            }
        }

        $room->game_state = $state;

        if ($state['status'] === 'finished' && $statusChanged) {
            $room->finished_at = now();
            $room->winner_id = $state['winner_id'];
            $room->save();

            $this->persistMatchResults($room, $state);

            try {
                broadcast(new GameOver($room->code, [
                    'winner_id'  => $state['winner_id'],
                    'scoreboard' => $this->buildScoreboard($room, $state),
                ]));
            } catch (\Throwable $e) {}
        } else {
            $room->save();
        }

        try {
            broadcast(new GameStateUpdated($room->code, $state));
        } catch (\Throwable $e) {}

        return $state;
    }

    /**
     * Gather buffered inputs for all players in a room.
     */
    public function collectInputsForRoom(Room $room): array
    {
        $bufferedInputs = [];
        $snakes = $room->game_state['snakes'] ?? [];
        $playerIds = !empty($snakes)
            ? array_keys($snakes)
            : Player::where('room_id', $room->id)->pluck('id');

        foreach ($playerIds as $pid) {
            $cacheKey = "snake_input_{$room->id}_{$pid}";
            $input = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if ($input) {
                $bufferedInputs[$pid] = $input;
            }
        }
        return $bufferedInputs;
    }

    /**
     * Persist match results and update persistent leaderboards.
     */
    public function persistMatchResults(Room $room, array $finalState): void
    {
        DB::transaction(function () use ($room, $finalState) {
            $winnerId = $finalState['winner_id'] ?? null;
            $winnerPlayer = $winnerId ? Player::find($winnerId) : null;
            $winnerNickname = $winnerPlayer ? $winnerPlayer->nickname : 'None';
            $winnerScore = $winnerPlayer ? ($finalState['snakes'][$winnerId]['score'] ?? 0) : 0;

            $startedAt = $room->started_at ?? now();
            $durationSeconds = max(1, now()->diffInSeconds($startedAt));

            // Record match
            MatchRecord::create([
                'room_id'          => $room->id,
                'winner_nickname'  => $winnerNickname,
                'winner_score'     => $winnerScore,
                'duration_seconds' => $durationSeconds,
                'results_json'     => $finalState,
            ]);

            // Update players and global leaderboards
            foreach ($room->players as $player) {
                $snakeData = $finalState['snakes'][$player->id] ?? null;
                if ($snakeData) {
                    $score = $snakeData['score'];
                    $length = $snakeData['length'];
                    $rank = $snakeData['rank'] ?? null;
                    $isWinner = ($player->id === $winnerId);

                    $player->update([
                        'score'    => $score,
                        'length'   => $length,
                        'rank'     => $rank,
                        'is_alive' => $snakeData['is_alive'],
                    ]);

                    Leaderboard::recordGame($player->nickname, $score, $isWinner);
                }
            }
        });
    }

    /**
     * Request a rematch.
     */
    public function requestRematch(Room $room, Player $player): array
    {
        return DB::transaction(function () use ($room, $player) {
            // Reset players
            foreach ($room->players as $p) {
                $p->update([
                    'is_alive' => true,
                    'score'    => 0,
                    'length'   => SnakeSimulationService::MIN_STARTING_LENGTH,
                    'rank'     => null,
                ]);
            }

            $room->update([
                'status'      => 'waiting',
                'winner_id'   => null,
                'game_state'  => null,
                'started_at'  => null,
                'finished_at' => null,
            ]);

            $snapshot = $this->getLobbySnapshot($room->fresh(['players']));

            try {
                broadcast(new RoomStateChanged($room->code, $snapshot));
            } catch (\Throwable $e) {}

            // If solo match, immediately start countdown so player can instantly jump back into action!
            if ($room->players()->count() === 1 && (int)$room->host_player_id === (int)$player->id) {
                $initialState = $this->startMatch($room, $player);
                return [
                    'lobby' => $snapshot,
                    'state' => $initialState,
                ];
            }

            return [
                'lobby' => $snapshot,
                'state' => null,
            ];
        });
    }

    /**
     * Leave room — works from lobby, countdown, and mid-match.
     */
    public function leaveRoom(Room $room, Player $player): void
    {
        DB::transaction(function () use ($room, $player) {
            $wasHost = $player->is_host;

            // If a match is running, mark the leaving player's snake as voluntarily left
            // so the simulation's winner detection ignores it (game keeps running for others)
            if (in_array($room->status, ['in_progress', 'countdown']) && $room->game_state) {
                $state = $room->game_state;
                if (isset($state['snakes'][$player->id])) {
                    $state['snakes'][$player->id]['is_alive']         = false;
                    $state['snakes'][$player->id]['left_voluntarily'] = true;
                    $state['snakes'][$player->id]['eliminated_at']    = $state['tick'] ?? 0;
                    $room->game_state = $state;
                    $room->save();
                }
            }

            $player->delete();

            $remaining = $room->players()->get();

            // Room is now empty — delete it entirely
            if ($remaining->isEmpty()) {
                Cache::forget("room_last_tick_time_{$room->id}");
                $room->delete();
                return;
            }

            // Transfer host to the next player if host left (private rooms only)
            if ($wasHost && !$room->is_public) {
                $newHost = $remaining->first();
                $newHost->update(['is_host' => true]);
                $room->update(['host_player_id' => $newHost->id]);
            }

            // Broadcast the departure — remaining players just keep playing
            $snapshot = $this->getLobbySnapshot($room->fresh(['players']));
            try {
                broadcast(new PlayerLeft($room->code, ['player_id' => $player->id, 'nickname' => $player->nickname]));
                broadcast(new RoomStateChanged($room->code, $snapshot));
                // Push updated game state so leaver's snake disappears from other screens
                if ($room->game_state) {
                    broadcast(new \App\Events\GameStateUpdated($room->code, $room->game_state));
                }
            } catch (\Throwable $e) {}
        });
    }

    /**
     * Generate sanitized lobby snapshot for room members.
     */
    public function getLobbySnapshot(Room $room): array
    {
        return [
            'id'          => $room->id,
            'code'        => $room->code,
            'name'        => $room->name,
            'status'      => $room->status,
            'is_public'   => (bool)$room->is_public,
            'max_players' => $room->max_players,
            'tick_rate'   => $room->tick_rate,
            'width'       => $room->width,
            'height'      => $room->height,
            'host_id'     => $room->is_public ? null : $room->host_player_id,
            'players'     => $room->players->map(function ($p) use ($room) {
                return [
                    'id'       => $p->id,
                    'nickname' => $p->nickname,
                    'color'    => $p->color,
                    'is_host'  => $room->is_public ? false : (bool)$p->is_host,
                    'is_ready' => (bool)$p->is_ready,
                    'score'    => $p->score,
                    'rank'     => $p->rank,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * Build final scoreboard array.
     */
    public function buildScoreboard(Room $room, array $finalState): array
    {
        $scoreboard = [];
        foreach ($finalState['snakes'] as $id => $snake) {
            $scoreboard[] = [
                'id'       => $id,
                'nickname' => $snake['nickname'],
                'color'    => $snake['color'],
                'score'    => $snake['score'],
                'length'   => $snake['length'],
                'rank'     => $snake['rank'] ?? 999,
                'is_alive' => $snake['is_alive'],
            ];
        }

        usort($scoreboard, function ($a, $b) {
            if ($a['rank'] !== $b['rank']) {
                return ($a['rank'] ?? 999) <=> ($b['rank'] ?? 999);
            }
            return $b['score'] <=> $a['score'];
        });

        return $scoreboard;
    }

    /**
     * Respawn an eliminated player with a fresh snake at a random position.
     */
    public function respawnPlayer(Room $room, Player $player): array
    {
        return DB::transaction(function () use ($room, $player) {
            $player->update([
                'is_alive' => true,
                'score'    => 0,
                'length'   => SnakeSimulationService::MIN_STARTING_LENGTH,
            ]);

            $state = $room->game_state;
            if ($state) {
                $newSnake = $this->simulation->createSnakeForPlayer(
                    $state['width'] ?? 3200,
                    $state['height'] ?? 3200,
                    $player->toArray(),
                    $state['snakes'] ?? []
                );
                $state['snakes'][$player->id] = $newSnake;
                $room->game_state = $state;
                $room->save();

                try {
                    broadcast(new \App\Events\GameStateUpdated($room->code, $state));
                } catch (\Throwable $e) {}
            }

            return $room->game_state ?? [];
        });
    }
}
