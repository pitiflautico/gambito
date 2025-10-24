<?php

namespace Tests\Feature;

use App\Events\Game\GameFinishedEvent;
use App\Events\Game\GameStartedEvent;
use App\Events\Game\RoundEndedEvent;
use App\Events\Game\RoundStartedEvent;
use App\Events\Game\TurnChangedEvent;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Test Exhaustivo de Flujo de Módulos
 *
 * Este test actúa como CONTRATO INAMOVIBLE para el funcionamiento de los módulos.
 * Verifica que el sistema de módulos funciona correctamente independiente del juego.
 *
 * Módulos probados:
 * - RoundSystem: Gestión de rondas, inicio, finalización
 * - TurnSystem: Turnos simultáneos y secuenciales
 * - TimerService: Inicialización y gestión de temporizadores
 *
 * NO prueba lógica específica de juegos, solo el sistema modular genérico.
 */
class ModuleFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Game $game;
    protected Room $room;
    protected GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        $this->master = User::factory()->create(['name' => 'Master']);

        // Crear juego genérico para testing
        $this->game = Game::create([
            'name' => 'Test Game',
            'slug' => 'test-game',
            'path' => 'games/test',
            'description' => 'Generic game for module testing',
            'is_active' => true,
            'metadata' => [
                'minPlayers' => 2,
                'maxPlayers' => 10,
            ],
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'TEST01',
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => null,
        ]);

        // Agregar jugadores
        Player::create([
            'match_id' => $this->match->id,
            'user_id' => $this->master->id,
            'name' => 'Player 1',
            'is_connected' => true,
        ]);

        Player::create([
            'match_id' => $this->match->id,
            'name' => 'Player 2',
            'is_connected' => true,
        ]);

        Player::create([
            'match_id' => $this->match->id,
            'name' => 'Player 3',
            'is_connected' => true,
        ]);
    }

    // ========================================================================
    // ROUND SYSTEM - Sistema de Rondas
    // ========================================================================

    /**
     * Test: RoundManager inicializa correctamente con X rondas configuradas
     */
    public function test_round_manager_initializes_with_configured_rounds(): void
    {
        $roundConfig = [
            'enabled' => true,
            'total_rounds' => 5,
        ];

        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $roundManager->initialize($this->match);

        $this->match->refresh();

        $this->assertNotNull($this->match->game_state['round_system']);
        $this->assertEquals(5, $this->match->game_state['round_system']['total_rounds']);
        $this->assertEquals(0, $this->match->game_state['round_system']['current_round']);
        $this->assertFalse($this->match->game_state['round_system']['is_complete']);
    }

    /**
     * Test: Iniciar primera ronda incrementa current_round a 1
     */
    public function test_starting_first_round_sets_current_round_to_1(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 3];
        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $roundManager->initialize($this->match);

        $roundManager->startRound($this->match);

        $this->match->refresh();
        $this->assertEquals(1, $this->match->game_state['round_system']['current_round']);
    }

    /**
     * Test: Completar ronda incrementa el contador
     */
    public function test_completing_round_increments_counter(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 3];
        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $roundManager->initialize($this->match);
        $roundManager->startRound($this->match);

        $roundManager->completeRound($this->match);

        $this->match->refresh();
        $this->assertEquals(2, $this->match->game_state['round_system']['current_round']);
    }

    /**
     * Test: Completar última ronda marca el sistema como completo
     */
    public function test_completing_last_round_marks_system_as_complete(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 3];
        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $roundManager->initialize($this->match);

        // Completar 3 rondas
        $roundManager->startRound($this->match); // Round 1
        $roundManager->completeRound($this->match); // Finish round 1, now at 2
        $roundManager->startRound($this->match); // Round 2
        $roundManager->completeRound($this->match); // Finish round 2, now at 3
        $roundManager->startRound($this->match); // Round 3
        $roundManager->completeRound($this->match); // Finish round 3

        $this->match->refresh();
        $this->assertTrue($this->match->game_state['round_system']['is_complete']);
        $this->assertEquals(3, $this->match->game_state['round_system']['current_round']);
    }

    /**
     * Test: isComplete() retorna true solo después de completar todas las rondas
     */
    public function test_is_complete_returns_true_only_after_all_rounds(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 2];
        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $roundManager->initialize($this->match);

        $this->assertFalse($roundManager->isComplete($this->match));

        $roundManager->startRound($this->match); // Round 1
        $this->assertFalse($roundManager->isComplete($this->match));

        $roundManager->completeRound($this->match); // Finish round 1
        $this->assertFalse($roundManager->isComplete($this->match));

        $roundManager->startRound($this->match); // Round 2
        $this->assertFalse($roundManager->isComplete($this->match));

        $roundManager->completeRound($this->match); // Finish round 2
        $this->assertTrue($roundManager->isComplete($this->match));
    }

    // ========================================================================
    // TURN SYSTEM - Sistema de Turnos
    // ========================================================================

    /**
     * Test: TurnManager inicializa con modo simultáneo
     */
    public function test_turn_manager_initializes_with_simultaneous_mode(): void
    {
        $turnConfig = [
            'enabled' => true,
            'mode' => 'simultaneous',
        ];

        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $this->match->refresh();

        $this->assertNotNull($this->match->game_state['turn_system']);
        $this->assertEquals('simultaneous', $this->match->game_state['turn_system']['mode']);
        $this->assertNull($this->match->game_state['turn_system']['current_turn_index']);
    }

    /**
     * Test: TurnManager inicializa con modo secuencial
     */
    public function test_turn_manager_initializes_with_sequential_mode(): void
    {
        $turnConfig = [
            'enabled' => true,
            'mode' => 'sequential',
        ];

        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $this->match->refresh();

        $this->assertEquals('sequential', $this->match->game_state['turn_system']['mode']);
        $this->assertEquals(0, $this->match->game_state['turn_system']['current_turn_index']);
    }

    /**
     * Test: Modo secuencial - nextTurn() avanza al siguiente jugador
     */
    public function test_sequential_mode_next_turn_advances_to_next_player(): void
    {
        $turnConfig = ['enabled' => true, 'mode' => 'sequential'];
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();
        $turnManager->startTurn($this->match, $playerIds);

        // Turn 0 → Turn 1
        $turnManager->nextTurn($this->match, $playerIds);
        $this->match->refresh();
        $this->assertEquals(1, $this->match->game_state['turn_system']['current_turn_index']);

        // Turn 1 → Turn 2
        $turnManager->nextTurn($this->match, $playerIds);
        $this->match->refresh();
        $this->assertEquals(2, $this->match->game_state['turn_system']['current_turn_index']);
    }

    /**
     * Test: Modo secuencial - último turno marca round_complete = true
     */
    public function test_sequential_mode_last_turn_marks_round_complete(): void
    {
        $turnConfig = ['enabled' => true, 'mode' => 'sequential'];
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();
        $turnManager->startTurn($this->match, $playerIds);

        $this->assertFalse($this->match->game_state['turn_system']['round_complete']);

        // Advance through all players
        $turnManager->nextTurn($this->match, $playerIds); // Player 1
        $this->assertFalse($this->match->fresh()->game_state['turn_system']['round_complete']);

        $turnManager->nextTurn($this->match, $playerIds); // Player 2
        $this->assertFalse($this->match->fresh()->game_state['turn_system']['round_complete']);

        $turnManager->nextTurn($this->match, $playerIds); // Player 3 (last)
        $this->assertTrue($this->match->fresh()->game_state['turn_system']['round_complete']);
    }

    /**
     * Test: Modo simultáneo - todos los jugadores actúan al mismo tiempo
     */
    public function test_simultaneous_mode_all_players_act_at_once(): void
    {
        $turnConfig = ['enabled' => true, 'mode' => 'simultaneous'];
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();
        $turnManager->startTurn($this->match, $playerIds);

        $this->match->refresh();

        // En modo simultáneo, current_turn_index es null
        $this->assertNull($this->match->game_state['turn_system']['current_turn_index']);

        // Todos los jugadores están pendientes
        $this->assertCount(3, $this->match->game_state['turn_system']['pending_players']);
    }

    /**
     * Test: Modo simultáneo - marcar acción de jugador lo remueve de pending
     */
    public function test_simultaneous_mode_marking_action_removes_from_pending(): void
    {
        $turnConfig = ['enabled' => true, 'mode' => 'simultaneous'];
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();
        $turnManager->startTurn($this->match, $playerIds);

        $playerId = $playerIds[0];
        $turnManager->markPlayerAction($this->match, $playerId);

        $this->match->refresh();

        $this->assertNotContains($playerId, $this->match->game_state['turn_system']['pending_players']);
        $this->assertContains($playerId, $this->match->game_state['turn_system']['completed_players']);
    }

    /**
     * Test: Modo simultáneo - cuando todos actúan, round_complete = true
     */
    public function test_simultaneous_mode_all_actions_complete_round(): void
    {
        $turnConfig = ['enabled' => true, 'mode' => 'simultaneous'];
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();
        $turnManager->startTurn($this->match, $playerIds);

        $this->assertFalse($this->match->game_state['turn_system']['round_complete']);

        // Marcar acción de cada jugador
        foreach ($playerIds as $playerId) {
            $turnManager->markPlayerAction($this->match, $playerId);
        }

        $this->match->refresh();
        $this->assertTrue($this->match->game_state['turn_system']['round_complete']);
        $this->assertEmpty($this->match->game_state['turn_system']['pending_players']);
    }

    // ========================================================================
    // TIMER SERVICE - Sistema de Temporizadores
    // ========================================================================

    /**
     * Test: TimerService inicializa con tiempo configurado
     */
    public function test_timer_service_initializes_with_configured_time(): void
    {
        $timerConfig = [
            'enabled' => true,
            'round_time' => 60,
        ];

        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $this->match->refresh();

        $this->assertNotNull($this->match->game_state['timer']);
        $this->assertEquals(60, $this->match->game_state['timer']['round_time']);
        $this->assertFalse($this->match->game_state['timer']['is_active']);
    }

    /**
     * Test: startTimer() inicia temporizador con remaining_time correcto
     */
    public function test_start_timer_sets_remaining_time_correctly(): void
    {
        $timerConfig = ['enabled' => true, 'round_time' => 30];
        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $timerService->startTimer($this->match);

        $this->match->refresh();

        $this->assertTrue($this->match->game_state['timer']['is_active']);
        $this->assertEquals(30, $this->match->game_state['timer']['remaining_time']);
        $this->assertNotNull($this->match->game_state['timer']['started_at']);
    }

    /**
     * Test: stopTimer() detiene temporizador
     */
    public function test_stop_timer_deactivates_timer(): void
    {
        $timerConfig = ['enabled' => true, 'round_time' => 30];
        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $timerService->startTimer($this->match);
        $this->assertTrue($this->match->fresh()->game_state['timer']['is_active']);

        $timerService->stopTimer($this->match);
        $this->assertFalse($this->match->fresh()->game_state['timer']['is_active']);
    }

    /**
     * Test: getRemainingTime() retorna tiempo restante correcto
     */
    public function test_get_remaining_time_returns_correct_value(): void
    {
        $timerConfig = ['enabled' => true, 'round_time' => 60];
        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $timerService->startTimer($this->match);

        $remainingTime = $timerService->getRemainingTime($this->match);

        // Debería ser aproximadamente 60 (puede ser 59 si tardó 1 segundo)
        $this->assertGreaterThanOrEqual(58, $remainingTime);
        $this->assertLessThanOrEqual(60, $remainingTime);
    }

    /**
     * Test: isExpired() retorna false si hay tiempo restante
     */
    public function test_is_expired_returns_false_when_time_remaining(): void
    {
        $timerConfig = ['enabled' => true, 'round_time' => 60];
        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $timerService->startTimer($this->match);

        $this->assertFalse($timerService->isExpired($this->match));
    }

    /**
     * Test: isExpired() retorna true si el tiempo expiró
     */
    public function test_is_expired_returns_true_when_time_elapsed(): void
    {
        $timerConfig = ['enabled' => true, 'round_time' => 1];
        $timerService = new \App\Services\Modules\TimerService($timerConfig);
        $timerService->initialize($this->match);

        $timerService->startTimer($this->match);

        // Esperar 2 segundos
        sleep(2);

        $this->assertTrue($timerService->isExpired($this->match));
    }

    // ========================================================================
    // INTEGRATION - Integración de módulos
    // ========================================================================

    /**
     * Test: Round + Turn (secuencial) - integración completa
     */
    public function test_round_and_sequential_turn_integration(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 2];
        $turnConfig = ['enabled' => true, 'mode' => 'sequential'];

        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);

        $roundManager->initialize($this->match);
        $turnManager->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();

        // ROUND 1
        $roundManager->startRound($this->match);
        $this->assertEquals(1, $this->match->fresh()->game_state['round_system']['current_round']);

        $turnManager->startTurn($this->match, $playerIds);

        // Todos los jugadores toman su turno
        $turnManager->nextTurn($this->match, $playerIds);
        $turnManager->nextTurn($this->match, $playerIds);
        $turnManager->nextTurn($this->match, $playerIds);

        $this->assertTrue($this->match->fresh()->game_state['turn_system']['round_complete']);

        $roundManager->completeRound($this->match);
        $this->assertEquals(2, $this->match->fresh()->game_state['round_system']['current_round']);

        // ROUND 2
        $roundManager->startRound($this->match);
        $turnManager->resetForNewRound($this->match, $playerIds);

        $turnManager->nextTurn($this->match, $playerIds);
        $turnManager->nextTurn($this->match, $playerIds);
        $turnManager->nextTurn($this->match, $playerIds);

        $this->assertTrue($this->match->fresh()->game_state['turn_system']['round_complete']);

        $roundManager->completeRound($this->match);
        $this->assertTrue($this->match->fresh()->game_state['round_system']['is_complete']);
    }

    /**
     * Test: Round + Turn (simultáneo) + Timer - integración completa
     */
    public function test_round_simultaneous_turn_and_timer_integration(): void
    {
        $roundConfig = ['enabled' => true, 'total_rounds' => 1];
        $turnConfig = ['enabled' => true, 'mode' => 'simultaneous'];
        $timerConfig = ['enabled' => true, 'round_time' => 30];

        $roundManager = new \App\Services\Modules\RoundSystem\RoundManager($roundConfig);
        $turnManager = new \App\Services\Modules\TurnSystem\TurnManager($turnConfig);
        $timerService = new \App\Services\Modules\TimerService($timerConfig);

        $roundManager->initialize($this->match);
        $turnManager->initialize($this->match);
        $timerService->initialize($this->match);

        $playerIds = $this->match->players->pluck('id')->toArray();

        // Start round with timer
        $roundManager->startRound($this->match);
        $turnManager->startTurn($this->match, $playerIds);
        $timerService->startTimer($this->match);

        $this->assertTrue($this->match->fresh()->game_state['timer']['is_active']);
        $this->assertEquals(30, $this->match->fresh()->game_state['timer']['remaining_time']);

        // Todos los jugadores actúan
        foreach ($playerIds as $playerId) {
            $turnManager->markPlayerAction($this->match, $playerId);
        }

        $this->assertTrue($this->match->fresh()->game_state['turn_system']['round_complete']);

        $timerService->stopTimer($this->match);
        $this->assertFalse($this->match->fresh()->game_state['timer']['is_active']);

        $roundManager->completeRound($this->match);
        $this->assertTrue($this->match->fresh()->game_state['round_system']['is_complete']);
    }
}
