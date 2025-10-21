# Game Registry (Módulo Core)

**Estado:** ✅ Implementado
**Tipo:** Core (obligatorio)
**Versión:** 1.0.0
**Última actualización:** 2025-10-21
**Tests:** ✅ 14 tests, 46 assertions (passing)

---

## 📋 Descripción

El **Game Registry** es un módulo core que descubre, valida y registra módulos de juegos automáticamente. Escanea la carpeta `games/` en busca de juegos válidos, verifica su estructura y configuración, y los registra en la base de datos para que estén disponibles en la plataforma.

## 🎯 Responsabilidades

- Descubrir juegos en la carpeta `games/`
- Validar estructura de cada módulo de juego
- Validar configuración (`config.json`) y capacidades (`capabilities.json`)
- Verificar que implementen `GameEngineInterface`
- Registrar/actualizar juegos en la base de datos
- Cachear configuraciones para performance
- Proporcionar comandos Artisan para gestión manual

## 🎯 Cuándo Usarlo

**Siempre.** Este es un módulo core que:
- Se ejecuta automáticamente al hacer deploy
- Permite descubrir nuevos juegos sin modificar código core
- Valida que los juegos cumplan con los contratos necesarios
- Mantiene sincronizada la BD con los juegos disponibles

---

## 📦 Componentes

### Servicio: `GameRegistry`

**Ubicación:** `app/Services/Core/GameRegistry.php`

**Implementa:** `GameConfigInterface`

**Configuración:** `config/games.php`

```php
return [
    'path' => base_path('games'),
    'required_files' => [
        'config.json',
        'capabilities.json',
    ],
    'cache_ttl' => 3600, // 1 hora
];
```

---

### Métodos Públicos

#### `discoverGames(): array`
Descubre todos los módulos de juegos válidos en `games/`.

**Retorna:** Array de juegos descubiertos con su configuración

**Funcionalidad:**
1. Escanea carpetas en `games/`
2. Valida cada módulo
3. Carga `config.json` y `capabilities.json`
4. Retorna solo juegos válidos

**Ejemplo de retorno:**
```php
[
    [
        'slug' => 'pictionary',
        'path' => 'games/pictionary',
        'config' => [...],
        'capabilities' => [...]
    ],
    [
        'slug' => 'uno',
        'path' => 'games/uno',
        'config' => [...],
        'capabilities' => [...]
    ]
]
```

**Ejemplo de uso:**
```php
$registry = app(GameRegistry::class);
$games = $registry->discoverGames();

foreach ($games as $game) {
    echo "Discovered: {$game['slug']}\n";
}
```

---

#### `validateGameModule(string $slug): array`
Valida la estructura completa de un módulo de juego.

**Parámetros:**
- `$slug`: Nombre de la carpeta del juego (ej: 'pictionary')

**Retorna:** Array con `['valid' => bool, 'errors' => array]`

**Validaciones:**
1. ✅ Carpeta `games/{slug}/` existe
2. ✅ Archivo `config.json` existe
3. ✅ Archivo `capabilities.json` existe
4. ✅ `config.json` tiene estructura válida
5. ✅ `capabilities.json` tiene estructura válida
6. ✅ Clase Engine implementa `GameEngineInterface` (si existe)

**Ejemplo de uso:**
```php
$validation = $registry->validateGameModule('pictionary');

if ($validation['valid']) {
    echo "Módulo válido\n";
} else {
    foreach ($validation['errors'] as $error) {
        echo "Error: {$error}\n";
    }
}
```

**Ejemplo de respuesta exitosa:**
```php
[
    'valid' => true,
    'errors' => []
]
```

**Ejemplo de respuesta con errores:**
```php
[
    'valid' => false,
    'errors' => [
        'Missing required file: config.json',
        'Invalid config.json: missing required field "name"'
    ]
]
```

---

#### `loadGameConfig(string $slug): array`
Carga y parsea el archivo `config.json` de un juego.

**Parámetros:**
- `$slug`: Nombre del juego

**Retorna:** Array con configuración del juego

**Cache:** Sí (TTL: 1 hora)

**Ejemplo de `config.json`:**
```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "description": "Dibuja y adivina",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito",
  "thumbnail": "/games/pictionary/assets/thumbnail.jpg"
}
```

**Ejemplo de uso:**
```php
$config = $registry->loadGameConfig('pictionary');
echo "{$config['name']} ({$config['minPlayers']}-{$config['maxPlayers']} players)\n";
```

---

#### `loadGameCapabilities(string $slug): array`
Carga y parsea el archivo `capabilities.json` de un juego.

**Parámetros:**
- `$slug`: Nombre del juego

**Retorna:** Array con capacidades declaradas

**Cache:** Sí (TTL: 1 hora)

