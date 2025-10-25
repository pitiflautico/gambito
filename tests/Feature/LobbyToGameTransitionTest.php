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
 * Tests para Transición Lobby → Game Room
 *
 * Estos tests aseguran que:
 * 1. Cuando se inicia el juego, todos los jugadores del lobby pasan al room
 * 2. Los jugadores conectados se marcan correctamente
 * 3. El WebSocket event 'game.started' se dispara
 * 4. Los jugadores son redirigidos automáticamente
 * 5. El estado del room cambia de 'waiting' a 'active'
 */
class LobbyToGameTransitionTest extends TestCase
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

        // Crear juego mockup (con engine real que cumple todas las convenciones)
        $this->game = Game::create([
            'slug' => 'mockup',
            'name' => 'Mockup Game',
            'description' => 'Game for testing events and states',
            'min_players' => 2,
            'max_players' => 4,
            'engine_class' => 'Games\\Mockup\\MockupEngine',
            'path' => 'games/mockup',
            'status' => 'active',
        ]);

        // Crear sala
        $this->room = Room::create([
            'master_id' => $this->master->id,
            'game_id' => $this->game->id,
            'code' => 'TEST01',
            'name' => 'Test Room',
            'status' => Room::STATUS_WAITING,
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        // Crear 3 jugadores (incluyendo master)
        $this->players[] = Player::create([
            'match_id' => $this->match->id,
            'user_id' => $this->master->id,
            'name' => $this->master->name,
            'is_connected' => true,
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $user = User::factory()->create(['role' => 'guest']);
            $this->players[] = Player::create([
                'match_id' => $this->match->id,
                'user_id' => $user->id,
                'name' => "Player {$i}",
                'is_connected' => true,
            ]);
        }
    }

    /** @test */
    public function all_connected_players_in_lobby_appear_in_game_room()
    {
        // Iniciar juego
        $response = $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $response->assertOk();

        // Refrescar room y match
        $this->room->refresh();
        $this->match->refresh();

        // Verificar que el room cambió a 'active'
        $this->assertEquals(Room::STATUS_ACTIVE, $this->room->status);

        // Verificar que el match tiene started_at
        $this->assertNotNull($this->match->started_at);

        // Verificar que todos los jugadores siguen en el match
        $playersInMatch = Player::where('match_id', $this->match->id)->get();
        $this->assertCount(3, $playersInMatch);

        // Verificar que todos los jugadores siguen conectados
        foreach ($playersInMatch as $player) {
            $this->assertTrue($player->is_connected, "Player {$player->id} should be connected");
        }
    }

    /** @test */
    public function starting_game_redirects_all_players_to_game_room()
    {
        // El master inicia el juego
        $response = $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $response->assertOk();

        // Verificar que los jugadores pueden acceder al room
        foreach ($this->players as $player) {
            $user = User::find($player->user_id);

            $roomResponse = $this->actingAs($user)
                ->get(route('rooms.show', $this->room->code));

            $roomResponse->assertOk();
            $roomResponse->assertViewIs('rooms.show');
        }
    }

    /** @test */
    public function game_started_event_is_broadcast_when_starting()
    {
        Event::fake();

        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        // Verificar que se disparó el evento game.started
        Event::assertDispatched(\App\Events\Room\GameStartedEvent::class, function ($event) {
            return $event->room->code === $this->room->code;
        });
    }

    /** @test */
    public function disconnected_players_are_marked_as_disconnected()
    {
        // Marcar un jugador como desconectado antes de iniciar
        $disconnectedPlayer = $this->players[2];
        $disconnectedPlayer->is_connected = false;
        $disconnectedPlayer->save();

        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        // Refrescar jugadores
        foreach ($this->players as $player) {
            $player->refresh();
        }

        // Verificar estados de conexión
        $this->assertTrue($this->players[0]->is_connected, 'Master should be connected');
        $this->assertTrue($this->players[1]->is_connected, 'Player 1 should be connected');
        $this->assertFalse($this->players[2]->is_connected, 'Player 2 should be disconnected');
    }

    /** @test */
    public function cannot_start_game_if_not_master()
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $response = $this->actingAs($guest)
            ->post(route('rooms.start', $this->room->code));

        $response->assertStatus(403);

        // Verificar que el room sigue en 'waiting'
        $this->room->refresh();
        $this->assertEquals(Room::STATUS_WAITING, $this->room->status);
    }

    /** @test */
    public function cannot_start_game_without_minimum_players()
    {
        // Eliminar todos los jugadores excepto el master
        Player::where('match_id', $this->match->id)
            ->where('user_id', '!=', $this->master->id)
            ->delete();

        // Juego requiere mínimo 2 jugadores
        $response = $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $response->assertStatus(400);
        $response->assertJson(['error' => 'No hay suficientes jugadores para iniciar']);

        // Verificar que el room sigue en 'waiting'
        $this->room->refresh();
        $this->assertEquals(Room::STATUS_WAITING, $this->room->status);
    }

    /** @test */
    public function game_room_shows_all_players_from_lobby()
    {
        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        // Acceder al room como master
        $response = $this->actingAs($this->master)
            ->get(route('rooms.show', $this->room->code));

        $response->assertOk();

        $content = $response->getContent();

        // Verificar que todos los jugadores aparecen en el HTML
        foreach ($this->players as $player) {
            $this->assertStringContainsString($player->name, $content);
        }
    }

    /** @test */
    public function lobby_listens_to_game_started_event()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe escuchar el evento .game.started
        $this->assertStringContainsString('.game.started', $content);

        // Debe redirigir cuando recibe el evento (NO location.reload)
        $this->assertStringContainsString('window.location.replace', $content);
    }

    /** @test */
    public function all_players_receive_game_started_websocket_event()
    {
        // Este test verifica que el WebSocket channel está configurado correctamente
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe estar suscrito al channel room.{code}
        $this->assertStringContainsString("channel('room.{$this->room->code}')", $content);

        // Debe escuchar .game.started
        $this->assertStringContainsString('.game.started', $content);
    }

    /** @test */
    public function starting_game_twice_fails()
    {
        // Iniciar juego la primera vez
        $response1 = $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $response1->assertOk();

        // Intentar iniciar de nuevo
        $response2 = $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $response2->assertStatus(400);
        $response2->assertJson(['error' => 'El juego ya ha sido iniciado']);
    }

    /** @test */
    public function player_count_matches_between_lobby_and_game_room()
    {
        $playersInLobby = Player::where('match_id', $this->match->id)->count();

        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        $playersInGame = Player::where('match_id', $this->match->id)->count();

        // El número de jugadores debe ser el mismo
        $this->assertEquals($playersInLobby, $playersInGame);
        $this->assertEquals(3, $playersInGame);
    }

    /** @test */
    public function game_room_is_accessible_only_after_starting()
    {
        // Antes de iniciar, redirige al lobby
        $responseBefore = $this->actingAs($this->master)
            ->get(route('rooms.show', $this->room->code));

        $responseBefore->assertRedirect(route('rooms.lobby', $this->room->code));

        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        // Después de iniciar, muestra el room
        $responseAfter = $this->actingAs($this->master)
            ->get(route('rooms.show', $this->room->code));

        $responseAfter->assertOk();
        $responseAfter->assertViewIs('rooms.show');
    }

    /** @test */
    public function guest_players_can_access_game_room_after_starting()
    {
        // Iniciar juego
        $this->actingAs($this->master)
            ->post(route('rooms.start', $this->room->code));

        // Verificar que los guests pueden acceder
        foreach ($this->players as $player) {
            if ($player->user_id !== $this->master->id) {
                $user = User::find($player->user_id);

                $response = $this->actingAs($user)
                    ->get(route('rooms.show', $this->room->code));

                $response->assertOk();
            }
        }
    }
}
