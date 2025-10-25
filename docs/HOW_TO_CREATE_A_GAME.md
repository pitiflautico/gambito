# Cómo Crear un Nuevo Juego

Guía completa y autocontenida para implementar un nuevo juego usando la arquitectura modular.

**IMPORTANTE**: Este documento contiene toda la información necesaria para que Claude (o cualquier desarrollador) pueda crear un juego completo sin necesidad de buscar en el código base.

---

## 📚 Documentación Técnica de Referencia

**ANTES DE EMPEZAR**, familiarízate con estos documentos técnicos:

### Arquitectura General
- **`docs/MODULAR_ARCHITECTURE.md`** - Visión general del sistema modular
- **`docs/TECHNICAL_DECISIONS.md`** - Decisiones técnicas clave del proyecto

### Módulos Core (Siempre Activos)
- **`app/Contracts/GameEngineInterface.php`** - Interfaz que DEBES implementar
  - Métodos obligatorios: `initialize()`, `processAction()`, `checkWinCondition()`, `getGameStateForPlayer()`, `endGame()`
  - Ubicación: `app/Contracts/GameEngineInterface.php`

### Módulos Opcionales (Documentación Técnica)

#### Round System + Turn System ⭐ MUY IMPORTANTE
- **`docs/modules/ROUND_TURN_ARCHITECTURE.md`** - Arquitectura separada Round/Turn
  - **Round System**: Gestiona rondas, eliminaciones, fin de juego
  - **Turn System**: Solo gestiona turnos (quién juega ahora)
  - Código: `app/Services/Modules/RoundSystem/RoundManager.php`
  - Código: `app/Services/Modules/TurnSystem/TurnManager.php`
  - Tests: `tests/Unit/Services/Modules/RoundSystem/RoundManagerTest.php` (15 tests)
  - Tests: `tests/Unit/Services/Modules/TurnSystem/TurnManagerTest.php`

#### Scoring System
- **Documentación técnica**: Pendiente crear `docs/modules/optional/SCORING_SYSTEM.md`
  - Código: `app/Services/Modules/ScoringSystem/ScoreManager.php`
  - Interfaz: `app/Contracts/ScoreCalculatorInterface.php`
  - Tests: `tests/Unit/Services/Modules/ScoringSystem/ScoreManagerTest.php`
  - Ejemplo: `games/pictionary/PictionaryScoreCalculator.php`

#### Timer System
- **Documentación técnica**: `docs/TIMER_TIMEOUT_STRATEGY.md` (estrategias de timeout)
  - Código: `app/Services/Modules/TimerSystem/TimerService.php`
  - Código: `app/Services/Modules/TimerSystem/Timer.php`
  - Tests: `tests/Unit/Services/Modules/TimerSystem/TimerServiceTest.php` (44 tests)

#### Player State System
- **Documentación técnica**: `docs/MODULES.md` (sección Player State System)
  - Código: `app/Services/Modules/PlayerStateSystem/PlayerStateManager.php`
  - Gestiona estado individual de jugadores: roles persistentes/temporales, bloqueos, acciones, estados custom, intentos

### Ejemplo Completo de Referencia
- **`games/pictionary/`** - Implementación completa de Pictionary
  - `PictionaryEngine.php` - Game engine completo con todos los módulos
  - `PictionaryScoreCalculator.php` - Calculador de puntos personalizado
  - `config.json` - Configuración del juego
  - `capabilities.json` - Módulos utilizados
  - Tests: `tests/Unit/Games/Pictionary/` - Tests completos del juego

---

## 🎯 Conceptos Clave de la Arquitectura

### ¿Qué es game_state?
El `game_state` es un JSON que se guarda en la tabla `game_matches` y contiene TODO el estado del juego:
- Estado de los módulos (turnos, rondas, puntuación, timers, roles)
- Datos específicos del juego
- Fase actual del juego

**Ejemplo de game_state en Pictionary:**
```json
{
  "phase": "playing",
  "current_word": "gato",
  "pending_answer": null,

  // Datos del Round System
  "current_round": 2,
  "total_rounds": 5,
  "permanently_eliminated": [],
  "temporarily_eliminated": [3],

  // Datos del Turn System (dentro de Round)
  "turn_system": {
    "turn_order": [1, 2, 3, 4],
    "current_turn_index": 1,
    "mode": "sequential",
    "is_paused": false,
    "direction": 1
  },

  // Datos del Scoring System
  "scores": {
    "1": 150,
    "2": 200,
    "3": 80,
    "4": 120
  },

  // Datos del Timer System
  "timers": {
    "turn_timer": {
      "name": "turn_timer",
      "duration": 90,
      "started_at": "2025-01-21 10:30:00",
      "paused_at": null,
      "paused_elapsed": 0
    }
  },

  // Datos del Roles System
  "player_roles": {
    "2": "drawer",
    "1": "guesser",
    "3": "guesser",
    "4": "guesser"
  },
  "available_roles": ["drawer", "guesser"],
  "allow_multiple_roles": false
}
```

