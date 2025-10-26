# Referencia de Módulos del Sistema

**Propósito**: Guía técnica completa de todos los módulos disponibles para juegos, cuándo usarlos, cómo configurarlos y ejemplos de código.

---

## Módulos Obligatorios (Siempre Activos)

### `game_core`
**Qué hace**: Ciclo de vida básico del juego (initialize → start → play → end)

**Cuándo usarlo**: Siempre (obligatorio)

**Configuración**: Automática

**Código**: Ya implementado en `BaseGameEngine`

---

### `room_manager`
**Qué hace**: Gestión de salas (crear, unirse, salir, estados)

**Cuándo usarlo**: Siempre (obligatorio)

**Configuración**: Automática

**Código**: `App\Models\Room`

---

### `real_time_sync`
**Qué hace**: WebSockets con Laravel Reverb para sincronización en tiempo real

**Cuándo usarlo**: Siempre (obligatorio)

**Configuración**: Automática

**Código**: `Echo` (Laravel) + eventos Broadcasting

---

## Módulos Opcionales

### 1. `guest_system`

**Qué hace**: Permite invitados sin registro

**Cuándo usarlo**:
- Juegos casuales donde no importa la cuenta
- Juegos para fiestas/reuniones presenciales
- Prototipado rápido

**Cuándo NO usarlo**:
- Juegos competitivos con ranking
- Juegos que guardan progreso
- Juegos con logros/achievements

**Configuración**:
```json
{
  "modules": {
    "guest_system": {
      "enabled": true
    }
  }
}
```

**Código en Engine**: Ninguno especial, se maneja automáticamente

**Ejemplo**: Trivia, Pictionary

---

### 2. `turn_system`

**Qué hace**: Gestión de turnos entre jugadores

**Modos disponibles**:
- `free`: Sin turnos, todos actúan cuando quieren
- `sequential`: Un jugador a la vez, orden fijo
- `simultaneous`: Todos al mismo tiempo

**Cuándo usarlo**:
- Sequential: Juegos por turnos (Pictionary, Ajedrez)
- Simultaneous: Juegos de respuesta rápida (Trivia)
- Free: Juegos abiertos sin restricciones

**Configuración**:
```json
{
  "modules": {
    "turn_system": {
      "enabled": true,
      "mode": "sequential"
    }
  }
}
```

**Código en Engine**:
```php
// Obtener jugador activo (modo sequential)
$turnManager = $this->getTurnManager($match);
$currentPlayer = $turnManager->getCurrentPlayer();

// Avanzar turno
$nextPlayer = $turnManager->advanceTurn($match);
$this->saveTurnManager($match, $turnManager);
```

**Ejemplo**: Pictionary (sequential), Trivia (simultaneous)

---

### 3. `round_system`

**Qué hace**: Divide el juego en rondas con inicio/fin

**Cuándo usarlo**:
- Juegos con preguntas/niveles
- Juegos con fases definidas
- Juegos con múltiples intentos

**Cuándo NO usarlo**:
- Juegos continuos sin divisiones
- Juegos con un solo round largo

**Configuración**:
```json
{
  "modules": {
    "round_system": {
      "enabled": true
    }
  }
}
```

En `initializeModules()`:
```php
$this->initializeModules($match, [
    'round_system' => [
        'total_rounds' => 10  // Número de rondas
    ]
]);
```

**Código en Engine**:
```php
// Obtener ronda actual
$roundManager = $this->getRoundManager($match);
$currentRound = $roundManager->getCurrentRound();

// Verificar si es última ronda
if ($roundManager->isLastRound()) {
    // Preparar finalización
}

// Avanzar ronda (automático en completeRound)
```

**Métodos del Engine**:
- `startNewRound()`: Preparar cada ronda (abstracto)
- `endCurrentRound()`: Finalizar ronda (abstracto)
- `completeRound()`: Flow completo (ya implementado)

**Ejemplo**: Trivia (10 preguntas = 10 rondas)

---

### 4. `scoring_system`

**Qué hace**: Sistema de puntuación y ranking

**Cuándo usarlo**:
- Juegos competitivos
- Juegos con ganador por puntos
- Juegos que requieren feedback numérico

