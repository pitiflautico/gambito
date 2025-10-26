<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests para BaseGameEngine usando MockupEngine
 *
 * Estos tests validan que:
 * 1. BaseGameEngine coordina correctamente con los módulos
 * 2. El flujo de rondas funciona correctamente
 * 3. Los eventos se emiten en el momento adecuado
 * 4. PlayerStateManager maneja locks correctamente
 * 5. RoundManager maneja rondas correctamente
 * 6. ScoreManager calcula puntos correctamente
 *
 * IMPORTANTE: Estos tests usan MockupEngine, que es un juego simple
 * que implementa BaseGameEngine sin lógica compleja. Cualquier cambio
 * en BaseGameEngine debe pasar estos tests.
 */
class BaseGameEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Game $game;
    protected Room $room;
    protected GameMatch $match;
    protected array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario master
        $this->master = User::factory()->create(['role' => 'user']);

        // Crear juego mockup
        $this->game = Game::create([
            'slug' => 'mockup',
            'name' => 'Mockup Game',
            'description' => 'Game for testing BaseGameEngine',
            'min_players' => 2,
            'max_players' => 4,
            'engine_class' => 'Games\\Mockup\\MockupEngine',
            'path' => 'games/mockup',
            'status' => 'active',
            'is_active' => true,
        ]);

        // Crear sala
        $this->room = Room::create([
            'master_id' => $this->master->id,
            'game_id' => $this->game->id,
            'code' => 'TEST01',
            'status' => Room::STATUS_WAITING,
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        // Crear 3 jugadores
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create(['role' => 'user']);
            $this->players[] = Player::create([
                'match_id' => $this->match->id,
                'user_id' => $user->id,
                'name' => "Player {$i}",
                'is_connected' => true,
            ]);
        }
    }

    /** @test */
    public function engine_initializes_correctly()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);

        $this->match->refresh();

        // Verificar que game_state tiene la estructura correcta
        $this->assertArrayHasKey('_config', $this->match->game_state);
        $this->assertArrayHasKey('phase', $this->match->game_state);
        $this->assertEquals('waiting', $this->match->game_state['phase']);

        // Verificar que los módulos se inicializaron
        $this->assertArrayHasKey('round_system', $this->match->game_state);
        $this->assertArrayHasKey('scoring_system', $this->match->game_state);
        $this->assertArrayHasKey('player_state', $this->match->game_state);
    }

    /** @test */
    public function engine_starts_game_and_emits_events()
    {
        Event::fake();

        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        $this->match->refresh();

        // Verificar que la fase cambió a playing
        $this->assertEquals('playing', $this->match->game_state['phase']);

        // Verificar que se emitió RoundStartedEvent
        Event::assertDispatched(\App\Events\Game\RoundStartedEvent::class);
    }

    /** @test */
    public function player_can_perform_action_and_gets_locked()
    {
        Event::fake();

        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        $player = $this->players[0];

        // Procesar acción del jugador
        $result = $this->match->processAction(
            player: $player,
            action: 'mockup_action',
            data: ['value' => 'test_value']
        );

        // Verificar que la acción fue exitosa
        $this->assertTrue($result['success']);

        // Verificar que el jugador fue bloqueado
        Event::assertDispatched(\App\Events\Game\PlayerLockedEvent::class, function ($event) use ($player) {
            return $event->playerId === $player->id;
        });

        // Verificar que el jugador no puede actuar de nuevo
        $result2 = $this->match->processAction(
            player: $player,
            action: 'mockup_action',
            data: ['value' => 'another_value']
        );

        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('Ya realizaste', $result2['message']);
    }

    /** @test */
    public function round_ends_when_all_players_act()
    {
        Event::fake();

        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Los 3 jugadores realizan su acción
        foreach ($this->players as $player) {
            $this->match->processAction(
                player: $player,
                action: 'mockup_action',
                data: ['value' => "action_player_{$player->id}"]
            );
        }

        // Verificar que se emitió RoundEndedEvent
        Event::assertDispatched(\App\Events\Game\RoundEndedEvent::class);
    }

    /** @test */
    public function scores_are_calculated_correctly_after_round()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Los 3 jugadores actúan
        foreach ($this->players as $player) {
            $this->match->processAction(
                player: $player,
                action: 'mockup_action',
                data: ['value' => 'test']
            );
        }

        $this->match->refresh();

        // Verificar que todos tienen 10 puntos (según config de mockup)
        $scores = $this->match->game_state['scoring_system']['scores'];

        foreach ($this->players as $player) {
            $this->assertEquals(10, $scores[$player->id]);
        }
    }

    /** @test */
    public function players_are_unlocked_for_next_round()
    {
        Event::fake();

        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Ronda 1: Todos actúan
        foreach ($this->players as $player) {
            $this->match->processAction(
                player: $player,
                action: 'mockup_action',
                data: ['value' => 'round1']
            );
        }

        // Simular que el frontend llama a next-round
        $engine->handleNewRound($this->match);

        // Verificar que se emitió PlayersUnlockedEvent
        Event::assertDispatched(\App\Events\Game\PlayersUnlockedEvent::class);

        // Ronda 2: Todos pueden actuar de nuevo
        $result = $this->match->processAction(
            player: $this->players[0],
            action: 'mockup_action',
            data: ['value' => 'round2']
        );

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function game_completes_after_configured_rounds()
    {
        Event::fake();

        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Mockup game tiene 3 rondas configuradas
        for ($round = 1; $round <= 3; $round++) {
            // Todos los jugadores actúan
            foreach ($this->players as $player) {
                $this->match->processAction(
                    player: $player,
                    action: 'mockup_action',
                    data: ['value' => "round{$round}"]
                );
            }

            // Si no es la última ronda, avanzar
            if ($round < 3) {
                $engine->handleNewRound($this->match);
            }
        }

        // Verificar que se emitió GameEndedEvent
        Event::assertDispatched(\App\Events\Game\GameEndedEvent::class);

        // Verificar que el juego está en fase finished
        $this->match->refresh();
        $this->assertEquals('finished', $this->match->game_state['phase']);
    }

    /** @test */
    public function final_ranking_is_correct()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // 3 rondas completas
        for ($round = 1; $round <= 3; $round++) {
            foreach ($this->players as $player) {
                $this->match->processAction(
                    player: $player,
                    action: 'mockup_action',
                    data: ['value' => "round{$round}"]
                );
            }

            if ($round < 3) {
                $engine->handleNewRound($this->match);
            }
        }

        $this->match->refresh();

        // Verificar que hay ranking
        $this->assertArrayHasKey('ranking', $this->match->game_state);
        $ranking = $this->match->game_state['ranking'];

        // Debe haber 3 jugadores en el ranking
        $this->assertCount(3, $ranking);

        // Todos deben tener 30 puntos (3 rondas × 10 puntos)
        foreach ($ranking as $entry) {
            $this->assertEquals(30, $entry['score']);
        }
    }

    /** @test */
    public function round_manager_tracks_current_round_correctly()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        $this->match->refresh();

        // Ronda inicial debe ser 1
        $this->assertEquals(1, $this->match->game_state['round_system']['current_round']);

        // Primera ronda
        foreach ($this->players as $player) {
            $this->match->processAction(
                player: $player,
                action: 'mockup_action',
                data: ['value' => 'round1']
            );
        }

        // Avanzar a ronda 2
        $engine->handleNewRound($this->match);
        $this->match->refresh();

        $this->assertEquals(2, $this->match->game_state['round_system']['current_round']);
    }

    /** @test */
    public function player_state_manager_tracks_locks_correctly()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Al inicio, nadie está bloqueado
        $this->match->refresh();
        $locks = $this->match->game_state['player_state']['locks'] ?? [];
        $this->assertEmpty($locks);

        // Primer jugador actúa
        $this->match->processAction(
            player: $this->players[0],
            action: 'mockup_action',
            data: ['value' => 'test']
        );

        $this->match->refresh();
        $locks = $this->match->game_state['player_state']['locks'] ?? [];

        // Solo 1 jugador bloqueado
        $this->assertCount(1, $locks);
        $this->assertArrayHasKey($this->players[0]->id, $locks);
    }

    /** @test */
    public function game_state_for_player_returns_correct_data()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        $player = $this->players[0];

        // Obtener estado para el jugador
        $state = $engine->getGameStateForPlayer($this->match, $player);

        // Verificar que tiene la información básica
        $this->assertIsArray($state);
        // El juego mockup puede retornar lo que quiera aquí
        // Solo verificamos que el método funciona
    }

    /** @test */
    public function cannot_start_next_round_if_game_is_complete()
    {
        $engine = $this->match->getEngine();
        $engine->initialize($this->match);
        $engine->startGame($this->match);

        // Completar las 3 rondas
        for ($round = 1; $round <= 3; $round++) {
            foreach ($this->players as $player) {
                $this->match->processAction(
                    player: $player,
                    action: 'mockup_action',
                    data: ['value' => "round{$round}"]
                );
            }

            if ($round < 3) {
                $engine->handleNewRound($this->match);
            }
        }

        $this->match->refresh();

        // Intentar iniciar ronda 4 no debe hacer nada
        // El juego ya terminó
        $this->assertEquals('finished', $this->match->game_state['phase']);

        // handleNewRound debe detectar que el juego está completo
        // y llamar a finalize() en lugar de avanzar ronda
        $currentRound = $this->match->game_state['round_system']['current_round'];

        $engine->handleNewRound($this->match);
        $this->match->refresh();

        // La ronda no debe haber cambiado
        $this->assertEquals($currentRound, $this->match->game_state['round_system']['current_round']);
    }
}

