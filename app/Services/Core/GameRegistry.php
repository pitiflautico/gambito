<?php

namespace App\Services\Core;

use App\Contracts\GameConfigInterface;
use App\Contracts\GameEngineInterface;
use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Servicio central para descubrir, validar y registrar módulos de juegos.
 *
 * Este servicio escanea la carpeta games/ en busca de módulos válidos,
 * valida su estructura y configuración, y los registra en la base de datos.
 */
class GameRegistry implements GameConfigInterface
{
    /**
     * Ruta base donde se encuentran los módulos de juegos.
     */
    protected string $gamesPath;

    /**
     * Configuración del sistema de juegos.
     */
    protected array $config;

    public function __construct()
    {
        $this->gamesPath = config('games.path');
        $this->config = config('games');
    }

    /**
     * Descubrir todos los módulos de juegos en la carpeta games/.
     *
     * Escanea la carpeta, valida cada módulo y retorna una lista
     * de juegos válidos con su configuración.
     *
     * @return array Array de módulos válidos con su configuración
     */
    public function discoverGames(): array
    {
        $discoveredGames = [];

        if (!File::isDirectory($this->gamesPath)) {
            Log::warning("Games directory does not exist: {$this->gamesPath}");
            return $discoveredGames;
        }

        $directories = File::directories($this->gamesPath);

        foreach ($directories as $directory) {
            $slug = basename($directory);
            $validation = $this->validateGameModule($slug);

            if ($validation['valid']) {
                $config = $this->loadGameConfig($slug);
                $capabilities = $this->loadGameCapabilities($slug);

                $discoveredGames[] = [
                    'slug' => $slug,
                    'path' => "games/{$slug}",
                    'config' => $config,
                    'capabilities' => $capabilities,
                ];

                Log::info("Discovered valid game module: {$slug}");
            } else {
                Log::warning("Invalid game module '{$slug}': " . implode(', ', $validation['errors']));
            }
        }

        return $discoveredGames;
    }