**Cuándo NO usarlo**:
- Juegos colaborativos sin puntos
- Juegos donde solo importa ganar/perder

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

**Crear ScoreCalculator**:
```php
namespace Games\YourGame;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class YourGameScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'correct_answer' => 10,
            'speed_bonus_max' => 5,
            'penalty' => -2,
        ], $config);
    }

    public function calculate(string $action, array $context = []): int
    {
        return match($action) {
            'correct_answer' => $this->calculateCorrectAnswer($context),
            'incorrect_answer' => $this->calculateIncorrectAnswer($context),
            default => 0,
        };
    }

    protected function calculateCorrectAnswer(array $context): int
    {
        $basePoints = $this->config['correct_answer'];

        // Speed bonus si hay timer
        if (isset($context['time_taken'], $context['time_limit'])) {
            $speedBonus = $this->calculateSpeedBonus($context);
            return $basePoints + $speedBonus;
        }

        return $basePoints;
    }

    protected function calculateSpeedBonus(array $context): int
    {
        $timeTaken = $context['time_taken'];
        $timeLimit = $context['time_limit'];

        $timeUsedPercent = min(1.0, $timeTaken / $timeLimit);
        $bonusPercent = 1.0 - $timeUsedPercent;

        return (int) round($bonusPercent * $this->config['speed_bonus_max']);
    }

    protected function calculateIncorrectAnswer(array $context): int
    {
        return $this->config['penalty'];
    }
}
```

**Código en Engine**:
```php
// Inicializar con tu calculator
$this->initializeModules($match, [
    'scoring_system' => [
        'calculator' => new YourGameScoreCalculator([
            'correct_answer' => 10,
            'speed_bonus_max' => 5,
        ])
    ]
]);

// Sumar puntos
$scoreManager = $this->getScoreManager($match);
$calculator = $scoreManager->getCalculator();

$context = [
    'difficulty' => 'hard',
    'time_taken' => 5,
    'time_limit' => 15,
];

$points = $calculator->calculate('correct_answer', $context);
$scoreManager->addScore($player->id, $points);
$this->saveScoreManager($match, $scoreManager);

// Obtener puntuaciones
$scores = $this->getScores($match->game_state);

// Obtener ranking
$ranking = $scoreManager->getRanking();
```

**Ejemplo**: Trivia (puntos + speed bonus)

---

### 5. `timer_system`

**Qué hace**: Timers con sincronización servidor-cliente

**Tipos de timers**:
- Timer por ronda (ej: 15s por pregunta)
- Timer por turno (ej: 30s para dibujar)
- Timer de partida completa (ej: 5 minutos totales)

**Cuándo usarlo**:
- Juegos de rapidez
- Juegos con límite de tiempo
- Juegos con presión temporal

**Configuración**:
```json
{
  "modules": {
    "timer_system": {
      "enabled": true,
      "round_duration": 15
    }
  },
  "timing": {
    "round_start": {
      "duration": 15,
      "countdown_visible": true,
      "warning_threshold": 3
    }
  }
}
```

**Código en Engine**:
```php
// El timer se inicia AUTOMÁTICAMENTE en cada ronda

// Obtener elapsed time (para speed bonus)
$elapsedTime = $this->getElapsedTime($match, 'round');

// Obtener tiempo restante
$remaining = $this->getTimeRemaining($match, 'round');

// Verificar si expiró
if ($this->isTimerExpired($match, 'round')) {
    // Timeout
}
```

**Hook cuando timer expira**:
```php
// Opción 1: Usar comportamiento por defecto (completa ronda)
// No hacer nada

// Opción 2: Lógica antes de completar ronda
protected function beforeTimerExpiredAdvance(GameMatch $match, string $timerName = 'round'): void
{
    // Logging, penalties, etc.
}

// Opción 3: Comportamiento completamente custom
protected function onRoundTimerExpired(GameMatch $match, string $timerName = 'round'): void
{
    // Pasar turno en lugar de completar ronda
    $this->advanceTurn($match);
}
```

**Ejemplo**: Trivia (15s por pregunta), Pictionary (30s por turno)

