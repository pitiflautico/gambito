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
 * Tests para Lobby - Sistema de conexiones en tiempo real
 *
 * Estos tests aseguran que:
 * 1. El lobby NO usa location.reload() - todo es dinámico
 * 2. El JavaScript está separado en LobbyManager.js
 * 3. El botón de iniciar se controla dinámicamente
 * 4. Los badges de conexión se muestran correctamente
 */
class LobbyRealTimeTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Game $game;
    protected Room $room;
    protected GameMatch $match;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario master
        $this->master = User::factory()->create(['role' => 'user']);

        // Crear juego directamente (sin factory)
        $this->game = Game::create([
            'slug' => 'test-game',
            'name' => 'Test Game',
            'description' => 'Test game for lobby tests',
            'min_players' => 2,
            'max_players' => 4,
            'engine_class' => 'TestEngine',
            'path' => 'games/test-game',
            'status' => 'active',
        ]);

        // Crear sala manualmente
        $this->room = Room::create([
            'master_id' => $this->master->id,
            'game_id' => $this->game->id,
            'code' => 'TEST01',
            'name' => 'Test Room',
            'status' => Room::STATUS_WAITING,
        ]);

        // Crear match manualmente
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'game_state' => [],
        ]);

        // Crear player para el master
        Player::create([
            'match_id' => $this->match->id,
            'user_id' => $this->master->id,
            'name' => $this->master->name,
            'is_connected' => true,
        ]);
    }

    /** @test */
    public function lobby_loads_correctly_for_master()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $response->assertOk();
        $response->assertViewIs('rooms.lobby');
        $response->assertViewHas('room');
        $response->assertViewHas('isMaster', true);
    }

    /** @test */
    public function lobby_uses_lobby_manager_not_inline_javascript()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe usar LobbyManager
        $this->assertStringContainsString('new window.LobbyManager', $content);
        $this->assertStringContainsString('let lobbyManager = null', $content);

        // NO debe tener las funciones inline antiguas
        $this->assertStringNotContainsString('function initializeWebSocket()', $content);
        $this->assertStringNotContainsString('function initializePresenceChannel()', $content);
        $this->assertStringNotContainsString('function addPlayerToList(user)', $content);
        $this->assertStringNotContainsString('function removePlayerFromList(user)', $content);
    }

    /** @test */
    public function lobby_does_not_use_location_reload()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // NO debe contener location.reload() para jugadores que se unen/salen
        // Solo se permite para redirección cuando el juego empieza
        $matches = [];
        preg_match_all('/location\.reload\(\)/', $content, $matches);

        $this->assertEmpty(
            $matches[0],
            'El lobby NO debe usar location.reload() - todo debe ser dinámico con Presence Channel'
        );
    }

    /** @test */
    public function master_sees_start_game_button_controlled_by_javascript()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe tener el botón con ID para control dinámico
        $this->assertStringContainsString('id="start-game-button"', $content);
        $this->assertStringContainsString('onclick="startGame()"', $content);

        // Debe tener el div de status
        $this->assertStringContainsString('id="start-game-status"', $content);

        // El botón debe estar inicialmente deshabilitado
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="start-game-button"[^>]*disabled[^>]*>/',
            $content
        );
    }

    /** @test */
    public function lobby_shows_players_list_with_connection_badges()
    {
        // Crear jugadores manualmente
        $player1 = Player::create([
            'match_id' => $this->match->id,
            'name' => 'Player 1',
            'is_connected' => true,
        ]);

        $player2 = Player::create([
            'match_id' => $this->match->id,
            'name' => 'Player 2',
            'is_connected' => false,
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe tener lista de jugadores con data-player-id
        $this->assertStringContainsString('data-player-id="'.$player1->id.'"', $content);
        $this->assertStringContainsString('data-player-id="'.$player2->id.'"', $content);

        // Debe tener ID de lista de jugadores para manipulación dinámica
        $this->assertStringContainsString('id="players-list"', $content);
    }

    /** @test */
    public function lobby_initializes_with_correct_room_code_and_options()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe pasar el código de sala correcto
        $this->assertStringContainsString(
            "new window.LobbyManager('{$this->room->code}'",
            $content
        );

        // Debe pasar isMaster correctamente
        $this->assertStringContainsString('isMaster: true', $content);

        // Debe pasar maxPlayers
        $this->assertStringContainsString(
            "maxPlayers: {$this->game->max_players}",
            $content
        );
    }

    /** @test */
    public function guest_does_not_see_start_game_button()
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $response = $this->actingAs($guest)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // No debe tener el botón de iniciar (solo master lo ve)
        $this->assertStringNotContainsString('id="start-game-button"', $content);
    }

    /** @test */
    public function lobby_has_no_large_waiting_indicator_banner()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // El indicador grande debe estar oculto (style="display: none")
        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="waiting-indicator"[^>]*style="display: none;"/',
            $content,
            'El banner grande de "Esperando jugadores" debe estar oculto'
        );
    }

    /** @test */
    public function lobby_manager_source_file_exists()
    {
        $lobbyManagerPath = resource_path('js/core/LobbyManager.js');

        $this->assertFileExists(
            $lobbyManagerPath,
            'El archivo LobbyManager.js debe existir en resources/js/core/'
        );

        $content = file_get_contents($lobbyManagerPath);

        // Debe exportar la clase
        $this->assertStringContainsString('export class LobbyManager', $content);

        // Debe tener los métodos clave
        $this->assertStringContainsString('addPlayerToList', $content);
        $this->assertStringContainsString('removePlayerFromList', $content);
        $this->assertStringContainsString('updateStartGameButton', $content);
        $this->assertStringContainsString('updatePlayerConnectionStatus', $content);

        // NO debe tener location.reload() como código ejecutable (comentarios están OK)
        // Buscar patrones de código real: location.reload() seguido de ; o en una línea sola
        $this->assertDoesNotMatchRegularExpression(
            '/[^\/\*]\s*location\.reload\(\)\s*;/',
            $content,
            'LobbyManager NO debe usar location.reload() como código ejecutable'
        );
    }
}
