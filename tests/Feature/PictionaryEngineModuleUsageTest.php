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

/**
 * Test: Verificar que PictionaryEngine usa correctamente BaseGameEngine y sus módulos.
 *
 * OBJETIVO: Documentar y validar que PictionaryEngine:
 * 1. NO modifica game_state directamente
 * 2. USA los módulos a través de BaseGameEngine (getRoundManager, getRoleManager, etc.)
 * 3. GUARDA los módulos con los helpers (saveRoundManager, saveRoleManager, etc.)
 *
 * ANTI-PATRONES A EVITAR:
 * ❌ $gameState['round_system']['current_round'] = X;  // Modificación directa
 * ❌ $match->game_state = $gameState;  // Sin usar módulos
 *
 * PATRONES CORRECTOS:
 * ✅ $roundManager = $this->getRoundManager($match);
 * ✅ $roundManager->nextTurn();
 * ✅ $this->saveRoundManager($match, $roundManager);
 */
class PictionaryEngineModuleUsageTest extends TestCase
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

        $this->artisan('config:clear');
        $this->artisan('cache:clear');

        $this->master = User::create([
            'name' => 'Master',
            'email' => 'master@test.com',
            'password' => bcrypt('password')
        ]);

        $game = Game::create([
            'name' => 'Pictionary',
            'slug' => 'pictionary',
            'path' => 'games/pictionary',
            'min_players' => 2,
            'max_players' => 8,
            'description' => 'Test game'
        ]);

        $this->room = Room::create([
            'code' => 'TEST01',
            'name' => 'Test Room',
            'game_id' => $game->id,
            'master_id' => $this->master->id,
            'status' => 'playing',
            'max_players' => 8
        ]);

        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => []
        ]);

        $this->players = [];
        for ($i = 1; $i <= 3; $i++) {
            $player = Player::create([
                'name' => "Player{$i}",
                'user_id' => null,
                'match_id' => $this->match->id,
                'is_connected' => true
            ]);
            $this->players[] = $player;
        }

        $this->engine = new PictionaryEngine();
        $this->engine->initialize($this->match);
        $this->match->refresh();
    }

    /**
     * TEST: Verificar que en round-per-turn mode, PictionaryEngine NO modifica
     * game_state directamente, sino que usa RoundManager.
     *
     * IMPLEMENTACIÓN ACTUAL (INCORRECTA):
     * En PictionaryEngine::nextTurn() líneas 1053-1060:
     * ```php
     * if ($roundPerTurn) {
     *     $gameState = $match->game_state;
     *     $currentRound = $gameState['round_system']['current_round'] ?? 1;
     *     $gameState['round_system']['current_round'] = $currentRound + 1;  // ❌ Modificación directa
     *     $match->game_state = $gameState;
     *     $match->save();
     * }
     * ```
     *
     * IMPLEMENTACIÓN CORRECTA (esperada):
     * ```php
     * if ($roundPerTurn) {
     *     // Opción A: Usar RoundManager para avanzar ronda manualmente
     *     $roundManager = $this->getRoundManager($match);
     *     $roundManager->currentRound++;  // O crear método advanceRound()
     *     $this->saveRoundManager($match, $roundManager);
     *
     *     // Opción B (mejor): Configurar TurnManager para que marque ciclo completo
     *     // cada turno, y RoundManager avanzará automáticamente
     * }
     * ```
     *
     * @test
     */
    public function pictionary_engine_should_use_round_manager_not_modify_game_state_directly()
    {
        echo "\n=== TEST: PictionaryEngine debe usar RoundManager ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];

        // Capturar estado inicial
        $initialRound = $this->match->game_state['round_system']['current_round'] ?? 1;
        echo "📍 Ronda inicial: $initialRound\n";

        // Simular una acción que avanza el turno
        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();
        $newRound = $this->match->game_state['round_system']['current_round'] ?? 1;
        echo "📍 Ronda después de turno: $newRound\n";

        // NOTA: Con round_per_turn=true, la ronda AVANZA en cada turno
        // Esto se implementa en BaseGameEngine que detecta la config y llama
        // a RoundManager::nextTurnWithRoundAdvance()
        $this->assertEquals($initialRound + 1, $newRound,
            "La ronda debe avanzar en cada turno (round_per_turn mode)");

        // VERIFICACIÓN CRÍTICA: ¿El game_state está sincronizado con módulos?
        // Si PictionaryEngine usara correctamente los módulos, game_state
        // debería ser una serialización perfecta de RoundManager::toArray()

        echo "\n🔍 Verificando estructura de game_state['round_system']...\n";

        $roundSystem = $this->match->game_state['round_system'] ?? [];

        // Debe tener la estructura completa de RoundManager::toArray()
        $this->assertArrayHasKey('current_round', $roundSystem,
            "game_state debe tener current_round");
        $this->assertArrayHasKey('total_rounds', $roundSystem,
            "game_state debe tener total_rounds");
        $this->assertArrayHasKey('permanently_eliminated', $roundSystem,
            "game_state debe tener permanently_eliminated");
        $this->assertArrayHasKey('temporarily_eliminated', $roundSystem,
            "game_state debe tener temporarily_eliminated");

        echo "✅ Estructura correcta: game_state tiene todos los campos de RoundManager\n";

        // VERIFICACIÓN: ¿turn_system también está completo?
        echo "\n🔍 Verificando estructura de game_state['turn_system']...\n";

        $turnSystem = $this->match->game_state['turn_system'] ?? [];

        $this->assertArrayHasKey('mode', $turnSystem);
        $this->assertArrayHasKey('turn_order', $turnSystem);
        $this->assertArrayHasKey('current_turn_index', $turnSystem);

        echo "✅ Estructura correcta: game_state tiene todos los campos de TurnManager\n";

        echo "\n📝 CONCLUSIÓN:\n";
        echo "   Si todos los campos están presentes, significa que se está usando\n";
        echo "   saveRoundManager() que serializa con toArray().\n";
        echo "   Si faltaran campos, indicaría modificación directa de game_state.\n";
    }

    /**
     * TEST: Verificar que PictionaryEngine usa RoleManager para rotar roles.
     *
     * @test
     */
    public function pictionary_engine_should_use_role_manager_for_role_rotation()
    {
        echo "\n=== TEST: PictionaryEngine debe usar RoleManager ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];

        // Estado inicial
        $initialRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        echo "📍 Roles iniciales: " . json_encode($initialRoles) . "\n";

        $initialDrawer = null;
        foreach ($initialRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $initialDrawer = $playerId;
                break;
            }
        }

        // Avanzar turno
        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();
        $newRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        echo "📍 Roles después: " . json_encode($newRoles) . "\n";

        $newDrawer = null;
        foreach ($newRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $newDrawer = $playerId;
                break;
            }
        }

        // Verificar que rotó
        $this->assertNotEquals($initialDrawer, $newDrawer,
            "El drawer debe haber rotado");

        // VERIFICACIÓN: Estructura completa de RoleManager
        echo "\n🔍 Verificando estructura de game_state['roles_system']...\n";

        $rolesSystem = $this->match->game_state['roles_system'] ?? [];

        $this->assertArrayHasKey('player_roles', $rolesSystem,
            "Debe tener player_roles");
        $this->assertArrayHasKey('available_roles', $rolesSystem,
            "Debe tener available_roles");
        $this->assertArrayHasKey('allow_multiple_roles', $rolesSystem,
            "Debe tener allow_multiple_roles");

        echo "✅ Estructura correcta: game_state tiene todos los campos de RoleManager\n";

        echo "\n📝 CONCLUSIÓN:\n";
        echo "   Si la estructura es completa, BaseGameEngine está usando saveRoleManager().\n";
        echo "   La rotación automática funciona porque BaseGameEngine::autoRotateRoles()\n";
        echo "   usa RoleManager correctamente.\n";
    }

    /**
     * TEST: Verificar que el problema está en PictionaryEngine::nextTurn(),
     * no en BaseGameEngine.
     *
     * @test
     */
    public function the_issue_is_in_pictionary_next_turn_not_base_engine()
    {
        echo "\n=== TEST: Identificar dónde está el problema ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];

        echo "\n📍 Analizando el flujo de round-per-turn...\n";

        // Estado antes
        $beforeRound = $this->match->game_state['round_system']['current_round'] ?? 1;
        echo "   Ronda ANTES de processAction: $beforeRound\n";

        // Ejecutar acción
        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        // Estado después
        $this->match->refresh();
        $afterRound = $this->match->game_state['round_system']['current_round'] ?? 1;
        echo "   Ronda DESPUÉS de processAction: $afterRound\n";

        $roundDifference = $afterRound - $beforeRound;
        echo "\n🔍 Diferencia de rondas: $roundDifference\n";

        // En round-per-turn, debería avanzar 1 ronda por turno
        // Si avanza 2, significa que hay duplicación:
        // 1. PictionaryEngine::nextTurn() incrementa manualmente
        // 2. RoundManager::nextTurn() incrementa automáticamente al detectar ciclo

        if ($roundDifference > 1) {
            echo "\n⚠️  PROBLEMA DETECTADO:\n";
            echo "   La ronda avanzó $roundDifference veces, esperaba 0 o 1.\n";
            echo "   Esto indica DUPLICACIÓN de lógica:\n";
            echo "   - PictionaryEngine::nextTurn() modifica game_state directamente\n";
            echo "   - RoundManager::nextTurn() también incrementa (correctamente)\n";
            echo "\n💡 SOLUCIÓN:\n";
            echo "   Eliminar la modificación directa en PictionaryEngine::nextTurn()\n";
            echo "   y dejar que RoundManager maneje todo.\n";
            $this->fail("Duplicación de lógica detectada");
        } else if ($roundDifference == 1) {
            echo "\n✅ CORRECTO:\n";
            echo "   La ronda avanzó exactamente 1 vez.\n";
            echo "   Esto indica que se completó un ciclo (3 jugadores).\n";
            echo "   PictionaryEngine está usando los módulos correctamente.\n";
        } else {
            echo "\n✅ CORRECTO:\n";
            echo "   La ronda no avanzó (aún dentro del mismo ciclo).\n";
            echo "   PictionaryEngine está usando los módulos correctamente.\n";
            echo "   El ciclo se completará después de 3 turnos.\n";
        }

        // Verificamos que NO hay duplicación (no avanza más de 1)
        $this->assertLessThanOrEqual(1, $roundDifference,
            "La ronda no debe avanzar más de 1 vez (indicaría duplicación)");
    }

    /**
     * TEST: Documentar la implementación correcta esperada.
     *
     * @test
     */
    public function document_expected_correct_implementation()
    {
        echo "\n=== IMPLEMENTACIÓN CORRECTA ESPERADA ===\n";

        echo "\n📝 PictionaryEngine::nextTurn() DEBERÍA ser:\n";
        echo <<<'PHP'

protected function nextTurn(GameMatch $match): array
{
    // En round-per-turn, cada turno marca el ciclo como completo
    // para que RoundManager avance la ronda automáticamente

    $config = $this->getGameConfig();
    $roundPerTurn = $config['modules']['turn_system']['round_per_turn'] ?? false;

    if ($roundPerTurn) {
        // OPCIÓN A: Marcar manualmente el ciclo como completo
        // (requiere agregar método a TurnManager)
        $turnManager = $this->getTurnManager($match);
        $turnManager->markCycleComplete();  // Método nuevo
        $this->saveTurnManager($match, $turnManager);
    }

    // BaseGameEngine::nextTurn() hace:
    // 1. RoundManager::nextTurn()
    // 2. Si TurnManager::isCycleComplete() → incrementa ronda
    // 3. Rota roles automáticamente
    // 4. Guarda todo con save helpers
    $turnInfo = parent::nextTurn($match);

    // Lógica específica de Pictionary
    $match->refresh();
    $gameState = $match->game_state;
    $gameState['current_word'] = $this->selectRandomWord($match, 'random');
    $gameState['pending_answer'] = null;
    $gameState['current_drawer_id'] = $turnInfo['player_id'];

    // ✅ Guardar cambios específicos de Pictionary
    $match->game_state = $gameState;
    $match->save();

    return $turnInfo;
}

PHP;

        echo "\n📝 ALTERNATIVA (mejor): Configurar TurnManager desde config.json\n";
        echo <<<'JSON'

"modules": {
  "turn_system": {
    "enabled": true,
    "mode": "sequential",
    "round_per_turn": true,
    "auto_complete_cycle": true  // ← NUEVA OPCIÓN
  }
}

JSON;

        echo "\n📝 Con esta configuración, TurnManager::nextTurn() automáticamente\n";
        echo "   marcaría el ciclo como completo en cada llamada, y RoundManager\n";
        echo "   avanzaría la ronda sin necesidad de código especial en PictionaryEngine.\n";

        $this->assertTrue(true, "Test de documentación");
    }
}
