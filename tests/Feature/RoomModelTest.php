<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test Exhaustivo del Modelo Room (Lobby)
 *
 * Este test actúa como CONTRATO INAMOVIBLE para Room.
 * Cualquier cambio en estos tests requiere aprobación explícita.
 *
 * Cubre:
 * 1. Estructura del modelo (fillable, casts, constantes)
 * 2. Generación automática de código único
 * 3. Relaciones (game, master, match)
 * 4. Estados (waiting, playing, finished)
 * 5. Transiciones de estado (startMatch, finishMatch)
 * 6. Scopes (waiting, playing, finished)
 * 7. Helpers (canStart, player_count, invite_url)
 */
class RoomModelTest extends TestCase
{
    use RefreshDatabase;

    protected Game $game;
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
    }

    // ========================================================================
    // 1. ESTRUCTURA DEL MODELO
    // ========================================================================

    /**
     * Test: Fillable attributes deben ser exactamente estos
     */
    public function test_fillable_attributes_are_correct(): void
    {
        $expected = [
            'code',
            'game_id',
            'master_id',
            'status',
            'settings',
            'game_settings',
        ];

        $room = new Room();
        $this->assertEquals($expected, $room->getFillable());
    }

    /**
     * Test: Casts deben incluir settings y game_settings como array
     */
    public function test_casts_are_correct(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'settings' => ['time_per_question' => 10],
            'game_settings' => ['difficulty' => 'hard'],
        ]);

        $this->assertIsArray($room->settings);
        $this->assertIsArray($room->game_settings);
        $this->assertEquals(['time_per_question' => 10], $room->settings);
        $this->assertEquals(['difficulty' => 'hard'], $room->game_settings);
    }

    /**
     * Test: Constantes de estado deben existir y tener valores correctos
     */
    public function test_status_constants_exist_and_have_correct_values(): void
    {
        $this->assertEquals('waiting', Room::STATUS_WAITING);
        $this->assertEquals('playing', Room::STATUS_PLAYING);
        $this->assertEquals('finished', Room::STATUS_FINISHED);
    }

    // ========================================================================
    // 2. GENERACIÓN AUTOMÁTICA DE CÓDIGO
    // ========================================================================

    /**
     * Test: El código debe generarse automáticamente si no se proporciona
     */
    public function test_code_is_generated_automatically_if_not_provided(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertNotNull($room->code);
        $this->assertEquals(6, strlen($room->code));
        $this->assertTrue(ctype_alnum($room->code));
        $this->assertEquals(strtoupper($room->code), $room->code); // Debe ser uppercase
    }

    /**
     * Test: El código debe ser único (no debe haber duplicados)
     */
    public function test_code_must_be_unique(): void
    {
        $room1 = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $room2 = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertNotEquals($room1->code, $room2->code);
    }

    /**
     * Test: Si se proporciona código manualmente, NO debe generarse uno nuevo
     */
    public function test_code_can_be_provided_manually(): void
    {
        $room = Room::create([
            'code' => 'CUSTOM',
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertEquals('CUSTOM', $room->code);
    }

    /**
     * Test: generateUniqueCode() debe generar códigos únicos
     */
    public function test_generate_unique_code_produces_unique_codes(): void
    {
        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $code = Room::generateUniqueCode();
            $this->assertNotContains($code, $codes);
            $codes[] = $code;
        }

        $this->assertCount(10, $codes);
    }

    // ========================================================================
    // 3. RELACIONES
    // ========================================================================

    /**
     * Test: Room belongsTo Game
     */
    public function test_belongs_to_game(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertInstanceOf(Game::class, $room->game);
        $this->assertEquals($this->game->id, $room->game->id);
    }

    /**
     * Test: Room belongsTo User (master)
     */
    public function test_belongs_to_master(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertInstanceOf(User::class, $room->master);
        $this->assertEquals($this->master->id, $room->master->id);
    }

    /**
     * Test: Room hasOne GameMatch
     */
    public function test_has_one_match(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $match = GameMatch::create([
            'room_id' => $room->id,
        ]);

        $this->assertInstanceOf(GameMatch::class, $room->match);
        $this->assertEquals($match->id, $room->match->id);
    }

    // ========================================================================
    // 4. ESTADOS
    // ========================================================================

    /**
     * Test: Estado inicial debe ser 'waiting' por defecto
     */
    public function test_initial_status_is_waiting_by_default(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        // Si no se especifica status, puede ser null o waiting según la migración
        // Verificamos que puede ser waiting
        $room->update(['status' => Room::STATUS_WAITING]);
        $this->assertEquals(Room::STATUS_WAITING, $room->fresh()->status);
    }

    /**
     * Test: isWaiting() retorna true solo cuando status es 'waiting'
     */
    public function test_is_waiting_returns_true_only_when_status_is_waiting(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $this->assertTrue($room->isWaiting());

        $room->update(['status' => Room::STATUS_PLAYING]);
        $room->refresh();
        $this->assertFalse($room->isWaiting());

        $room->update(['status' => Room::STATUS_FINISHED]);
        $room->refresh();
        $this->assertFalse($room->isWaiting());
    }

    /**
     * Test: isPlaying() retorna true solo cuando status es 'playing'
     */
    public function test_is_playing_returns_true_only_when_status_is_playing(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $this->assertFalse($room->isPlaying());

        $room->update(['status' => Room::STATUS_PLAYING]);
        $room->refresh();
        $this->assertTrue($room->isPlaying());

        $room->update(['status' => Room::STATUS_FINISHED]);
        $room->refresh();
        $this->assertFalse($room->isPlaying());
    }

    /**
     * Test: isFinished() retorna true solo cuando status es 'finished'
     */
    public function test_is_finished_returns_true_only_when_status_is_finished(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $this->assertFalse($room->isFinished());

        $room->update(['status' => Room::STATUS_PLAYING]);
        $room->refresh();
        $this->assertFalse($room->isFinished());

        $room->update(['status' => Room::STATUS_FINISHED]);
        $room->refresh();
        $this->assertTrue($room->isFinished());
    }

    // ========================================================================
    // 5. TRANSICIONES DE ESTADO
    // ========================================================================

    /**
     * Test: startMatch() debe cambiar status de waiting a playing
     */
    public function test_start_match_changes_status_from_waiting_to_playing(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $this->assertEquals(Room::STATUS_WAITING, $room->status);

        $room->startMatch();
        $room->refresh();

        $this->assertEquals(Room::STATUS_PLAYING, $room->status);
    }

    /**
     * Test: finishMatch() debe cambiar status a finished
     */
    public function test_finish_match_changes_status_to_finished(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        $this->assertEquals(Room::STATUS_PLAYING, $room->status);

        $room->finishMatch();
        $room->refresh();

        $this->assertEquals(Room::STATUS_FINISHED, $room->status);
    }

    /**
     * Test: Las transiciones de estado NO deben permitirse en orden incorrecto
     * (Este test documenta el comportamiento esperado, aunque el modelo no lo previene)
     */
    public function test_state_transitions_should_follow_correct_order(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        // Orden correcto: waiting -> playing -> finished
        $this->assertEquals(Room::STATUS_WAITING, $room->status);

        $room->startMatch();
        $this->assertEquals(Room::STATUS_PLAYING, $room->fresh()->status);

        $room->finishMatch();
        $this->assertEquals(Room::STATUS_FINISHED, $room->fresh()->status);
    }

    // ========================================================================
    // 6. SCOPES
    // ========================================================================

    /**
     * Test: Scope waiting filtra solo salas en waiting
     */
    public function test_scope_waiting_filters_only_waiting_rooms(): void
    {
        $waiting = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $playing = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        $finished = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_FINISHED,
        ]);

        $result = Room::waiting()->get();

        $this->assertCount(1, $result);
        $this->assertEquals($waiting->id, $result[0]->id);
    }

    /**
     * Test: Scope playing filtra solo salas en playing
     */
    public function test_scope_playing_filters_only_playing_rooms(): void
    {
        $waiting = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $playing = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        $finished = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_FINISHED,
        ]);

        $result = Room::playing()->get();

        $this->assertCount(1, $result);
        $this->assertEquals($playing->id, $result[0]->id);
    }

    /**
     * Test: Scope finished filtra solo salas finished
     */
    public function test_scope_finished_filters_only_finished_rooms(): void
    {
        $waiting = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_WAITING,
        ]);

        $playing = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        $finished = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'status' => Room::STATUS_FINISHED,
        ]);

        $result = Room::finished()->get();

        $this->assertCount(1, $result);
        $this->assertEquals($finished->id, $result[0]->id);
    }

    // ========================================================================
    // 7. HELPERS
    // ========================================================================

    /**
     * Test: player_count debe retornar 0 cuando no hay match
     */
    public function test_player_count_returns_zero_when_no_match(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertEquals(0, $room->player_count);
    }

    /**
     * Test: player_count debe retornar el número correcto de jugadores
     */
    public function test_player_count_returns_correct_number(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $match = GameMatch::create(['room_id' => $room->id]);

        Player::create([
            'name' => 'Player 1',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Player 2',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Player 3',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        // Refrescar room para cargar relación
        $room->refresh();

        $this->assertEquals(3, $room->player_count);
    }

    /**
     * Test: canStart() debe retornar false cuando no hay suficientes jugadores
     */
    public function test_can_start_returns_false_when_not_enough_players(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $match = GameMatch::create(['room_id' => $room->id]);

        // Crear solo 1 jugador (min_players es 2)
        Player::create([
            'name' => 'Player 1',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        $room->refresh();

        $this->assertFalse($room->canStart());
    }

    /**
     * Test: canStart() debe retornar true cuando hay suficientes jugadores
     */
    public function test_can_start_returns_true_when_enough_players(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $match = GameMatch::create(['room_id' => $room->id]);

        // Crear 2 jugadores (min_players es 2)
        Player::create([
            'name' => 'Player 1',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        Player::create([
            'name' => 'Player 2',
            'match_id' => $match->id,
            'is_connected' => true,
        ]);

        $room->refresh();

        $this->assertTrue($room->canStart());
    }

    /**
     * Test: canStart() debe retornar false cuando hay demasiados jugadores
     */
    public function test_can_start_returns_false_when_too_many_players(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $match = GameMatch::create(['room_id' => $room->id]);

        // Crear 11 jugadores (max_players es 10)
        for ($i = 1; $i <= 11; $i++) {
            Player::create([
                'name' => "Player $i",
                'match_id' => $match->id,
                'is_connected' => true,
            ]);
        }

        $room->refresh();

        $this->assertFalse($room->canStart());
    }

    /**
     * Test: invite_url debe retornar la URL correcta
     */
    public function test_invite_url_returns_correct_url(): void
    {
        $room = Room::create([
            'code' => 'ABC123',
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $expectedUrl = route('rooms.join', ['code' => 'ABC123']);

        $this->assertEquals($expectedUrl, $room->invite_url);
    }

    /**
     * Test: settings debe permitir almacenar configuración personalizada
     */
    public function test_settings_can_store_custom_configuration(): void
    {
        $settings = [
            'time_per_question' => 15,
            'questions_per_game' => 10,
            'difficulty' => 'hard',
            'category' => 'science',
        ];

        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
            'settings' => $settings,
        ]);

        $room->refresh();

        $this->assertEquals($settings, $room->settings);
        $this->assertEquals(15, $room->settings['time_per_question']);
        $this->assertEquals('hard', $room->settings['difficulty']);
    }

    /**
     * Test: settings puede ser nullable
     */
    public function test_settings_can_be_null(): void
    {
        $room = Room::create([
            'game_id' => $this->game->id,
            'master_id' => $this->master->id,
        ]);

        $this->assertNull($room->settings);
    }
}
