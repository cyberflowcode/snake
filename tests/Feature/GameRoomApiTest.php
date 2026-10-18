<?php

namespace Tests\Feature;

use App\Models\MatchRecord;
use App\Models\Player;
use App\Models\Room;
use App\Services\GameRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameRoomApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_private_room_and_receive_credentials()
    {
        $response = $this->postJson('/api/rooms', [
            'nickname'    => 'TestViper',
            'color'       => 'cyan',
            'max_players' => 6,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'room' => ['id', 'code', 'status', 'max_players', 'players'],
                'player' => ['id', 'nickname', 'color', 'is_host'],
                'guest_token',
            ]);

        $this->assertNotEmpty($response->json('guest_token'));
        $this->assertEquals(6, strlen($response->json('room.code')));
        $this->assertDatabaseHas('rooms', ['code' => $response->json('room.code')]);
        $this->assertDatabaseHas('players', ['nickname' => 'TestViper', 'is_host' => true]);
    }

    public function test_can_join_existing_room()
    {
        $createRes = $this->postJson('/api/rooms', [
            'nickname' => 'HostPlayer',
            'color'    => 'cyan',
        ]);
        $roomCode = $createRes->json('room.code');

        $joinRes = $this->postJson("/api/rooms/{$roomCode}/join", [
            'nickname' => 'SecondPlayer',
            'color'    => 'yellow',
        ]);

        $joinRes->assertStatus(200)
            ->assertJsonStructure(['success', 'room', 'player', 'guest_token']);

        $this->assertDatabaseHas('players', [
            'nickname' => 'SecondPlayer',
            'is_host'  => false,
        ]);
    }

    public function test_rejects_joining_non_existent_room()
    {
        $response = $this->postJson('/api/rooms/NONEXIST/join', [
            'nickname' => 'Intruder',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['room_code']);
    }

    public function test_rejects_joining_full_room()
    {
        $createRes = $this->postJson('/api/rooms', [
            'nickname'    => 'Host',
            'max_players' => 2,
        ]);
        $roomCode = $createRes->json('room.code');

        // Join 2nd player (room full)
        $this->postJson("/api/rooms/{$roomCode}/join", ['nickname' => 'P2'])->assertStatus(200);

        // Try 3rd player
        $overRes = $this->postJson("/api/rooms/{$roomCode}/join", ['nickname' => 'P3']);
        $overRes->assertStatus(422)
            ->assertJsonValidationErrors(['room_code']);
    }

    public function test_channel_authorization_allows_valid_guest_and_rejects_invalid()
    {
        $createRes = $this->postJson('/api/rooms', ['nickname' => 'SecurePlayer']);
        $roomCode = $createRes->json('room.code');
        $validToken = $createRes->json('guest_token');

        // Valid guest authorization
        $validAuth = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-room.{$roomCode}",
            'socket_id'    => '12345.67890',
            'guest_token'  => $validToken,
        ]);
        $validAuth->assertStatus(200)->assertJsonStructure(['auth']);

        // Invalid guest token rejection
        $invalidAuth = $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-room.{$roomCode}",
            'socket_id'    => '12345.67890',
            'guest_token'  => 'fake_bogus_token',
        ]);
        $invalidAuth->assertStatus(403);
    }

    public function test_direction_input_validation()
    {
        $createRes = $this->postJson('/api/rooms', ['nickname' => 'Driver']);
        $roomCode = $createRes->json('room.code');
        $token = $createRes->json('guest_token');

        // Valid angle
        $valid = $this->withHeaders(['X-Guest-Token' => $token])
            ->postJson("/api/rooms/{$roomCode}/direction", [
                'angle' => 1.57,
                'boost' => true,
            ]);
        $valid->assertStatus(200)->assertJson(['success' => true]);

        // Missing angle
        $invalid = $this->withHeaders(['X-Guest-Token' => $token])
            ->postJson("/api/rooms/{$roomCode}/direction", [
                'boost' => true,
            ]);
        $invalid->assertStatus(422)->assertJsonValidationErrors(['angle']);
    }

    public function test_reconnection_with_guest_token()
    {
        $createRes = $this->postJson('/api/rooms', ['nickname' => 'Returner']);
        $roomCode = $createRes->json('room.code');
        $token = $createRes->json('guest_token');

        $reconRes = $this->withHeaders(['X-Guest-Token' => $token])
            ->postJson("/api/rooms/{$roomCode}/reconnect");

        $reconRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'player'  => ['nickname' => 'Returner'],
            ]);
    }

    public function test_match_result_persistence_and_leaderboard_update()
    {
        $roomService = app(GameRoomService::class);
        $res = $roomService->createRoom('Champion', 'cyan');
        $room = $res['room'];
        $player = $res['player'];

        $simState = [
            'status'       => 'finished',
            'tick'         => 120,
            'winner_id'    => $player->id,
            'snakes'       => [
                $player->id => [
                    'id'       => $player->id,
                    'nickname' => 'Champion',
                    'color'    => 'cyan',
                    'score'    => 1500,
                    'length'   => 45,
                    'rank'     => 1,
                    'is_alive' => true,
                ],
            ],
            'foods'        => [],
            'eliminations' => [],
        ];

        $roomService->persistMatchResults($room, $simState);

        $this->assertDatabaseHas('matches', [
            'room_id'         => $room->id,
            'winner_nickname' => 'Champion',
            'winner_score'    => 1500,
        ]);

        $this->assertDatabaseHas('leaderboards', [
            'player_nickname' => 'Champion',
            'high_score'      => 1500,
            'wins_count'      => 1,
        ]);
    }

    public function test_health_check_endpoint()
    {
        $res = $this->getJson('/api/health');
        $res->assertStatus(200)
            ->assertJson(['status' => 'ok', 'database' => 'connected']);
    }
}