**Ejemplo de `capabilities.json`:**
```json
{
  "slug": "pictionary",
  "version": "1.0",
  "requires": {
    "guest_system": true,
    "turn_system": {
      "enabled": true,
      "mode": "sequential"
    },
    "scoring_system": {
      "enabled": true,
      "type": "individual"
    },
    "timer_system": {
      "enabled": true,
      "timers": [
        {"name": "drawing_timer", "duration": 60}
      ]
    },
    "roles_system": {
      "enabled": true,
      "roles": [
        {"name": "drawer", "count": 1},
        {"name": "guesser", "count": "*"}
      ]
    },
    "realtime_sync": {
      "enabled": true,
      "channels": ["game", "canvas"]
    }
  },
  "provides": {
    "events": ["CanvasDrawEvent", "PlayerAnswered"],
    "routes": ["canvas", "spectator"],
    "views": ["canvas.blade.php", "spectator.blade.php"]
  }
}
```

**Ejemplo de uso:**
```php
$capabilities = $registry->loadGameCapabilities('pictionary');

if ($capabilities['requires']['realtime_sync']['enabled']) {
    // Cargar módulo de WebSockets
}
```

---

#### `validateConfigJson(array $config): array`
Valida la estructura de `config.json`.

**Parámetros:**
- `$config`: Configuración parseada

**Retorna:** Array con `['valid' => bool, 'errors' => array]`

**Campos requeridos:**
- `id` (string)
- `name` (string)
- `description` (string)
- `minPlayers` (integer, >= 1)
- `maxPlayers` (integer, >= minPlayers)
- `version` (string)

**Campos opcionales:**
- `estimatedDuration` (string)
- `type` (string)
- `isPremium` (boolean, default: false)
- `author` (string)
- `thumbnail` (string)

**Ejemplo:**
```php
$validation = $registry->validateConfigJson($config);

if (!$validation['valid']) {
    foreach ($validation['errors'] as $error) {
        Log::error("Config validation error: {$error}");
    }
}
```

---

#### `validateCapabilitiesJson(array $capabilities): array`
Valida la estructura de `capabilities.json`.

**Parámetros:**
- `$capabilities`: Capacidades parseadas

**Retorna:** Array con `['valid' => bool, 'errors' => array]`

**Campos requeridos:**
- `slug` (string, debe coincidir con nombre de carpeta)
- `requires` (object, módulos opcionales que necesita)

**Campos opcionales:**
- `version` (string)
- `provides` (object con events, routes, views)

**Ejemplo:**
```php
$validation = $registry->validateCapabilitiesJson($capabilities);
```

---

#### `registerGame(string $slug): ?Game`
Registra o actualiza un juego en la base de datos.

**Parámetros:**
- `$slug`: Nombre del juego

**Retorna:** Instancia de `Game` o `null` si falla

**Funcionalidad:**
1. Valida el módulo
2. Carga config y capabilities
3. Crea o actualiza registro en tabla `games`
4. Marca como activo

**Ejemplo de uso:**
```php
$game = $registry->registerGame('pictionary');

if ($game) {
    echo "Game registered: {$game->name}\n";
}
```

---

#### `registerAllGames(): int`
Descubre y registra todos los juegos válidos.

**Retorna:** Número de juegos registrados exitosamente

**Uso:** Comando Artisan `games:discover`

**Ejemplo:**
```php
$registered = $registry->registerAllGames();
echo "Registered {$registered} games\n";
```

---

#### `getRegisteredGames(): Collection`
Obtiene todos los juegos registrados en BD.

**Retorna:** Collection de modelos `Game`

**Filtros aplicados:** Solo juegos activos

**Ejemplo:**
```php
$games = $registry->getRegisteredGames();

foreach ($games as $game) {
    echo "- {$game->name} ({$game->slug})\n";
}
```

---

#### `getGameEngine(string $slug): ?GameEngineInterface`
Obtiene una instancia del Engine de un juego.

**Parámetros:**
- `$slug`: Nombre del juego

**Retorna:** Instancia de `GameEngineInterface` o `null`

**Convención:** Busca clase `Games\{Slug}\{Slug}Engine`

**Ejemplo:**
```php
$engine = $registry->getGameEngine('pictionary');
// Retorna instancia de Games\Pictionary\PictionaryEngine

if ($engine) {
    $engine->initializeGame($match);
}
```

---

## 🎮 Comandos Artisan

El módulo proporciona 2 comandos Artisan para gestión manual:

### `php artisan games:discover`

Descubre y registra todos los juegos en `games/`.

**Uso:**
```bash
php artisan games:discover
```

**Salida:**
```
Discovering game modules...
✓ Discovered and registered: pictionary
✓ Discovered and registered: uno
✓ Discovered and registered: trivia

Total games registered: 3
```

**Cuándo ejecutar:**
- Después de añadir un nuevo juego en `games/`
- Después de hacer deploy
- Para sincronizar BD con juegos disponibles

---

### `php artisan games:validate {slug?}`

Valida un juego específico o todos los juegos.

**Uso:**
```bash
# Validar todos los juegos
php artisan games:validate

# Validar un juego específico
php artisan games:validate pictionary
```

**Salida exitosa:**
```
Validating module: pictionary
✓ Module structure is valid
✓ config.json is valid
✓ capabilities.json is valid
✓ Engine class exists and implements GameEngineInterface

Module 'pictionary' is valid!
```