### Ciclo de Vida de un Módulo

1. **Inicialización** (en `initialize()`):
```php
$roundManager = new RoundManager($turnManager, totalRounds: 5);
$gameState = $roundManager->toArray(); // Serializa a array
```

2. **Uso** (en `processAction()`, etc):
```php
$roundManager = RoundManager::fromArray($gameState); // Deserializa
$roundManager->nextTurn(); // Usa el módulo
$gameState = array_merge($gameState, $roundManager->toArray()); // Re-serializa
```

3. **Persistencia**:
```php
$match->game_state = $gameState; // Guarda en BD
$match->save();
```

### Separación Round vs Turn ⭐ CRÍTICO

**ANTES (incorrecto, mezclado):**
```php
$turnManager = new TurnManager($playerIds, mode: 'sequential', totalRounds: 5);
$turnManager->eliminatePlayer($id); // ❌ Mezcla conceptos
```

**AHORA (correcto, separado):**
```php
// TurnManager: SOLO turnos
$turnManager = new TurnManager($playerIds, mode: 'sequential');

// RoundManager: Rondas + Eliminaciones + Fin de juego
$roundManager = new RoundManager($turnManager, totalRounds: 5);
$roundManager->eliminatePlayer($id, permanent: false); // ✅ Correcto
```

**Regla de oro:**
- **TurnManager**: Responde "¿De quién es el turno AHORA?"
- **RoundManager**: Responde "¿En qué ronda estamos? ¿Quién está eliminado? ¿Terminó el juego?"

---

## Índice

