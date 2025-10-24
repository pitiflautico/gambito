<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test Exhaustivo del Modelo GameMatch
 *
 * Este test actúa como CONTRATO INAMOVIBLE para GameMatch.
 * Cualquier cambio en estos tests requiere aprobación explícita.
 *
 * Cubre:
 * 1. Estructura del modelo (fillable, casts, table)
 * 2. Relaciones (room, players, events, winner)
 * 3. Estados (isInProgress, isFinished, scopes)
 * 4. Ciclo de vida (start, finish)
 * 5. Lock mechanism (prevención de race conditions)
 * 6. Helpers (duration, connectedPlayers, etc.)
 */
class GameMatchModelTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
    protected Room $room;
    protected User $master;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear master
        $this->master = User::create([
            'name' => 'Master User',
            'email' => 'master@test.com',
            'password' => bcrypt('password'),
        ]);

        // Crear juego genérico (NO específico de ningún juego real)
        $this->game = Game::create([
            'name' => 'Test Game',
            'slug' => 'test-game',
            'path' => 'games/test',
            'description' => 'Generic game for testing models',
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
            'status' => 'waiting',
            'settings' => ['test' => true],
        ]);
    }

    // ========================================================================
    // 1. ESTRUCTURA DEL MODELO
    // ========================================================================

    /**
     * Test: La tabla debe ser 'matches' (no 'game_matches')
     */
    public function test_table_name_is_matches(): void
    {
        $match = new GameMatch();
        $this->assertEquals('matches', $match->getTable());
    }

    /**
     * Test: Fillable attributes deben ser exactamente estos
     */
    public function test_fillable_attributes_are_correct(): void
    {
        $expected = [
            'room_id',
            'started_at',
            'finished_at',
            'winner_id',
            'game_state',
        ];

        $match = new GameMatch();
        $this->assertEquals($expected, $match->getFillable());
    }

    /**
     * Test: Casts deben incluir game_state como array y timestamps como datetime
     */
    public function test_casts_are_correct(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => ['test' => 'data'],
        ]);

        // game_state debe ser array
        $this->assertIsArray($match->game_state);
        $this->assertEquals(['test' => 'data'], $match->game_state);

        // started_at y finished_at deben ser datetime (cuando se asignan)
        $match->update(['started_at' => now()]);
        $match->refresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $match->started_at);
    }

    /**
     * Test: game_state debe ser nullable (puede crearse sin estado inicial)
     */
    public function test_game_state_can_be_null_on_creation(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
        ]);

        $this->assertNull($match->game_state);
    }

    // ========================================================================
    // 2. RELACIONES
    // ========================================================================

    /**
     * Test: GameMatch belongsTo Room
     */
    public function test_belongs_to_room(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        $this->assertInstanceOf(Room::class, $match->room);
        $this->assertEquals($this->room->id, $match->room->id);
    }

    /**
     * Test: GameMatch hasMany Players
     */
    public function test_has_many_players(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        Player::create([
            'name' => 'Alice',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Bob',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        $this->assertCount(2, $match->players);
        $this->assertEquals('Alice', $match->players[0]->name);
        $this->assertEquals('Bob', $match->players[1]->name);
    }

    /**
     * Test: GameMatch belongsTo Player (winner)
     */
    public function test_belongs_to_winner(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        $winner = Player::create([
            'name' => 'Winner',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        $match->update(['winner_id' => $winner->id]);
        $match->refresh();

        $this->assertInstanceOf(Player::class, $match->winner);
        $this->assertEquals('Winner', $match->winner->name);
    }

    // ========================================================================
    // 3. ESTADOS Y SCOPES
    // ========================================================================

    /**
     * Test: isInProgress() retorna false cuando no ha empezado
     */
    public function test_is_in_progress_returns_false_when_not_started(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        $this->assertFalse($match->isInProgress());
    }

    /**
     * Test: isInProgress() retorna true cuando está en curso
     */
    public function test_is_in_progress_returns_true_when_started(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $this->assertTrue($match->isInProgress());
    }

    /**
     * Test: isInProgress() retorna false cuando ha finalizado
     */
    public function test_is_in_progress_returns_false_when_finished(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);

        $this->assertFalse($match->isInProgress());
    }

    /**
     * Test: isFinished() retorna true solo cuando finished_at está set
     */
    public function test_is_finished_returns_true_only_when_finished_at_is_set(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);
        $this->assertFalse($match->isFinished());

        $match->update(['started_at' => now()]);
        $match->refresh();
        $this->assertFalse($match->isFinished());

        $match->update(['finished_at' => now()]);
        $match->refresh();
        $this->assertTrue($match->isFinished());
    }

    /**
     * Test: Scope inProgress filtra correctamente
     */
    public function test_scope_in_progress_filters_correctly(): void
    {
        // Crear 3 matches con diferentes estados
        $notStarted = GameMatch::create(['room_id' => $this->room->id]);

        $inProgress = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $finished = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);

        $result = GameMatch::inProgress()->get();

        $this->assertCount(1, $result);
        $this->assertEquals($inProgress->id, $result[0]->id);
    }

    /**
     * Test: Scope finished filtra correctamente
     */
    public function test_scope_finished_filters_correctly(): void
    {
        $notStarted = GameMatch::create(['room_id' => $this->room->id]);

        $inProgress = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $finished = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);

        $result = GameMatch::finished()->get();

        $this->assertCount(1, $result);
        $this->assertEquals($finished->id, $result[0]->id);
    }

    // ========================================================================
    // 4. CICLO DE VIDA
    // ========================================================================

    /**
     * Test: finish() debe actualizar finished_at y winner_id
     */
    public function test_finish_updates_timestamps_and_winner(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $winner = Player::create([
            'name' => 'Winner',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        $this->assertNull($match->finished_at);
        $this->assertNull($match->winner_id);

        $match->finish($winner);
        $match->refresh();

        $this->assertNotNull($match->finished_at);
        $this->assertEquals($winner->id, $match->winner_id);
    }

    /**
     * Test: finish() puede llamarse sin winner
     */
    public function test_finish_can_be_called_without_winner(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $match->finish();
        $match->refresh();

        $this->assertNotNull($match->finished_at);
        $this->assertNull($match->winner_id);
    }

    /**
     * Test: finish() debe actualizar el estado de la sala a 'finished'
     */
    public function test_finish_updates_room_status_to_finished(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now(),
        ]);

        $this->room->update(['status' => 'playing']);
        $this->assertEquals('playing', $this->room->fresh()->status);

        $match->finish();

        $this->assertEquals('finished', $this->room->fresh()->status);
    }

    /**
     * Test: updateGameState() debe actualizar game_state correctamente
     */
    public function test_update_game_state_updates_state_correctly(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => ['old' => 'data'],
        ]);

        $newState = ['new' => 'state', 'round' => 2];
        $match->updateGameState($newState);
        $match->refresh();

        $this->assertEquals($newState, $match->game_state);
    }

    // ========================================================================
    // 5. LOCK MECHANISM (Race Condition Prevention)
    // ========================================================================

    /**
     * Test: acquireRoundLock() debe adquirir lock exitosamente
     */
    public function test_acquire_round_lock_succeeds_first_time(): void
    {
        Cache::flush(); // Limpiar cache antes del test

        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [
                'round_system' => ['current_round' => 1],
                'phase' => 'question',
            ],
        ]);

        $acquired = $match->acquireRoundLock();

        $this->assertTrue($acquired);
        $this->assertTrue($match->hasRoundLock());
    }

    /**
     * Test: acquireRoundLock() debe fallar si otro cliente tiene el lock
     */
    public function test_acquire_round_lock_fails_when_already_locked(): void
    {
        Cache::flush();

        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [
                'round_system' => ['current_round' => 1],
                'phase' => 'question',
            ],
        ]);

        // Primer intento debe tener éxito
        $this->assertTrue($match->acquireRoundLock());

        // Segundo intento debe fallar (lock ya adquirido)
        $this->assertFalse($match->acquireRoundLock());
    }

    /**
     * Test: releaseRoundLock() debe liberar el lock correctamente
     */
    public function test_release_round_lock_releases_lock(): void
    {
        Cache::flush();

        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [
                'round_system' => ['current_round' => 1],
                'phase' => 'question',
            ],
        ]);

        $match->acquireRoundLock();
        $this->assertTrue($match->hasRoundLock());

        $match->releaseRoundLock();
        $this->assertFalse($match->hasRoundLock());
    }

    /**
     * Test: Lock key debe incluir match_id, round y phase
     */
    public function test_lock_key_includes_match_id_round_and_phase(): void
    {
        Cache::flush();

        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [
                'round_system' => ['current_round' => 2],
                'phase' => 'results',
            ],
        ]);

        $match->acquireRoundLock();

        // Verificar que el lock key sea único por ronda y fase
        $expectedKey = sprintf('match:%d:round:2:phase:results:lock', $match->id);
        $this->assertTrue(Cache::has($expectedKey));
    }

    /**
     * Test: Locks de diferentes rondas deben ser independientes
     */
    public function test_locks_from_different_rounds_are_independent(): void
    {
        Cache::flush();

        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [
                'round_system' => ['current_round' => 1],
                'phase' => 'question',
            ],
        ]);

        // Adquirir lock para ronda 1
        $this->assertTrue($match->acquireRoundLock());

        // Cambiar a ronda 2
        $match->game_state = [
            'round_system' => ['current_round' => 2],
            'phase' => 'question',
        ];
        $match->save();

        // Debe poder adquirir lock para ronda 2 (es diferente)
        $this->assertTrue($match->acquireRoundLock());
    }

    // ========================================================================
    // 6. HELPERS
    // ========================================================================

    /**
     * Test: duration() retorna null cuando no ha empezado
     */
    public function test_duration_returns_null_when_not_started(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        $this->assertNull($match->duration());
    }

    /**
     * Test: duration() calcula duración correctamente cuando está en curso
     */
    public function test_duration_calculates_correctly_when_in_progress(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subSeconds(60),
        ]);

        $duration = $match->duration();

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(59, $duration);
        $this->assertLessThanOrEqual(61, $duration); // Margen de 1 segundo
    }

    /**
     * Test: duration() calcula duración correctamente cuando ha finalizado
     */
    public function test_duration_calculates_correctly_when_finished(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);

        $duration = $match->duration();

        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(299, $duration); // ~5 minutos
        $this->assertLessThanOrEqual(301, $duration);
    }

    /**
     * Test: duration attribute debe funcionar igual que duration()
     */
    public function test_duration_attribute_works(): void
    {
        $match = GameMatch::create([
            'room_id' => $this->room->id,
            'started_at' => now()->subSeconds(30),
        ]);

        $this->assertEquals($match->duration(), $match->duration);
    }

    /**
     * Test: connectedPlayers() retorna solo jugadores conectados
     */
    public function test_connected_players_returns_only_connected(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        Player::create([
            'name' => 'Connected 1',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Connected 2',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Disconnected',
            'match_id' => $match->id,
            'is_connected' => false,
        ]);

        $connected = $match->connectedPlayers()->get();

        $this->assertCount(2, $connected);
        $this->assertEquals('Connected 1', $connected[0]->name);
        $this->assertEquals('Connected 2', $connected[1]->name);
    }

    /**
     * Test: disconnectedPlayers() retorna solo jugadores desconectados
     */
    public function test_disconnected_players_returns_only_disconnected(): void
    {
        $match = GameMatch::create(['room_id' => $this->room->id]);

        Player::create([
            'name' => 'Connected',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Disconnected 1',
            'match_id' => $match->id,
            'is_connected' => false,
        ]);

        Player::create([
            'name' => 'Disconnected 2',
            'match_id' => $match->id,
            'is_connected' => false,
        ]);

        $disconnected = $match->disconnectedPlayers()->get();

        $this->assertCount(2, $disconnected);
        $this->assertEquals('Disconnected 1', $disconnected[0]->name);
        $this->assertEquals('Disconnected 2', $disconnected[1]->name);
    }
}
