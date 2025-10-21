<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class GameServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Cargar rutas de juegos dinámicamente
        $this->loadGameRoutes();

        // Registrar vistas de juegos con namespace
        $this->loadGameViews();
    }

    /**
     * Cargar rutas de todos los juegos automáticamente.
     */
    protected function loadGameRoutes(): void
    {
        $gamesPath = base_path('games');

        if (!File::isDirectory($gamesPath)) {
            return;
        }

        // Obtener todos los subdirectorios en games/
        $gameFolders = File::directories($gamesPath);

        foreach ($gameFolders as $gameFolder) {
            $routesFile = $gameFolder . '/routes.php';

            // Si el juego tiene un archivo routes.php, cargarlo
            if (File::exists($routesFile)) {
                $this->loadGameRouteFile($gameFolder, $routesFile);
            }
        }
    }

    /**
     * Cargar archivo de rutas de un juego específico.
     */
    protected function loadGameRouteFile(string $gameFolder, string $routesFile): void
    {
        // Obtener slug del juego (nombre de la carpeta)
        $gameSlug = basename($gameFolder);

        // Cargar configuración del juego si existe
        $configFile = $gameFolder . '/config.php';
        $config = File::exists($configFile) ? require $configFile : [];

        // Obtener middlewares de la configuración
        $middleware = $config['routes']['middleware'] ?? [];

        // Cargar el archivo de rutas
        // Nota: Las rutas dentro del archivo ya tienen sus propios prefijos y nombres
        require $routesFile;
    }

    /**
     * Registrar vistas de todos los juegos.
     */
    protected function loadGameViews(): void
    {
        $gamesPath = base_path('games');

        if (!File::isDirectory($gamesPath)) {
            return;
        }

        // Obtener todos los subdirectorios en games/
        $gameFolders = File::directories($gamesPath);

        foreach ($gameFolders as $gameFolder) {
            $viewsFolder = $gameFolder . '/views';

            // Si el juego tiene carpeta de vistas, registrarla
            if (File::isDirectory($viewsFolder)) {
                $gameSlug = basename($gameFolder);
                $this->loadViewsFrom($viewsFolder, $gameSlug);
            }
        }
    }
}
