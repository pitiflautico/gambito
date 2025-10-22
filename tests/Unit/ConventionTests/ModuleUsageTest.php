<?php

namespace Tests\Unit\ConventionTests;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test que valida el uso correcto de módulos en los juegos.
 *
 * Verifica que:
 * 1. Los módulos declarados en capabilities.json se usan correctamente
 * 2. Los módulos se instancian con los parámetros correctos
 * 3. No hay uso incorrecto de constructores de módulos
 */
class ModuleUsageTest extends TestCase
{
    private string $gamesPath;
    private array $games;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gamesPath = base_path('games');
        $this->games = $this->discoverGames();
    }

    /**
     * Descubrir todos los juegos en la carpeta games/
     */
    private function discoverGames(): array
    {
        if (!File::isDirectory($this->gamesPath)) {
            return [];
        }

        $games = [];
        $directories = File::directories($this->gamesPath);

        foreach ($directories as $directory) {
            $slug = basename($directory);
            $games[] = [
                'slug' => $slug,
                'path' => $directory,
            ];
        }

        return $games;
    }

    /**
     * TEST 1: ScoreManager debe instanciarse con ScoreCalculatorInterface
     */
    public function test_score_manager_uses_calculator(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);

            // Si usa ScoreManager
            if (str_contains($engineCode, 'ScoreManager')) {
                // Verificar que NO use parámetros incorrectos
                $this->assertStringNotContainsString(
                    'allowNegativeScores:',
                    $engineCode,
                    "El juego '{$slug}' usa parámetro 'allowNegativeScores' que no existe en ScoreManager. Debe usar un ScoreCalculatorInterface."
                );

                // Verificar que use un Calculator
                if (preg_match('/new ScoreManager\s*\(/s', $engineCode)) {
                    $this->assertStringContainsString(
                        'calculator:',
                        $engineCode,
                        "El juego '{$slug}' instancia ScoreManager sin pasar un 'calculator'. Debe pasar una implementación de ScoreCalculatorInterface."
                    );

                    // Verificar que exista el Calculator del juego
                    $calculatorFile = "{$game['path']}/{$className}ScoreCalculator.php";
                    $this->assertFileExists(
                        $calculatorFile,
                        "El juego '{$slug}' debe tener su propio ScoreCalculator: {$className}ScoreCalculator.php"
                    );
                }
            }
        }
    }

    /**
     * TEST 2: Verificar que se use match->players en lugar de match->room->players
     */
    public function test_uses_match_players_correctly(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);

            // Verificar que NO use $match->room->players (incorrecto)
            $this->assertStringNotContainsString(
                '$match->room->players',
                $engineCode,
                "El juego '{$slug}' usa '\$match->room->players' que es incorrecto. Debe usar '\$match->players' (los players pertenecen al match, no al room)."
            );
        }
    }

    /**
     * TEST 3: Verificar que se usen queries correctos para matches activos
     */
    public function test_uses_correct_match_queries_in_controllers(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $controllerFile = "{$game['path']}/{$className}Controller.php";

            if (!File::exists($controllerFile)) {
                continue;
            }

            $controllerCode = File::get($controllerFile);

            // Verificar que NO use campo 'status' que no existe
            $this->assertStringNotContainsString(
                "where('status',",
                $controllerCode,
                "El juego '{$slug}' usa campo 'status' en GameMatch que NO EXISTE. Debe usar 'whereNotNull(\"started_at\")' y 'whereNull(\"finished_at\")'"
            );

            $this->assertStringNotContainsString(
                '->status',
                $controllerCode,
                "El juego '{$slug}' intenta acceder a campo 'status' en GameMatch que NO EXISTE. Debe usar 'started_at' y 'finished_at'"
            );
        }
    }

    /**
     * TEST 4: Módulos declarados en capabilities deben estar importados
     */
    public function test_declared_modules_are_imported(): void
    {
        $moduleClassMap = [
            'turn_system' => 'TurnManager',
            'scoring_system' => 'ScoreManager',
            'timer_system' => 'TimerService',
            'round_system' => 'RoundManager',
            'roles_system' => 'RoleManager',
        ];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";
            $capabilitiesFile = "{$game['path']}/capabilities.json";

            if (!File::exists($engineFile) || !File::exists($capabilitiesFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);
            $capabilities = json_decode(File::get($capabilitiesFile), true);
            $declaredModules = $capabilities['requires']['modules'] ?? [];

            foreach ($declaredModules as $moduleName => $version) {
                if (!isset($moduleClassMap[$moduleName])) {
                    continue; // Skip módulos no mapeados
                }

                $expectedClass = $moduleClassMap[$moduleName];

                // Verificar que el módulo esté importado
                $this->assertStringContainsString(
                    "use App\\Services\\Modules\\",
                    $engineCode,
                    "El juego '{$slug}' declara el módulo '{$moduleName}' pero no importa ningún módulo"
                );

                // Verificar que la clase específica esté importada O usada
                $hasImport = str_contains($engineCode, "use App\\Services\\Modules\\") &&
                             str_contains($engineCode, $expectedClass);

                $hasUsage = str_contains($engineCode, "new {$expectedClass}") ||
                            str_contains($engineCode, "{$expectedClass}::");

                $this->assertTrue(
                    $hasImport || $hasUsage,
                    "El juego '{$slug}' declara '{$moduleName}' en capabilities pero no importa/usa '{$expectedClass}'"
                );
            }
        }
    }

    /**
     * TEST 5: TurnManager debe usarse con modo correcto
     */
    public function test_turn_manager_uses_valid_mode(): void
    {
        $validModes = ['sequential', 'simultaneous', 'free'];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);

            // Si usa TurnManager
            if (str_contains($engineCode, 'new TurnManager')) {
                // Buscar el modo que está usando
                if (preg_match('/mode:\s*[\'"](\w+)[\'"]/s', $engineCode, $matches)) {
                    $mode = $matches[1];

                    $this->assertContains(
                        $mode,
                        $validModes,
                        "El juego '{$slug}' usa modo de turno '{$mode}' que no es válido. Modos válidos: " . implode(', ', $validModes)
                    );
                }
            }
        }
    }

    /**
     * TEST 6: fromArray debe usarse correctamente para reconstruir módulos
     *
     * Solo aplica si el módulo se instancia con 'new' en initialize()
     * y se accede en otros métodos que usan game_state
     */
    public function test_modules_reconstructed_with_from_array(): void
    {
        $moduleClasses = ['ScoreManager', 'RoundManager', 'TimerService'];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);

            foreach ($moduleClasses as $moduleClass) {
                // Verificar si se instancia en initialize()
                $hasNewInstance = str_contains($engineCode, "new {$moduleClass}");

                // Verificar si se usa en métodos que acceden a game_state
                $usesGameState = str_contains($engineCode, '$gameState =') ||
                                 str_contains($engineCode, 'game_state[');

                // Si se instancia Y se usa game_state, DEBE tener fromArray
                if ($hasNewInstance && $usesGameState) {
                    $this->assertStringContainsString(
                        "{$moduleClass}::fromArray",
                        $engineCode,
                        "El juego '{$slug}' instancia '{$moduleClass}' en initialize() y usa game_state en otros métodos, por lo tanto debe reconstruir el módulo con fromArray()"
                    );
                }
            }
        }
    }
}
