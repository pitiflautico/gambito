# Módulos Disponibles

El sistema de módulos permite que cada juego active solo las funcionalidades que necesita. Todos los módulos están en `app/Services/Modules/`.

## Módulos Core (Siempre Activos)

### GameEngine
**Ubicación**: `app/Contracts/BaseGameEngine.php`

**Responsabilidad**: Ciclo de vida completo del juego.

**Métodos principales**:
- `initialize(GameMatch $match)` - Prepara el juego
- `startGame(GameMatch $match)` - Inicia el juego (emite `GameStartedEvent`)
- `finalize(GameMatch $match)` - Finaliza el juego (emite `GameFinishedEvent`)
- `checkWinCondition(GameMatch $match)` - Verifica si hay ganador
- `getGameStateForPlayer(GameMatch $match, Player $player)` - Estado para un jugador específico

**Hooks disponibles**:
```php
protected function onGameStart(GameMatch $match): void
{
    // Ejecutado justo antes de emitir GameStartedEvent
    // Útil para inicializar lógica específica del juego
}
```

### RoomManager
**Ubicación**: `app/Models/Room.php`

**Responsabilidad**: Gestión de salas y lifecycle de partidas.

**Estados**:
- `waiting` - Esperando jugadores en el lobby
- `playing` - Partida en curso
- `finished` - Partida terminada

**Métodos**:
- `createForGame(Game $game, User $master)` - Crear sala
- `isWaiting()`, `isPlaying()`, `isFinished()` - Verificar estado
- `match` - Relación con GameMatch activo

---

## Módulos Opcionales

### 1. Round System

**Ubicación**: `app/Services/Modules/RoundSystem/RoundManager.php`

**¿Cuándo usarlo?**: Juegos que se dividen en rondas (ej: Trivia con 10 preguntas).

**Configuración** (`config.json`):
```json
{
  "modules": {
    "round_system": {
      "enabled": true,
      "total_rounds": 10
    }
  }
}
```

**Estado en game_state**:
```json
{
  "round_system": {
    "enabled": true,
    "total_rounds": 10,
    "current_round": 3,
    "is_complete": false,
    "players": [1, 2, 3]
  }
}
```

**API**:

```php
$roundManager = new RoundManager($config);

// Inicializar
$roundManager->initialize($match);

// Iniciar ronda
$roundManager->startRound($match);

// Completar ronda
$roundManager->completeRound($match);

// Verificar estado
$isComplete = $roundManager->isComplete($match);
$currentRound = $roundManager->getCurrentRound($match);
$totalRounds = $roundManager->getTotalRounds($match);
```

**Eventos emitidos**:
- `RoundStartedEvent` - Al iniciar cada ronda
- `RoundEndedEvent` - Al completar cada ronda

---

### 2. Turn System

**Ubicación**: `app/Services/Modules/TurnSystem/TurnManager.php`

**¿Cuándo usarlo?**: Juegos donde los jugadores toman turnos.

**Modos**:
- `simultaneous` - Todos juegan al mismo tiempo (ej: Trivia)
- `sequential` - Uno después del otro (ej: juego de cartas)

**Configuración**:
```json
{
  "modules": {
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous"  // o "sequential"
    }
  }
}
```

**Estado en game_state**:

**Modo Simultáneo**:
```json
{
  "turn_system": {
    "enabled": true,
    "mode": "simultaneous",
    "current_turn_index": null,
    "pending_players": [1, 2, 3],
    "completed_players": [],
    "round_complete": false
  }
}
```

**Modo Secuencial**:
```json
{
  "turn_system": {
    "enabled": true,
    "mode": "sequential",
    "current_turn_index": 0,
    "current_player_id": 1,
    "round_complete": false
  }
}
```

**API**:

```php
$turnManager = new TurnManager($config);

// Inicializar
$turnManager->initialize($match);

// Iniciar turno
$playerIds = $match->players->pluck('id')->toArray();
$turnManager->startTurn($match, $playerIds);

// Modo Simultáneo
$turnManager->markPlayerAction($match, $playerId);
$isComplete = $turnManager->isRoundComplete($match);

// Modo Secuencial
$turnManager->nextTurn($match, $playerIds);
$currentPlayerId = $turnManager->getCurrentPlayer($match);

// Resetear para nueva ronda
$turnManager->resetForNewRound($match, $playerIds);
```

**Eventos emitidos**:
- `TurnChangedEvent` - Al cambiar de turno (modo secuencial)

---

### 3. Scoring System

**Ubicación**: `app/Services/Modules/ScoringSystem/ScoreManager.php`

**¿Cuándo usarlo?**: Juegos con puntuación.

**Configuración**:
```json
{
  "modules": {
    "scoring_system": {
      "enabled": true
    }
  }
}
```

**Estado en game_state**:
```json
{
  "scoring_system": {
    "enabled": true,
    "scores": {
      "1": 450,
      "2": 300,
      "3": 150
    }
  }
}
```

**API**:

```php
$scoreManager = new ScoreManager($config);

// Inicializar
$scoreManager->initialize($match);

// Actualizar puntuación
$scoreManager->updateScore($match, $playerId, $points);

// Obtener puntuaciones
$scores = $scoreManager->getScores($match);

// Obtener ranking
$ranking = $scoreManager->getRanking($match);
// [
//   ['player_id' => 1, 'score' => 450],
//   ['player_id' => 2, 'score' => 300],
//   ['player_id' => 3, 'score' => 150]
// ]
```

---

### 4. Timer Service

**Ubicación**: `app/Services/Modules/TimerService.php`

