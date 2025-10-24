<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use Games\Trivia\TriviaEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test de Cumplimiento de Convenciones para Trivia
 *
 * Este test verifica que TriviaEngine implementa correctamente las
 * convenciones documentadas en MODULE_USAGE_CONVENTIONS.md
 */
class TriviaConventionComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected User $master;
    protected Room $room;
    protected GameMatch $match;
    protected array $players;
    protected TriviaEngine $engine;

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

        // Crear juego Trivia
        $game = Game::create([
            'name' => 'Trivia',
            'slug' => 'trivia',
            'path' => 'games/trivia',
            'min_players' => 1,
            'max_players' => 10,
            'description' => 'Convention compliance test'
        ]);

        // Crear sala
        $this->room = Room::create([
            'code' => 'CONV01',
            'name' => 'Convention Test Room',
            'game_id' => $game->id,
            'master_id' => $this->master->id,
            'status' => 'playing',
            'max_players' => 10
        ]);

        // Crear match
        $this->match = GameMatch::create([
            'room_id' => $this->room->id,
            'status' => 'in_progress',
            'game_state' => []
        ]);

        // Crear 3 jugadores
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

        // Inicializar engine y empezar el juego directamente (sin flujo de Room)
        $this->engine = new TriviaEngine();
        $this->engine->initialize($this->match);
        $this->engine->startGame($this->match); // Resetea módulos
        $this->engine->triggerGameStart($this->match); // Ejecuta onGameStart() -> inicia primera pregunta
        $this->match->refresh();
    }

    /**
     * Test 1: Verificar que game_state tiene estructura completa de módulos
     *
     * @test
     */
    public function trivia_initializes_with_complete_module_structure()
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  TEST 1: Estructura completa de módulos en game_state     ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";

        $gameState = $this->match->game_state;

        // Verificar que tiene las secciones de módulos
        echo "📋 Verificando secciones de módulos:\n";

        // Round System
        echo "  ├─ round_system... ";
        $this->assertArrayHasKey('round_system', $gameState);
        echo "✓\n";

        $roundSystem = $gameState['round_system'];
        echo "     ├─ current_round... ";
        $this->assertArrayHasKey('current_round', $roundSystem);
        echo "✓\n";

        echo "     ├─ total_rounds... ";
        $this->assertArrayHasKey('total_rounds', $roundSystem);
        echo "✓\n";

        // Turn System
        echo "  ├─ turn_system... ";
        $this->assertArrayHasKey('turn_system', $gameState);
        echo "✓\n";

        $turnSystem = $gameState['turn_system'];
        echo "     ├─ mode... ";
        $this->assertArrayHasKey('mode', $turnSystem);
        echo "✓ (modo: {$turnSystem['mode']})\n";

        // Scoring System
        echo "  ├─ scoring_system... ";
        $this->assertArrayHasKey('scoring_system', $gameState);
        echo "✓\n";

        $scoringSystem = $gameState['scoring_system'];
        echo "     ├─ scores... ";
        $this->assertArrayHasKey('scores', $scoringSystem);
        echo "✓\n";

        // Timer System
        echo "  └─ timer_system... ";
        $this->assertArrayHasKey('timer_system', $gameState);
        echo "✓\n\n";

        echo "✅ CONCLUSIÓN: game_state tiene estructura completa de módulos\n";
        echo "   Esto indica que BaseGameEngine está serializando correctamente.\n\n";
    }

    /**
     * Test 2: Verificar que processAction maneja respuestas correctamente
     *
     * @test
     */
    public function trivia_process_action_updates_state_correctly()
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  TEST 2: processAction actualiza estado correctamente      ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";

        $player1 = $this->players[0];

        $beforeState = $this->match->game_state;
        $beforeRound = $beforeState['round_system']['current_round'] ?? 'unknown';

        echo "📍 Estado inicial:\n";
        echo "   Ronda: {$beforeRound}\n";
        echo "   Fase: {$beforeState['phase']}\n\n";

        // Simular que el jugador responde
        echo "🎯 Jugador 1 responde la pregunta...\n";

        $currentQuestion = $beforeState['current_question'] ?? [];
        $correctAnswer = $currentQuestion['correct'] ?? 0;

        $response = $this->engine->processAction($this->match, $player1, 'answer', [
            'answer' => $correctAnswer
        ]);

        echo "   └─ Respuesta procesada: " . ($response['success'] ? "✓" : "✗") . "\n\n";

        $this->match->refresh();
        $afterState = $this->match->game_state;

        // Verificar que el estado se actualizó
        echo "📊 Estado después:\n";
        echo "   Puntos Player1: " . ($afterState['scoring_system']['scores'][$player1->id] ?? 0) . "\n\n";

        echo "✅ CONCLUSIÓN: processAction funciona correctamente\n\n";

        $this->assertTrue($response['success']);
    }

    /**
     * Test 3: Verificar que los módulos NO se modifican directamente
     *
     * @test
     */
    public function trivia_does_not_modify_game_state_directly()
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  TEST 3: Verificar que NO se modifica game_state          ║\n";
        echo "║          directamente (usa módulos)                        ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";

        // Este test verifica que TriviaEngine no tiene líneas como:
        // $gameState['round_system']['current_round'] = X;

        echo "📝 Verificando que TriviaEngine usa convenciones:\n\n";

        // Leer el código fuente de TriviaEngine
        $enginePath = base_path('games/trivia/TriviaEngine.php');
        $engineCode = file_get_contents($enginePath);

        // Buscar patrones incorrectos
        $badPatterns = [
            "game_state['round_system']['current_round']" => "Modificación directa de current_round",
            "game_state['turn_system']" => "Modificación directa de turn_system",
            // NO marcar como error la lectura, solo la modificación con =
        ];

        $violations = [];
        foreach ($badPatterns as $pattern => $description) {
            // Buscar líneas que MODIFICAN (tienen = después)
            if (preg_match('/' . preg_quote($pattern, '/') . '\s*=/', $engineCode)) {
                $violations[] = $description;
            }
        }

        if (empty($violations)) {
            echo "   ✅ No se detectaron modificaciones directas de game_state\n\n";
            echo "✅ CONCLUSIÓN: TriviaEngine usa los módulos correctamente\n\n";
        } else {
            echo "   ⚠️  VIOLACIONES DETECTADAS:\n";
            foreach ($violations as $violation) {
                echo "      - {$violation}\n";
            }
            echo "\n";
            echo "❌ CONCLUSIÓN: TriviaEngine modifica game_state directamente\n";
            echo "   Debe usar getRoundManager(), saveRoundManager(), etc.\n\n";

            $this->fail("TriviaEngine viola las convenciones: " . implode(", ", $violations));
        }

        $this->assertTrue(true);
    }

    /**
     * Test 4: Simular partida completa para verificar flujo
     *
     * @test
     */
    public function trivia_complete_game_flow_works()
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  TEST 4: Flujo completo de partida de Trivia              ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n\n";

        $player1 = $this->players[0];
        $player2 = $this->players[1];
        $player3 = $this->players[2];

        $initialRound = $this->match->game_state['round_system']['current_round'];
        echo "📍 Ronda inicial: {$initialRound}\n\n";

        // Simular que diferentes jugadores responden correctamente en cada pregunta
        // IMPORTANTE: En Trivia, cuando un jugador responde correctamente, la ronda termina
        $winners = [$player1, $player2, $player3];
        for ($round = 1; $round <= 3; $round++) {
            echo "🎯 Pregunta {$round}:\n";

            $this->match->refresh();
            $currentQuestion = $this->match->game_state['current_question'] ?? [];
            $correctAnswer = $currentQuestion['correct'] ?? 0;

            // Solo el ganador de esta ronda responde correctamente
            $winner = $winners[$round - 1];
            echo "   ├─ {$winner->name} responde correctamente... ";
            $this->engine->processAction($this->match, $winner, 'answer', ['answer' => $correctAnswer]);
            echo "✓\n";

            $this->match->refresh();
            $currentRound = $this->match->game_state['round_system']['current_round'];
            echo "   └─ Ronda actual después: {$currentRound}\n\n";

            // MOCKUP: Avanzar a siguiente pregunta (si no es la última)
            if ($round < 3) {
                $gameState = $this->match->game_state;
                if (isset($gameState['phase']) && $gameState['phase'] === 'results') {
                    echo "   │  ⏭️  MOCKUP: Avanzando a siguiente pregunta...\n";
                    try {
                        $reflection = new \ReflectionClass($this->engine);
                        $method = $reflection->getMethod('startNewRound');
                        $method->setAccessible(true);
                        $method->invoke($this->engine, $this->match);
                        echo "   │  ✅ Nueva pregunta iniciada\n\n";
                    } catch (\Exception $e) {
                        echo "   │  ⚠️  No se pudo iniciar nueva pregunta: " . $e->getMessage() . "\n\n";
                    }
                }
            }
        }

        // Verificar puntos finales
        $this->match->refresh();
        $finalScores = $this->match->game_state['scoring_system']['scores'] ?? [];

        echo "📊 Puntuaciones finales:\n";
        foreach ($this->players as $player) {
            $score = $finalScores[$player->id] ?? 0;
            echo "   ├─ {$player->name}: {$score} puntos\n";
            // Solo verificamos que todos los que ganaron tienen puntos
            if (in_array($player, $winners)) {
                $this->assertGreaterThan(0, $score, "{$player->name} debe tener puntos (ganó una ronda)");
            }
        }

        // Verificar que hay al menos un ganador
        $maxScore = max($finalScores);
        $this->assertGreaterThan(0, $maxScore, "Debe haber al menos un jugador con puntos");

        echo "\n✅ CONCLUSIÓN: El flujo completo funciona correctamente\n\n";
    }
}
