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
 * Test de Validación de Acciones en PlayController
 *
 * Este test valida que el endpoint apiProcessAction implemente correctamente
 * todas las validaciones necesarias antes de procesar una acción del juego.
 *
 * Validaciones probadas:
 * 1. Campo 'action' es requerido
 * 2. Campo 'data' debe ser un array (si se proporciona)
 * 3. Match debe estar en estado 'in_progress'
 * 4. Player debe existir en el match
 * 5. Player no debe estar desconectado
 */
class PlayControllerActionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Game $game;
    protected Room $room;
    protected GameMatch $match;
    protected Player $player;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario
        $this->user = User::factory()->create();

        // Crear juego genérico
        $this->game = Game::create([
            'name' => 'Test Game',
            'slug' => 'test-game',
            'path' => 'games/test',
            'description' => 'Generic game for testing',
            'is_active' => true,
            'metadata' => [
                'minPlayers' => 2,
                'maxPlayers' => 8,
            ],
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'TEST01',
            'game_id' => $this->game->id,
            'master_id' => $this->user->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => [
                'phase' => 'playing',
                'round' => 1,
            ],
        ]);

        // Crear player
        $this->player = Player::create([
            'match_id' => $this->match->id,
            'user_id' => $this->user->id,
            'name' => $this->user->name,
        ]);
    }

    /**
     * Test: El campo 'action' es requerido
     */
    public function test_action_field_is_required(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                // Sin campo 'action'
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    }

    /**
     * Test: El campo 'action' debe ser string
     */
    public function test_action_field_must_be_string(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 12345, // No es string
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    }

    /**
     * Test: El campo 'data' debe ser array si se proporciona
     */
    public function test_data_field_must_be_array_if_provided(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => 'not-an-array', // No es array
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data']);
    }

    /**
     * Test: El match debe estar en estado 'in_progress'
     */
    public function test_match_must_be_in_progress(): void
    {
        // Cambiar estado del match a 'finished'
        $this->match->update(['status' => 'finished']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => 'Match not active',
        ]);
    }

    /**
     * Test: El jugador debe estar registrado en el match
     */
    public function test_player_must_be_in_match(): void
    {
        // Crear un usuario diferente que no está en el match
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'Player not in match',
        ]);
    }

    /**
     * Test: El jugador no debe estar desconectado
     */
    public function test_player_must_not_be_disconnected(): void
    {
        // Marcar al jugador como desconectado en game_state
        $gameState = $this->match->game_state;
        $gameState['disconnected_players'] = [$this->player->id];
        $this->match->game_state = $gameState;
        $this->match->save();

        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'error' => 'Player disconnected',
        ]);
    }

    /**
     * Test: La sala debe estar en estado PLAYING
     */
    public function test_room_must_be_playing(): void
    {
        // Cambiar estado de la sala a WAITING
        $this->room->update(['status' => Room::STATUS_WAITING]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => ['some' => 'data'],
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'La sala no está en juego',
        ]);
    }

    /**
     * Test: Validación exitosa permite pasar al procesamiento
     *
     * NOTA: Este test fallará con excepción porque no hay engine real configurado,
     * pero al menos valida que todas las validaciones pasaron correctamente.
     */
    public function test_valid_request_passes_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/rooms/{$this->room->code}/action", [
                'action' => 'test_action',
                'data' => ['some' => 'data'],
            ]);

        // Esperamos 500 porque no hay engine configurado, pero no debe fallar por validación
        // Si falla con 400/422/403, significa que una validación está mal
        $this->assertNotEquals(400, $response->status());
        $this->assertNotEquals(422, $response->status());
        $this->assertNotEquals(403, $response->status());
    }
}