**Salida con errores:**
```
Validating module: broken-game
✗ Missing required file: config.json
✗ Invalid capabilities.json: missing required field "slug"

Module 'broken-game' has 2 errors!
```

---

## 📁 Estructura de un Módulo de Juego

Para que el Game Registry reconozca un juego, debe tener esta estructura mínima:

```
games/
└── pictionary/
    ├── config.json              ← REQUERIDO
    ├── capabilities.json        ← REQUERIDO
    ├── PictionaryEngine.php     ← REQUERIDO (implementa GameEngineInterface)
    ├── assets/
    │   ├── words.json
    │   └── thumbnail.jpg
    ├── views/
    │   ├── canvas.blade.php
    │   └── spectator.blade.php
    ├── js/
    │   └── canvas.js
    └── css/
        └── pictionary.css
```

---

## 🧪 Tests

**Ubicación:** `tests/Feature/Core/GameRegistryTest.php`

**Tests implementados (14 tests, 46 assertions):**
- ✅ Descubrir juegos en carpeta `games/`
- ✅ Validar módulo con estructura correcta
- ✅ Detectar módulo sin `config.json`
- ✅ Detectar módulo sin `capabilities.json`
- ✅ Validar `config.json` con campos requeridos
- ✅ Rechazar `config.json` con campos faltantes
- ✅ Validar `capabilities.json` con estructura correcta
- ✅ Rechazar `capabilities.json` inválido
- ✅ Validar rango de jugadores (min <= max)
- ✅ Cargar configuración desde JSON
- ✅ Cargar capacidades desde JSON
- ✅ Registrar juego en base de datos
- ✅ Actualizar juego existente
- ✅ Cachear configuraciones

**Ejecutar tests:**
```bash
php artisan test --filter=GameRegistryTest
```

---

## 💡 Ejemplos de Uso

### Descubrir y registrar juegos (CLI)
```bash
# Descubrir todos los juegos
php artisan games:discover

# Validar un juego específico
php artisan games:validate pictionary
```

### Usar en código
```php
use App\Services\Core\GameRegistry;

// Descubrir juegos
$registry = app(GameRegistry::class);
$games = $registry->discoverGames();

// Validar módulo
$validation = $registry->validateGameModule('pictionary');
if ($validation['valid']) {
    // Registrar en BD
    $game = $registry->registerGame('pictionary');
}

// Obtener Engine
$engine = $registry->getGameEngine('pictionary');
if ($engine) {
    $engine->startGame($match);
}
```

### En un Service Provider
```php
public function boot(GameRegistry $registry)
{
    // Auto-registrar juegos al bootear la aplicación
    if (app()->environment('production')) {
        $registry->registerAllGames();
    }
}
```

### En un Controller
```php
public function index(GameRegistry $registry)
{
    $games = $registry->getRegisteredGames();
    return view('games.index', compact('games'));
}
```

---

## 🔧 Configuración

**Archivo:** `config/games.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Games Path
    |--------------------------------------------------------------------------
    |
    | Ruta donde se encuentran los módulos de juegos.
    |
    */
    'path' => base_path('games'),

    /*
    |--------------------------------------------------------------------------
    | Required Files
    |--------------------------------------------------------------------------
    |
    | Archivos que DEBEN existir en cada módulo de juego.
    |
    */
    'required_files' => [
        'config.json',
        'capabilities.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Tiempo en segundos para cachear configuraciones de juegos.
    |
    */
    'cache_ttl' => 3600, // 1 hora

    /*
    |--------------------------------------------------------------------------
    | Engine Namespace
    |--------------------------------------------------------------------------
    |
    | Namespace base para las clases Engine de los juegos.
    |
    */
    'engine_namespace' => 'Games',
];
```

---

## 📦 Dependencias

### Internas:
- `Game` model
- `GameEngineInterface` contract
- `GameConfigInterface` contract
- Laravel Cache
- Laravel File
- Laravel Log

### Externas:
- Ninguna

---

## 🔗 Referencias

- **Servicio:** [`app/Services/Core/GameRegistry.php`](../../../app/Services/Core/GameRegistry.php)
- **Interface:** [`app/Contracts/GameEngineInterface.php`](../../../app/Contracts/GameEngineInterface.php)
- **Config:** [`config/games.php`](../../../config/games.php)
- **Commands:**
  - [`app/Console/Commands/DiscoverGamesCommand.php`](../../../app/Console/Commands/DiscoverGamesCommand.php)
  - [`app/Console/Commands/ValidateGameCommand.php`](../../../app/Console/Commands/ValidateGameCommand.php)
- **Tests:** [`tests/Feature/Core/GameRegistryTest.php`](../../../tests/Feature/Core/GameRegistryTest.php)
- **Glosario:** [`docs/GLOSSARY.md`](../../GLOSSARY.md#game-registry)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../../tasks/0001-prd-plataforma-juegos-sociales.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** 2025-10-21
