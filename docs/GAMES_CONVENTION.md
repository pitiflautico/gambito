# Convención de Estructura para Juegos

## 🎯 Requisitos Obligatorios

**TODOS los juegos DEBEN cumplir con estos requisitos:**

1. ✅ **Tener su propia vista y controller**
   - Cada juego debe manejar su propia interfaz
   - No usar vistas genéricas compartidas

2. ✅ **Implementar la ruta `{slug}.game`**
   - Esta ruta carga la vista principal del juego
   - `RoomController::show()` redirige automáticamente a esta ruta

3. ✅ **Controller en la carpeta del juego**
   - Ubicación: `games/{slug}/{GameName}Controller.php`
   - Namespace: `Games\{GameName}`

4. ✅ **Usar campos correctos de GameMatch**
   - ❌ NO existe campo `status` en la tabla `matches`
   - ✅ Usar `started_at` y `finished_at` para verificar estado

5. ✅ **Pasar los tests de convención**
   - Ejecutar: `php artisan test tests/Unit/ConventionTests/GameConventionsTest.php`

6. ✅ **Usar EventManager para WebSockets** (si requiere real-time sync)
   - Declarar `event_manager` en `capabilities.json`
   - Configurar eventos en `event_config`
   - NO duplicar lógica de WebSockets en cada juego

## 📁 Estructura de Carpetas para Juegos

Cada juego debe seguir esta estructura estándar:

```
games/
└── {game-slug}/                    # Slug del juego (ej: pictionary, trivia, uno)
    ├── {GameName}Engine.php       # Motor del juego (ej: PictionaryEngine.php)
    ├── {GameName}Controller.php   # Controlador del juego (ej: TriviaController.php)
    ├── capabilities.json          # Módulos que requiere el juego
    ├── config.php                 # Configuración específica del juego
    ├── routes.php                 # Rutas API y Web del juego (auto-cargadas)
    ├── Events/                    # Eventos de broadcasting del juego
    │   ├── PlayerAnsweredEvent.php
    │   ├── GameStateUpdatedEvent.php
    │   └── ...
    └── views/                     # Vistas Blade del juego
        ├── game.blade.php
        └── ...
```

## ⚠️ IMPORTANTE: JavaScript y Assets

**NO** colocar archivos JavaScript en `games/{slug}/js/`.

**SÍ** colocar los archivos JavaScript en `resources/js/` con el siguiente patrón:

```
resources/
└── js/
    ├── {game-slug}-canvas.js      # JavaScript principal del juego
    ├── {game-slug}-lobby.js       # JavaScript del lobby (si aplica)
    └── app.js                     # Importa los módulos del juego
```

### ❌ NO importar en app.js

**IMPORTANTE**: El JavaScript de cada juego NO se debe importar en `app.js`. Cada juego carga su propio JavaScript solo cuando se accede a su vista.

```javascript
// resources/js/app.js
// ❌ NO HACER ESTO:
// import './pictionary-canvas.js';
// import './trivia-game.js';

// ✅ Los juegos cargan su JS en sus propias vistas
```

### ✅ Configurar en vite.config.js

```javascript
// vite.config.js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Entry points para cada juego
                'resources/js/pictionary-canvas.js',
                'resources/js/trivia-game.js',
            ],
            refresh: true,
        }),
    ],
});
```

### ✅ Cargar en la vista del juego

```blade
{{-- games/{slug}/views/game.blade.php --}}
@push('scripts')
    @vite(['resources/js/{slug}-game.js'])
@endpush
```

## 🎨 CSS/Estilos

Los estilos deben estar en:

```
public/
└── games/
    └── {game-slug}/
        └── css/
            └── canvas.css         # Estilos del juego
```

Y se cargan directamente en las vistas Blade:

```blade
@push('styles')
    <link rel="stylesheet" href="{{ asset('games/pictionary/css/canvas.css') }}">
@endpush
```

## 🔧 Motor del Juego (Engine)

**Ubicación:** `games/{slug}/{GameName}Engine.php`

**Namespace:** `Games\{GameName}\`

**Ejemplo:** `Games\Pictionary\PictionaryEngine`

**Debe implementar:** `App\Contracts\GameEngineInterface`

## 🎮 Controlador del Juego (Controller)

**Ubicación:** `games/{slug}/{GameName}Controller.php`

**Namespace:** `Games\{GameName}\`

**Ejemplo:** `Games\Trivia\TriviaController`

**Debe extender:** `App\Http\Controllers\Controller`

**Propósito:** Manejar peticiones HTTP (vistas y API) del juego

**Ejemplo:**
```php
<?php