1. [Configuración Inicial](#configuración-inicial)
2. [Estructura de Archivos](#estructura-de-archivos)
3. [Paso a Paso](#paso-a-paso)
4. [Módulos Disponibles](#módulos-disponibles)
5. [Ejemplos por Tipo de Juego](#ejemplos-por-tipo-de-juego)
6. [Checklist de Implementación](#checklist-de-implementación)
7. [Errores Comunes](#errores-comunes)
8. [Debugging y Testing](#debugging-y-testing)

---

## Configuración Inicial

### 1. Crear Directorio del Juego

```bash
mkdir -p games/mi-juego
mkdir -p games/mi-juego/Events
mkdir -p games/mi-juego/views
```

### 2. Crear Archivos Base

```
games/mi-juego/
├── MiJuegoEngine.php          # Motor principal del juego
├── MiJuegoScoreCalculator.php # Calculador de puntuación (opcional)
├── config.json                # Configuración del juego
├── capabilities.json          # Capacidades y módulos usados
├── Events/                    # Eventos WebSocket
└── views/                     # Vistas del juego
```

---

## Estructura de Archivos

### config.json

Define la configuración del juego:

```json
{
  "name": "Mi Juego",
  "slug": "mi-juego",
  "description": "Descripción del juego",
  "min_players": 2,
  "max_players": 8,
  "estimated_duration_minutes": 20,
  "difficulty": "easy",
  "category": "estrategia",

  "defaultSettings": {
    "rounds": 5,
    "turn_duration": 60
  },

  "customizableSettings": {
    "rounds": {
      "type": "number",
      "min": 1,
      "max": 10,
      "default": 5,
      "label": "Número de rondas"
    },
    "turn_duration": {
      "type": "number",
      "min": 30,
      "max": 180,
      "default": 60,
      "label": "Duración del turno (segundos)"
    }
  },

  "turnSystemConfig": {
    "mode": "sequential"
  }
}
```

### capabilities.json

Define qué módulos usa tu juego:

```json
{
  "modules": {
    "core": {
      "game_core": true,
      "room_manager": true
    },
    "optional": {
      "guest_system": false,
      "turn_system": true,
      "round_system": true,
      "scoring_system": true,
      "teams_system": false,
      "timer_system": true,
      "player_state_system": false,
      "card_system": false,
      "board_system": false,
      "spectator_mode": false,
      "ai_players": false,
      "replay_system": false,
      "realtime_sync": true
    }
  },

  "features": {
    "has_timer": true,
    "has_turns": true,
    "has_roles": false,
    "has_teams": false,
    "supports_guests": false
  }
}
```

---

## Paso a Paso

### Paso 1: Crear el Game Engine

```php
<?php

namespace Games\MiJuego;

use App\Contracts\GameEngineInterface;
use App\Models\GameMatch;
use App\Models\Player;
use App\Services\Modules\RoundSystem\RoundManager;
use App\Services\Modules\TurnSystem\TurnManager;
use App\Services\Modules\ScoringSystem\ScoreManager;
use App\Services\Modules\TimerSystem\TimerService;

class MiJuegoEngine implements GameEngineInterface
{
    public function initialize(GameMatch $match): void
    {
        // 1. Obtener configuración
        $gameConfig = json_decode(
            file_get_contents(__DIR__ . '/config.json'),
            true
        );

        $roomSettings = $match->room->settings ?? [];
        $playerIds = $match->room->players->pluck('id')->toArray();

        // 2. Inicializar módulos necesarios

        // TURN SYSTEM (si lo necesitas)
        $turnManager = new TurnManager(
            playerIds: $playerIds,
            mode: $gameConfig['turnSystemConfig']['mode']
        );

        // ROUND SYSTEM (si tienes rondas)
        $totalRounds = $roomSettings['rounds'] ?? $gameConfig['defaultSettings']['rounds'];
        $roundManager = new RoundManager(
            turnManager: $turnManager,
            totalRounds: $totalRounds,
            currentRound: 1
        );

        // SCORING SYSTEM (si tienes puntuación)
        $scoreCalculator = new MiJuegoScoreCalculator();
        $scoreManager = new ScoreManager(
            playerIds: $playerIds,
            calculator: $scoreCalculator,
            trackHistory: false
        );

        // TIMER SYSTEM (si tienes temporizadores)
        $turnDuration = $roomSettings['turn_duration'] ?? $gameConfig['defaultSettings']['turn_duration'];
        $timerService = new TimerService();
        $timerService->startTimer('turn_timer', $turnDuration);

        // 3. Crear estado inicial del juego
        $match->game_state = array_merge([
            'phase' => 'playing',
            'game_specific_data' => [
                // Datos específicos de tu juego
            ]
        ],
        $roundManager->toArray(),
        $scoreManager->toArray(),
        $timerService->toArray()
        );

        $match->save();
    }

    public function processAction(GameMatch $match, Player $player, string $action, array $data): array
    {
        $gameState = $match->game_state;

        // Restaurar módulos desde game_state
        $roundManager = RoundManager::fromArray($gameState);
        $scoreManager = ScoreManager::fromArray($gameState);
        $timerService = TimerService::fromArray($gameState);

        // Procesar acción según el tipo
        return match ($action) {
            'mi_accion' => $this->handleMiAccion($match, $player, $data),
            default => ['success' => false, 'error' => 'Acción desconocida'],
        };
    }

    public function checkWinCondition(GameMatch $match): ?Player
    {
        $gameState = $match->game_state;
        $roundManager = RoundManager::fromArray($gameState);

        // Verificar si el juego terminó
        if (!$roundManager->isGameComplete()) {
            return null;
        }

        // Encontrar ganador
        $scores = $gameState['scores'];
        $winnerId = array_search(max($scores), $scores);

        return Player::find($winnerId);
    }

    public function getGameStateForPlayer(GameMatch $match, Player $player): array
    {
        $gameState = $match->game_state;
        $roundManager = RoundManager::fromArray($gameState);

        return [
            'phase' => $gameState['phase'],
            'round' => $roundManager->getCurrentRound(),
            'rounds_total' => $roundManager->getTotalRounds(),
            'current_player_id' => $roundManager->getCurrentPlayer(),
            'is_my_turn' => $roundManager->isPlayerTurn($player->id),
            'scores' => $gameState['scores'],
            // Datos específicos de tu juego
        ];
    }

    public function endGame(GameMatch $match): array
    {
        $gameState = $match->game_state;
        $scoreManager = ScoreManager::fromArray($gameState);

        return [
            'winner' => $this->checkWinCondition($match)?->id,
            'final_scores' => $scoreManager->getRanking(),
            'statistics' => [
                // Estadísticas del juego
            ]
        ];
    }
}
```

### Paso 2: Crear Score Calculator (si tienes puntuación)

```php
<?php

namespace Games\MiJuego;

use App\Contracts\ScoreCalculatorInterface;

class MiJuegoScoreCalculator implements ScoreCalculatorInterface
{
    public function calculate(string $event, array $context): array
    {
        return match ($event) {
            'accion_correcta' => [
                'player_id' => $context['player_id'],
                'points' => 100,
                'reason' => 'Acción correcta'
            ],
            'accion_incorrecta' => [
                'player_id' => $context['player_id'],
                'points' => -50,
                'reason' => 'Acción incorrecta'
            ],
            default => throw new \InvalidArgumentException("Evento no soportado: {$event}")
        };
    }

    public function supportsEvent(string $event): bool
    {
        return in_array($event, [
            'accion_correcta',
            'accion_incorrecta'
        ]);
    }

    public function getConfig(): array
    {
        return [
            'supported_events' => [
                'accion_correcta' => 'Puntos por acción correcta',
                'accion_incorrecta' => 'Penalización por error'
            ]
        ];
    }
}
```

---

## Módulos Disponibles

### Round System + Turn System

**Cuándo usar:**
- Juegos con rondas (Pictionary, Trivia, UNO)
- Necesitas contar rondas
- Necesitas eliminar jugadores (temporal o permanente)

**Ejemplo:**
```php
// Inicializar
$turnManager = new TurnManager($playerIds, mode: 'sequential');
$roundManager = new RoundManager($turnManager, totalRounds: 5);

// Avanzar turno
$roundManager->nextTurn();

// Verificar ronda completada
if ($roundManager->isNewRound()) {
    echo "Nueva ronda: " . $roundManager->getCurrentRound();
}

// Eliminar jugador
$roundManager->eliminatePlayer($playerId, permanent: false); // Temporal
$roundManager->eliminatePlayer($playerId, permanent: true);  // Permanente

// Fin de juego
if ($roundManager->isGameComplete()) {
    // Juego terminado
}
```

### Scoring System

**Cuándo usar:**
- Juegos con puntuación
- Necesitas ranking de jugadores

**Ejemplo:**
```php
$scoreCalculator = new MiJuegoScoreCalculator();
$scoreManager = new ScoreManager($playerIds, $scoreCalculator);

// Agregar puntos
$result = $scoreManager->addScore('evento_victoria', [
    'player_id' => $playerId,
    'bonus' => 50
]);

// Obtener ranking
$ranking = $scoreManager->getRanking();
// [
//   ['player_id' => 1, 'score' => 250],
//   ['player_id' => 2, 'score' => 180],
// ]
```

### Timer System

**Cuándo usar:**
- Juegos con límite de tiempo por turno
- Temporizadores de cuenta regresiva

**Ejemplo:**
```php
$timerService = new TimerService();

// Iniciar timer
$timerService->startTimer('turn_timer', 60); // 60 segundos

// Verificar tiempo restante
$remaining = $timerService->getRemainingTime('turn_timer'); // 45

// Verificar si expiró
if ($timerService->isExpired('turn_timer')) {
    // Tiempo agotado
}

// Pausar/Reanudar
$timerService->pauseTimer('turn_timer');
$timerService->resumeTimer('turn_timer');

// Reiniciar
$timerService->restartTimer('turn_timer');
```

### Roles System

**Cuándo usar:**
- Juegos con roles específicos (Pictionary: drawer/guesser, Mafia: detective/mafia/civil)
- Roles que rotan o son fijos

**Ejemplo:**
```php
$roleManager = new RoleManager(
    availableRoles: ['drawer', 'guesser'],
    allowMultipleRoles: false
);

// Asignar rol
$roleManager->assignRole($playerId, 'drawer');

// Verificar rol
if ($roleManager->hasRole($playerId, 'drawer')) {
    // Es drawer
}

// Obtener jugadores con rol
$drawers = $roleManager->getPlayersWithRole('drawer'); // [1]

// Rotar rol (para turnos)
$newDrawerId = $roleManager->rotateRole('drawer', $turnOrder);
```

---

## Ejemplos por Tipo de Juego

### Juego de Turnos Secuenciales (como Pictionary)

**Módulos necesarios:**
- ✅ Round System + Turn System
- ✅ Scoring System
- ✅ Timer System
- ✅ Roles System (si hay roles)

**capabilities.json:**
```json
{
  "modules": {
    "optional": {
      "turn_system": true,
      "round_system": true,
      "scoring_system": true,
      "timer_system": true,
      "player_state_system": true
    }
  }
}
```

**Flujo:**
```php
// initialize()
$turnManager = new TurnManager($playerIds, 'sequential');
$roundManager = new RoundManager($turnManager, totalRounds: 5);
$playerState = new PlayerStateManager(['drawer', 'guesser']);
$timerService = new TimerService();

// Cada turno
$roundManager->nextTurn();
$roleManager->rotateRole('drawer', $turnOrder);
$timerService->restartTimer('turn_timer');

// Al completar ronda
if ($roundManager->isNewRound()) {
    // Eliminaciones temporales se limpian automáticamente
}
```

### Juego Simultáneo (como Trivia)

**Módulos necesarios:**
- ✅ Round System
- ✅ Scoring System
- ✅ Timer System (opcional)
- ❌ Turn System (todos juegan a la vez)

**capabilities.json:**
```json
{
  "modules": {
    "optional": {
      "round_system": true,
      "scoring_system": true,
      "timer_system": true,
      "turn_system": false
    }
  }
}
```

**Flujo:**
```php
// initialize()
// NO usar TurnManager, solo RoundManager sin turnos internos
$roundManager = new RoundManager(
    turnManager: null, // O crear uno con mode: 'simultaneous'
    totalRounds: 10
);

// Cada ronda
// Todos los jugadores responden al mismo tiempo
// Al final de la ronda:
$roundManager->nextRound(); // Método personalizado
```

### Battle Royale (Eliminación Permanente)

**Módulos necesarios:**
- ✅ Round System (rondas infinitas)
- ✅ Scoring System (opcional)
- ❌ Timer System
- ❌ Roles System

**capabilities.json:**
```json
{
  "modules": {
    "optional": {
      "round_system": true,
      "scoring_system": false,
      "turn_system": false,
      "timer_system": false
    }
  }
}
```

**Flujo:**
```php
// initialize()
$roundManager = new RoundManager(
    turnManager: new TurnManager($playerIds, 'simultaneous'),
    totalRounds: 0 // Infinitas
);

// Eliminar jugadores
$roundManager->eliminatePlayer($playerId, permanent: true);

// Verificar fin de juego
if ($roundManager->getActivePlayerCount() <= 1) {
    // Solo queda 1 jugador - FIN
    $winner = $roundManager->getActivePlayers()[0];
}
```

### Juego de Equipos (como Futbolín)

**Módulos necesarios:**
- ✅ Teams System (futuro)
- ✅ Scoring System
- ✅ Timer System

**capabilities.json:**
```json
{
  "modules": {
    "optional": {
      "teams_system": true,
      "scoring_system": true,
      "timer_system": true
    }
  }
}
```

---

## Checklist de Implementación

### ✅ Fase 1: Configuración
- [ ] Crear directorio `games/mi-juego/`
- [ ] Crear `config.json` con configuración del juego
- [ ] Crear `capabilities.json` definiendo módulos usados
- [ ] Definir min/max jugadores

### ✅ Fase 2: Game Engine
- [ ] Crear `MiJuegoEngine.php`
- [ ] Implementar `initialize()`
  - [ ] Inicializar módulos necesarios
  - [ ] Crear estado inicial
- [ ] Implementar `processAction()`
  - [ ] Definir acciones del juego
  - [ ] Validar acciones
- [ ] Implementar `checkWinCondition()`
- [ ] Implementar `getGameStateForPlayer()`
- [ ] Implementar `endGame()`

### ✅ Fase 3: Score Calculator (si aplica)
- [ ] Crear `MiJuegoScoreCalculator.php`
- [ ] Implementar `calculate()`
- [ ] Definir eventos soportados
- [ ] Configurar puntuaciones

### ✅ Fase 4: Tests
- [ ] Crear tests unitarios del engine
- [ ] Crear tests del score calculator
- [ ] Crear tests de flujo completo
- [ ] Verificar casos edge

### ✅ Fase 5: Frontend
- [ ] Crear vistas en `games/mi-juego/views/`
- [ ] Implementar lógica de interfaz
- [ ] Integrar WebSockets para tiempo real
- [ ] Manejar eventos del juego

### ✅ Fase 6: Eventos WebSocket (opcional)
- [ ] Crear eventos en `games/mi-juego/Events/`
- [ ] Implementar broadcast en tiempo real
- [ ] Manejar sincronización de estado

### ✅ Fase 7: Documentación
- [ ] Documentar reglas del juego
- [ ] Documentar API de acciones
- [ ] Crear guía de jugador

---

## Patrones Comunes

### Patrón: Avanzar Turno con Rotación de Roles

```php
private function nextTurn(GameMatch $match): void
{
    $gameState = $match->game_state;

    // Restaurar módulos
    $roundManager = RoundManager::fromArray($gameState);
    $playerState = PlayerStateManager::fromArray($gameState);
    $timerService = TimerService::fromArray($gameState);

    // Avanzar turno
    $turnInfo = $roundManager->nextTurn();

    // Rotar roles
    $newDrawerId = $roleManager->rotateRole('drawer', $roundManager->getTurnOrder());

    // Reiniciar timer
    $timerService->restartTimer('turn_timer');

    // Guardar estado
    $gameState = array_merge(
        $gameState,
        $roundManager->toArray(),
        $roleManager->toArray(),
        $timerService->toArray()
    );

    $match->game_state = $gameState;
    $match->save();
}
```

### Patrón: Verificar y Aplicar Puntuación

```php
private function handleCorrectAnswer(GameMatch $match, Player $player): array
{
    $gameState = $match->game_state;
    $scoreManager = ScoreManager::fromArray($gameState);

    // Calcular puntos
    $scoreResult = $scoreManager->addScore('correct_answer', [
        'player_id' => $player->id,
        'speed_bonus' => 50
    ]);

    // Actualizar estado
    $gameState['scores'] = $scoreManager->toArray()['scores'];
    $match->game_state = $gameState;
    $match->save();

    return [
        'success' => true,
        'points_earned' => $scoreResult['points_awarded'],
        'total_score' => $scoreManager->getScore($player->id)
    ];
}
```

### Patrón: Eliminación Temporal por Ronda

```php
// Pictionary: dibujante que nadie adivina
$roundManager->eliminatePlayer($drawerId, permanent: false);

// Al completar ronda, se restaura automáticamente
if ($roundManager->isNewRound()) {
    // temporarilyEliminated ya está vacío
}
```

### Patrón: Eliminación Permanente

```php
// Battle Royale: jugador derrotado
$roundManager->eliminatePlayer($playerId, permanent: true);

// Verificar si queda solo 1 jugador
if ($roundManager->getActivePlayerCount() === 1) {
    $winnerId = $roundManager->getActivePlayers()[0];
    // FIN DEL JUEGO
}
```

---

## ❌ Errores Comunes

### Error 1: Usar TurnManager para Rondas o Eliminaciones

**Incorrecto:**
```php
$turnManager = new TurnManager($playerIds, totalRounds: 5); // ❌ No existe totalRounds
$turnManager->eliminatePlayer($id); // ❌ Método no existe
$turnManager->getCurrentRound(); // ❌ Método no existe
```

**Correcto:**
```php
$turnManager = new TurnManager($playerIds, mode: 'sequential');
$roundManager = new RoundManager($turnManager, totalRounds: 5);
$roundManager->eliminatePlayer($id, permanent: false);
$roundManager->getCurrentRound();
```

### Error 2: Olvidar Re-serializar Módulos

**Incorrecto:**
```php
$roundManager = RoundManager::fromArray($gameState);
$roundManager->nextTurn();
// ❌ No guardaste los cambios!
$match->save();
```

**Correcto:**
```php
$roundManager = RoundManager::fromArray($gameState);
$roundManager->nextTurn();
$gameState = array_merge($gameState, $roundManager->toArray()); // ✅ Re-serializar
$match->game_state = $gameState;
$match->save();
```

### Error 3: No Verificar Eliminaciones antes de Acciones

**Incorrecto:**
```php
public function handleAction(GameMatch $match, Player $player): array
{
    // ❌ No verificaste si el jugador está eliminado
    return ['success' => true];
}
```

**Correcto:**
```php
public function handleAction(GameMatch $match, Player $player): array
{
    $roundManager = RoundManager::fromArray($match->game_state);

    if ($roundManager->isEliminated($player->id)) {
        return ['success' => false, 'error' => 'Jugador eliminado'];
    }

    return ['success' => true];
}
```

### Error 4: Mezclar Datos Específicos del Juego con Módulos

**Incorrecto:**
```php
$match->game_state = [
    'phase' => 'playing',
    'my_custom_data' => 'foo',
    // ❌ Mezclar todo en el mismo nivel
    ...$roundManager->toArray(),
    ...$scoreManager->toArray()
];
```

**Correcto (opción 1 - Plano):**
```php
$match->game_state = array_merge([
    'phase' => 'playing',
    'my_custom_data' => 'foo'
], $roundManager->toArray(), $scoreManager->toArray());
```

**Correcto (opción 2 - Anidado):**
```php
$match->game_state = [
    'phase' => 'playing',
    'game_data' => [
        'my_custom_data' => 'foo'
    ],
    'modules' => [
        'round' => $roundManager->toArray(),
        'score' => $scoreManager->toArray()
    ]
];
```

### Error 5: No Actualizar capabilities.json

**Problema:**
```json
{
  "modules": {
    "optional": {
      "turn_system": true,
      "round_system": false  // ❌ Pero estás usando RoundManager!
    }
  }
}
```

**Solución:**
```json
{
  "modules": {
    "optional": {
      "turn_system": true,
      "round_system": true  // ✅ Correcto
    }
  }
}
```

---

## 🐛 Debugging y Testing

### Cómo Debuggear game_state

**1. Ver el estado actual:**
```php
// En cualquier método del engine
Log::info("Game State Debug", [
    'game_state' => $match->game_state
]);
```

**2. Verificar módulos individuales:**
```php
$roundManager = RoundManager::fromArray($gameState);
Log::info("Round Manager State", [
    'current_round' => $roundManager->getCurrentRound(),
    'total_rounds' => $roundManager->getTotalRounds(),
    'active_players' => $roundManager->getActivePlayers(),
    'eliminated_permanent' => $roundManager->getPermanentlyEliminated(),
    'eliminated_temporal' => $roundManager->getTemporarilyEliminated()
]);
```

**3. Usar Tinker para inspeccionar:**
```bash
php artisan tinker

$match = App\Models\GameMatch::find(1);
$gameState = $match->game_state;
$roundManager = App\Services\Modules\RoundSystem\RoundManager::fromArray($gameState);
$roundManager->getCurrentRound(); // 2
$roundManager->getActivePlayers(); // [1, 2, 4]
```

### Tests Básicos Recomendados

**1. Test de Inicialización:**
```php
public function test_can_initialize_game(): void
{
    $match = GameMatch::factory()->create();
    $engine = new MiJuegoEngine();

    $engine->initialize($match);

    $this->assertNotNull($match->game_state);
    $this->assertEquals('playing', $match->game_state['phase']);
    $this->assertArrayHasKey('current_round', $match->game_state);
}
```

**2. Test de Acción:**
```php
public function test_can_process_action(): void
{
    $match = GameMatch::factory()->create();
    $player = $match->room->players->first();
    $engine = new MiJuegoEngine();

    $engine->initialize($match);

    $result = $engine->processAction($match, $player, 'mi_accion', [
        'data' => 'test'
    ]);

    $this->assertTrue($result['success']);
}
```

**3. Test de Fin de Juego:**
```php
public function test_detects_win_condition(): void
{
    $match = GameMatch::factory()->create();
    $engine = new MiJuegoEngine();

    $engine->initialize($match);

    // Simular fin de juego
    $gameState = $match->game_state;
    $roundManager = RoundManager::fromArray($gameState);
    // ... avanzar todas las rondas

    $winner = $engine->checkWinCondition($match);
    $this->assertNotNull($winner);
}
```

### Comandos Útiles

```bash
# Ejecutar tests del juego
php artisan test tests/Unit/Games/MiJuego --testdox

# Ejecutar tests de un módulo
php artisan test tests/Unit/Services/Modules/RoundSystem --testdox

# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# Limpiar cache de configuración
php artisan config:clear
php artisan cache:clear
```

---

## 📋 Checklist Pre-Commit

Antes de hacer commit de tu juego, verifica:

- [ ] ✅ `config.json` está completo con todas las configuraciones
- [ ] ✅ `capabilities.json` lista correctamente los módulos usados
- [ ] ✅ Game Engine implementa todos los métodos de `GameEngineInterface`
- [ ] ✅ Score Calculator (si aplica) implementa `ScoreCalculatorInterface`
- [ ] ✅ Todos los módulos se serializan/deserializan correctamente
- [ ] ✅ Tests básicos pasan (inicialización, acciones, fin de juego)
- [ ] ✅ No hay logs de error en `storage/logs/laravel.log`
- [ ] ✅ El juego funciona en el navegador (prueba manual)
- [ ] ✅ WebSockets funcionan (si usas `realtime_sync: true`)
- [ ] ✅ Documentaste las reglas del juego

---

## 🎓 Recursos Adicionales

### Documentación de Módulos (LEER ANTES DE USAR)

#### ⭐ MUY IMPORTANTE
- **`docs/modules/ROUND_TURN_ARCHITECTURE.md`** - Arquitectura Round/Turn (LEER PRIMERO)
  - Explica la separación Round vs Turn
  - Ejemplos de eliminación temporal/permanente
  - Casos de uso por tipo de juego

#### Documentación Técnica Detallada
- **`docs/TIMER_TIMEOUT_STRATEGY.md`** - Estrategias de timeout para timers
  - 3 opciones de implementación (Middleware, Queue, WebSocket)
  - Cuál usar según el caso

- **Pendiente**: `docs/modules/optional/SCORING_SYSTEM.md` - Sistema de puntuación
- **Pendiente**: `docs/modules/optional/ROLES_SYSTEM.md` - Sistema de roles
- **Pendiente**: `docs/modules/optional/TIMER_SYSTEM.md` - Sistema de temporizadores

### Código de Referencia

#### Módulos (Código Fuente)
- **Round System**: `app/Services/Modules/RoundSystem/RoundManager.php` (400 líneas)
- **Turn System**: `app/Services/Modules/TurnSystem/TurnManager.php` (300 líneas)
- **Scoring System**: `app/Services/Modules/ScoringSystem/ScoreManager.php`
- **Timer System**: `app/Services/Modules/TimerSystem/TimerService.php`
- **Player State System**: `app/Services/Modules/PlayerStateSystem/PlayerStateManager.php`

#### Tests (Ejemplos de Uso)
- **RoundManager**: `tests/Unit/Services/Modules/RoundSystem/RoundManagerTest.php` (15 tests, 59 assertions)
- **PlayerStateManager**: Gestiona roles persistentes/temporales, bloqueos, acciones, estados
- **TimerService**: `tests/Unit/Services/Modules/TimerSystem/TimerServiceTest.php` (44 tests, 130 assertions)
- **ScoreManager**: `tests/Unit/Services/Modules/ScoringSystem/ScoreManagerTest.php`

#### Ejemplo Completo
- **Pictionary Engine**: `games/pictionary/PictionaryEngine.php` (~1000 líneas)
  - Usa TODOS los módulos (Round, Turn, Score, Timer, Roles)
  - Ejemplo de inicialización completa
  - Ejemplo de múltiples acciones (draw, answer, confirm)
  - Ejemplo de fin de juego y estadísticas

- **Pictionary Score Calculator**: `games/pictionary/PictionaryScoreCalculator.php`
  - Ejemplo de calculador personalizado
  - Puntuación basada en tiempo y dificultad

- **Pictionary Tests**: `tests/Unit/Games/Pictionary/`
  - Tests de flujo completo
  - Tests de score calculator

### Interfaces (Contratos que DEBES Implementar)
- **`app/Contracts/GameEngineInterface.php`** - Motor de juego (OBLIGATORIO)
  ```php
  interface GameEngineInterface {
      public function initialize(GameMatch $match): void;
      public function processAction(GameMatch $match, Player $player, string $action, array $data): array;
      public function checkWinCondition(GameMatch $match): ?Player;
      public function getGameStateForPlayer(GameMatch $match, Player $player): array;
      public function endGame(GameMatch $match): array;
  }
  ```

- **`app/Contracts/ScoreCalculatorInterface.php`** - Calculador de puntos (OPCIONAL)
  ```php
  interface ScoreCalculatorInterface {
      public function calculate(string $event, array $context): array;
      public function supportsEvent(string $event): bool;
      public function getConfig(): array;
  }
  ```

### Arquitectura General del Proyecto
- **`docs/MODULAR_ARCHITECTURE.md`** - Visión general del sistema modular
  - Sistema de plugins por juego
  - 14 módulos configurables
  - Arquitectura monolito preparada para microservicios

- **`docs/TECHNICAL_DECISIONS.md`** - Decisiones técnicas clave
  - Por qué Laravel Reverb para WebSockets
  - Por qué configuración híbrida (JSON + BD + Redis)
  - Por qué NO hay chat (juegos presenciales)

---

## 💡 Consejos Finales

1. **Lee PRIMERO `docs/modules/ROUND_TURN_ARCHITECTURE.md`** antes de usar Round/Turn System
2. **Usa Pictionary como referencia** - Es un ejemplo completo y funcionando
3. **Escribe tests desde el principio** - Te ahorrará mucho tiempo de debugging
4. **Usa Tinker para experimentar** - Prueba los módulos interactivamente
5. **Serializa SIEMPRE** - Cada vez que modificas un módulo, re-serializa con `toArray()`
6. **No mezcles conceptos** - Round ≠ Turn, lee la separación de responsabilidades
7. **Consulta los tests** - Los tests de los módulos son excelentes ejemplos de uso

---

**Versión:** 1.1
**Fecha:** 2025-01-21
**Actualizado:** 2025-01-21 (agregada sección de errores comunes y debugging)
**Autor:** Claude Code

¿Tienes dudas? Consulta los ejemplos en `games/pictionary/` o la documentación técnica de cada módulo en `docs/modules/`.
