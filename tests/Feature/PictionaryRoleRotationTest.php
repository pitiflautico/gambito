<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Games\Pictionary\PictionaryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PictionaryRoleRotationTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Room $room;
    protected GameMatch $match;
    protected array $players;
    protected PictionaryEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear usuario master
        $this->master = User::factory()->create([
            'name' => 'Master',
            'email' => 'master@test.com'
        ]);

        // Crear juego Pictionary
        $game = Game::create([
            'name' => 'Pictionary',
            'slug' => 'pictionary',
            'path' => 'games/pictionary',
            'min_players' => 2,
            'max_players' => 8,
            'description' => 'Test game'
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'TEST01',
            'name' => 'Test Room',
            'game_id' => $game->id,
            'master_id' => $this->master->id,
            'status' => 'playing',
            'max_players' => 8
        ]);

        // Crear 3 jugadores
        $this->players = [
            Player::create(['name' => 'Player1', 'user_id' => null]),
            Player::create(['name' => 'Player2', 'user_id' => null]),
            Player::create(['name' => 'Player3', 'user_id' => null]),
        ];

        // Asociar jugadores a la sala
        foreach ($this->players as $player) {
            $this->room->players()->attach($player->id);
        }

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => []
        ]);

        // Inicializar engine
        $this->engine = new PictionaryEngine();
        $this->engine->initializeMatch($this->match, $this->players);
        $this->match->refresh();
    }

    /** @test */
    public function it_rotates_roles_correctly_when_advancing_turns()
    {
        $this->artisan('config:clear');
        $this->artisan('cache:clear');

        echo "\n=== ESTADO INICIAL ===\n";
        $initialRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        echo "Roles iniciales: " . json_encode($initialRoles, JSON_PRETTY_PRINT) . "\n";
        echo "Turn order: " . json_encode($this->match->game_state['turn_system']['turn_order'] ?? []) . "\n";

        $player1Id = $this->players[0]->id;
        $player2Id = $this->players[1]->id;
        $player3Id = $this->players[2]->id;

        // Verificar estado inicial
        $this->assertEquals('drawer', $initialRoles[$player1Id] ?? null, "Player 1 debería ser drawer inicialmente");
        $this->assertEquals('guesser', $initialRoles[$player2Id] ?? null, "Player 2 debería ser guesser inicialmente");
        $this->assertEquals('guesser', $initialRoles[$player3Id] ?? null, "Player 3 debería ser guesser inicialmente");

        echo "\n=== AVANZANDO AL TURNO 2 (Player 2 debería ser drawer) ===\n";

        // Simular que Player 2 contestó correctamente
        $player2 = $this->players[1];
        $result = $this->engine->processAction($this->match, $player2, 'answer', []);
        echo "Player 2 respondió: " . json_encode($result) . "\n";

        // Player 1 (drawer) confirma que es correcto
        $player1 = $this->players[0];
        $result = $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2Id
        ]);
        echo "Player 1 confirmó respuesta correcta: " . json_encode($result) . "\n";

        // Refrescar match
        $this->match->refresh();

        $rolesAfterTurn1 = $this->match->game_state['roles_system']['player_roles'] ?? [];
        echo "\n=== ROLES DESPUÉS DEL TURNO 1 ===\n";
        echo json_encode($rolesAfterTurn1, JSON_PRETTY_PRINT) . "\n";
        echo "Current turn index: " . ($this->match->game_state['turn_system']['current_turn_index'] ?? 'unknown') . "\n";

        // Verificar que los roles rotaron
        $this->assertNotEmpty($rolesAfterTurn1, "Roles no deberían estar vacíos");
        $this->assertArrayHasKey($player1Id, $rolesAfterTurn1, "Player 1 debería tener un rol");
        $this->assertArrayHasKey($player2Id, $rolesAfterTurn1, "Player 2 debería tener un rol");
        $this->assertArrayHasKey($player3Id, $rolesAfterTurn1, "Player 3 debería tener un rol");

        echo "\nPlayer 1 ($player1Id) rol: " . ($rolesAfterTurn1[$player1Id] ?? 'MISSING') . "\n";
        echo "Player 2 ($player2Id) rol: " . ($rolesAfterTurn1[$player2Id] ?? 'MISSING') . "\n";
        echo "Player 3 ($player3Id) rol: " . ($rolesAfterTurn1[$player3Id] ?? 'MISSING') . "\n";

        $this->assertEquals('guesser', $rolesAfterTurn1[$player1Id] ?? null, "Player 1 debería ser guesser ahora");
        $this->assertEquals('drawer', $rolesAfterTurn1[$player2Id] ?? null, "Player 2 debería ser drawer ahora");
        $this->assertEquals('guesser', $rolesAfterTurn1[$player3Id] ?? null, "Player 3 debería ser guesser todavía");

        echo "\n=== AVANZANDO AL TURNO 3 (Player 3 debería ser drawer) ===\n";

        // Player 3 responde correctamente
        $player3 = $this->players[2];
        $this->engine->processAction($this->match, $player3, 'answer', []);

        // Player 2 (ahora drawer) confirma
        $this->engine->processAction($this->match, $player2, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player3Id
        ]);

        $this->match->refresh();
        $rolesAfterTurn2 = $this->match->game_state['roles_system']['player_roles'] ?? [];

        echo "\n=== ROLES DESPUÉS DEL TURNO 2 ===\n";
        echo json_encode($rolesAfterTurn2, JSON_PRETTY_PRINT) . "\n";

        $this->assertArrayHasKey($player1Id, $rolesAfterTurn2, "Player 1 debería tener un rol");
        $this->assertArrayHasKey($player2Id, $rolesAfterTurn2, "Player 2 debería tener un rol");
        $this->assertArrayHasKey($player3Id, $rolesAfterTurn2, "Player 3 debería tener un rol");

        echo "\nPlayer 1 ($player1Id) rol: " . ($rolesAfterTurn2[$player1Id] ?? 'MISSING') . "\n";
        echo "Player 2 ($player2Id) rol: " . ($rolesAfterTurn2[$player2Id] ?? 'MISSING') . "\n";
        echo "Player 3 ($player3Id) rol: " . ($rolesAfterTurn2[$player3Id] ?? 'MISSING') . "\n";

        $this->assertEquals('guesser', $rolesAfterTurn2[$player1Id] ?? null, "Player 1 debería ser guesser");
        $this->assertEquals('guesser', $rolesAfterTurn2[$player2Id] ?? null, "Player 2 debería ser guesser ahora");
        $this->assertEquals('drawer', $rolesAfterTurn2[$player3Id] ?? null, "Player 3 debería ser drawer ahora");

        echo "\n✅ TEST PASSED: Roles rotan correctamente\n";
    }

    /** @test */
    public function it_includes_all_players_in_role_system()
    {
        $roles = $this->match->game_state['roles_system']['player_roles'] ?? [];

        echo "\n=== VERIFICANDO QUE TODOS LOS JUGADORES TIENEN ROL ===\n";
        echo "Número de jugadores: " . count($this->players) . "\n";
        echo "Número de roles asignados: " . count($roles) . "\n";
        echo "Roles: " . json_encode($roles, JSON_PRETTY_PRINT) . "\n";

        $this->assertCount(3, $roles, "Deberían haber 3 jugadores con roles asignados");

        foreach ($this->players as $player) {
            $this->assertArrayHasKey(
                $player->id,
                $roles,
                "Player {$player->id} ({$player->name}) debería tener un rol asignado"
            );
        }

        echo "\n✅ TEST PASSED: Todos los jugadores tienen rol asignado\n";
    }
}
