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
 * Tests para TeamManager - Sistema de equipos en tiempo real
 *
 * Estos tests aseguran que:
 * 1. El código de equipos NO está inline - está en TeamManager.js
 * 2. TeamManager.js NO usa location.reload() - todo es dinámico
 * 3. El lobby inicializa TeamManager correctamente
 * 4. La validación de equipos funciona antes de iniciar partida
 * 5. Los métodos clave están presentes en TeamManager
 */
class TeamManagerTest extends TestCase
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

        // Crear juego con equipos habilitados
        $this->game = Game::create([
            'slug' => 'team-game',
            'name' => 'Team Game',
            'description' => 'Test game with teams',
            'min_players' => 2,
            'max_players' => 4,
            'engine_class' => 'TestEngine',
            'path' => 'games/team-game',
            'status' => 'active',
        ]);

        // Crear sala con equipos habilitados
        $this->room = Room::create([
            'master_id' => $this->master->id,
            'game_id' => $this->game->id,
            'code' => 'TEAM01',
            'name' => 'Team Test Room',
            'status' => Room::STATUS_WAITING,
            'game_settings' => [
                'play_with_teams' => true,
            ],
        ]);

        // Crear match
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
    public function lobby_uses_team_manager_not_inline_javascript()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe usar TeamManager
        $this->assertStringContainsString('new window.TeamManager', $content);
        $this->assertStringContainsString('let teamManager = null', $content);

        // NO debe tener las funciones inline antiguas de equipos
        $this->assertStringNotContainsString('function initializeTeams()', $content);
        $this->assertStringNotContainsString('function renderTeams(teams', $content);
        $this->assertStringNotContainsString('function balanceTeams()', $content);
        $this->assertStringNotContainsString('function resetTeams()', $content);
        $this->assertStringNotContainsString('function loadExistingTeams()', $content);
        $this->assertStringNotContainsString('function updatePlayerTeamBadges()', $content);
    }

    /** @test */
    public function team_manager_source_file_exists()
    {
        $teamManagerPath = resource_path('js/core/TeamManager.js');

        $this->assertFileExists(
            $teamManagerPath,
            'El archivo TeamManager.js debe existir en resources/js/core/'
        );

        $content = file_get_contents($teamManagerPath);

        // Debe exportar la clase
        $this->assertStringContainsString('export class TeamManager', $content);

        // Debe tener los métodos clave
        $this->assertStringContainsString('initialize()', $content);
        $this->assertStringContainsString('changeTeamCount', $content);
        $this->assertStringContainsString('initializeTeams', $content);
        $this->assertStringContainsString('renderTeams', $content);
        $this->assertStringContainsString('joinTeam', $content);
        $this->assertStringContainsString('removeFromTeam', $content);
        $this->assertStringContainsString('balanceTeams', $content);
        $this->assertStringContainsString('resetTeams', $content);
        $this->assertStringContainsString('validateTeamsForStart', $content);
        $this->assertStringContainsString('updatePlayerTeamBadges', $content);
        $this->assertStringContainsString('loadExistingTeams', $content);
        $this->assertStringContainsString('setupTeamsWebSocket', $content);

        // NO debe tener location.reload() como código ejecutable
        $this->assertDoesNotMatchRegularExpression(
            '/[^\/\*]\s*location\.reload\(\)\s*;/',
            $content,
            'TeamManager NO debe usar location.reload() como código ejecutable'
        );
    }

    /** @test */
    public function team_manager_has_drag_and_drop_handlers()
    {
        $teamManagerPath = resource_path('js/core/TeamManager.js');
        $content = file_get_contents($teamManagerPath);

        // Debe tener los handlers de drag & drop
        $this->assertStringContainsString('handleDragStart', $content);
        $this->assertStringContainsString('handleDragEnd', $content);
        $this->assertStringContainsString('handleDragOver', $content);
        $this->assertStringContainsString('handleDragEnter', $content);
        $this->assertStringContainsString('handleDragLeave', $content);
        $this->assertStringContainsString('handleDrop', $content);

        // Debe tener la variable draggedPlayerId
        $this->assertStringContainsString('draggedPlayerId', $content);
    }

    /** @test */
    public function lobby_initializes_team_manager_with_correct_options()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // Debe pasar el código de sala correcto
        $this->assertStringContainsString(
            "new window.TeamManager('{$this->room->code}'",
            $content
        );

        // Debe pasar isMaster correctamente
        $this->assertStringContainsString('isMaster: true', $content);

        // Debe pasar currentPlayerId
        $this->assertStringContainsString('currentPlayerId:', $content);
    }

    /** @test */
    public function start_game_validates_teams_before_starting()
    {
        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // La función startGame debe validar equipos
        $this->assertStringContainsString('teamManager.validateTeamsForStart()', $content);
        $this->assertStringContainsString('if (!validation.valid)', $content);
    }

    /** @test */
    public function team_manager_only_initializes_when_teams_are_enabled()
    {
        // Crear sala SIN equipos
        $roomWithoutTeams = Room::create([
            'master_id' => $this->master->id,
            'game_id' => $this->game->id,
            'code' => 'NOTEAM',
            'name' => 'No Teams Room',
            'status' => Room::STATUS_WAITING,
            'game_settings' => [
                'play_with_teams' => false,
            ],
        ]);

        $response = $this->actingAs($this->master)
            ->get(route('rooms.lobby', $roomWithoutTeams->code));

        $content = $response->getContent();

        // NO debe inicializar TeamManager si no hay equipos
        $this->assertStringNotContainsString('new window.TeamManager', $content);
    }

    /** @test */
    public function team_manager_websocket_listeners_are_configured()
    {
        $teamManagerPath = resource_path('js/core/TeamManager.js');
        $content = file_get_contents($teamManagerPath);

        // Debe escuchar eventos de equipos
        $this->assertStringContainsString('.teams.balanced', $content);
        $this->assertStringContainsString('.teams.config-updated', $content);
        $this->assertStringContainsString('.player.moved', $content);
        $this->assertStringContainsString('.player.removed', $content);

        // Los listeners deben actualizar la vista dinámicamente (NO reload)
        $this->assertStringContainsString('loadExistingTeams()', $content);
        $this->assertStringContainsString('updatePlayerTeamBadges()', $content);
    }

    /** @test */
    public function team_manager_validation_logic_exists()
    {
        $teamManagerPath = resource_path('js/core/TeamManager.js');
        $content = file_get_contents($teamManagerPath);

        // Debe tener lógica de validación
        $this->assertStringContainsString('validateTeamsForStart()', $content);
        $this->assertStringContainsString('valid: true', $content);
        $this->assertStringContainsString('valid: false', $content);

        // Debe validar que los equipos tengan jugadores
        $this->assertStringContainsString('teamsWithPlayers', $content);
        $this->assertStringContainsString('emptyTeams', $content);
    }

    /** @test */
    public function guest_cannot_see_master_team_controls()
    {
        $guest = User::factory()->create(['role' => 'guest']);

        Player::create([
            'match_id' => $this->match->id,
            'user_id' => $guest->id,
            'name' => $guest->name,
            'is_connected' => true,
        ]);

        $response = $this->actingAs($guest)
            ->get(route('rooms.lobby', $this->room->code));

        $content = $response->getContent();

        // TeamManager se inicializa con isMaster: false
        $this->assertStringContainsString('isMaster: false', $content);
    }

    /** @test */
    public function lobby_reduced_in_size_after_extraction()
    {
        // El archivo lobby.blade.php debe haberse reducido significativamente
        $lobbyPath = resource_path('views/rooms/lobby.blade.php');
        $content = file_get_contents($lobbyPath);
        $lines = count(explode("\n", $content));

        // Antes de la extracción tenía ~975 líneas
        // Después debe tener ~550 líneas o menos
        $this->assertLessThan(
            600,
            $lines,
            'El archivo lobby.blade.php debe haberse reducido después de extraer TeamManager'
        );

        // Debe tener la inicialización simple de TeamManager
        $this->assertStringContainsString('initializeTeamManager()', $content);
        $this->assertStringContainsString('teamManager.initialize()', $content);
    }
}