namespace Games\Trivia;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TriviaController extends Controller
{
    public function game(string $roomCode)
    {
        // Mostrar vista del juego
    }

    public function answer(Request $request)
    {
        // Procesar acción vía API
    }
}
```

## 🛣️ Rutas del Juego (Routes)

**Ubicación:** `games/{slug}/routes.php`

**Auto-carga:** Las rutas se cargan automáticamente por el `GameServiceProvider`

**Estructura estándar:**
```php
<?php

use App\Http\Controllers\{GameName}Controller;
use Illuminate\Support\Facades\Route;

// API Routes - IMPORTANTE: Usar middleware('api') para eximir de CSRF
Route::prefix('api/{slug}')->name('api.{slug}.')->middleware('api')->group(function () {
    Route::post('/action', [Controller::class, 'method'])->name('action');
});

// Web Routes - IMPORTANTE: Usar middleware('web') para sesiones y CSRF
Route::prefix('{slug}')->name('{slug}.')->middleware('web')->group(function () {
    Route::get('/page', [Controller::class, 'method'])->name('page');
});
```

**Convenciones:**
- API routes: `api/{slug}/action` → nombre: `api.{slug}.action`
- Web routes: `{slug}/page` → nombre: `{slug}.page`

**Documentación completa:** Ver [DYNAMIC_ROUTES.md](architecture/DYNAMIC_ROUTES.md)

## 📝 Vistas Blade

**Ubicación:** `games/{slug}/views/`

**Registro:** Las vistas se registran automáticamente en el `GameServiceProvider`

**Convención Importante:**
- ✅ **TODOS los juegos DEBEN tener su propia vista**
- ✅ Cada juego debe tener una ruta `{slug}.game` que cargue su vista
- ✅ La vista principal del juego debe llamarse `game.blade.php`
- ✅ El `RoomController::show()` redirige automáticamente a la ruta del juego

**Ejemplo de ruta web requerida:**
```php
// games/{slug}/routes.php
Route::prefix('{slug}')->name('{slug}.')->group(function () {
    Route::get('/{roomCode}', [{GameName}Controller::class, 'game'])->name('game');
});
```

**Ejemplo de controller:**
```php
// games/{slug}/{GameName}Controller.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->first();

    return view('{slug}::game', compact('room', 'match'));
}
```

**Uso en vistas:** `@extends('pictionary::canvas')` o `view('trivia::game')`

## 🎯 Resumen de Ubicaciones

| Tipo de Archivo | Ubicación | ¿Se compila? |
|-----------------|-----------|-------------|
| Motor (Engine) | `games/{slug}/{Game}Engine.php` | ❌ No |
| Rutas | `games/{slug}/routes.php` | ❌ No (auto-cargadas) |
| Eventos | `games/{slug}/Events/*.php` | ❌ No |
| Vistas Blade | `games/{slug}/views/*.blade.php` | ❌ No |
| **JavaScript** | **`resources/js/{slug}-*.js`** | **✅ SÍ (Vite)** |
| CSS | `public/games/{slug}/css/*.css` | ❌ No (se carga directamente) |
| Imágenes/Assets | `public/games/{slug}/assets/` | ❌ No |

## 🚀 Workflow de Desarrollo

1. **Crear archivos PHP** en `games/{slug}/`
2. **Crear JavaScript** en `resources/js/{slug}-*.js`
3. **Importar JS** en `resources/js/app.js`
4. **Compilar:** `npm run build` o `npm run dev`
5. **Crear CSS** en `public/games/{slug}/css/`
6. **Cargar CSS** en vista Blade con `@push('styles')`

## ❌ NO HACER

- ❌ NO crear JavaScript en `games/{slug}/js/` - **No se compila**
- ❌ NO duplicar archivos - Solo una versión
- ❌ NO usar `public/build/` manualmente - Lo genera Vite
- ❌ NO importar archivos desde `games/` en JavaScript

## ✅ HACER

- ✅ Todo JavaScript en `resources/js/`
- ✅ Compilar con `npm run build` después de cambios JS
- ✅ Usar un solo archivo fuente (sin duplicados)
- ✅ Seguir la convención de nombres

## 🔄 Migración de Juegos Existentes

Si ya tienes archivos en ubicaciones incorrectas:

1. Mover JS de `games/{slug}/js/` a `resources/js/{slug}-*.js`
2. Borrar archivos antiguos en `games/{slug}/js/`
3. Borrar archivos en `public/games/{slug}/js/` (son copias estáticas)
4. Importar en `resources/js/app.js`
5. Ejecutar `npm run build`

## 📌 Ejemplo Completo: Pictionary

```
# Estructura correcta de Pictionary
games/pictionary/
├── PictionaryEngine.php           ✅ Motor del juego
├── capabilities.json
├── config.php                     ✅ Configuración del juego
├── routes.php                     ✅ Rutas (auto-cargadas)
├── Events/
│   ├── PlayerAnsweredEvent.php
│   └── GameStateUpdatedEvent.php
└── views/
    └── canvas.blade.php           ✅ Vista Blade

resources/js/
├── pictionary-canvas.js           ✅ JavaScript (se compila)
└── app.js                         ✅ Importa pictionary-canvas.js

public/games/pictionary/
└── css/
    └── canvas.css                 ✅ Estilos (se carga directo)

# NO debe existir:
games/pictionary/js/               ❌ BORRAR
public/games/pictionary/js/        ❌ BORRAR
```

## 📡 Event Manager (WebSockets)

**Módulo:** `event_manager`

**Cuando usarlo:** Juegos que requieren comunicación en tiempo real (real_time_sync)

### Declarar en capabilities.json

```json
{
  "slug": "trivia",
  "requires": {
    "modules": {
      "event_manager": "^1.0",
      "turn_system": "^1.0",
      "scoring_system": "^1.0"
    }
  },
  "provides": {
    "events": [
      "QuestionStartedEvent",
      "PlayerAnsweredEvent",
      "QuestionEndedEvent"
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "QuestionStartedEvent": {
        "name": "trivia.question.started",
        "handler": "handleQuestionStarted"
      },
      "PlayerAnsweredEvent": {
        "name": "trivia.player.answered",
        "handler": "handlePlayerAnswered"
      },
      "QuestionEndedEvent": {
        "name": "trivia.question.ended",
        "handler": "handleQuestionEnded"
      }
    }
  }
}
```

### Usar en JavaScript

```javascript
import EventManager from './modules/EventManager.js';

class TriviaGame {
    constructor(config) {
        // Inicializar EventManager
        this.eventManager = new EventManager({
            roomCode: config.roomCode,
            gameSlug: config.gameSlug,
            eventConfig: config.eventConfig,
            handlers: {
                handleQuestionStarted: (e) => this.onQuestionStarted(e),
                handlePlayerAnswered: (e) => this.onPlayerAnswered(e),
                handleQuestionEnded: (e) => this.onQuestionEnded(e),
            }
        });
        // EventManager se conecta automáticamente
    }

    onQuestionStarted(event) {
        // Actualizar UI con nueva pregunta
        console.log('Nueva pregunta:', event.question);
    }
}
```

### Pasar configuración desde el Controller

```php
// games/trivia/TriviaController.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();

    // Cargar event_config desde capabilities.json
    $capabilitiesPath = base_path("games/{$room->game->slug}/capabilities.json");
    $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
    $eventConfig = $capabilities['event_config'] ?? null;

    return view('trivia::game', [
        'room' => $room,
        'match' => $match,
        'eventConfig' => $eventConfig, // Pasar a la vista
    ]);
}
```

### Cargar en la vista

```blade
@push('scripts')
    @vite(['resources/js/trivia-game.js'])

    <script>
        window.gameData = {
            roomCode: '{{ $room->code }}',
            gameSlug: 'trivia',
            eventConfig: @json($eventConfig ?? null),
        };

        document.addEventListener('DOMContentLoaded', function() {
            window.triviaGame = new window.TriviaGame(window.gameData);
        });
    </script>
@endpush
```

### ✅ Ventajas del EventManager

1. **DRY**: Lógica de WebSockets escrita una vez
2. **Declarativo**: Eventos configurados en JSON
3. **Debugging**: Logs centralizados
4. **Robusto**: Manejo de errores unificado
5. **Testeable**: Mock fácil del EventManager

### ❌ NO hacer

```javascript
// ❌ NO duplicar lógica de WebSockets en cada juego
setupWebSocket() {
    const channel = window.Echo.channel(`room.${this.roomCode}`);
    channel.listen('trivia.question.started', ...);
    // Esto es código duplicado que debe estar en EventManager
}
```

**Documentación completa:** Ver [EventManager.md](../app/Services/Modules/EventManager/EventManager.md)

---

**Última actualización:** 2025-10-22
