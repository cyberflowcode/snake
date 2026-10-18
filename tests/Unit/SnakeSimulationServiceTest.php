<?php

namespace Tests\Unit;

use App\Services\SnakeSimulationService;
use Tests\TestCase;

class SnakeSimulationServiceTest extends TestCase
{
    protected SnakeSimulationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SnakeSimulationService();
    }

    public function test_initializes_game_state_with_correct_snakes_and_food()
    {
        $players = [
            ['id' => 1, 'nickname' => 'Alpha', 'color' => 'cyan'],
            ['id' => 2, 'nickname' => 'Beta', 'color' => 'yellow'],
        ];

        $state = $this->service->initializeState(2400, 2400, $players);

        $this->assertEquals('countdown', $state['status']);
        $this->assertEquals(3, $state['countdown']);
        $this->assertCount(2, $state['snakes']);
        $this->assertCount(SnakeSimulationService::TARGET_FOOD_COUNT, $state['foods']);

        $this->assertTrue($state['snakes'][1]['is_alive']);
        $this->assertEquals('Alpha', $state['snakes'][1]['nickname']);
        $this->assertEquals(SnakeSimulationService::MIN_STARTING_LENGTH, count($state['snakes'][1]['body']));
    }

    public function test_advances_snake_position_and_clamps_reversal()
    {
        $players = [['id' => 1, 'nickname' => 'Solo', 'color' => 'green']];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';
        $state['snakes'][1]['x'] = 1000.0;
        $state['snakes'][1]['y'] = 1000.0;
        $state['snakes'][1]['angle'] = 0.0; // Facing East

        // Attempt a sharp 180-degree instant reversal to PI
        $inputs = [1 => ['angle' => M_PI, 'boost' => false]];

        $nextState = $this->service->tick($state, $inputs);
        $snake = $nextState['snakes'][1];

        // Should not have jumped 180 degrees instantly; clamped to MAX_TURN_PER_TICK
        $this->assertLessThanOrEqual(SnakeSimulationService::MAX_TURN_PER_TICK + 0.01, abs($snake['angle']));
        // X position should have moved forward
        $this->assertGreaterThan(1000.0, $snake['x']);
    }

    public function test_food_collection_increases_length_and_score()
    {
        $players = [['id' => 1, 'nickname' => 'Eater', 'color' => 'red']];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';

        $snake = &$state['snakes'][1];
        $snake['x'] = 500.0;
        $snake['y'] = 500.0;
        $snake['angle'] = 0.0;
        $snake['score'] = 0;
        $initialLength = $snake['length'];
        unset($snake);

        // Place food directly ahead of snake head
        $state['foods'] = [
            ['id' => 'target_food', 'x' => 506.0, 'y' => 500.0, 'value' => 5, 'color' => '#ffffff'],
        ];

        $nextState = $this->service->tick($state);
        $snakeAfter = $nextState['snakes'][1];

        $this->assertEquals(5, $snakeAfter['score']);
        $this->assertEquals($initialLength + 1, $snakeAfter['length']);
    }

    public function test_wall_collision_eliminates_snake()
    {
        $players = [['id' => 1, 'nickname' => 'Crasher', 'color' => 'purple']];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';

        // Position snake at the edge moving into the left wall
        $snake = &$state['snakes'][1];
        $snake['x'] = 10.0;
        $snake['y'] = 500.0;
        $snake['angle'] = M_PI; // Facing West into left wall
        unset($snake);

        $nextState = $this->service->tick($state);

        $this->assertFalse($nextState['snakes'][1]['is_alive']);
        $this->assertEquals('wall', $nextState['eliminations'][0]['reason']);
    }

    public function test_collision_with_other_snake_body_eliminates_attacker_and_drops_food()
    {
        $players = [
            ['id' => 1, 'nickname' => 'Victim', 'color' => 'cyan'],
            ['id' => 2, 'nickname' => 'Attacker', 'color' => 'yellow'],
        ];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';
        // Victim is a long snake (10 segments) moving East at (800, 800)
        $state['snakes'][1]['x'] = 800.0;
        $state['snakes'][1]['y'] = 800.0;
        $state['snakes'][1]['angle'] = 0.0;
        $state['snakes'][1]['target_angle'] = 0.0;
        $state['snakes'][1]['length'] = 10;
        $state['snakes'][1]['body'] = [];
        for ($i = 0; $i < 10; $i++) {
            $state['snakes'][1]['body'][] = ['x' => 800.0 - ($i * 9.0), 'y' => 800.0];
        }

        // Attacker hits body segment at x = 750 (far from head at 807)
        $state['snakes'][2]['x'] = 750.0;
        $state['snakes'][2]['y'] = 788.0;
        $state['snakes'][2]['angle'] = M_PI / 2.0;
        $state['snakes'][2]['target_angle'] = M_PI / 2.0;
        $state['snakes'][2]['length'] = 5;
        $state['snakes'][2]['body'] = [
            ['x' => 750.0, 'y' => 788.0],
            ['x' => 750.0, 'y' => 779.0],
            ['x' => 750.0, 'y' => 770.0],
            ['x' => 750.0, 'y' => 761.0],
            ['x' => 750.0, 'y' => 752.0],
        ];
        $state['foods'] = [];

        $nextState = $this->service->tick($state);

        $this->assertTrue($nextState['snakes'][1]['is_alive']);
        $this->assertFalse($nextState['snakes'][2]['is_alive']);
        $this->assertEquals('body', $nextState['eliminations'][0]['reason']);

        // Eliminated snake should have dropped glowing food particles
        $this->assertNotEmpty($nextState['foods']);
    }

    public function test_simultaneous_head_on_collision_eliminates_both()
    {
        $players = [
            ['id' => 1, 'nickname' => 'SnakeA', 'color' => 'red'],
            ['id' => 2, 'nickname' => 'SnakeB', 'color' => 'blue'],
        ];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';

        // Snakes heading directly towards each other
        $state['snakes'][1]['x'] = 500.0;
        $state['snakes'][1]['y'] = 500.0;
        $state['snakes'][1]['angle'] = 0.0; // East

        $state['snakes'][2]['x'] = 512.0;
        $state['snakes'][2]['y'] = 500.0;
        $state['snakes'][2]['angle'] = M_PI; // West

        $nextState = $this->service->tick($state);

        $this->assertFalse($nextState['snakes'][1]['is_alive']);
        $this->assertFalse($nextState['snakes'][2]['is_alive']);
        $this->assertCount(2, $nextState['eliminations']);
    }

    public function test_multiplayer_winner_declared_when_one_snake_remains()
    {
        $players = [
            ['id' => 1, 'nickname' => 'Survivor', 'color' => 'green'],
            ['id' => 2, 'nickname' => 'Doomed', 'color' => 'red'],
        ];
        $state = $this->service->initializeState(2400, 2400, $players);
        $state['status'] = 'in_progress';

        // Put doomed near wall
        $state['snakes'][2]['x'] = 5.0;
        $state['snakes'][2]['y'] = 500.0;
        $state['snakes'][2]['angle'] = M_PI;

        $nextState = $this->service->tick($state);

        $this->assertEquals('finished', $nextState['status']);
        $this->assertEquals(1, $nextState['winner_id']);
        $this->assertEquals(1, $nextState['snakes'][1]['rank']);
    }
}
