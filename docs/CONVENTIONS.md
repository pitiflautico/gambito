# Convenciones y Modo de Trabajo

## Principios Fundamentales

### 1. Tests como Contratos Inmutables

Los tests NO son simples verificaciones, son **contratos que definen el comportamiento esperado**:

- **No se pueden modificar** sin aprobación explícita del producto
- **Deben pasar 100%** antes de cualquier commit
- Si un test falla, el código está roto, no el test
- Si necesitas cambiar un test, primero debes justificar por qué el contrato debe cambiar

#### Tests de Contrato Actuales:

```
tests/Feature/
├── RoomCreationFlowTest.php    # Contrato: Creación de salas (14 tests)
├── LobbyJoinFlowTest.php       # Contrato: Entrada al lobby (18 tests)
└── ModuleFlowTest.php          # Contrato: Sistema de módulos (27 tests)
```

**Total: 59 tests - Todos deben pasar siempre**

### 2. Código Genérico > Código Específico

El sistema está diseñado para ser **agnóstico del juego**:

- ✅ **Correcto**: Crear módulos reutilizables (RoundSystem, TurnSystem)
- ❌ **Incorrecto**: Hardcodear lógica específica de un juego en el core

Ejemplo:

```php
// ❌ MAL - Específico de Trivia
if ($game->slug === 'trivia') {
    // lógica hardcodeada
}

// ✅ BIEN - Genérico para todos
$engine = $game->getEngine();
$engine->startGame($match);
```

### 3. Separación de Responsabilidades

```
app/Contracts/          # Contratos y abstracciones
app/Services/Modules/   # Módulos genéricos reutilizables
games/{slug}/           # Lógica específica del juego
```

Cada juego implementa `BaseGameEngine` y define qué módulos usa en `config.json`.

## Convenciones de Código

### Nomenclatura

**Archivos y Clases:**
- PascalCase para clases: `GameEngine`, `RoomController`
- snake_case para archivos de config: `config.json`, `capabilities.json`
- kebab-case para slugs: `trivia`, `room-manager`

**Base de Datos:**
- Tablas en plural: `rooms`, `matches`, `players`
- Campos en snake_case: `game_state`, `is_connected`, `started_at`
- Foreign keys: `{tabla}_id` → `room_id`, `match_id`

**Rutas:**
```php
// Rutas genéricas - web.php
Route::get('/rooms/{code}/lobby', [RoomController::class, 'lobby'])
    ->name('rooms.lobby');

// Rutas de juego - games/{slug}/routes.php
Route::get('/{roomCode}', [TriviaController::class, 'show'])
    ->name('trivia.show');

// Rutas de debug - routes/debug.php
Route::get('/game-events/{roomCode}', function ($roomCode) {
    // debug panel
})->name('debug.game-events.panel');
```

### Eventos

**Convención de Nombres:**

```php
// Eventos genéricos (todos los juegos)
GameStartedEvent
RoundStartedEvent
RoundEndedEvent
GameFinishedEvent
PlayerConnectedEvent
PlayerActionEvent
TurnChangedEvent

// Eventos específicos del juego (prefijo del juego)
Trivia\QuestionDisplayedEvent
Trivia\AnswerSubmittedEvent
```

**Broadcasting:**

```php
// Método broadcastAs() define el nombre del evento
public function broadcastAs(): string
{
    return 'game.started'; // Prefijo "game." para eventos genéricos
}

// Eventos específicos usan prefijo del juego
public function broadcastAs(): string
{
    return 'trivia.question.displayed';
}
```

**Channels:**

```php
// Todos los juegos usan el mismo canal
return new PrivateChannel("room.{$this->roomCode}");
```

### Estado del Juego (game_state)

El `game_state` es un JSON que contiene el estado completo del juego:

```json
{
  "phase": "playing|finished",
  "round_system": {
    "enabled": true,
    "total_rounds": 10,
    "current_round": 3,
    "is_complete": false
  },
  "turn_system": {
    "enabled": true,
    "mode": "simultaneous|sequential",
    "current_turn_index": null,
    "pending_players": [1, 2, 3],
    "completed_players": [],
    "round_complete": false
  },
  "scoring_system": {
    "enabled": true,
    "scores": {
      "1": 300,
      "2": 150
    }
  },
  "timer": {
    "enabled": true,
    "round_time": 15,
    "remaining_time": 10,
    "is_active": true,
    "started_at": "2025-10-24 20:00:00"
  }
}
```

**Convenciones:**
- Cada módulo tiene su propia sección en `game_state`
- El módulo es responsable de inicializar y actualizar su sección
- Nunca modificar secciones de otros módulos directamente

### Módulos

**Configuración en `config.json`:**

