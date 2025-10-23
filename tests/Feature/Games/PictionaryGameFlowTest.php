<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Games\Pictionary\PictionaryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PictionaryGameFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
    protected Room $room;
    protected User $master;
    protected PictionaryEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear juego Pictionary
        $this->game = Game::create([
            'name' => 'Pictionary',
            'slug' => 'pictionary',
            'description' => 'Dibuja y adivina',
            'min_players' => 3,
            'max_players' => 10,
            'path' => 'games/pictionary',
            'is_active' => true,
        ]);

        // Crear usuario master
        $this->master = User::factory()->create([
            'name' => 'Master',
            'email' => 'master@test.com',
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'TEST01',
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => 'waiting',
        ]);

        $this->engine = new PictionaryEngine();
    }

    /**
     * Helper para extraer scores (soporta ambos formatos)
     */
    private function getScores(array $gameState): array
    {
        return $gameState['scoring_system']['scores'] ?? $gameState['scores'] ?? [];
    }

    /**
     * Helper para crear 3 jugadores en un match
     */
    protected function createPlayersForMatch(GameMatch $match, int $count = 3): array
    {
        $players = [];

        for ($i = 1; $i <= $count; $i++) {
            $players[] = Player::create([
                'match_id' => $match->id,
                'name' => "Player $i",
                'session_id' => "session-$i",
                'is_connected' => true,
            ]);
        }

        return $players;
    }

    /** @test */
    public function test_can_create_room_for_pictionary()
    {
        $this->actingAs($this->master);

        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rooms', [
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => 'waiting',
        ]);
    }

    /** @test */
    public function test_players_can_join_lobby()
    {
        $response = $this->post(route('rooms.joinByCode'), [
            'code' => $this->room->code,
        ]);

        // Ahora redirige directamente al lobby
        $response->assertRedirect(route('rooms.lobby', ['code' => $this->room->code]));
    }

    /** @test */
    public function test_guest_can_set_name_and_join()
    {
        // Ir a página de nombre
        $this->get(route('rooms.guestName', ['code' => $this->room->code]))
            ->assertOk();

        // Enviar nombre (el campo es player_name, no guest_name)
        $response = $this->post(route('rooms.storeGuestName', ['code' => $this->room->code]), [
            'player_name' => 'Player 1',
        ]);

        $response->assertRedirect(route('rooms.lobby', ['code' => $this->room->code]));
    }

    /** @test */
    public function test_master_can_start_game_with_minimum_players()
    {
        $this->actingAs($this->master);

        // Crear match
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        // Crear 3 jugadores (mínimo requerido)
        Player::create([
            'match_id' => $match->id,
            'user_id' => $this->master->id,
            'name' => 'Master',
            'is_connected' => true,
        ]);

        Player::create([
            'match_id' => $match->id,
            'name' => 'Guest Player 1',
            'session_id' => 'test-session-1',
            'is_connected' => true,
        ]);

        Player::create([
            'match_id' => $match->id,
            'name' => 'Guest Player 2',
            'session_id' => 'test-session-2',
            'is_connected' => true,
        ]);

        // Iniciar juego
        $response = $this->post(route('rooms.start', ['code' => $this->room->code]));

        $response->assertJson(['success' => true]);

        $this->room->refresh();
        $this->assertEquals('playing', $this->room->status);

        $match->refresh();
        $this->assertNotNull($match->started_at);
        $this->assertNotNull($match->game_state);
        $this->assertEquals('playing', $match->game_state['phase']);
    }

    /** @test */
    public function test_game_initializes_with_correct_state()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        // Crear 3 jugadores
        [$player1, $player2, $player3] = $this->createPlayersForMatch($match);

        // Inicializar juego
        $this->engine->initialize($match);
        $match->refresh();

        $gameState = $match->game_state;

        // Verificar estado inicial
        $this->assertEquals('playing', $gameState['phase']);
        $this->assertEquals(1, $gameState['round_system']['current_round'] ?? $gameState['current_round']); // Desde RoundManager
        $this->assertEquals(3, $gameState['round_system']['total_rounds'] ?? $gameState['total_rounds']); // Desde RoundManager: 3 jugadores = 3 rondas (modo auto)
        $this->assertEquals(0, $gameState['turn_system']['current_turn_index']); // Anidado en turn_system
        $this->assertNotNull($gameState['current_drawer_id']);
        $this->assertNotNull($gameState['current_word']);
        $this->assertIsArray($gameState['turn_system']['turn_order']); // Anidado en turn_system
        $this->assertCount(3, $gameState['turn_system']['turn_order']);
        $scores = $this->getScores($gameState);
        $this->assertArrayHasKey($player1->id, $scores);
        $this->assertEquals(0, $scores[$player1->id]);
        $this->assertEquals(0, $scores[$player2->id]);
        $this->assertEquals(0, $scores[$player3->id]);
    }

    /** @test */
    public function test_player_can_answer()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$player1, $player2, $player3] = $this->createPlayersForMatch($match);
        $this->engine->initialize($match);
        $match->refresh();

        // El jugador que no es dibujante intenta responder
        $drawerId = $match->game_state['current_drawer_id'];
        $guesser = $player1->id === $drawerId ? $player2 : $player1;

        $response = $this->engine->processAction($match, $guesser, 'answer', []);

        $this->assertTrue($response['success']);
        $match->refresh();
        $this->assertNotNull($match->game_state['pending_answer']);
        $this->assertEquals($guesser->id, $match->game_state['pending_answer']['player_id']);
    }

    /** @test */
    public function test_drawer_confirms_correct_answer()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$player1, $player2, $player3] = $this->createPlayersForMatch($match);
        $this->engine->initialize($match);
        $match->refresh();

        $drawerId = $match->game_state['current_drawer_id'];
        $drawer = Player::find($drawerId);
        $guesser = $player1->id === $drawerId ? $player2 : $player1;

        // Jugador responde
        $this->engine->processAction($match, $guesser, 'answer', []);
        $match->refresh();

        // Dibujante confirma correcto
        $response = $this->engine->processAction($match, $drawer, 'confirm_answer', [
            'guesser_id' => $guesser->id,
            'is_correct' => true,
        ]);

        $this->assertTrue($response['success']);
        $this->assertTrue($response['correct']);
        $this->assertTrue($response['round_ended']);
        $this->assertGreaterThan(0, $response['guesser_points']);
        $this->assertGreaterThan(0, $response['drawer_points']);

        // Verificar que se otorgaron puntos correctamente
        $match->refresh();
        $scores = $this->getScores($match->game_state);
        $this->assertGreaterThan(0, $scores[$guesser->id]);
        $this->assertGreaterThan(0, $scores[$drawer->id]);
    }

    /** @test */
    public function test_drawer_confirms_incorrect_answer()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$player1, $player2, $player3] = $this->createPlayersForMatch($match);
        $this->engine->initialize($match);
        $match->refresh();

        $drawerId = $match->game_state['current_drawer_id'];
        $drawer = Player::find($drawerId);
        $guesser = $player1->id === $drawerId ? $player2 : $player1;

        // Jugador responde
        $this->engine->processAction($match, $guesser, 'answer', []);
        $match->refresh();

        // Dibujante confirma incorrecto
        $response = $this->engine->processAction($match, $drawer, 'confirm_answer', [
            'guesser_id' => $guesser->id,
            'is_correct' => false,
        ]);

        $this->assertTrue($response['success']);
        $this->assertFalse($response['correct']);
        $this->assertTrue($response['round_continues']);
        $this->assertEquals("{$guesser->name} falló. El juego continúa.", $response['message']);

        $match->refresh();
        $tempElim = $match->game_state['round_system']['temporarily_eliminated'] ?? $match->game_state['temporarily_eliminated'];
        $this->assertContains($guesser->id, $tempElim); // Actualizado de eliminated_this_round
    }

    /** @test */
    public function test_game_advances_to_next_turn()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        $this->createPlayersForMatch($match);
        $this->engine->initialize($match);
        $match->refresh();

        $initialDrawerId = $match->game_state['current_drawer_id'];

        // Avanzar fase a scoring y luego avanzar turno
        $gameState = $match->game_state;
        $gameState['phase'] = 'scoring';
        $match->game_state = $gameState;
        $match->save();

        $this->engine->advancePhase($match);
        $match->refresh();

        $newDrawerId = $match->game_state['current_drawer_id'];

        // El drawer debe haber cambiado
        $this->assertNotEquals($initialDrawerId, $newDrawerId);
        $this->assertEquals('playing', $match->game_state['phase']);
    }

    /** @test */
    public function test_game_completes_after_all_rounds()
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$player1, $player2, $player3] = $this->createPlayersForMatch($match);
        $this->engine->initialize($match);
        $match->refresh();

        // Forzar última ronda, último turno
        // Con 3 jugadores, modo auto = 3 rondas
        // Última ronda = 3, último turno = índice 2 (0-based)
        $gameState = $match->game_state;
        $gameState['round_system']['current_round'] = 3; // Última ronda (formato modular)
        $gameState['round_system']['total_rounds'] = 3; // Total de rondas (3 jugadores = 3 rondas)
        $gameState['turn_system']['current_turn_index'] = 2; // Último turno (0-based, anidado en turn_system)
        $gameState['phase'] = 'scoring';
        // Configurar scores en formato modular (tiene prioridad sobre legacy)
        $gameState['scoring_system']['scores'][$player1->id] = 500;
        $gameState['scoring_system']['scores'][$player2->id] = 300;
        $gameState['scoring_system']['scores'][$player3->id] = 200;
        $match->game_state = $gameState;
        $match->save();

        // Avanzar fase (debería terminar el juego)
        $this->engine->advancePhase($match);
        $match->refresh();

        // El juego debe cambiar a fase 'results'
        $this->assertEquals('results', $match->game_state['phase']);

        // Verificar que hay un ganador calculado
        $scores = $this->getScores($match->game_state);
        $this->assertNotEmpty($scores);
        $maxScore = max($scores);
        $this->assertEquals(500, $maxScore); // El jugador con más puntos
    }

    /** @test */
    public function test_round_ends_when_all_guessers_fail()
    {
        // Crear match con 3 jugadores (1 drawer + 2 guessers)
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$drawer, $guesser1, $guesser2] = $this->createPlayersForMatch($match, 3);
        $this->engine->initialize($match);
        $match->refresh();

        // Verificar scores iniciales
        $scores = $this->getScores($match->game_state);
        $this->assertEquals(0, $scores[$drawer->id]);
        $this->assertEquals(0, $scores[$guesser1->id]);
        $this->assertEquals(0, $scores[$guesser2->id]);

        // Simular que guesser1 presiona "YO SÉ" y falla
        $response = $this->engine->processAction($match, $guesser1, 'answer', []);
        $this->assertTrue($response['success']);

        $response = $this->engine->processAction($match, $drawer, 'confirm_answer', [
            'is_correct' => false,
        ]);
        $this->assertTrue($response['success']);
        $this->assertTrue($response['round_continues']); // Aún queda guesser2

        // Simular que guesser2 presiona "YO SÉ" y falla (último guesser)
        $response = $this->engine->processAction($match, $guesser2, 'answer', []);
        $this->assertTrue($response['success']);

        $response = $this->engine->processAction($match, $drawer, 'confirm_answer', [
            'is_correct' => false,
        ]);

        // Verificar respuesta - AHORA la ronda debe terminar
        $this->assertTrue($response['success']);
        $this->assertFalse($response['correct']);
        $this->assertTrue($response['round_ended']); // La ronda debe terminar
        $this->assertTrue($response['all_eliminated']); // Todos eliminados
        $this->assertEquals('Todos los jugadores fallaron. La ronda termina sin ganador.', $response['message']);
        $this->assertEquals('scoring', $response['phase']);

        // Verificar estado del match
        $match->refresh();
        $this->assertEquals('scoring', $match->game_state['phase']);
        $this->assertFalse($match->game_state['game_is_paused']);
        $this->assertNull($match->game_state['pending_answer']);

        // Verificar que NO se otorgaron puntos
        $scores = $this->getScores($match->game_state);
        $this->assertEquals(0, $scores[$drawer->id]);
        $this->assertEquals(0, $scores[$guesser1->id]);
        $this->assertEquals(0, $scores[$guesser2->id]);

        // Verificar que ambos guessers fueron eliminados temporalmente
        $tempElim = $match->game_state['round_system']['temporarily_eliminated'] ?? $match->game_state['temporarily_eliminated'];
        $this->assertContains($guesser1->id, $tempElim);
        $this->assertContains($guesser2->id, $tempElim);
    }

    /** @test */
    public function test_round_continues_when_some_guessers_remain()
    {
        // Crear match con 3 jugadores (1 drawer + 2 guessers)
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        [$drawer, $guesser1, $guesser2] = $this->createPlayersForMatch($match, 3);
        $this->engine->initialize($match);
        $match->refresh();

        // Simular que guesser1 presiona "YO SÉ"
        $response = $this->engine->processAction($match, $guesser1, 'answer', []);
        $this->assertTrue($response['success']);

        // El drawer confirma que es INCORRECTA
        $response = $this->engine->processAction($match, $drawer, 'confirm_answer', [
            'is_correct' => false,
        ]);

        // Verificar respuesta
        $this->assertTrue($response['success']);
        $this->assertFalse($response['correct']);
        $this->assertTrue($response['round_continues']); // La ronda debe CONTINUAR
        $this->assertArrayNotHasKey('all_eliminated', $response); // No todos eliminados
        $this->assertEquals("{$guesser1->name} falló. El juego continúa.", $response['message']);
        $this->assertEquals(1, $response['active_guessers_remaining']); // Queda 1 guesser

        // Verificar estado del match
        $match->refresh();
        $this->assertEquals('playing', $match->game_state['phase']); // Sigue jugando
        $this->assertFalse($match->game_state['game_is_paused']);
        $this->assertNull($match->game_state['pending_answer']);

        // Verificar que guesser1 fue eliminado temporalmente
        $tempElim = $match->game_state['round_system']['temporarily_eliminated'] ?? $match->game_state['temporarily_eliminated'];
        $this->assertContains($guesser1->id, $tempElim);

        // Verificar que guesser2 NO está eliminado (puede seguir jugando)
        $this->assertNotContains($guesser2->id, $tempElim);
    }
}