**Documentación**: `docs/TIMER_SYSTEM_INTEGRATION.md`

---

### 6. `teams_system`

**Qué hace**: Agrupa jugadores en equipos

**Cuándo usarlo**:
- Juegos colaborativos
- Juegos competitivos por equipos
- Juegos con roles distribuidos

**Configuración**:
```json
{
  "modules": {
    "teams_system": {
      "enabled": true,
      "min_teams": 2,
      "max_teams": 4,
      "allow_self_selection": false
    }
  }
}
```

**Código en Engine**:
```php
// Obtener equipo de un jugador
$teamId = $match->game_state['teams_system']['player_teams'][$player->id] ?? null;

// Obtener todos los jugadores de un equipo
$teamPlayers = collect($match->game_state['teams_system']['player_teams'])
    ->filter(fn($team) => $team === $teamId)
    ->keys()
    ->toArray();

// Sumar puntos a equipo
$scoreManager = $this->getScoreManager($match);
foreach ($teamPlayers as $playerId) {
    $scoreManager->addScore($playerId, $points);
}
```

**Ejemplo**: Pictionary por equipos, Trivia por equipos

---

### 7. `player_state_system`

**Qué hace**: Gestiona estado de jugadores (locks, eliminaciones temporales, acciones)

**Cuándo usarlo**:
- Juegos donde jugadores se bloquean tras actuar
- Juegos con eliminaciones temporales
- Juegos que rastrean acciones de jugadores

**Configuración**:
```json
{
  "modules": {
    "player_state_system": {
      "enabled": true,
      "uses_locks": true
    }
  }
}
```

**Código en Engine**:
```php
// Inicializar con IDs de jugadores
$playerIds = $match->players->pluck('id')->toArray();
$playerState = new \App\Services\Modules\PlayerStateSystem\PlayerStateManager($playerIds);
$this->savePlayerStateManager($match, $playerState);

// Verificar si jugador está bloqueado
$playerState = $this->getPlayerStateManager($match);
if ($playerState->isPlayerLocked($player->id)) {
    return ['success' => false, 'message' => 'Ya respondiste'];
}

// Bloquear jugador (emite PlayerLockedEvent)
$lockResult = $playerState->lockPlayer($player->id, $match, $player, $actionData);
$this->savePlayerStateManager($match, $playerState);

// Verificar si todos están bloqueados
if ($lockResult['all_players_locked']) {
    // Terminar ronda
}

// Desbloquear todos (en nueva ronda)
$playerState->reset($match);  // Emite PlayersUnlockedEvent
```

**Eventos automáticos**:
- `PlayerLockedEvent` cuando se bloquea un jugador
- `PlayersUnlockedEvent` cuando se desbloquean todos

**Ejemplo**: Trivia (bloqueo tras responder)

---

### 8. `roles_system`

**Qué hace**: Asigna roles específicos a jugadores

**Cuándo usarlo**:
- Juegos con roles asimétricos
- Juegos donde jugadores tienen habilidades diferentes
- Juegos sociales (Mafia, Werewolf)

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

**Código en Engine**:
```php
// Asignar rol
$match->game_state['roles_system']['player_roles'][$player->id] = 'drawer';

// Obtener rol
$role = $match->game_state['roles_system']['player_roles'][$player->id] ?? null;

// Rotar roles
$this->rotateRoles($match);
```

**Ejemplo**: Pictionary (drawer/guesser), Mafia (mafia/ciudadano)

---

### 9. `card_deck_system`

**Qué hace**: Gestión de mazos de cartas (barajar, repartir, robar)

**Cuándo usarlo**:
- Juegos de cartas (UNO, Poker)
- Juegos con recursos aleatorios
- Juegos con deck building

**Configuración**:
```json
{
  "modules": {
    "card_deck_system": {
      "enabled": true
    }
  }
}
```

**Código en Engine**:
```php
// TODO: Implementar cuando sea necesario
// Por ahora, manejar manualmente en game_state
```

**Ejemplo**: UNO, Poker

---

### 10. `board_grid_system`

**Qué hace**: Tableros y grids para juegos espaciales

