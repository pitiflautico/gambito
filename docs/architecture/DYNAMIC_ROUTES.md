# Sistema de Rutas Dinámicas

## 📋 Índice

- [Visión General](#visión-general)
- [Arquitectura](#arquitectura)
- [Convención de Rutas](#convención-de-rutas)
- [Configuración de Middlewares](#configuración-de-middlewares)
- [GameServiceProvider](#gameserviceprovider)
- [Ejemplos Prácticos](#ejemplos-prácticos)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## Visión General

El sistema de rutas dinámicas permite que cada juego sea completamente autónomo y self-contained, cargando sus propias rutas automáticamente sin necesidad de modificar los archivos de rutas principales de Laravel (`routes/web.php`, `routes/api.php`).

### Beneficios

✅ **Modularidad:** Cada juego mantiene sus propias rutas
✅ **Plug-and-play:** Agregar un juego nuevo no requiere tocar archivos core
✅ **Desacoplamiento:** Los juegos no dependen de la plataforma para sus rutas
✅ **Fácil mantenimiento:** Rutas del juego junto al código del juego
✅ **Configuración flexible:** Cada juego puede declarar sus propios middlewares

## Arquitectura

### Flujo de Carga de Rutas

```
1. Laravel inicia
2. GameServiceProvider::boot() se ejecuta
3. loadGameRoutes() escanea games/
4. Para cada juego con routes.php:
   a. Lee config.php (si existe)
   b. Obtiene middlewares configurados
   c. require routes.php (carga las rutas)
5. Las rutas están disponibles globalmente
```

### Diagrama de Estructura

```
games/
└── {game-slug}/
    ├── routes.php           ← Define rutas API y Web del juego
    ├── config.php           ← Configuración incluyendo middlewares
    ├── {Game}Engine.php
    ├── Events/
    └── views/

app/Providers/
└── GameServiceProvider.php  ← Auto-carga routes.php de todos los juegos
```

## Convención de Rutas

### Ubicación

**Archivo:** `games/{game-slug}/routes.php`

### Estructura Estándar

```php
<?php

use App\Http\Controllers\{GameName}Controller;
use Illuminate\Support\Facades\Route;

// ========================================
// API ROUTES
// ========================================
Route::prefix('api/{game-slug}')->name('api.{game-slug}.')->group(function () {
    // Endpoints del juego
    Route::post('/action-name', [{GameName}Controller::class, 'methodName'])
        ->name('action-name');

    // Más endpoints...
});

// ========================================
// WEB ROUTES
// ========================================
Route::prefix('{game-slug}')->name('{game-slug}.')->group(function () {
    // Páginas del juego
    Route::get('/page-name', [{GameName}Controller::class, 'methodName'])
        ->name('page-name');

    // Más páginas...
});
```

### Convenciones de Nombres

| Tipo | Prefijo | Nombre | Ejemplo Completo |
|------|---------|--------|------------------|
| API Routes | `api/{slug}` | `api.{slug}.action` | `api.pictionary.draw` |
| Web Routes | `{slug}` | `{slug}.page` | `pictionary.demo` |

### Ejemplos de URIs

```
API:
- POST /api/pictionary/draw              → api.pictionary.draw
- POST /api/trivia/submit-answer         → api.trivia.submit-answer
- GET  /api/uno/current-card             → api.uno.current-card

Web:
- GET /pictionary/demo                   → pictionary.demo
- GET /trivia/leaderboard                → trivia.leaderboard
- GET /uno/rules                         → uno.rules
```

## Configuración de Middlewares

### Declaración en config.php

```php
// games/{slug}/config.php

return [
    'routes' => [
        // Middlewares para TODAS las rutas del juego
        'middleware' => [
            'throttle:60,1',  // Rate limiting
        ],

        // Middlewares solo para rutas API
        'api_middleware' => [
            'auth:sanctum',   // Autenticación API
        ],

        // Middlewares solo para rutas Web
        'web_middleware' => [
            'verified',       // Email verificado
        ],
    ],

    // Resto de configuración...
];
```

### Aplicación de Middlewares

**Nota:** Actualmente el sistema lee la configuración pero **NO aplica automáticamente** los middlewares declarados. Los middlewares deben aplicarse manualmente en `routes.php`:

```php
// Aplicar middlewares dentro del archivo routes.php
Route::prefix('api/pictionary')->middleware(['throttle:60,1'])->group(function () {
    // Rutas con rate limiting
});
```

**Roadmap:** La aplicación automática de middlewares está planeada para una versión futura.

## GameServiceProvider

### Implementación Actual

**Ubicación:** `app/Providers/GameServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class GameServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Cargar rutas de juegos dinámicamente
        $this->loadGameRoutes();

        // Registrar vistas de juegos con namespace
        $this->loadGameViews();
    }

    /**
     * Escanea games/ y carga routes.php de cada juego.
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
     * Carga el archivo de rutas de un juego específico.
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
        require $routesFile;
    }

    /**
     * Registra las vistas de todos los juegos con namespace.
     */
    protected function loadGameViews(): void
    {
        $gamesPath = base_path('games');

        if (!File::isDirectory($gamesPath)) {
            return;
        }

        $gameFolders = File::directories($gamesPath);

        foreach ($gameFolders as $gameFolder) {
            $viewsFolder = $gameFolder . '/views';

            if (File::isDirectory($viewsFolder)) {
                $gameSlug = basename($gameFolder);
                $this->loadViewsFrom($viewsFolder, $gameSlug);
            }
        }
    }
}
```

### Registro del Provider

El `GameServiceProvider` debe estar registrado en `config/app.php`:

```php
'providers' => ServiceProvider::defaultProviders()->merge([
    // ...
    App\Providers\GameServiceProvider::class,
])->toArray(),
```

## Ejemplos Prácticos

### Ejemplo 1: Pictionary (Completo)

**games/pictionary/routes.php**

```php
<?php

use App\Http\Controllers\PictionaryController;
use Illuminate\Support\Facades\Route;

// ========================================
// API ROUTES - Endpoints del juego
// ========================================
Route::prefix('api/pictionary')->name('api.pictionary.')->group(function () {
    // Canvas - Dibujo en tiempo real
    Route::post('/draw', [PictionaryController::class, 'broadcastDraw'])
        ->name('draw');

    Route::post('/clear', [PictionaryController::class, 'broadcastClear'])
        ->name('clear');

    // Gameplay - Mecánicas del juego
    Route::post('/player-answered', [PictionaryController::class, 'playerAnswered'])
        ->name('player-answered');

    Route::post('/confirm-answer', [PictionaryController::class, 'confirmAnswer'])
        ->name('confirm-answer');

    Route::post('/advance-phase', [PictionaryController::class, 'advancePhase'])
        ->name('advance-phase');

    Route::post('/get-word', [PictionaryController::class, 'getWord'])
        ->name('get-word');
});

// ========================================
// WEB ROUTES - Páginas del juego
// ========================================
Route::prefix('pictionary')->name('pictionary.')->group(function () {
    // Demo/Testing
    Route::get('/demo', [PictionaryController::class, 'demo'])
        ->name('demo');
});
```

### Ejemplo 2: Trivia (Hipotético)

**games/trivia/routes.php**

```php
<?php

use App\Http\Controllers\TriviaController;
use Illuminate\Support\Facades\Route;

// API Routes
Route::prefix('api/trivia')->name('api.trivia.')->group(function () {
    Route::post('/submit-answer', [TriviaController::class, 'submitAnswer'])
        ->name('submit-answer');

    Route::get('/next-question', [TriviaController::class, 'nextQuestion'])
        ->name('next-question');

    Route::get('/leaderboard', [TriviaController::class, 'leaderboard'])
        ->name('leaderboard');
});

// Web Routes
Route::prefix('trivia')->name('trivia.')->group(function () {
    Route::get('/game', [TriviaController::class, 'game'])
        ->name('game');

    Route::get('/results', [TriviaController::class, 'results'])
        ->name('results');
});
```

### Ejemplo 3: Con Middlewares Personalizados

**games/uno/routes.php**

```php
<?php

use App\Http\Controllers\UnoController;
use Illuminate\Support\Facades\Route;

// API Routes con rate limiting agresivo
Route::prefix('api/uno')
    ->middleware(['throttle:120,1']) // 120 requests por minuto
    ->name('api.uno.')
    ->group(function () {
        Route::post('/play-card', [UnoController::class, 'playCard'])
            ->name('play-card');

        Route::post('/draw-card', [UnoController::class, 'drawCard'])
            ->name('draw-card');

        Route::post('/say-uno', [UnoController::class, 'sayUno'])
            ->name('say-uno');
    });

// Web Routes protegidas
Route::prefix('uno')
    ->middleware(['auth']) // Requiere autenticación
    ->name('uno.')
    ->group(function () {
        Route::get('/game', [UnoController::class, 'game'])
            ->name('game');
    });
```

## Testing

### Test de Rutas Dinámicas

**Ubicación:** `tests/Unit/Providers/GameServiceProviderTest.php`

```php
<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GameServiceProviderTest extends TestCase
{
    /** @test */
    public function test_pictionary_routes_are_loaded()
    {
        $routeNames = collect(Route::getRoutes())
            ->map(fn($route) => $route->getName())
            ->filter();

        // Verificar rutas API
        $this->assertTrue($routeNames->contains('api.pictionary.draw'));
        $this->assertTrue($routeNames->contains('api.pictionary.clear'));

        // Verificar rutas Web
        $this->assertTrue($routeNames->contains('pictionary.demo'));
    }

    /** @test */
    public function test_pictionary_routes_have_correct_prefixes()
    {
        $routes = Route::getRoutes();

        $drawRoute = collect($routes)
            ->first(fn($route) => $route->getName() === 'api.pictionary.draw');

        $this->assertNotNull($drawRoute);
        $this->assertEquals('api/pictionary/draw', $drawRoute->uri());
    }

    /** @test */
    public function test_core_routes_still_exist()
    {
        $routeNames = collect(Route::getRoutes())
            ->map(fn($route) => $route->getName())
            ->filter();

        // Verificar que rutas core no se eliminaron
        $this->assertTrue($routeNames->contains('games.index'));
        $this->assertTrue($routeNames->contains('rooms.lobby'));
    }

    /** @test */
    public function test_game_views_are_registered()
    {
        // Verificar namespace de vistas
        $this->assertTrue(view()->exists('pictionary::canvas'));
    }
}
```

### Ejecutar Tests

```bash
# Todos los tests de GameServiceProvider
php artisan test --filter=GameServiceProviderTest

# Test específico
php artisan test --filter=test_pictionary_routes_are_loaded
```

### Verificar Rutas Cargadas

```bash
# Listar todas las rutas
php artisan route:list

# Filtrar rutas de un juego específico
php artisan route:list --name=pictionary

# Filtrar rutas API
php artisan route:list --path=api/pictionary
```

## Troubleshooting

### ❌ Las rutas no se cargan

**Síntomas:** `Route [api.pictionary.draw] not defined`

**Solución:**
1. Verificar que `routes.php` existe en `games/{slug}/`
2. Verificar que `GameServiceProvider` está registrado en `config/app.php`
3. Limpiar caché de rutas: `php artisan route:clear`
4. Verificar errores de sintaxis en `routes.php`

### ❌ Conflictos de nombres de rutas

**Síntomas:** `Route name already exists`

**Solución:**
- Usar prefijos únicos por juego: `api.{slug}.` o `{slug}.`
- No usar nombres genéricos como `api.draw` (usar `api.pictionary.draw`)

### ❌ Middlewares no se aplican

**Síntomas:** Rate limiting o autenticación no funciona

**Solución:**
- Aplicar middlewares manualmente en `routes.php` (ver ejemplo arriba)
- No confiar en `config.php` para middlewares (aún no implementado)

### ❌ Controller no encontrado

**Síntomas:** `Target class [PictionaryController] does not exist`

**Solución:**
- Verificar namespace completo: `use App\Http\Controllers\PictionaryController;`
- Verificar que el controller existe en `app/Http/Controllers/`

### ❌ Rutas duplicadas

**Síntomas:** Una ruta existe en `routes/web.php` Y en `games/{slug}/routes.php`

**Solución:**
- Eliminar la ruta de `routes/web.php` o `routes/api.php`
- Mantener solo una versión en `games/{slug}/routes.php`

## Migración de Rutas Existentes

### Paso 1: Crear routes.php

```bash
# Crear archivo de rutas del juego
touch games/{slug}/routes.php
```

### Paso 2: Copiar Rutas

Copiar las rutas del juego desde `routes/api.php` y `routes/web.php` al nuevo archivo.

### Paso 3: Eliminar Rutas Antiguas

Eliminar las rutas del juego de los archivos core.

### Paso 4: Verificar

```bash
# Verificar que las rutas aún existen
php artisan route:list --name={slug}

# Ejecutar tests
php artisan test
```

## Roadmap

### Futuras Mejoras

- [ ] **Aplicación automática de middlewares** desde `config.php`
- [ ] **Hot-reload de rutas** sin reiniciar servidor
- [ ] **Validación de rutas** al iniciar aplicación
- [ ] **Documentación automática** de endpoints (OpenAPI/Swagger)
- [ ] **Rate limiting per-game** configurable
- [ ] **Versionado de APIs** (`/api/v1/pictionary/...`)

---

**Última actualización:** 2025-10-21
**Versión:** 1.0.0
**Estado:** ✅ Implementado y en producción