```json
{
  "modules": {
    "round_system": {
      "enabled": true,
      "total_rounds": 10
    },
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous"
    },
    "scoring_system": {
      "enabled": true
    },
    "timer": {
      "enabled": true,
      "round_time": 15
    }
  }
}
```

**Uso en el Engine:**

```php
class TriviaEngine extends BaseGameEngine
{
    protected function getGameConfig(): array
    {
        return json_decode(
            file_get_contents(base_path('games/trivia/config.json')),
            true
        );
    }

    public function initialize(GameMatch $match): void
    {
        // BaseGameEngine carga y configura módulos automáticamente
        parent::initialize($match);

        // Lógica específica de Trivia aquí
    }
}
```

## Workflow de Desarrollo

### 1. Antes de Empezar

```bash
# Actualizar dependencias
composer install
npm install

# Ejecutar migraciones
php artisan migrate

# Seed (crear usuarios de prueba)
php artisan db:seed

# Iniciar servicios
php artisan serve
php artisan reverb:start
npm run dev
```

### 2. Durante el Desarrollo

**Ejecutar Tests Frecuentemente:**

```bash
# Ejecutar todos los tests
./test-clean.sh

# Test específico
./test-clean.sh --filter="RoomCreationFlowTest"
```

**No commitear si los tests fallan.**

### 3. Debugging

**Panel de Debug:**

```
http://gambito.test/debug/game-events/{roomCode}
```

Permite:
- Ver todos los eventos WebSocket en tiempo real
- Botones para disparar acciones (Start Game, Next Round, End Game)
- Inspeccionar `game_state` completo
- Auto-refresh del estado

**Logs:**

```bash
# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# Logs de Reverb
php artisan reverb:start --debug
```

### 4. Crear un Nuevo Juego

1. **Copiar template:**

```bash
cp -r games/trivia games/mi-juego
```

2. **Renombrar archivos y clases:**
   - `TriviaEngine.php` → `MiJuegoEngine.php`
   - `TriviaController.php` → `MiJuegoController.php`
   - Namespace: `namespace Games\MiJuego;`

3. **Configurar módulos en `config.json`:**

```json
{
  "modules": {
    "round_system": { "enabled": true, "total_rounds": 5 },
    "turn_system": { "enabled": true, "mode": "sequential" },
    "scoring_system": { "enabled": true },
    "timer": { "enabled": false }
  }
}
```

4. **Actualizar `capabilities.json`:**

```json
{
  "name": "Mi Juego",
  "slug": "mi-juego",
  "minPlayers": 2,
  "maxPlayers": 8
}
```

5. **Implementar métodos del Engine:**

```php
class MiJuegoEngine extends BaseGameEngine
{
    protected function getGameConfig(): array { }
    protected function onGameStart(GameMatch $match): void { }
    public function initialize(GameMatch $match): void { }
    public function checkWinCondition(GameMatch $match): ?Player { }
    public function getGameStateForPlayer(GameMatch $match, Player $player): array { }
    public function finalize(GameMatch $match): array { }
}
```

6. **Registrar el juego:**

```bash
php artisan games:discover
```

7. **Crear tests específicos del juego** (opcional):

```php
tests/Feature/Games/MiJuegoGameFlowTest.php
```

### 5. Antes de Commitear

**Checklist:**

- [ ] Todos los tests pasan (59/59)
- [ ] No hay código específico de juego en el core
- [ ] Los módulos se usan correctamente
- [ ] Los eventos se emiten en el orden correcto
- [ ] El debug panel funciona con tu cambio
- [ ] No hay errores en la consola del navegador
- [ ] Reverb está funcionando

```bash
# Verificar tests
./test-clean.sh

# Verificar que Reverb funciona
php artisan reverb:start --debug

# Limpiar caché
php artisan cache:clear
php artisan config:clear
php artisan route:clear
```

## Convenciones de Git

### Commits

```
feat: descripción corta del feature

Descripción detallada de qué se hizo y por qué.

📝 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

**Prefijos:**
- `feat:` - Nuevo feature
- `fix:` - Bug fix
- `docs:` - Documentación
- `refactor:` - Refactorización
- `test:` - Tests
- `chore:` - Tareas de mantenimiento

### Branches

- `main` - Producción
- `develop` - Desarrollo
- `feature/nombre` - Features nuevos
- `fix/nombre` - Bug fixes

## Convenciones de Documentación

- **README.md**: Overview del proyecto
- **CONVENTIONS.md**: Este documento
- **MODULES.md**: Lista de módulos disponibles y cómo usarlos
- Documentación técnica en `docs/` solo si es necesaria

**Keep it simple**: Prefiere código auto-documentado sobre documentación extensa.
