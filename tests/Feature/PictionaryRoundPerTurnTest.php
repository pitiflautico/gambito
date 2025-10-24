<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Games\Pictionary\PictionaryEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Test del modo round-per-turn en Pictionary.
 *
 * En este modo, cada turno = una ronda completa.
 * Cuando alguien acierta, avanza a la siguiente ronda Y cambia de dibujante.
 */
class PictionaryRoundPerTurnTest extends TestCase
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

        // Limpiar config cache
        $this->artisan('config:clear');
        $this->artisan('cache:clear');

        // Crear usuario master
        $this->master = User::create([
            'name' => 'Master',
            'email' => 'master@test.com',
            'password' => bcrypt('password')
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

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => []
        ]);

        // Crear 3 jugadores conectados y asociarlos al match
        $this->players = [];
        for ($i = 1; $i <= 3; $i++) {
            $player = Player::create([
                'name' => "Player{$i}",
                'user_id' => null,
                'match_id' => $this->match->id,
                'is_connected' => true  // IMPORTANTE: deben estar conectados
            ]);
            $this->players[] = $player;
        }

        // Inicializar engine (obtiene los players del match automáticamente)
        $this->engine = new PictionaryEngine();
        $this->engine->initialize($this->match);
        $this->match->refresh();
    }

    /** @test */
    public function it_advances_round_on_every_turn_in_round_per_turn_mode()
    {
        echo "\n=== VERIFICANDO ROUND-PER-TURN MODE ===\n";
        echo "NOTA: En round-per-turn, cada turno = una ronda completa.\n";
        echo "      Cada dibujante dibuja = 1 ronda.\n\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];
        $player3 = $this->players[2];

        // =====================================================================
        // ESTADO INICIAL: Ronda 1, Player 1 es drawer
        // =====================================================================
        $initialState = $this->match->game_state;
        $initialRound = $initialState['round_system']['current_round'] ?? 1;
        $initialRoles = $initialState['roles_system']['player_roles'] ?? [];

        echo "📍 Estado inicial:\n";
        echo "  Ronda: $initialRound\n";
        echo "  Roles: " . json_encode($initialRoles) . "\n";

        $this->assertEquals(1, $initialRound, "Debe empezar en ronda 1");
        $this->assertCount(3, $initialRoles, "Debe haber 3 jugadores con roles");

        // =====================================================================
        // COMPORTAMIENTO ESPERADO: En round-per-turn, cada turno avanza ronda
        // =====================================================================
        echo "\n📝 COMPORTAMIENTO ESPERADO (round-per-turn):\n";
        echo "   En round-per-turn con 3 jugadores:\n";
        echo "   - Turno 0 (Player 1 dibuja): Ronda 1\n";
        echo "   - Turno 1 (Player 2 dibuja): Ronda 2 ← Avanza cada turno\n";
        echo "   - Turno 2 (Player 3 dibuja): Ronda 3 ← Avanza cada turno\n";
        echo "   - Turno 0 (Player 1 dibuja): Ronda 4 ← Avanza cada turno\n\n";

        // =====================================================================
        // TURNO 1: Player 2 acierta
        // Esperado: Ronda avanza a 2, roles rotan
        // =====================================================================
        echo "🎯 Turno 1: Player 2 adivina correctamente...\n";

        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();
        $afterTurn1 = $this->match->game_state;

        echo "📍 Después del Turno 1:\n";
        echo "  Ronda: " . ($afterTurn1['round_system']['current_round'] ?? 'unknown') . "\n";
        echo "  Roles: " . json_encode($afterTurn1['roles_system']['player_roles'] ?? []) . "\n";

        // En round-per-turn, la ronda DEBE avanzar en cada turno
        $this->assertEquals(2, $afterTurn1['round_system']['current_round'] ?? 0,
            "La ronda debe avanzar a 2 (round-per-turn mode)");

        // Los roles SÍ rotan
        $rolesAfterTurn1 = $afterTurn1['roles_system']['player_roles'] ?? [];
        $this->assertEquals('drawer', $rolesAfterTurn1[$player2->id] ?? null,
            "Player 2 debería ser drawer (roles rotan)");

        // =====================================================================
        // TURNO 2: Player 3 acierta
        // Esperado: Ronda avanza a 3
        // =====================================================================
        echo "\n🎯 Turno 2: Player 3 adivina correctamente...\n";

        $this->engine->processAction($this->match, $player3, 'answer', []);
        $this->engine->processAction($this->match, $player2, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player3->id
        ]);

        $this->match->refresh();
        $afterTurn2 = $this->match->game_state;

        echo "📍 Después del Turno 2:\n";
        echo "  Ronda: " . ($afterTurn2['round_system']['current_round'] ?? 'unknown') . "\n";
        echo "  Roles: " . json_encode($afterTurn2['roles_system']['player_roles'] ?? []) . "\n";

        // La ronda debe avanzar a 3
        $this->assertEquals(3, $afterTurn2['round_system']['current_round'] ?? 0,
            "La ronda debe avanzar a 3 (round-per-turn mode)");

        // Verificar que el drawer es Player 3
        $rolesAfterTurn2 = $afterTurn2['roles_system']['player_roles'] ?? [];
        $this->assertEquals('drawer', $rolesAfterTurn2[$player3->id] ?? null,
            "Player 3 debería ser drawer");

        echo "\n✅ ROUND-PER-TURN MODE FUNCIONANDO CORRECTAMENTE:\n";
        echo "   - Cada turno avanza la ronda (1 → 2 → 3) ✓\n";
        echo "   - Los roles rotan en cada turno ✓\n";
        echo "   - BaseGameEngine maneja round_per_turn correctamente ✓\n";
        echo "   - RoundManager::nextTurnWithRoundAdvance() funciona ✓\n\n";

        echo "📝 NOTA:\n";
        echo "   Este test verifica 2 avances de turno para demostrar el patrón.\n";
        echo "   Más turnos requerirían manejar delays asíncronos (scheduleNextRound).\n\n";
    }

    /** @test */
    public function it_rotates_drawer_each_turn()
    {
        echo "\n=== VERIFICANDO ROTACIÓN DE DRAWER EN CADA TURNO ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];
        $player3 = $this->players[2];

        // =====================================================================
        // VERIFICAR DRAWER INICIAL
        // =====================================================================
        $initialRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        $initialDrawer = null;
        foreach ($initialRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $initialDrawer = $playerId;
                break;
            }
        }

        echo "\n📍 Drawer inicial: Player $initialDrawer\n";
        $this->assertNotNull($initialDrawer, "Debe haber un drawer inicial");
        $this->assertEquals($player1->id, $initialDrawer, "Player 1 debería ser el primer drawer");

        // =====================================================================
        // TURNO 1: Alguien acierta
        // =====================================================================
        echo "🎯 Turno 1: Player 2 acierta...\n";

        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();

        // =====================================================================
        // VERIFICAR QUE EL DRAWER CAMBIÓ
        // =====================================================================
        $newRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        $newDrawer = null;
        foreach ($newRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $newDrawer = $playerId;
                break;
            }
        }

        echo "📍 Nuevo drawer después del turno 1: Player $newDrawer\n";

        $this->assertNotNull($newDrawer, "Debe haber un nuevo drawer");
        $this->assertNotEquals($initialDrawer, $newDrawer, "El drawer debe haber cambiado");
        $this->assertEquals($player2->id, $newDrawer, "Player 2 debería ser el nuevo drawer");

        // =====================================================================
        // TURNO 2: Otro jugador acierta
        // =====================================================================
        echo "🎯 Turno 2: Player 3 acierta...\n";

        $this->engine->processAction($this->match, $player3, 'answer', []);
        $this->engine->processAction($this->match, $player2, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player3->id
        ]);

        $this->match->refresh();

        // =====================================================================
        // VERIFICAR QUE EL DRAWER VOLVIÓ A CAMBIAR
        // =====================================================================
        $thirdRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        $thirdDrawer = null;
        foreach ($thirdRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $thirdDrawer = $playerId;
                break;
            }
        }

        echo "📍 Drawer después del turno 2: Player $thirdDrawer\n";

        $this->assertNotNull($thirdDrawer, "Debe haber un drawer");
        $this->assertEquals($player3->id, $thirdDrawer, "Player 3 debería ser el drawer");

        echo "\n✅ ROTACIÓN DE DRAWER: ¡Funciona correctamente!\n";
        echo "   Secuencia: Player 1 → Player 2 → Player 3\n";
        echo "   Los roles rotan en orden secuencial cada turno\n\n";
    }
}
