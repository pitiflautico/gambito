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
 * Test de CONVENCIÓN: Cómo usar correctamente BaseGameEngine y los módulos.
 *
 * Este test documenta las mejores prácticas y convenciones para:
 * 1. DENTRO del GameEngine: Usar módulos (getRoundManager, etc.)
 * 2. FUERA del GameEngine (tests, controllers): Solo usar API pública + observar game_state
 * 3. Modo round-per-turn debe usar TurnManager para detectar ciclos
 * 4. Los módulos son la ÚNICA fuente de verdad, game_state es su serialización
 *
 * CONVENCIONES ESTABLECIDAS:
 *
 * PARA GAME ENGINES (código interno de PictionaryEngine, etc.):
 * - ✅ Usar getRoundManager() / getRoleManager() / etc.
 * - ✅ Modificar módulos y guardar con saveRoundManager() / saveRoleManager()
 * - ❌ NUNCA modificar game_state directamente (ej: game_state['round_system']['current_round'] = X)
 *
 * PARA TESTS Y CÓDIGO EXTERNO:
 * - ✅ Llamar métodos públicos (processAction, initialize, etc.)
 * - ✅ Observar game_state para verificar comportamiento
 * - ❌ NO acceder a métodos protected (getRoundManager es protected por diseño)
 *
 * ARQUITECTURA:
 * - Módulos (RoundManager, etc.) = Source of truth (dentro del engine)
 * - game_state = Serialización de módulos (para persistencia y lectura externa)
 * - API pública = Única interfaz para código externo
 */
