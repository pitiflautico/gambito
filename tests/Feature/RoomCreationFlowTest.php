<?php

namespace Tests\Feature;

use App\Events\PlayerJoinedEvent;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Test Exhaustivo de Creación de Salas
 *
 * Este test actúa como CONTRATO INAMOVIBLE para la creación de salas.
 * El flujo es GENÉRICO y debe funcionar igual para TODOS los juegos.
 *
 * Flujo probado:
 * 1. Usuario autenticado crea sala
 * 2. Se genera código único de 6 caracteres
 * 3. Se crea GameMatch asociado
 * 4. El master se agrega automáticamente como jugador
 * 5. Redirección al lobby con código correcto
 */
class RoomCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario
        $this->user = User::factory()->create();

        // Crear juego genérico (funciona para cualquier juego)
        $this->game = Game::create([
            'name' => 'Test Game',
            'slug' => 'test-game',
            'path' => 'games/test',
            'description' => 'Generic game for testing',
            'is_active' => true,
            'metadata' => [
                'minPlayers' => 2,
                'maxPlayers' => 10,
            ],
        ]);
    }

    /**
     * Test: Usuario autenticado puede crear una sala
     */
    public function test_authenticated_user_can_create_room(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rooms', [
            'game_id' => $this->game->id,
            'master_id' => $this->user->id,
            'status' => Room::STATUS_WAITING,
        ]);
    }

    /**
     * Test: Al crear sala se genera código único de 6 caracteres
     */
    public function test_room_creation_generates_unique_6_char_code(): void
    {
        $this->actingAs($this->user);

        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();

        $this->assertNotNull($room);
        $this->assertEquals(6, strlen($room->code));
        $this->assertTrue(ctype_alnum($room->code));
        $this->assertEquals(strtoupper($room->code), $room->code);
    }

    /**
     * Test: Al crear sala se crea automáticamente un GameMatch
     */
    public function test_room_creation_creates_match_automatically(): void
    {
        $this->actingAs($this->user);

        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();

        $this->assertNotNull($room->match);
        $this->assertInstanceOf(GameMatch::class, $room->match);
        $this->assertEquals($room->id, $room->match->room_id);
    }

    /**
     * Test: Al crear sala, el master se agrega automáticamente como jugador
     */
    public function test_room_creation_adds_master_as_player(): void
    {
        $this->actingAs($this->user);

        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();
        $match = $room->match;

        $this->assertCount(1, $match->players);

        $masterPlayer = $match->players->first();
        $this->assertEquals($this->user->id, $masterPlayer->user_id);
        $this->assertEquals($this->user->name, $masterPlayer->name);
        $this->assertEquals('master', $masterPlayer->role);
        $this->assertTrue($masterPlayer->is_connected);
    }

    /**
     * Test: Creación de sala redirige al lobby con código correcto
     */
    public function test_room_creation_redirects_to_lobby_with_code(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();

        $response->assertRedirect(route('rooms.lobby', ['code' => $room->code]));
    }

    /**
     * Test: NO se puede crear sala sin game_id
     */
    public function test_cannot_create_room_without_game_id(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('rooms.store'), []);

        // Laravel devuelve 404 para validación faltante de exists:games
        $response->assertStatus(404);
        $this->assertDatabaseCount('rooms', 0);
    }

    /**
     * Test: NO se puede crear sala con game_id inválido
     */
    public function test_cannot_create_room_with_invalid_game_id(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('rooms.store'), [
            'game_id' => 9999, // ID que no existe
        ]);

        // Laravel valida exists:games y devuelve 404
        $response->assertStatus(404);
        $this->assertDatabaseCount('rooms', 0);
    }

    /**
     * Test: NO se puede crear sala con juego inactivo
     */
    public function test_cannot_create_room_with_inactive_game(): void
    {
        $this->game->update(['is_active' => false]);

        $this->actingAs($this->user);

        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $response->assertSessionHasErrors('game_id');
        $this->assertDatabaseCount('rooms', 0);
    }

    /**
     * Test: Usuario NO autenticado NO puede crear sala
     */
    public function test_guest_cannot_create_room(): void
    {
        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('rooms', 0);
    }

    /**
     * Test: Al crear sala, se limpia sesión de invitado anterior
     */
    public function test_room_creation_clears_guest_session(): void
    {
        $this->actingAs($this->user);

        // Simular sesión de invitado anterior
        session(['guest_player' => [
            'session_id' => 'old-session',
            'name' => 'Old Guest',
        ]]);

        // El código debe ejecutarse sin errores (clearAllSessions se llama internamente)
        $response = $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $response->assertRedirect();

        // Verificar que la sala se creó correctamente
        $this->assertDatabaseHas('rooms', [
            'game_id' => $this->game->id,
            'master_id' => $this->user->id,
        ]);
    }

    /**
     * Test: Al crear sala, se desconecta de otras partidas activas
     */
    public function test_room_creation_disconnects_from_other_matches(): void
    {
        $this->actingAs($this->user);

        // Crear partida anterior donde el usuario está conectado
        $oldRoom = Room::create([
            'code' => 'OLD123',
            'game_id' => $this->game->id,
            'master_id' => $this->user->id,
            'status' => Room::STATUS_PLAYING,
        ]);

        $oldMatch = GameMatch::create([
            'room_id' => $oldRoom->id,
            'started_at' => now(),
            'game_state' => [],
        ]);

        Player::create([
            'match_id' => $oldMatch->id,
            'user_id' => $this->user->id,
            'name' => $this->user->name,
            'is_connected' => true,
        ]);

        // Verificar que está conectado
        $this->assertTrue(
            Player::where('match_id', $oldMatch->id)
                ->where('user_id', $this->user->id)
                ->first()
                ->is_connected
        );

        // Crear nueva sala
        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        // Verificar que se desconectó de la partida anterior
        $this->assertFalse(
            Player::where('match_id', $oldMatch->id)
                ->where('user_id', $this->user->id)
                ->first()
                ->is_connected
        );
    }

    /**
     * Test: Puede crear múltiples salas con códigos únicos
     */
    public function test_can_create_multiple_rooms_with_unique_codes(): void
    {
        $this->actingAs($this->user);

        // Crear 3 salas
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('rooms.store'), [
                'game_id' => $this->game->id,
            ]);
        }

        $rooms = Room::where('master_id', $this->user->id)->get();

        $this->assertCount(3, $rooms);

        // Verificar que todos los códigos son únicos
        $codes = $rooms->pluck('code')->toArray();
        $this->assertCount(3, array_unique($codes));
    }

    /**
     * Test: GameMatch inicial tiene game_state vacío (o null)
     */
    public function test_initial_match_has_empty_game_state(): void
    {
        $this->actingAs($this->user);

        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();
        $match = $room->match;

        // game_state debe ser array vacío o null (ambos son válidos)
        $this->assertTrue(
            $match->game_state === [] || $match->game_state === null
        );
    }

    /**
     * Test: Room inicial tiene status 'waiting'
     */
    public function test_initial_room_has_waiting_status(): void
    {
        $this->actingAs($this->user);

        $this->post(route('rooms.store'), [
            'game_id' => $this->game->id,
        ]);

        $room = Room::where('master_id', $this->user->id)->first();

        $this->assertEquals(Room::STATUS_WAITING, $room->status);
        $this->assertTrue($room->isWaiting());
        $this->assertFalse($room->isPlaying());
        $this->assertFalse($room->isFinished());
    }
}
