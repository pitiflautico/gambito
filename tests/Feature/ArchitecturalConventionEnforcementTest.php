<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Test de Cumplimiento de Convenciones Arquitectónicas
 *
 * Este test verifica que los juegos NUNCA violen las convenciones establecidas:
 *
 * ❌ PROHIBIDO en game code:
 * - Llamar métodos de RoundManager directamente (nextRound, nextTurn, etc.)
 * - Emitir eventos genéricos (RoundStartedEvent, RoundEndedEvent, TurnTimeoutEvent)
 * - Crear instancias de módulos (TurnManager, TimerService, etc.)
 * - Avanzar manualmente rounds/turns
 *
 * ✅ PERMITIDO en game code:
 * - Extender BaseGameEngine
 * - Implementar métodos abstractos (initialize, startGame, startNewRound, etc.)
 * - Llamar métodos protected de BaseGameEngine (completeRound, finalize)
 * - Emitir eventos específicos del juego
 * - Lógica de negocio del juego (calcular puntos, validar respuestas, etc.)
 */
class ArchitecturalConventionEnforcementTest extends TestCase
{
    /**
     * Directorio base de juegos
     */
    protected string $gamesPath;

    /**
     * Lista de archivos Engine encontrados
     */
    protected array $engineFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gamesPath = base_path('games');
        $this->scanEngineFiles();
    }

    /**
     * Escanea todos los archivos *Engine.php en games/
     */
    protected function scanEngineFiles(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->gamesPath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Engine.php')) {
                $this->engineFiles[] = $file->getPathname();
            }
        }
    }

    /**
     * Test: Verificar que ningún juego llama a métodos de RoundManager directamente
     */
    public function test_games_never_call_round_manager_methods_directly(): void
    {
        $prohibitedMethods = [
            '->nextRound(',
            '->nextTurn(',
            '->advanceRound(',
            '->startRound(',
            '->endRound(',
            '->setCurrentRound(',
            '->eliminatePlayer(',
            '->nextTurnWithRoundAdvance(',
        ];

        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                foreach ($prohibitedMethods as $method) {
                    if (str_contains($line, $method)) {
                        // Ignorar comentarios
                        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                            continue;
                        }

                        $violations[] = [
                            'file' => basename($file),
                            'line' => $lineNumber + 1,
                            'method' => trim($method, '('),
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are calling RoundManager methods directly',
                $violations,
                'Games should NEVER call RoundManager methods. Use BaseGameEngine::completeRound() instead.'
            )
        );
    }

    /**
     * Test: Verificar que ningún juego emite eventos genéricos del sistema
     */
    public function test_games_never_emit_generic_events(): void
    {
        $prohibitedEvents = [
            'RoundStartedEvent',
            'RoundEndedEvent',
            'TurnStartedEvent',
            'TurnEndedEvent',
            'TurnTimeoutEvent',
            'GameStartingEvent',
            'GameStartedEvent',
            'GameEndedEvent',
        ];

        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                foreach ($prohibitedEvents as $event) {
                    // Buscar: event(new XxxEvent(...))
                    if (str_contains($line, "event(new $event") || str_contains($line, "dispatch(new $event")) {
                        // Ignorar comentarios
                        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                            continue;
                        }

                        $violations[] = [
                            'file' => basename($file),
                            'line' => $lineNumber + 1,
                            'event' => $event,
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are emitting generic system events',
                $violations,
                'Games should NEVER emit system events. These are emitted automatically by BaseGameEngine.'
            )
        );
    }

    /**
     * Test: Verificar que ningún juego crea instancias de módulos directamente
     */
    public function test_games_never_instantiate_modules_directly(): void
    {
        $prohibitedClasses = [
            'new RoundManager(',
            'new TurnManager(',
            'new TimerService(',
            'new ScoringManager(',
            'new TeamManager(',
        ];

        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                foreach ($prohibitedClasses as $class) {
                    if (str_contains($line, $class)) {
                        // Ignorar comentarios
                        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                            continue;
                        }

                        $violations[] = [
                            'file' => basename($file),
                            'line' => $lineNumber + 1,
                            'class' => trim($class, '('),
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are instantiating modules directly',
                $violations,
                'Games should NEVER create module instances. Modules are managed by BaseGameEngine.'
            )
        );
    }

    /**
     * Test: Verificar que todos los juegos extienden BaseGameEngine
     */
    public function test_all_games_extend_base_game_engine(): void
    {
        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);

            // Buscar: class XxxEngine extends ???
            if (preg_match('/class\s+\w+Engine\s+extends\s+(\w+)/', $content, $matches)) {
                $parentClass = $matches[1];

                if ($parentClass !== 'BaseGameEngine') {
                    $violations[] = [
                        'file' => basename($file),
                        'extends' => $parentClass,
                        'expected' => 'BaseGameEngine'
                    ];
                }
            } else {
                // No encontró extends
                $violations[] = [
                    'file' => basename($file),
                    'extends' => 'NONE',
                    'expected' => 'BaseGameEngine'
                ];
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are not extending BaseGameEngine',
                $violations,
                'All game engines MUST extend BaseGameEngine.'
            )
        );
    }

    /**
     * Test: Verificar que los juegos no llaman a save() en módulos
     */
    public function test_games_never_save_module_state_manually(): void
    {
        $prohibitedPatterns = [
            '$roundManager->save(',
            '$turnManager->save(',
            '$timerService->save(',
            '$scoringManager->save(',
        ];

        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                foreach ($prohibitedPatterns as $pattern) {
                    if (str_contains($line, $pattern)) {
                        // Ignorar comentarios
                        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                            continue;
                        }

                        $violations[] = [
                            'file' => basename($file),
                            'line' => $lineNumber + 1,
                            'pattern' => $pattern,
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are saving module state manually',
                $violations,
                'Games should NEVER save module state. Use $this->saveModules($match) instead.'
            )
        );
    }

    /**
     * Test: Verificar que los juegos no acceden a game_state de módulos directamente
     */
    public function test_games_use_module_methods_not_direct_state_access(): void
    {
        $prohibitedPatterns = [
            "\$gameState['round_system']",
            "\$gameState['turn_system']",
            "\$gameState['timer_system']",
            "\$gameState['scoring_system']",
        ];

        $violations = [];

        foreach ($this->engineFiles as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                foreach ($prohibitedPatterns as $pattern) {
                    if (str_contains($line, $pattern)) {
                        // Ignorar comentarios
                        if (preg_match('/^\s*\/\//', $line) || preg_match('/^\s*\*/', $line)) {
                            continue;
                        }

                        // Permitir acceso de SOLO LECTURA con ?? (null coalescing)
                        if (preg_match('/\$gameState\[.*?\]\s*\?\?/', $line)) {
                            continue;
                        }

                        $violations[] = [
                            'file' => basename($file),
                            'line' => $lineNumber + 1,
                            'pattern' => $pattern,
                            'code' => trim($line)
                        ];
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            $this->formatViolationMessage(
                'Games are accessing module state directly',
                $violations,
                'Games should use module methods (e.g., $this->roundManager->getCurrentRound()) instead of accessing game_state directly.'
            )
        );
    }

    /**
     * Formatea mensajes de violación de forma legible
     */
    protected function formatViolationMessage(string $title, array $violations, string $hint): string
    {
        if (empty($violations)) {
            return '';
        }

        $message = "\n\n❌ ARCHITECTURAL VIOLATION: $title\n";
        $message .= str_repeat('=', 80) . "\n\n";

        foreach ($violations as $violation) {
            $message .= "📁 File: {$violation['file']}\n";

            if (isset($violation['line'])) {
                $message .= "📍 Line: {$violation['line']}\n";
            }

            if (isset($violation['code'])) {
                $message .= "💥 Code: {$violation['code']}\n";
            }

            if (isset($violation['method'])) {
                $message .= "🚫 Method: {$violation['method']}\n";
            }

            if (isset($violation['event'])) {
                $message .= "🚫 Event: {$violation['event']}\n";
            }

            if (isset($violation['class'])) {
                $message .= "🚫 Class: {$violation['class']}\n";
            }

            if (isset($violation['extends'])) {
                $message .= "🚫 Extends: {$violation['extends']} (expected: {$violation['expected']})\n";
            }

            $message .= "\n";
        }

        $message .= str_repeat('=', 80) . "\n";
        $message .= "💡 HINT: $hint\n";
        $message .= str_repeat('=', 80) . "\n";

        return $message;
    }

    /**
     * Test de ejemplo: Verificar que TriviaEngine cumple todas las convenciones
     */
    public function test_trivia_engine_follows_all_conventions(): void
    {
        $triviaEngine = base_path('games/trivia/TriviaEngine.php');

        $this->assertFileExists($triviaEngine);

        $content = file_get_contents($triviaEngine);

        // Debe extender BaseGameEngine
        $this->assertStringContainsString('extends BaseGameEngine', $content);

        // NO debe emitir RoundStartedEvent
        $this->assertStringNotContainsString('event(new RoundStartedEvent', $content);

        // NO debe emitir RoundEndedEvent
        $this->assertStringNotContainsString('event(new RoundEndedEvent', $content);

        // NO debe llamar a nextRound
        $this->assertStringNotContainsString('->nextRound(', $content);

        // NO debe llamar a nextTurn
        $this->assertStringNotContainsString('->nextTurn(', $content);

        // DEBE llamar a completeRound (patrón correcto)
        $this->assertStringContainsString('$this->completeRound(', $content);
    }
}