class ModularGameEngineConventionTest extends TestCase
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

        // Crear 3 jugadores conectados
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

        // Inicializar engine
        $this->engine = new PictionaryEngine();
        $this->engine->initialize($this->match);
        $this->match->refresh();
    }

    /**
     * CONVENCIÓN 1: Obtener módulos usando los helpers de BaseGameEngine
     *
     * @test
     */
    public function convention_1_always_use_module_getters_not_direct_game_state_access()
    {
        echo "\n=== CONVENCIÓN 1: Usar getters de módulos ===\n";

        // ✅ CORRECTO: Usar getRoundManager()
        $roundManager = $this->engine->getRoundManager($this->match);
        $currentRound = $roundManager->getCurrentRound();

        echo "✅ Usando getRoundManager(): Ronda actual = $currentRound\n";
        $this->assertEquals(1, $currentRound);

        // ✅ CORRECTO: Usar getRoleManager()
        $roleManager = $this->engine->getRoleManager($this->match);
        $roles = $roleManager->toArray()['player_roles'] ?? [];

        echo "✅ Usando getRoleManager(): " . count($roles) . " jugadores con roles\n";
        $this->assertCount(3, $roles);

        // ❌ INCORRECTO (no hacer esto):
        // $currentRound = $this->match->game_state['round_system']['current_round'];
        // $roles = $this->match->game_state['roles_system']['player_roles'];

        echo "\n✅ CONVENCIÓN CUMPLIDA: Siempre usar getters de módulos\n";
    }

    /**
     * CONVENCIÓN 2: Modificar módulos y guardar usando save helpers
     *
     * @test
     */
    public function convention_2_always_save_modules_after_modifications()
    {
        echo "\n=== CONVENCIÓN 2: Guardar módulos después de modificaciones ===\n";

        // ✅ CORRECTO: Obtener módulo -> Modificar -> Guardar
        $roundManager = $this->engine->getRoundManager($this->match);

        echo "Ronda antes de modificar: " . $roundManager->getCurrentRound() . "\n";

        // Simular avance de turno (esto lo hace nextTurn automáticamente)
        $roundManager->nextTurn();

        echo "Después de nextTurn(): Ronda = " . $roundManager->getCurrentRound() . "\n";

        // ✅ IMPORTANTE: Guardar el módulo modificado
        $this->engine->saveRoundManager($this->match, $roundManager);

        // Verificar que se guardó en BD
        $this->match->refresh();
        $savedRound = $this->match->game_state['round_system']['current_round'] ?? 0;

        echo "✅ Guardado en BD correctamente: Ronda = $savedRound\n";
        $this->assertEquals($roundManager->getCurrentRound(), $savedRound);

        // ❌ INCORRECTO (no hacer esto):
        // $this->match->game_state['round_system']['current_round'] = 2;
        // $this->match->save();

        echo "\n✅ CONVENCIÓN CUMPLIDA: Siempre guardar módulos con save helpers\n";
    }

    /**
     * CONVENCIÓN 3: Round-per-turn usando TurnManager, no manipulación manual
     *
     * Este test documenta cómo DEBERÍA implementarse round-per-turn:
     * - Configurar TurnManager para que cada turno complete un ciclo
     * - RoundManager detecta el ciclo y avanza la ronda automáticamente
     * - NO modificar current_round manualmente
     *
     * @test
     */
    public function convention_3_round_per_turn_should_use_turn_manager_cycle_completion()
    {
        echo "\n=== CONVENCIÓN 3: Round-per-turn usando módulos ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];
        $player3 = $this->players[2];

        // Estado inicial
        $roundManager = $this->engine->getRoundManager($this->match);
        $initialRound = $roundManager->getCurrentRound();

        echo "\n📍 Estado inicial: Ronda $initialRound\n";
        $this->assertEquals(1, $initialRound);

        // =====================================================================
        // CONCEPTO: En round-per-turn, cada turno debería marcar el ciclo como completo
        // para que RoundManager avance la ronda automáticamente
        // =====================================================================

        echo "\n🎯 Player 2 acierta (debería avanzar turno Y ronda)...\n";

        // Simular respuesta correcta
        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();

        // Verificar que la ronda avanzó
        $roundManager = $this->engine->getRoundManager($this->match);
        $newRound = $roundManager->getCurrentRound();

        echo "📍 Después del turno: Ronda $newRound\n";

        // En round-per-turn, cada respuesta correcta = nueva ronda
        $this->assertGreaterThan($initialRound, $newRound,
            "En round-per-turn mode, la ronda debe avanzar en cada turno");

        // =====================================================================
        // VERIFICAR QUE SE USA CORRECTAMENTE EL MÓDULO
        // =====================================================================

        echo "\n🔍 Verificando que se usó RoundManager correctamente...\n";

        // El game_state debe reflejar exactamente lo que dice el módulo
        $stateRound = $this->match->game_state['round_system']['current_round'] ?? 0;
        $moduleRound = $roundManager->getCurrentRound();

        echo "   - Ronda en game_state: $stateRound\n";
        echo "   - Ronda en RoundManager: $moduleRound\n";

        $this->assertEquals($moduleRound, $stateRound,
            "El game_state debe estar sincronizado con el módulo");

        // =====================================================================
        // SEGUNDA RONDA: Verificar consistencia
        // =====================================================================

        echo "\n🎯 Player 3 acierta (avanzando a ronda 3)...\n";

        $this->engine->processAction($this->match, $player3, 'answer', []);
        $this->engine->processAction($this->match, $player2, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player3->id
        ]);

        $this->match->refresh();
        $roundManager = $this->engine->getRoundManager($this->match);
        $finalRound = $roundManager->getCurrentRound();

        echo "📍 Después del segundo turno: Ronda $finalRound\n";

        $this->assertGreaterThan($newRound, $finalRound,
            "La ronda debe continuar avanzando en cada turno");

        echo "\n✅ CONVENCIÓN CUMPLIDA: Round-per-turn usa módulos correctamente\n";
    }

    /**
     * CONVENCIÓN 4: RoleManager maneja roles, BaseEngine coordina
     *
     * @test
     */
    public function convention_4_role_manager_handles_roles_base_engine_coordinates()
    {
        echo "\n=== CONVENCIÓN 4: RoleManager maneja roles ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];

        // ✅ CORRECTO: Obtener roles desde RoleManager
        $roleManager = $this->engine->getRoleManager($this->match);
        $roles = $roleManager->toArray()['player_roles'] ?? [];

        $initialDrawer = null;
        foreach ($roles as $playerId => $role) {
            if ($role === 'drawer') {
                $initialDrawer = $playerId;
                break;
            }
        }

        echo "📍 Drawer inicial: Player $initialDrawer\n";

        // Simular avance de turno con respuesta correcta
        $this->engine->processAction($this->match, $player2, 'answer', []);
        $this->engine->processAction($this->match, $player1, 'confirm_answer', [
            'is_correct' => true,
            'guesser_id' => $player2->id
        ]);

        $this->match->refresh();

        // ✅ CORRECTO: Verificar roles desde RoleManager
        $roleManager = $this->engine->getRoleManager($this->match);
        $newRoles = $roleManager->toArray()['player_roles'] ?? [];

        $newDrawer = null;
        foreach ($newRoles as $playerId => $role) {
            if ($role === 'drawer') {
                $newDrawer = $playerId;
                break;
            }
        }

        echo "📍 Nuevo drawer: Player $newDrawer\n";

        $this->assertNotEquals($initialDrawer, $newDrawer,
            "RoleManager debe haber rotado el drawer");

        // Verificar sincronización con game_state
        $stateRoles = $this->match->game_state['roles_system']['player_roles'] ?? [];
        $moduleRoles = $roleManager->toArray()['player_roles'] ?? [];

        $this->assertEquals($moduleRoles, $stateRoles,
            "Los roles en game_state deben estar sincronizados con RoleManager");

        echo "\n✅ CONVENCIÓN CUMPLIDA: RoleManager es la fuente de verdad para roles\n";
    }

    /**
     * CONVENCIÓN 5: BaseEngine helper methods vs direct module access
     *
     * @test
     */
    public function convention_5_use_base_engine_helpers_for_common_operations()
    {
        echo "\n=== CONVENCIÓN 5: Usar helpers de BaseEngine ===\n";

        // ✅ CORRECTO: Usar helpers de BaseEngine para operaciones comunes

        // Helper: getCurrentRound()
        $round = $this->engine->getCurrentRound($this->match->game_state);
        echo "✅ getCurrentRound(): $round\n";
        $this->assertEquals(1, $round);

        // Helper: getScores()
        $scores = $this->engine->getScores($this->match->game_state);
        echo "✅ getScores(): " . count($scores) . " jugadores\n";
        $this->assertCount(3, $scores);

        // Helper: getCurrentPlayer()
        $currentPlayer = $this->engine->getCurrentPlayer($this->match->game_state);
        echo "✅ getCurrentPlayer(): Player $currentPlayer\n";
        $this->assertNotNull($currentPlayer);

        echo "\n✅ CONVENCIÓN CUMPLIDA: Usar helpers simplifica el código\n";
    }

    /**
     * CONVENCIÓN 6: Flujo completo usando módulos correctamente
     *
     * @test
     */
    public function convention_6_complete_game_flow_using_modules_correctly()
    {
        echo "\n=== CONVENCIÓN 6: Flujo completo con módulos ===\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];
        $player3 = $this->players[2];

        echo "\n🎮 Simulando partida completa...\n";

        for ($i = 1; $i <= 3; $i++) {
            echo "\n--- Turno $i ---\n";

            // 1. Obtener estado actual usando módulos
            $roundManager = $this->engine->getRoundManager($this->match);
            $roleManager = $this->engine->getRoleManager($this->match);

            $round = $roundManager->getCurrentRound();
            $turnIndex = $roundManager->getCurrentTurnIndex();
            $currentDrawer = $roundManager->getCurrentPlayer();

            echo "Ronda: $round, Turno: $turnIndex, Drawer: Player $currentDrawer\n";

            // 2. Verificar sincronización con game_state
            $stateRound = $this->match->game_state['round_system']['current_round'] ?? 0;
            $this->assertEquals($round, $stateRound, "Round debe estar sincronizado");

            // 3. Simular acción del juego
            $guesser = $this->players[$i % 3];
            $drawer = $this->players[($i - 1) % 3];

            $this->engine->processAction($this->match, $guesser, 'answer', []);
            $this->engine->processAction($this->match, $drawer, 'confirm_answer', [
                'is_correct' => true,
                'guesser_id' => $guesser->id
            ]);

            $this->match->refresh();

            // 4. Verificar que módulos se actualizaron
            $roundManager = $this->engine->getRoundManager($this->match);
            $newRound = $roundManager->getCurrentRound();

            echo "Después de la acción: Ronda $newRound\n";

            // En round-per-turn, ronda debe avanzar
            $this->assertGreaterThanOrEqual($round, $newRound,
                "La ronda debe avanzar o mantenerse");
        }

        echo "\n✅ CONVENCIÓN CUMPLIDA: Flujo completo usando módulos correctamente\n";
    }
}