**Cuándo usarlo**:
- Juegos con tablero (Ajedrez, Damas)
- Juegos con mapa (Battle Royale)
- Juegos con posiciones (Tic Tac Toe)

**Configuración**:
```json
{
  "modules": {
    "board_grid_system": {
      "enabled": true,
      "rows": 8,
      "cols": 8
    }
  }
}
```

**Código en Engine**:
```php
// TODO: Implementar cuando sea necesario
// Por ahora, manejar manualmente en game_state
```

**Ejemplo**: Ajedrez, Tic Tac Toe

---

### 11. `spectator_mode`

**Qué hace**: Permite observadores que no juegan

**Cuándo usarlo**:
- Juegos competitivos con audiencia
- Juegos con streaming
- Juegos educativos (profesor observa)

**Configuración**:
```json
{
  "modules": {
    "spectator_mode": {
      "enabled": true,
      "allow_spectators": true
    }
  }
}
```

**Código en Engine**:
```php
// TODO: Implementar cuando sea necesario
// Spectators reciben eventos pero no pueden actuar
```

**Ejemplo**: Trivia (profesor observa), Pictionary (audiencia)

---

### 12. `replay_history`

**Qué hace**: Graba partidas para replay posterior

**Cuándo usarlo**:
- Juegos competitivos donde se quiere revisar
- Juegos educativos (análisis de errores)
- Juegos con controversias (verificar jugadas)

**Configuración**:
```json
{
  "modules": {
    "replay_history": {
      "enabled": true,
      "save_to_db": true
    }
  }
}
```

**Código en Engine**:
```php
// TODO: Implementar cuando sea necesario
// Grabar cada acción en historial
```

**Ejemplo**: Ajedrez (análisis de partidas)

---

### 13. `ai_players`

**Qué hace**: Bots/IA que juegan automáticamente

**Cuándo usarlo**:
- Juegos single-player con bots
- Completar partidas cuando faltan jugadores
- Testing automatizado

**Configuración**:
```json
{
  "modules": {
    "ai_players": {
      "enabled": true,
      "difficulty": "medium"
    }
  }
}
```

**Código en Engine**:
```php
// TODO: Implementar cuando sea necesario
// Bot ejecuta acciones automáticamente
```

**Ejemplo**: Trivia (bot responde aleatoriamente)

---

## Matriz de Compatibilidad

| Módulo 1 | Módulo 2 | Compatible | Notas |
|----------|----------|------------|-------|
| turn_system (sequential) | timer_system | ✅ | Timer por turno |
| turn_system (simultaneous) | timer_system | ✅ | Timer por ronda |
| turn_system (free) | timer_system | ⚠️ | No tiene sentido |
| teams_system | scoring_system | ✅ | Puntos por equipo |
| teams_system | roles_system | ✅ | Roles dentro de equipos |
| player_state_system | turn_system | ✅ | Locks + turnos |

---

## Quick Reference: ¿Qué módulos necesito?

### Trivia
- ✅ round_system (10 preguntas)
- ✅ scoring_system (puntos + speed bonus)
- ✅ timer_system (15s por pregunta)
- ✅ player_state_system (locks tras responder)
- ✅ turn_system (simultaneous)

### Pictionary
- ✅ round_system
- ✅ turn_system (sequential)
- ✅ timer_system (30s por turno)
- ✅ roles_system (drawer/guesser)
- ✅ teams_system (opcional)

### UNO
- ✅ card_deck_system
- ✅ turn_system (sequential)
- ✅ scoring_system (puntos por ganar)
- ❌ NO timer_system
- ❌ NO round_system

### Ajedrez
- ✅ board_grid_system (8x8)
- ✅ turn_system (sequential)
- ✅ timer_system (opcional, por turno)
- ✅ replay_history
- ❌ NO teams_system
- ❌ NO round_system

---

## Principios de Diseño

1. **Modular**: Cada módulo es independiente
2. **Opcional**: Solo activa lo que necesitas
3. **Configurable**: JSON config + código
4. **Zero Duplication**: Lógica en un solo lugar
5. **Extensible**: Hooks para customización
6. **Event-Driven**: Cambios emiten eventos