**¿Cuándo usarlo?**: Juegos con límite de tiempo por ronda.

**Configuración**:
```json
{
  "modules": {
    "timer": {
      "enabled": true,
      "round_time": 15  // segundos
    }
  }
}
```

**Estado en game_state**:
```json
{
  "timer": {
    "enabled": true,
    "round_time": 15,
    "remaining_time": 10,
    "is_active": true,
    "started_at": "2025-10-24 20:00:00"
  }
}
```

**API**:

```php
$timerService = new TimerService($config);

// Inicializar
$timerService->initialize($match);

// Iniciar timer
$timerService->startTimer($match);

// Detener timer
$timerService->stopTimer($match);

// Obtener tiempo restante
$remaining = $timerService->getRemainingTime($match);

// Verificar si expiró
$expired = $timerService->isExpired($match);

// Resetear para nueva ronda
$timerService->resetTimer($match);
```

---

### 5. Roles System

**Ubicación**: `app/Services/Modules/RolesSystem/RoleManager.php`

**¿Cuándo usarlo?**: Juegos donde los jugadores tienen roles (ej: dibujante/adivinador).

**Configuración**:
```json
{
  "modules": {
    "roles_system": {
      "enabled": true,
      "roles": ["drawer", "guesser"]
    }
  }
}
```

**Estado en game_state**:
```json
{
  "roles_system": {
    "enabled": true,
    "player_roles": {
      "1": "drawer",
      "2": "guesser",
      "3": "guesser"
    }
  }
}
```

**API**:

```php
$roleManager = new RoleManager($config);

// Asignar rol
$roleManager->assignRole($match, $playerId, 'drawer');

// Obtener rol
$role = $roleManager->getPlayerRole($match, $playerId);

// Rotar roles
$roleManager->rotateRoles($match);
```

---

## Uso en un Game Engine

### Ejemplo: Trivia

**config.json**:
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

**TriviaEngine.php**:
```php
class TriviaEngine extends BaseGameEngine
{
    protected function getGameConfig(): array
    {
        $configPath = base_path('games/trivia/config.json');
        return json_decode(file_get_contents($configPath), true);
    }

    public function initialize(GameMatch $match): void
    {
        // BaseGameEngine carga todos los módulos automáticamente
        parent::initialize($match);

        // Lógica específica de Trivia
        $this->loadQuestions($match);
    }

    protected function onGameStart(GameMatch $match): void
    {
        // Hook: ejecutado antes de GameStartedEvent
        $this->startFirstRound($match);
    }

    private function startFirstRound(GameMatch $match): void
    {
        $roundManager = $this->moduleLoader->get('round_system');
        $turnManager = $this->moduleLoader->get('turn_system');
        $timerService = $this->moduleLoader->get('timer');

        // Iniciar ronda 1
        $roundManager->startRound($match);

        // Iniciar turnos
        $playerIds = $match->players->pluck('id')->toArray();
        $turnManager->startTurn($match, $playerIds);

        // Iniciar timer
        $timerService->startTimer($match);

        // Mostrar pregunta
        $this->displayCurrentQuestion($match);
    }
}
```

---

## Testing de Módulos

Los módulos tienen tests de contrato en `ModuleFlowTest.php`:

```php
// Verificar que Round System funciona
test_round_manager_initializes_with_configured_rounds()
test_completing_last_round_marks_system_as_complete()

// Verificar que Turn System funciona
test_simultaneous_mode_all_actions_complete_round()
test_sequential_mode_last_turn_marks_round_complete()

// Verificar que Timer funciona
test_start_timer_sets_remaining_time_correctly()
test_is_expired_returns_true_when_time_elapsed()
```

**Todos los tests deben pasar antes de cualquier commit.**

---

## Crear un Módulo Nuevo

1. **Crear clase en `app/Services/Modules/`**:

```php
namespace App\Services\Modules\MiModulo;

class MiModulo
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function initialize(GameMatch $match): void
    {
        // Inicializar sección en game_state
        $gameState = $match->game_state ?? [];
        $gameState['mi_modulo'] = [
            'enabled' => true,
            // ... estado inicial
        ];
        $match->update(['game_state' => $gameState]);
    }

    // Métodos públicos del módulo...
}
```

2. **Registrar en ModuleLoader** (`app/Services/Modules/ModuleLoader.php`):

```php
protected function loadModule(string $moduleKey, array $moduleConfig): mixed
{
    return match($moduleKey) {
        'round_system' => new RoundManager($moduleConfig),
        'turn_system' => new TurnManager($moduleConfig),
        'scoring_system' => new ScoreManager($moduleConfig),
        'timer' => new TimerService($moduleConfig),
        'roles_system' => new RoleManager($moduleConfig),
        'mi_modulo' => new MiModulo($moduleConfig),  // ← Agregar aquí
        default => null,
    };
}
```

3. **Agregar tests de contrato en `ModuleFlowTest.php`**

4. **Documentar en este archivo**

---

## Convenciones de Módulos

1. **Cada módulo gestiona su propia sección en `game_state`**
   - Nunca modificar secciones de otros módulos directamente

2. **Todos los módulos deben tener `initialize()`**
   - Prepara el estado inicial en `game_state`

3. **Configuración vía `config.json`**
   - El juego declara qué módulos usa y cómo

4. **Tests como contratos**
   - Cada módulo tiene tests que definen su comportamiento esperado
   - No se pueden cambiar sin aprobación

5. **Stateless cuando sea posible**
   - El estado vive en `game_state` (base de datos)
   - Los módulos son servicios que operan sobre ese estado
