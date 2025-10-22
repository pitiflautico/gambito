<?php

namespace Tests\Unit\ConventionTests;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test que valida que los juegos cumplan con las convenciones establecidas.
 *
 * Basado en:
 * - docs/GAMES_CONVENTION.md
 * - docs/conventions/GAME_CONFIGURATION_CONVENTION.md
 * - docs/HOW_TO_CREATE_A_GAME.md
 *
 * Estos tests garantizan que:
 * 1. Todos los juegos tengan la estructura de archivos correcta
 * 2. Los config.json sigan el formato esperado
 * 3. Los capabilities.json tengan la estructura correcta
 * 4. Los Engine implementen GameEngineInterface
 * 5. Los módulos declarados en capabilities coincidan con los usados
 */
class GameConventionsTest extends TestCase
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
     * TEST 1: Cada juego debe tener los archivos requeridos
     */
    public function test_games_have_required_files(): void
    {
        $requiredFiles = [
            'config.json',
            'capabilities.json',
        ];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $path = $game['path'];

            foreach ($requiredFiles as $file) {
                $filePath = "{$path}/{$file}";
                $this->assertFileExists(
                    $filePath,
                    "El juego '{$slug}' debe tener el archivo '{$file}'"
                );
            }
        }
    }

    /**
     * TEST 2: Cada juego debe tener un Engine válido
     */
    public function test_games_have_valid_engine(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineClass = "Games\\{$className}\\{$className}Engine";
            $engineFile = "{$game['path']}/{$className}Engine.php";

            // Verificar que existe el archivo
            $this->assertFileExists(
                $engineFile,
                "El juego '{$slug}' debe tener un archivo Engine: {$className}Engine.php"
            );

            // Verificar que la clase existe
            $this->assertTrue(
                class_exists($engineClass),
                "La clase Engine '{$engineClass}' debe existir para el juego '{$slug}'"
            );

            // Verificar que implementa GameEngineInterface
            $this->assertContains(
                \App\Contracts\GameEngineInterface::class,
                class_implements($engineClass) ?: [],
                "La clase Engine '{$engineClass}' debe implementar GameEngineInterface"
            );
        }
    }

    /**
     * TEST 3: Cada juego debe tener un Controller válido
     */
    public function test_games_have_valid_controller(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $controllerClass = "Games\\{$className}\\{$className}Controller";
            $controllerFile = "{$game['path']}/{$className}Controller.php";

            // Verificar que existe el archivo
            $this->assertFileExists(
                $controllerFile,
                "El juego '{$slug}' debe tener un archivo Controller: {$className}Controller.php"
            );

            // Verificar que la clase existe
            $this->assertTrue(
                class_exists($controllerClass),
                "La clase Controller '{$controllerClass}' debe existir para el juego '{$slug}'"
            );

            // Verificar que extiende Controller
            $this->assertTrue(
                is_subclass_of($controllerClass, \App\Http\Controllers\Controller::class),
                "La clase Controller '{$controllerClass}' debe extender App\\Http\\Controllers\\Controller"
            );
        }
    }

    /**
     * TEST 4: config.json debe tener los campos requeridos
     */
    public function test_config_json_has_required_fields(): void
    {
        $requiredFields = [
            'id',
            'name',
            'slug',
            'description',
            'minPlayers',
            'maxPlayers',
            'estimatedDuration',
            'type',
            'isPremium',
            'version',
            'author',
        ];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $configPath = "{$game['path']}/config.json";
            $config = json_decode(File::get($configPath), true);

            $this->assertIsArray(
                $config,
                "El config.json del juego '{$slug}' debe ser JSON válido"
            );

            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $config,
                    "El config.json del juego '{$slug}' debe tener el campo '{$field}'"
                );
            }

            // Validar que slug coincida con el nombre de carpeta
            $this->assertEquals(
                $slug,
                $config['slug'],
                "El slug en config.json debe coincidir con el nombre de la carpeta"
            );
        }
    }

    /**
     * TEST 4: config.json debe usar camelCase en campos estándar
     */
    public function test_config_json_uses_camel_case(): void
    {
        $camelCaseFields = [
            'minPlayers',
            'maxPlayers',
            'estimatedDuration',
            'isPremium',
            'customizableSettings',
            'turnSystemConfig',
        ];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $configPath = "{$game['path']}/config.json";
            $config = json_decode(File::get($configPath), true);

            foreach ($camelCaseFields as $field) {
                if (isset($config[$field])) {
                    $this->assertArrayHasKey(
                        $field,
                        $config,
                        "El juego '{$slug}' usa el campo correcto '{$field}' en camelCase"
                    );
                }
            }

            // Verificar que NO existan versiones snake_case
            $snakeCaseVersions = [
                'min_players',
                'max_players',
                'estimated_duration',
                'estimated_duration_minutes',
                'is_premium',
                'customizable_settings',
                'turn_system_config',
            ];

            foreach ($snakeCaseVersions as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $config,
                    "El juego '{$slug}' NO debe usar '{$field}' (debe ser camelCase)"
                );
            }
        }
    }

    /**
     * TEST 5: capabilities.json debe tener la estructura correcta
     */
    public function test_capabilities_json_has_correct_structure(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $capabilitiesPath = "{$game['path']}/capabilities.json";
            $capabilities = json_decode(File::get($capabilitiesPath), true);

            $this->assertIsArray(
                $capabilities,
                "El capabilities.json del juego '{$slug}' debe ser JSON válido"
            );

            // Estructura requerida según Pictionary (el estándar)
            $this->assertArrayHasKey(
                'slug',
                $capabilities,
                "El capabilities.json del juego '{$slug}' debe tener 'slug'"
            );

            $this->assertArrayHasKey(
                'version',
                $capabilities,
                "El capabilities.json del juego '{$slug}' debe tener 'version'"
            );

            $this->assertArrayHasKey(
                'requires',
                $capabilities,
                "El capabilities.json del juego '{$slug}' debe tener 'requires'"
            );

            $this->assertArrayHasKey(
                'provides',
                $capabilities,
                "El capabilities.json del juego '{$slug}' debe tener 'provides'"
            );

            // Verificar estructura de requires
            $this->assertArrayHasKey(
                'modules',
                $capabilities['requires'],
                "El capabilities.json del juego '{$slug}' debe tener 'requires.modules'"
            );
        }
    }

    /**
     * TEST 6: capabilities.json no debe tener la estructura antigua
     */
    public function test_capabilities_json_not_using_old_structure(): void
    {
        // Estructura antigua (incorrecta) tenía capabilities como booleanos directos
        $oldStructureFields = [
            'guest_support',
            'spectator_mode',
            'real_time_sync',
            'pause_resume',
            'save_replay',
            'chat',
            'teams',
            'ai_players',
            'custom_rules',
            'leaderboard',
            'achievements',
            'player_actions',
            'game_phases',
            'events', // Sin estar bajo 'provides'
        ];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $capabilitiesPath = "{$game['path']}/capabilities.json";
            $capabilities = json_decode(File::get($capabilitiesPath), true);

            foreach ($oldStructureFields as $field) {
                if ($field === 'events' && isset($capabilities['provides']['events'])) {
                    // events bajo provides es correcto
                    continue;
                }

                $this->assertArrayNotHasKey(
                    $field,
                    $capabilities,
                    "El capabilities.json del juego '{$slug}' NO debe usar la estructura antigua con '{$field}' en la raíz. Debe usar requires/provides."
                );
            }
        }
    }

    /**
     * TEST 7: Si usa un módulo, debe declararlo en capabilities
     */
    public function test_declared_modules_match_usage(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);
            $capabilitiesPath = "{$game['path']}/capabilities.json";
            $capabilities = json_decode(File::get($capabilitiesPath), true);

            $declaredModules = $capabilities['requires']['modules'] ?? [];

            // Verificar módulos comunes
            $moduleMapping = [
                'turn_system' => ['TurnManager', 'TurnSystem'],
                'scoring_system' => ['ScoreManager', 'ScoreCalculator', 'ScoringSystem'],
                'timer_system' => ['TimerService', 'Timer'],
                'round_system' => ['RoundManager', 'RoundSystem'],
                'roles_system' => ['RoleManager', 'RolesSystem'],
            ];

            foreach ($moduleMapping as $moduleName => $classPatterns) {
                $usesModule = false;

                foreach ($classPatterns as $pattern) {
                    if (str_contains($engineCode, $pattern)) {
                        $usesModule = true;
                        break;
                    }
                }

                if ($usesModule) {
                    $this->assertArrayHasKey(
                        $moduleName,
                        $declaredModules,
                        "El juego '{$slug}' usa {$moduleMapping[$moduleName][0]} pero NO lo declara en capabilities.json under 'requires.modules.{$moduleName}'"
                    );
                }
            }
        }
    }

    /**
     * TEST 8: customizableSettings debe seguir el formato correcto
     */
    public function test_customizable_settings_follow_convention(): void
    {
        $validTypes = ['radio', 'select', 'number', 'checkbox'];

        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $configPath = "{$game['path']}/config.json";
            $config = json_decode(File::get($configPath), true);

            if (!isset($config['customizableSettings'])) {
                continue; // No tiene settings customizables
            }

            foreach ($config['customizableSettings'] as $key => $setting) {
                $this->assertArrayHasKey(
                    'type',
                    $setting,
                    "El setting '{$key}' del juego '{$slug}' debe tener 'type'"
                );

                $this->assertContains(
                    $setting['type'],
                    $validTypes,
                    "El setting '{$key}' del juego '{$slug}' tiene un type inválido: '{$setting['type']}'"
                );

                $this->assertArrayHasKey(
                    'label',
                    $setting,
                    "El setting '{$key}' del juego '{$slug}' debe tener 'label'"
                );

                $this->assertArrayHasKey(
                    'default',
                    $setting,
                    "El setting '{$key}' del juego '{$slug}' debe tener 'default'"
                );

                // Validaciones por tipo
                switch ($setting['type']) {
                    case 'number':
                        $this->assertArrayHasKey('min', $setting);
                        $this->assertArrayHasKey('max', $setting);
                        $this->assertArrayHasKey('step', $setting);
                        break;

                    case 'select':
                    case 'radio':
                        $this->assertArrayHasKey('options', $setting);
                        $this->assertIsArray($setting['options']);
                        $this->assertGreaterThanOrEqual(2, count($setting['options']));
                        break;

                    case 'checkbox':
                        $this->assertIsBool($setting['default']);
                        break;
                }
            }
        }
    }

    /**
     * TEST 9: turnSystemConfig debe estar presente si usa TurnManager
     */
    public function test_turn_system_config_present_if_using_turns(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $className = str_replace('-', '', ucwords($slug, '-'));
            $engineFile = "{$game['path']}/{$className}Engine.php";

            if (!File::exists($engineFile)) {
                continue;
            }

            $engineCode = File::get($engineFile);
            $usesTurnManager = str_contains($engineCode, 'TurnManager');

            $configPath = "{$game['path']}/config.json";
            $config = json_decode(File::get($configPath), true);

            if ($usesTurnManager) {
                $this->assertArrayHasKey(
                    'turnSystemConfig',
                    $config,
                    "El juego '{$slug}' usa TurnManager pero NO tiene 'turnSystemConfig' en config.json"
                );

                $this->assertArrayHasKey(
                    'mode',
                    $config['turnSystemConfig'],
                    "El juego '{$slug}' debe especificar 'turnSystemConfig.mode' (sequential/simultaneous/etc)"
                );
            }
        }
    }

    /**
     * TEST 10: No debe haber archivos JS en games/{slug}/js/
     */
    public function test_no_javascript_in_game_folder(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $jsPath = "{$game['path']}/js";

            $this->assertDirectoryDoesNotExist(
                $jsPath,
                "El juego '{$slug}' NO debe tener carpeta 'js/'. JavaScript debe estar en resources/js/{$slug}-*.js"
            );
        }
    }

    /**
     * TEST 11: Debe tener carpeta views/
     */
    public function test_has_views_folder(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $viewsPath = "{$game['path']}/views";

            $this->assertDirectoryExists(
                $viewsPath,
                "El juego '{$slug}' debe tener carpeta 'views/' para las vistas Blade"
            );
        }
    }

    /**
     * TEST 12: Debe tener carpeta Events/ si usa WebSockets
     */
    public function test_has_events_folder_if_using_websockets(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $capabilitiesPath = "{$game['path']}/capabilities.json";
            $capabilities = json_decode(File::get($capabilitiesPath), true);

            // Verificar si declara eventos
            $hasEvents = isset($capabilities['provides']['events']) &&
                        !empty($capabilities['provides']['events']);

            if ($hasEvents) {
                $eventsPath = "{$game['path']}/Events";

                $this->assertDirectoryExists(
                    $eventsPath,
                    "El juego '{$slug}' declara eventos en capabilities pero NO tiene carpeta 'Events/'"
                );

                // Verificar que los archivos de eventos existan
                foreach ($capabilities['provides']['events'] as $eventName) {
                    $eventFile = "{$eventsPath}/{$eventName}.php";

                    $this->assertFileExists(
                        $eventFile,
                        "El juego '{$slug}' declara el evento '{$eventName}' pero NO existe el archivo Events/{$eventName}.php"
                    );
                }
            }
        }
    }

    /**
     * TEST 13: Cada juego debe tener una ruta {slug}.game
     */
    public function test_games_have_required_game_route(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $routeName = "{$slug}.game";

            $this->assertTrue(
                \Route::has($routeName),
                "El juego '{$slug}' debe tener una ruta '{$routeName}' definida en routes.php. Esta ruta es necesaria para que RoomController::show() pueda redirigir a la vista del juego."
            );

            // Verificar que la ruta acepta roomCode como parámetro
            $route = \Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "La ruta '{$routeName}' existe pero no se puede obtener");

            // Verificar que tiene el parámetro roomCode
            $parameterNames = $route->parameterNames();
            $this->assertContains(
                'roomCode',
                $parameterNames,
                "La ruta '{$routeName}' debe aceptar el parámetro 'roomCode'"
            );
        }
    }

    /**
     * TEST 14: Las rutas del juego deben usar middleware correcto
     * - Rutas API deben usar middleware('api') para eximir CSRF
     * - Rutas Web deben usar middleware('web') para sesiones y CSRF
     */
    public function test_game_routes_use_correct_middleware(): void
    {
        foreach ($this->games as $game) {
            $slug = $game['slug'];
            $routesFile = "{$game['path']}/routes.php";

            if (!File::exists($routesFile)) {
                $this->fail("El juego '{$slug}' debe tener un archivo routes.php");
            }

            $routesCode = File::get($routesFile);

            // Verificar que las rutas API usan middleware('api')
            if (str_contains($routesCode, "prefix('api/")) {
                $this->assertTrue(
                    str_contains($routesCode, "->middleware('api')"),
                    "El juego '{$slug}' tiene rutas API pero NO usa ->middleware('api'). Las rutas API deben usar middleware('api') para eximir CSRF."
                );
            }

            // Verificar que las rutas web usan middleware('web')
            if (str_contains($routesCode, "prefix('{$slug}')")) {
                $this->assertTrue(
                    str_contains($routesCode, "->middleware('web')"),
                    "El juego '{$slug}' tiene rutas web pero NO usa ->middleware('web'). Las rutas web deben usar middleware('web') para sesiones y CSRF."
                );
            }
        }
    }
}