    /**
     * Validar la estructura completa de un módulo de juego.
     *
     * Verifica que el módulo tenga todos los archivos requeridos,
     * que la configuración sea válida y que implemente la interfaz correcta.
     *
     * @param string $slug Slug del juego (nombre de la carpeta)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateGameModule(string $slug): array
    {
        $errors = [];
        $modulePath = $this->gamesPath . '/' . $slug;

        // Verificar que la carpeta exista
        if (!File::isDirectory($modulePath)) {
            return ['valid' => false, 'errors' => ["Module directory does not exist: {$modulePath}"]];
        }

        // Verificar archivos requeridos
        foreach ($this->config['required_files'] as $requiredFile) {
            $filePath = $modulePath . '/' . $requiredFile;
            if (!File::exists($filePath)) {
                $errors[] = "Missing required file: {$requiredFile}";
            }
        }

        // Verificar que exista la clase Engine
        $engineClass = $this->getEngineClass($slug);
        if (!class_exists($engineClass)) {
            $errors[] = "Engine class does not exist: {$engineClass}";
        } else {
            // Verificar que implemente GameEngineInterface
            if (!in_array(GameEngineInterface::class, class_implements($engineClass))) {
                $errors[] = "Engine class must implement GameEngineInterface";
            }
        }

        // Validar config.json si existe
        if (File::exists($modulePath . '/config.json')) {
            $config = json_decode(File::get($modulePath . '/config.json'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Invalid JSON in config.json: " . json_last_error_msg();
            } else {
                $configValidation = $this->validateConfig($config);
                if (!$configValidation['valid']) {
                    $errors = array_merge($errors, $configValidation['errors']);
                }
            }
        }

        // Validar capabilities.json si existe
        if (File::exists($modulePath . '/capabilities.json')) {
            $capabilities = json_decode(File::get($modulePath . '/capabilities.json'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Invalid JSON in capabilities.json: " . json_last_error_msg();
            } else {
                $capabilitiesValidation = $this->validateCapabilities($capabilities);
                if (!$capabilitiesValidation['valid']) {
                    $errors = array_merge($errors, $capabilitiesValidation['errors']);
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Registrar o actualizar un juego en la base de datos.
     *
     * @param string $slug Slug del juego
     * @return Game|null Modelo del juego creado/actualizado, o null si falla
     */
    public function registerGame(string $slug): ?Game
    {
        $validation = $this->validateGameModule($slug);

        if (!$validation['valid']) {
            Log::error("Cannot register invalid game module '{$slug}': " . implode(', ', $validation['errors']));
            return null;
        }

        $config = $this->loadGameConfig($slug);
        $path = "games/{$slug}";

        // Crear o actualizar el juego en la base de datos
        $game = Game::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $config['name'],
                'description' => $config['description'],
                'path' => $path,
                'metadata' => $config, // Cachear toda la configuración
                'is_premium' => $config['isPremium'] ?? false,
                'is_active' => true,
            ]
        );

        Log::info("Registered game: {$slug}");

        return $game;
    }

    /**
     * Registrar todos los juegos descubiertos en la base de datos.
     *
     * @return array Array con estadísticas: ['registered' => int, 'failed' => int, 'games' => array]
     */
    public function registerAllGames(): array
    {
        $discoveredGames = $this->discoverGames();
        $stats = [
            'registered' => 0,
            'failed' => 0,
            'games' => [],
        ];

        foreach ($discoveredGames as $gameData) {
            $game = $this->registerGame($gameData['slug']);
            if ($game) {
                $stats['registered']++;
                $stats['games'][] = $gameData['slug'];
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Cargar la configuración de un juego desde config.json.
     *
     * @param string $slug Slug del juego
     * @return array Configuración del juego
     */
    public function loadGameConfig(string $slug): array
    {
        $configPath = $this->gamesPath . '/' . $slug . '/config.json';

        if (!File::exists($configPath)) {
            return [];
        }

        $config = json_decode(File::get($configPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Failed to parse config.json for '{$slug}': " . json_last_error_msg());
            return [];
        }

        return $config;
    }

    /**
     * Cargar las capacidades de un juego desde capabilities.json.
     *
     * @param string $slug Slug del juego
     * @return array Capacidades del juego
     */
    public function loadGameCapabilities(string $slug): array
    {
        $capabilitiesPath = $this->gamesPath . '/' . $slug . '/capabilities.json';

        if (!File::exists($capabilitiesPath)) {
            return [];
        }

        $capabilities = json_decode(File::get($capabilitiesPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Failed to parse capabilities.json for '{$slug}': " . json_last_error_msg());
            return [];
        }

        return $capabilities;
    }

    /**
     * Obtener una instancia del Engine de un juego.
     *
     * @param string $slug Slug del juego
     * @return GameEngineInterface|null Instancia del engine, o null si falla
     */
    public function getGameEngine(string $slug): ?GameEngineInterface
    {
        $engineClass = $this->getEngineClass($slug);

        if (!class_exists($engineClass)) {
            Log::error("Engine class does not exist: {$engineClass}");
            return null;
        }

        if (!in_array(GameEngineInterface::class, class_implements($engineClass))) {
            Log::error("Engine class does not implement GameEngineInterface: {$engineClass}");
            return null;
        }

        return app($engineClass);
    }

    /**
     * Obtener el nombre de la clase Engine de un juego.
     *
     * @param string $slug Slug del juego
     * @return string Nombre completo de la clase
     */
    protected function getEngineClass(string $slug): string
    {
        // Convertir slug a PascalCase (ej: pictionary -> Pictionary)
        $className = str_replace('-', '', ucwords($slug, '-'));

        return "Games\\{$className}\\{$className}Engine";
    }

    /**
     * Validar el archivo config.json de un juego.
     *
     * @param array $config Contenido del config.json parseado
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Verificar campos requeridos
        foreach ($this->getRequiredConfigFields() as $field) {
            if (!isset($config[$field])) {
                $errors[] = "Missing required config field: {$field}";
            }
        }

        // Validar que slug coincida con el nombre de la carpeta (se valida externamente)

        // Validar minPlayers y maxPlayers
        if (isset($config['minPlayers'])) {
            $min = $this->config['validation']['minPlayers']['min'];
            $max = $this->config['validation']['minPlayers']['max'];
            if ($config['minPlayers'] < $min || $config['minPlayers'] > $max) {
                $errors[] = "minPlayers must be between {$min} and {$max}";
            }
        }

        if (isset($config['maxPlayers'])) {
            $min = $this->config['validation']['maxPlayers']['min'];
            $max = $this->config['validation']['maxPlayers']['max'];
            if ($config['maxPlayers'] < $min || $config['maxPlayers'] > $max) {
                $errors[] = "maxPlayers must be between {$min} and {$max}";
            }
        }

        // Validar que maxPlayers >= minPlayers
        if (isset($config['minPlayers']) && isset($config['maxPlayers'])) {
            if ($config['maxPlayers'] < $config['minPlayers']) {
                $errors[] = "maxPlayers must be greater than or equal to minPlayers";
            }
        }

        // Validar formato de versión (semver)
        if (isset($config['version'])) {
            $versionPattern = $this->config['validation']['version']['format'];
            if (!preg_match($versionPattern, $config['version'])) {
                $errors[] = "version must follow semantic versioning format (e.g., 1.0 or 1.0.1)";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validar el archivo capabilities.json de un juego.
     *
     * @param array $capabilities Contenido del capabilities.json parseado
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateCapabilities(array $capabilities): array
    {
        $errors = [];

        // Verificar que tenga la estructura correcta
        if (!isset($capabilities['slug'])) {
            $errors[] = "Missing required field: slug";
        }

        if (!isset($capabilities['requires'])) {
            $errors[] = "Missing required field: requires";
        } else {
            // Verificar que todas las capabilities sean válidas
            foreach ($capabilities['requires'] as $capability => $required) {
                if (!in_array($capability, $this->getAvailableCapabilities())) {
                    $errors[] = "Invalid capability: {$capability}";
                }

                // Verificar que el valor sea booleano
                if (!is_bool($required)) {
                    $errors[] = "Capability '{$capability}' must be a boolean value";
                }
            }
        }

        // Verificar estructura de 'provides' si existe
        if (isset($capabilities['provides'])) {
            if (!is_array($capabilities['provides'])) {
                $errors[] = "'provides' must be an array";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Obtener los campos requeridos en config.json.
     *
     * @return array Lista de campos obligatorios
     */
    public function getRequiredConfigFields(): array
    {
        return $this->config['required_config_fields'];
    }

    /**
     * Obtener los servicios compartidos disponibles.
     *
     * @return array Lista de servicios que un juego puede requerir
     */
    public function getAvailableCapabilities(): array
    {
        return $this->config['available_capabilities'];
    }

    /**
     * Obtener todos los juegos activos desde la base de datos.
     *
     * @param bool $useCache Si debe usar caché o consultar directamente
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveGames(bool $useCache = true)
    {
        if (!$useCache || !$this->config['cache']['enabled']) {
            return Game::active()->get();
        }

        $cacheKey = $this->config['cache']['key_prefix'] . 'active_games';
        $cacheTtl = $this->config['cache']['ttl'];

        return Cache::remember($cacheKey, $cacheTtl, function () {
            return Game::active()->get();
        });
    }

    /**
     * Limpiar caché de un juego específico.
     *
     * @param string $slug Slug del juego
     * @return void
     */
    public function clearGameCache(string $slug): void
    {
        $cacheKey = $this->config['cache']['key_prefix'] . $slug;
        Cache::forget($cacheKey);

        // También limpiar caché de juegos activos
        Cache::forget($this->config['cache']['key_prefix'] . 'active_games');
    }

    /**
     * Limpiar todo el caché de juegos.
     *
     * @return void
     */
    public function clearAllCache(): void
    {
        Cache::flush();
        Log::info("Cleared all game cache");
    }
}
