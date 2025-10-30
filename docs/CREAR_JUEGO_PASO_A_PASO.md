# 📋 Crear un Juego con Fases - Guía Paso a Paso

> **Guía estructurada en fases lógicas** para crear un juego sin errores siguiendo todas las convenciones de la arquitectura.

---

## 🎯 Orden de Ejecución

```
FASE 1: Planificación y Diseño
   ↓
FASE 2: Estructura de Archivos
   ↓
FASE 3: Configuración (config.json)
   ↓
FASE 4: Eventos Personalizados
   ↓
FASE 5: Engine (Backend)
   ↓
FASE 6: Frontend (Cliente)
   ↓
FASE 7: Vistas y UI
   ↓
FASE 8: Testing y Validación
```

---

## FASE 1: Planificación y Diseño

### ✅ Checklist Pre-Implementación

Antes de escribir código, responde estas preguntas:

- [ ] **¿Cuántas fases tiene tu juego por ronda?**
  - Ejemplo Mockup: 3 fases (phase1, phase2, phase3)
  - Ejemplo Pictionary: 3 fases (preparation, drawing, voting)

- [ ] **¿Cuánto dura cada fase?**
  - Ejemplo: preparation=10s, drawing=60s, voting=15s

- [ ] **¿Qué hace el jugador en cada fase?**
  - Preparation: Elegir palabra
  - Drawing: Dibujar en canvas
  - Voting: Votar si es bueno el dibujo

- [ ] **¿Necesitas eventos personalizados o genéricos?**
  - Personalizado: Si la fase tiene lógica única (mostrar canvas, botones específicos)
  - Genérico: Si solo muestra un mensaje genérico

- [ ] **¿Cuántas rondas tiene el juego?**
  - Ejemplo: 5 rondas

- [ ] **¿Cómo se calculan los puntos?**
  - Ejemplo: 10 puntos por voto positivo, 0 puntos por voto negativo

### 📝 Documento de Diseño

Crea un archivo `DESIGN.md` en `games/tu-juego/`:

```markdown
# Diseño de [Tu Juego]

## Fases del Juego

### Fase 1: Preparation (10s)
- **Descripción**: Jugador elige una palabra
- **UI**: Lista de palabras para elegir
- **Evento**: PreparationStartedEvent (personalizado)
- **Acción**: Elegir palabra → POST /api/rooms/{code}/action

### Fase 2: Drawing (60s)
- **Descripción**: Jugador dibuja la palabra
- **UI**: Canvas de dibujo
- **Evento**: DrawingStartedEvent (personalizado)
- **Acción**: Enviar trazos → WebSocket en tiempo real

### Fase 3: Voting (15s)
- **Descripción**: Jugadores votan si está bien dibujado
- **UI**: Botones Si/No
- **Evento**: VotingStartedEvent (personalizado)
- **Acción**: Votar → POST /api/rooms/{code}/action

## Puntuación
- Voto positivo: +10 puntos al dibujante
- Voto negativo: 0 puntos

## Condiciones de Fin
- Fase 2 termina si jugador finaliza dibujo antes de tiempo
- Fase 3 termina cuando todos han votado
```

---

## FASE 2: Estructura de Archivos

### 📁 Crear Directorios y Archivos Base

```bash
# Crear estructura
mkdir -p games/tu-juego/js
mkdir -p games/tu-juego/views/partials
mkdir -p app/Events/TuJuego

# Crear archivos base (vacíos por ahora)
touch games/tu-juego/config.json
touch games/tu-juego/capabilities.json
touch games/tu-juego/TuJuegoEngine.php
touch games/tu-juego/TuJuegoScoreCalculator.php
touch games/tu-juego/js/TuJuegoClient.js
touch games/tu-juego/views/game.blade.php
```

### ✅ Verificación

```bash
ls -R games/tu-juego/
```

Debes ver:

```
games/tu-juego/
├── config.json
├── capabilities.json
├── TuJuegoEngine.php
├── TuJuegoScoreCalculator.php
├── js/
│   └── TuJuegoClient.js
└── views/
    ├── game.blade.php
    └── partials/
```

---

## FASE 3: Configuración (config.json)

### 🔧 Paso 3.1: Información Básica

```json
{
  "id": "tu-juego",
  "name": "Tu Juego",
  "slug": "tu-juego",
  "version": "1.0.0",
  "description": "Descripción de tu juego",
  "minPlayers": 2,
  "maxPlayers": 8,
  "estimatedDuration": 15
}
```

### ✅ Validar
- `id` y `slug` deben ser iguales y en minúsculas
- `minPlayers` >= 1
- `maxPlayers` >= `minPlayers`

### 🔧 Paso 3.2: Configurar Timing (Round Ended)

```json
{
  "timing": {
    "round_ended": {
      "type": "countdown",
      "message": "Siguiente ronda en",
      "delay": 3,
      "auto_next": true
    }
  }
}
```

### ✅ Validar
- `delay`: Segundos de pausa entre rondas
- `auto_next: true`: El sistema avanza automáticamente
- `auto_next: false`: Requiere acción manual

### 🔧 Paso 3.3: Configurar Módulos

```json
{
  "modules": {
    "game_core": {
      "enabled": true
    },
    "room_manager": {
      "enabled": true
    },
    "guest_system": {
      "enabled": true
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 5,
      "inter_round_delay": 3
    },
    "scoring_system": {
      "enabled": true
    },
    "player_system": {
      "enabled": true
    },
    "timer_system": {
      "enabled": true
    },
    "real_time_sync": {
      "enabled": true
    }
  }
}
```

### ✅ Validar
- `round_system.total_rounds`: Número de rondas del juego
- `round_system.inter_round_delay`: Debe coincidir con `timing.round_ended.delay`
- Todos los módulos que necesitas están en `enabled: true`

### 🔧 Paso 3.4: Configurar Fases

**IMPORTANTE**: Aquí es donde defines la estructura de tu juego.

```json
{
  "modules": {
    "phase_system": {
      "enabled": true,
      "phases": [
        {
          "name": "preparation",
          "duration": 10,
          "on_start": "App\\Events\\TuJuego\\PreparationStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePreparationEnded"
        },
        {
          "name": "drawing",
          "duration": 60,
          "on_start": "App\\Events\\TuJuego\\DrawingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleDrawingEnded"
        },
        {
          "name": "voting",
          "duration": 15,
          "on_start": "App\\Events\\TuJuego\\VotingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleVotingEnded"
        }
      ]
    }
  }
}
```

### ✅ Checklist de Validación de Fases

Para CADA fase:

- [ ] **`name`**: Nombre único, lowercase, sin espacios (ej: `"preparation"`)
- [ ] **`duration`**: Segundos que dura la fase (ej: `10`)
- [ ] **`on_start`**:
  - Evento personalizado: `"App\\Events\\TuJuego\\NombreFaseStartedEvent"`
  - Evento genérico: `"App\\Events\\Game\\PhaseStartedEvent"`
- [ ] **`on_end`**: Siempre `"App\\Events\\Game\\PhaseEndedEvent"` (genérico)
- [ ] **`on_end_callback`**: Método del Engine `"handleNombreFaseEnded"` (camelCase)

### ⚠️ Convenciones Críticas

```
❌ MAL:
"on_end_callback": "handle_preparation_ended"  // Snake case
"on_end_callback": "handlepreparationended"     // Sin mayúsculas

✅ BIEN:
"on_end_callback": "handlePreparationEnded"     // camelCase
```

### 🔧 Paso 3.5: Configurar Event Config

```json
{
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "PhaseChangedEvent": {
        "name": ".phase.changed",
        "handler": "handlePhaseChanged"
      },
      "RoundStartedEvent": {
        "name": ".round.started",
        "handler": "handleRoundStarted"
      },
      "RoundEndedEvent": {
        "name": ".round.ended",
        "handler": "handleRoundEnded"
      },
      "PreparationStartedEvent": {
        "name": ".tu-juego.preparation.started",
        "handler": "handlePreparationStarted"
      },
      "DrawingStartedEvent": {
        "name": ".tu-juego.drawing.started",
        "handler": "handleDrawingStarted"
      },
      "VotingStartedEvent": {
        "name": ".tu-juego.voting.started",
        "handler": "handleVotingStarted"
      },
      "PhaseEndedEvent": {
        "name": ".game.phase.ended",
        "handler": "handlePhaseEnded"
      },
      "PlayerLockedEvent": {
        "name": ".game.player.locked",
        "handler": "handlePlayerLocked"
      },
      "PlayersUnlockedEvent": {
        "name": ".players.unlocked",
        "handler": "handlePlayersUnlocked"
      }
    }
  }
}
```

### ✅ Checklist Event Config

Para CADA evento personalizado:

- [ ] **Nombre del key**: Nombre de la clase sin namespace (ej: `"PreparationStartedEvent"`)
- [ ] **`name`**: Nombre del evento en WebSocket (ej: `".tu-juego.preparation.started"`)
  - DEBE empezar con punto (`.`)
  - Formato: `.nombre-juego.fase.started`
- [ ] **`handler`**: Nombre del método en `TuJuegoClient.js` (ej: `"handlePreparationStarted"`)

### ⚠️ Convenciones Críticas

```
❌ MAL:
"name": "tu-juego.preparation.started"      // Sin punto inicial
"name": ".TuJuego.Preparation.Started"      // Mayúsculas
"handler": "handle_preparation_started"     // Snake case

✅ BIEN:
"name": ".tu-juego.preparation.started"     // Punto inicial, lowercase, kebab-case
"handler": "handlePreparationStarted"       // camelCase
```

### 📝 Template Completo de config.json

```json
{
  "id": "tu-juego",
  "name": "Tu Juego",
  "slug": "tu-juego",
  "version": "1.0.0",
  "description": "Descripción de tu juego",
  "minPlayers": 2,
  "maxPlayers": 8,
  "estimatedDuration": 15,

  "timing": {
    "round_ended": {
      "type": "countdown",
      "message": "Siguiente ronda en",
      "delay": 3,
      "auto_next": true
    }
  },

  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "PhaseChangedEvent": {
        "name": ".phase.changed",
        "handler": "handlePhaseChanged"
      },
      "RoundStartedEvent": {
        "name": ".round.started",
        "handler": "handleRoundStarted"
      },
      "RoundEndedEvent": {
        "name": ".round.ended",
        "handler": "handleRoundEnded"
      },
      "PreparationStartedEvent": {
        "name": ".tu-juego.preparation.started",
        "handler": "handlePreparationStarted"
      },
      "DrawingStartedEvent": {
        "name": ".tu-juego.drawing.started",
        "handler": "handleDrawingStarted"
      },
      "VotingStartedEvent": {
        "name": ".tu-juego.voting.started",
        "handler": "handleVotingStarted"
      }
    }
  },

  "modules": {
    "game_core": {
      "enabled": true
    },
    "room_manager": {
      "enabled": true
    },
    "guest_system": {
      "enabled": true
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 5,
      "inter_round_delay": 3
    },
    "phase_system": {
      "enabled": true,
      "phases": [
        {
          "name": "preparation",
          "duration": 10,
          "on_start": "App\\Events\\TuJuego\\PreparationStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePreparationEnded"
        },
        {
          "name": "drawing",
          "duration": 60,
          "on_start": "App\\Events\\TuJuego\\DrawingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleDrawingEnded"
        },
        {
          "name": "voting",
          "duration": 15,
          "on_start": "App\\Events\\TuJuego\\VotingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleVotingEnded"
        }
      ]
    },
    "timer_system": {
      "enabled": true
    },
    "scoring_system": {
      "enabled": true
    },
    "player_system": {
      "enabled": true
    },
    "real_time_sync": {
      "enabled": true
    }
  }
}
```

---

## FASE 4: Eventos Personalizados

### 📝 Paso 4.1: Crear Archivo de Evento

Para CADA fase que use evento personalizado (no genérico), crear:

```bash
touch app/Events/TuJuego/PreparationStartedEvent.php
```

### 🔧 Paso 4.2: Implementar Evento

**Template de Evento Personalizado:**

```php
<?php

namespace App\Events\TuJuego;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento emitido cuando inicia la fase de preparación.
 */
class PreparationStartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $matchId;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->matchId = $match->id;
        $this->phase = 'preparation';  // ← MISMO nombre que en config.json phases[].name
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer';
        $this->serverTime = now()->timestamp;
        $this->phaseData = $phaseConfig;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'tu-juego.preparation.started';  // ← SIN punto inicial (Laravel lo agrega)
    }

    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'match_id' => $this->matchId,
            'phase' => $this->phase,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'timer_name' => $this->phase,
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
```

### ✅ Checklist por Evento

- [ ] **Namespace**: `App\Events\TuJuego`
- [ ] **Implements**: `ShouldBroadcastNow` (NO `ShouldBroadcast`)
- [ ] **Use traits**: `Dispatchable, InteractsWithSockets, SerializesModels`
- [ ] **Propiedades públicas**:
  - `$roomCode`, `$matchId`, `$phase`, `$duration`, `$timerId`, `$serverTime`, `$phaseData`
- [ ] **Constructor**:
  - Acepta `GameMatch $match, array $phaseConfig`
  - `$this->phase` = nombre de la fase en config.json
- [ ] **`broadcastOn()`**: Retorna `PresenceChannel('room.' . $this->roomCode)`
- [ ] **`broadcastAs()`**: Retorna nombre SIN punto inicial
  - ❌ `'.tu-juego.preparation.started'`
  - ✅ `'tu-juego.preparation.started'`
- [ ] **`broadcastWith()`**: Incluye todos los campos necesarios

### ⚠️ Convención Crítica: broadcastAs()

```php
// config.json
"name": ".tu-juego.preparation.started"

// Evento PHP
public function broadcastAs(): string
{
    return 'tu-juego.preparation.started';  // ← SIN punto inicial
}
```

El punto inicial en `config.json` es para el EventManager. Laravel agrega el punto automáticamente al broadcast.

### 🔧 Paso 4.3: Repetir para Todas las Fases

Crear un evento para cada fase con evento personalizado:

```bash
app/Events/TuJuego/
├── PreparationStartedEvent.php
├── DrawingStartedEvent.php
└── VotingStartedEvent.php
```

---

## FASE 5: Engine (Backend)

### 🔧 Paso 5.1: Esqueleto del Engine

```php
<?php

namespace Games\TuJuego;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class TuJuegoEngine extends BaseGameEngine
{
    // PASO 5.2: initialize()
    // PASO 5.3: onGameStart()
    // PASO 5.4: startNewRound()
    // PASO 5.5: processRoundAction()
    // PASO 5.6: endCurrentRound()
    // PASO 5.7: Callbacks de fases (handlePreparationEnded, etc.)
    // PASO 5.8: Métodos auxiliares (getAllPlayerResults, getRoundResults, getFinalScores)
}
```

### 🔧 Paso 5.2: Método `initialize()`

**RESPONSABILIDAD**: Configurar el juego UNA SOLA VEZ al crearlo.

```php
public function initialize(GameMatch $match): void
{
    Log::info("[TuJuego] Initializing", ['match_id' => $match->id]);

    // Cargar config.json
    $gameConfig = $this->getGameConfig();

    // Configuración inicial
    $match->game_state = [
        '_config' => [
            'game' => 'tu-juego',
            'initialized_at' => now()->toDateTimeString(),
            'timing' => $gameConfig['timing'] ?? null,
            'modules' => $gameConfig['modules'] ?? [],
        ],
        'phase' => 'starting',
        'actions' => [],
        // Aquí puedes agregar estado específico de tu juego
        'words' => [], // Ejemplo: palabras para dibujar
        'drawings' => [], // Ejemplo: dibujos de jugadores
    ];

    $match->save();

    // Inicializar módulos
    $this->initializeModules($match, [
        'round_system' => [
            'total_rounds' => $gameConfig['modules']['round_system']['total_rounds'] ?? 5
        ],
        'scoring_system' => [
            'calculator' => new TuJuegoScoreCalculator()
        ]
    ]);

    // Inicializar PlayerManager
    $playerIds = $match->players->pluck('id')->toArray();
    $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
        playerIds: $playerIds,
        scoreCalculator: null
    );
    $this->savePlayerManager($match, $playerManager);

    Log::info("[TuJuego] Initialized successfully", ['players' => count($playerIds)]);
}
```

### ✅ Checklist initialize()

- [ ] Cargar `config.json` usando `$this->getGameConfig()`
- [ ] Crear `game_state` con:
  - `_config.game` = slug del juego
  - `_config.timing` y `_config.modules` desde config.json
  - `phase = 'starting'`
  - Estado personalizado del juego (palabras, dibujos, etc.)
- [ ] Llamar `$this->initializeModules($match, [...])`
- [ ] Crear `PlayerManager` con IDs de jugadores
- [ ] Llamar `$this->savePlayerManager($match, $playerManager)`

### 🔧 Paso 5.3: Método `onGameStart()`

**RESPONSABILIDAD**: Lógica cuando inicia el juego (después del countdown).

```php
protected function onGameStart(GameMatch $match): void
{
    Log::info("🎮 [TuJuego] ===== PARTIDA INICIADA =====", ['match_id' => $match->id]);

    // Actualizar fase a "playing"
    $match->game_state = array_merge($match->game_state, [
        'phase' => 'playing',
    ]);
    $match->save();

    // Iniciar primera ronda
    // advanceRound: false porque es la primera ronda
    $this->handleNewRound($match, advanceRound: false);

    Log::info("🎮 [TuJuego] Primera ronda iniciada");
}
```

### ✅ Checklist onGameStart()

- [ ] Actualizar `game_state['phase'] = 'playing'`
- [ ] Llamar `$this->handleNewRound($match, advanceRound: false)`
- [ ] NO llamar directamente `startNewRound()` (lo hace `handleNewRound()`)

### 🔧 Paso 5.4: Método `startNewRound()`

**RESPONSABILIDAD**: Preparar estado para nueva ronda (desbloquear jugadores, limpiar acciones).

```php
protected function startNewRound(GameMatch $match): void
{
    $currentRound = $this->getRoundManager($match)->getCurrentRound();

    Log::info("[TuJuego] Starting new round", [
        'match_id' => $match->id,
        'round' => $currentRound,
    ]);

    // Desbloquear todos los jugadores
    $playerManager = $this->getPlayerManager($match);
    $playerManager->unlockAllPlayers($match);
    $this->savePlayerManager($match, $playerManager);

    // Emitir evento de jugadores desbloqueados
    event(new \App\Events\Game\PlayersUnlockedEvent(
        roomCode: $match->room->code,
        fromNewRound: true
    ));

    // Limpiar estado de la ronda anterior
    $gameState = $match->game_state;
    $gameState['actions'] = [];
    $gameState['phase'] = 'playing';

    // Estado específico de tu juego
    $gameState['current_word'] = null;
    $gameState['current_drawer'] = null;
    $gameState['votes'] = [];

    $match->game_state = $gameState;
    $match->save();

    Log::info("[TuJuego] Round prepared - players unlocked");
}
```

### ✅ Checklist startNewRound()

- [ ] Desbloquear jugadores con `PlayerManager::unlockAllPlayers($match)`
- [ ] Emitir `PlayersUnlockedEvent`
- [ ] Limpiar `actions`, `votes`, etc. del estado anterior
- [ ] Resetear variables específicas de tu juego

### 🔧 Paso 5.5: Método `processRoundAction()`

**RESPONSABILIDAD**: Procesar acciones de jugadores (votar, elegir palabra, etc.).

```php
protected function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    $action = $data['action'] ?? 'unknown';

    Log::info("[TuJuego] Processing action", [
        'match_id' => $match->id,
        'player_id' => $player->id,
        'action' => $action,
    ]);

    $playerManager = $this->getPlayerManager($match);

    // Validar que el jugador no esté bloqueado
    if ($playerManager->isPlayerLocked($player->id)) {
        return [
            'success' => false,
            'message' => 'Ya realizaste tu acción en esta ronda',
            'force_end' => false,
        ];
    }

    // Ejemplo: Acción de elegir palabra (en fase preparation)
    if ($action === 'choose_word') {
        $word = $data['word'] ?? null;

        if (!$word) {
            return [
                'success' => false,
                'message' => 'Debes elegir una palabra',
                'force_end' => false,
            ];
        }

        // Guardar palabra elegida
        $gameState = $match->game_state;
        $gameState['current_word'] = $word;
        $gameState['current_drawer'] = $player->id;
        $match->game_state = $gameState;
        $match->save();

        // Bloquear jugador (ya eligió)
        $playerManager->lockPlayer($player->id, $match, $player);
        $this->savePlayerManager($match, $playerManager);

        return [
            'success' => true,
            'player_id' => $player->id,
            'data' => ['word' => $word],
            'force_end' => false, // No forzar fin aún
        ];
    }

    // Ejemplo: Acción de votar (en fase voting)
    if ($action === 'vote') {
        $vote = $data['vote'] ?? null; // true/false

        if ($vote === null) {
            return [
                'success' => false,
                'message' => 'Voto inválido',
                'force_end' => false,
            ];
        }

        // Guardar voto
        $gameState = $match->game_state;
        $gameState['votes'][$player->id] = $vote;
        $match->game_state = $gameState;
        $match->save();

        // Bloquear jugador (ya votó)
        $playerManager->lockPlayer($player->id, $match, $player);
        $this->savePlayerManager($match, $playerManager);

        // Verificar si todos han votado
        $allLocked = $playerManager->areAllPlayersLocked();

        if ($allLocked) {
            Log::info("🔒 [TuJuego] All players voted - forcing round end");

            // Calcular puntos antes de terminar
            $this->calculateVotingScores($match);

            return [
                'success' => true,
                'player_id' => $player->id,
                'data' => ['vote' => $vote],
                'force_end' => true,
                'end_reason' => 'all_players_voted',
            ];
        }

        return [
            'success' => true,
            'player_id' => $player->id,
            'data' => ['vote' => $vote],
            'force_end' => false,
        ];
    }

    return [
        'success' => false,
        'message' => 'Acción desconocida',
        'force_end' => false,
    ];
}
```

### ✅ Checklist processRoundAction()

- [ ] Validar que jugador NO esté bloqueado
- [ ] Switch/if por tipo de acción (`choose_word`, `vote`, etc.)
- [ ] Guardar datos en `game_state`
- [ ] Bloquear jugador si corresponde con `PlayerManager::lockPlayer()`
- [ ] Retornar array con:
  - `success`: true/false
  - `message`: (si falla)
  - `force_end`: true si debe terminar la ronda
  - `end_reason`: (si force_end = true)
  - `player_id` y `data` (si success = true)

### 🔧 Paso 5.6: Método `endCurrentRound()`

**RESPONSABILIDAD**: Finalizar ronda y calcular resultados.

```php
public function endCurrentRound(GameMatch $match): void
{
    Log::info("[TuJuego] Ending current round", ['match_id' => $match->id]);

    // Obtener resultados de la ronda
    $allActions = $match->game_state['actions'] ?? [];
    $votes = $match->game_state['votes'] ?? [];

    // Calcular puntos finales (si no se hizo antes)
    $scoreManager = $this->getScoreManager($match);
    $scores = $scoreManager->getScores();

    // Resultados para mostrar en popup
    $results = [
        'actions' => $allActions,
        'votes' => $votes,
        'word' => $match->game_state['current_word'] ?? null,
        'drawer' => $match->game_state['current_drawer'] ?? null,
    ];

    // Completar ronda (emite RoundEndedEvent con countdown)
    $this->completeRound($match, $results, $scores);

    Log::info("[TuJuego] Round ended successfully");
}
```

### ✅ Checklist endCurrentRound()

- [ ] Obtener resultados del `game_state`
- [ ] Calcular puntos si no se hizo antes
- [ ] Crear array `$results` con datos para mostrar
- [ ] Llamar `$this->completeRound($match, $results, $scores)`

### 🔧 Paso 5.7: Callbacks de Fases

Para CADA fase definida en `config.json`, crear su callback:

```php
/**
 * Callback cuando expira la fase de preparación.
 */
public function handlePreparationEnded(GameMatch $match, array $phaseData): void
{
    Log::info("🏁 [TuJuego] PREPARATION ENDED", [
        'match_id' => $match->id,
        'phase_data' => $phaseData
    ]);

    // Obtener PhaseManager
    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    if (!$phaseManager) {
        Log::warning("[TuJuego] PhaseManager not found");
        return;
    }

    // ⚠️ CRÍTICO: Asignar match al PhaseManager
    $phaseManager->setMatch($match);

    // Avanzar a siguiente fase
    $nextPhaseInfo = $phaseManager->nextPhase();

    Log::info("➡️  [TuJuego] Next phase", [
        'phase_name' => $nextPhaseInfo['phase_name'],
        'duration' => $nextPhaseInfo['duration']
    ]);

    // Guardar RoundManager actualizado
    $this->saveRoundManager($match, $roundManager);

    // Emitir evento de cambio de fase
    event(new \App\Events\Game\PhaseChangedEvent(
        match: $match,
        newPhase: $nextPhaseInfo['phase_name'],
        previousPhase: $phaseData['name'] ?? 'preparation',
        additionalData: [
            'phase_index' => $nextPhaseInfo['phase_index'],
            'duration' => $nextPhaseInfo['duration'],
            'phase_name' => $nextPhaseInfo['phase_name']
        ]
    ));
}

/**
 * Callback cuando expira la fase de dibujo.
 */
public function handleDrawingEnded(GameMatch $match, array $phaseData): void
{
    Log::info("🏁 [TuJuego] DRAWING ENDED");

    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    if (!$phaseManager) return;

    $phaseManager->setMatch($match);
    $nextPhaseInfo = $phaseManager->nextPhase();

    $this->saveRoundManager($match, $roundManager);

    // Si completó el ciclo, terminar ronda
    if ($nextPhaseInfo['cycle_completed']) {
        Log::info("✅ [TuJuego] Cycle completed - ending round");
        $this->endCurrentRound($match);
    } else {
        // Emitir cambio de fase
        event(new \App\Events\Game\PhaseChangedEvent(
            match: $match,
            newPhase: $nextPhaseInfo['phase_name'],
            previousPhase: 'drawing'
        ));
    }
}

/**
 * Callback cuando expira la fase de votación.
 */
public function handleVotingEnded(GameMatch $match, array $phaseData): void
{
    Log::info("🏁 [TuJuego] VOTING ENDED");

    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    if (!$phaseManager) return;

    $phaseManager->setMatch($match);
    $nextPhaseInfo = $phaseManager->nextPhase();

    $this->saveRoundManager($match, $roundManager);

    // Última fase → siempre completar ciclo
    if ($nextPhaseInfo['cycle_completed']) {
        Log::info("✅ [TuJuego] Voting completed - ending round");
        $this->endCurrentRound($match);
    }
}
```

### ✅ Checklist por Callback de Fase

- [ ] Nombre del método: `handle{NombreFase}Ended` (coincide con config.json)
- [ ] Obtener `PhaseManager` desde `RoundManager`
- [ ] ⚠️ **CRÍTICO**: Llamar `$phaseManager->setMatch($match)`
- [ ] Llamar `$phaseManager->nextPhase()`
- [ ] Guardar con `$this->saveRoundManager($match, $roundManager)`
- [ ] Si `cycle_completed: true` → llamar `$this->endCurrentRound($match)`
- [ ] Si NO completó → emitir `PhaseChangedEvent`

### 🔧 Paso 5.8: Métodos Auxiliares

```php
protected function getAllPlayerResults(GameMatch $match): array
{
    return $match->game_state['actions'] ?? [];
}

protected function getRoundResults(GameMatch $match): array
{
    $scoreManager = $this->getScoreManager($match);
    $scores = $scoreManager->getScores();

    return [
        'actions' => $match->game_state['actions'] ?? [],
        'votes' => $match->game_state['votes'] ?? [],
        'scores' => $scores,
    ];
}

protected function getFinalScores(GameMatch $match): array
{
    Log::info("[TuJuego] Calculating final scores");

    $scoreManager = $this->getScoreManager($match);
    return $scoreManager->getScores();
}

protected function getGameConfig(): array
{
    $configPath = base_path('games/tu-juego/config.json');

    if (!file_exists($configPath)) {
        return [];
    }

    return json_decode(file_get_contents($configPath), true);
}

/**
 * Método auxiliar para calcular puntos de votación
 */
private function calculateVotingScores(GameMatch $match): void
{
    $votes = $match->game_state['votes'] ?? [];
    $drawer = $match->game_state['current_drawer'] ?? null;

    if (!$drawer) return;

    $positiveVotes = array_filter($votes, fn($vote) => $vote === true);
    $points = count($positiveVotes) * 10;

    if ($points > 0) {
        $scoreManager = $this->getScoreManager($match);
        $scoreManager->awardPoints($drawer, 'votes', ['points' => $points]);
        $this->saveScoreManager($match, $scoreManager);

        Log::info("[TuJuego] Awarded points to drawer", [
            'drawer_id' => $drawer,
            'points' => $points,
            'positive_votes' => count($positiveVotes)
        ]);
    }
}
```

---

## FASE 6: Frontend (Cliente)

### 🔧 Paso 6.1: Esqueleto del Cliente

```javascript
// games/tu-juego/js/TuJuegoClient.js

const { BaseGameClient } = window;

export class TuJuegoClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.config = config;
        this.customHandlers = null;
        this.setupEventManager();
    }

    // PASO 6.2: setupEventManager()
    // PASO 6.3: Handlers de fases
    // PASO 6.4: Métodos auxiliares de UI
    // PASO 6.5: Acciones de jugador
}

// Hacer disponible globalmente
window.TuJuegoClient = TuJuegoClient;
```

### 🔧 Paso 6.2: setupEventManager()

```javascript
setupEventManager() {
    this.customHandlers = {
        // Handler del DOM listo
        handleDomLoaded: (event) => {
            super.handleDomLoaded(event);
            this.setupGameControls();
            this.restoreGameState();
        },

        // Handlers de fases personalizadas
        handlePreparationStarted: (event) => {
            this.onPreparationStarted(event);
        },

        handleDrawingStarted: (event) => {
            this.onDrawingStarted(event);
        },

        handleVotingStarted: (event) => {
            this.onVotingStarted(event);
        },

        // Handler genérico de fase (si usas)
        handlePhaseStarted: (event) => {
            console.log('🎬 [TuJuego] FASE INICIADA', event.phase_name);
        },

        // Handlers de jugador bloqueado
        handlePlayerLocked: (event) => {
            this.onPlayerLocked(event);
        },

        handlePlayersUnlocked: (event) => {
            this.onPlayersUnlocked(event);
        }
    };

    super.setupEventManager(this.customHandlers);
}
```

### ✅ Checklist setupEventManager()

- [ ] Crear objeto `customHandlers` con todos los handlers
- [ ] Cada handler debe coincidir con config.json `event_config.events.*.handler`
- [ ] `handleDomLoaded` DEBE llamar `super.handleDomLoaded(event)` primero
- [ ] Llamar `super.setupEventManager(this.customHandlers)` al final

### 🔧 Paso 6.3: Handlers de Fases

```javascript
/**
 * Handler: Fase de preparación iniciada
 */
onPreparationStarted(event) {
    console.log('🎯 [TuJuego] PREPARATION PHASE STARTED');

    // Ocultar elementos de otras fases
    this.hideAllPhaseUI();

    // Mostrar UI de preparación (lista de palabras)
    const preparationUI = document.getElementById('preparation-ui');
    if (preparationUI) {
        preparationUI.style.display = 'block';
    }

    // Generar lista de palabras
    this.showWordList();

    console.log('📋 [TuJuego] Preparation UI ready');
}

/**
 * Handler: Fase de dibujo iniciada
 */
onDrawingStarted(event) {
    console.log('🎨 [TuJuego] DRAWING PHASE STARTED');

    this.hideAllPhaseUI();

    // Mostrar canvas de dibujo
    const drawingUI = document.getElementById('drawing-ui');
    if (drawingUI) {
        drawingUI.style.display = 'block';
    }

    // Inicializar canvas
    this.initializeCanvas();

    // Mostrar palabra a dibujar (solo para el dibujante)
    const currentDrawer = this.gameState?.current_drawer;
    if (currentDrawer === this.config.playerId) {
        this.showCurrentWord();
    }

    console.log('🖌️ [TuJuego] Canvas ready');
}

/**
 * Handler: Fase de votación iniciada
 */
onVotingStarted(event) {
    console.log('🗳️ [TuJuego] VOTING PHASE STARTED');

    this.hideAllPhaseUI();

    // Mostrar UI de votación
    const votingUI = document.getElementById('voting-ui');
    if (votingUI) {
        votingUI.style.display = 'block';
    }

    // Mostrar el dibujo final
    this.showFinalDrawing();

    // Mostrar botones de voto
    this.showVoteButtons();

    // Restaurar estado de bloqueado si ya votó
    this.restorePlayerLockedState();

    console.log('✅ [TuJuego] Voting UI ready');
}

/**
 * Ocultar todas las UIs de fases
 */
hideAllPhaseUI() {
    const uiElements = [
        'preparation-ui',
        'drawing-ui',
        'voting-ui'
    ];

    uiElements.forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            element.style.display = 'none';
        }
    });
}

/**
 * Handler cuando jugador es bloqueado
 */
onPlayerLocked(event) {
    if (event.player_id !== this.config.playerId) {
        return;
    }

    console.log('🔒 [TuJuego] Current player locked');

    // Ocultar botones según fase actual
    const phase = this.gameState?.phase || 'unknown';

    if (phase === 'preparation') {
        this.hideWordList();
    } else if (phase === 'voting') {
        this.hideVoteButtons();
    }

    // Mostrar mensaje de bloqueado
    const lockedMessage = document.getElementById('locked-message');
    if (lockedMessage) {
        lockedMessage.style.display = 'block';
    }
}

/**
 * Handler cuando jugadores son desbloqueados
 */
onPlayersUnlocked(event) {
    console.log('🔓 [TuJuego] Players unlocked');

    // Ocultar mensaje de bloqueado
    const lockedMessage = document.getElementById('locked-message');
    if (lockedMessage) {
        lockedMessage.style.display = 'none';
    }

    // Restaurar botones si estamos en fase correcta
    this.restorePhaseUI();
}
```

### ✅ Checklist Handlers

- [ ] Cada handler tiene nombre `onFaseStarted()` (camelCase)
- [ ] Handler oculta UI de otras fases con `hideAllPhaseUI()`
- [ ] Handler muestra UI específica de la fase
- [ ] Handler inicializa elementos interactivos (canvas, botones, etc.)
- [ ] Handler restaura estado si es necesario

### 🔧 Paso 6.4: Métodos Auxiliares de UI

```javascript
/**
 * Configurar controles del juego
 */
setupGameControls() {
    // Botón de elegir palabra
    const wordButtons = document.querySelectorAll('.word-button');
    wordButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const word = btn.dataset.word;
            this.chooseWord(word);
        });
    });

    // Botones de votación
    const yesBtn = document.getElementById('vote-yes');
    const noBtn = document.getElementById('vote-no');

    if (yesBtn) {
        yesBtn.addEventListener('click', () => this.vote(true));
    }

    if (noBtn) {
        noBtn.addEventListener('click', () => this.vote(false));
    }

    console.log('🎮 [TuJuego] Game controls setup complete');
}

/**
 * Mostrar lista de palabras para elegir
 */
showWordList() {
    const wordListContainer = document.getElementById('word-list');
    if (!wordListContainer) return;

    const words = ['Casa', 'Perro', 'Sol', 'Árbol', 'Coche'];

    wordListContainer.innerHTML = '';
    words.forEach(word => {
        const button = document.createElement('button');
        button.className = 'word-button bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg m-2';
        button.textContent = word;
        button.dataset.word = word;
        button.addEventListener('click', () => this.chooseWord(word));
        wordListContainer.appendChild(button);
    });
}

/**
 * Ocultar lista de palabras
 */
hideWordList() {
    const wordListContainer = document.getElementById('word-list');
    if (wordListContainer) {
        wordListContainer.style.display = 'none';
    }
}

/**
 * Inicializar canvas de dibujo
 */
initializeCanvas() {
    const canvas = document.getElementById('drawing-canvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');

    let drawing = false;

    canvas.addEventListener('mousedown', () => { drawing = true; });
    canvas.addEventListener('mouseup', () => { drawing = false; });
    canvas.addEventListener('mousemove', (e) => {
        if (!drawing) return;

        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;

        ctx.lineTo(x, y);
        ctx.stroke();
    });

    console.log('🖌️ [TuJuego] Canvas initialized');
}

/**
 * Mostrar palabra actual
 */
showCurrentWord() {
    const word = this.gameState?.current_word;
    if (!word) return;

    const wordDisplay = document.getElementById('current-word');
    if (wordDisplay) {
        wordDisplay.textContent = `Tu palabra: ${word}`;
        wordDisplay.style.display = 'block';
    }
}

/**
 * Mostrar botones de votación
 */
showVoteButtons() {
    const voteButtons = document.getElementById('vote-buttons');
    if (voteButtons) {
        voteButtons.style.display = 'flex';
    }
}

/**
 * Ocultar botones de votación
 */
hideVoteButtons() {
    const voteButtons = document.getElementById('vote-buttons');
    if (voteButtons) {
        voteButtons.style.display = 'none';
    }
}

/**
 * Restaurar UI de la fase actual
 */
restorePhaseUI() {
    const phase = this.gameState?.phase || 'unknown';

    if (phase === 'preparation') {
        this.showWordList();
    } else if (phase === 'voting') {
        this.showVoteButtons();
    }
}

/**
 * Restaurar estado del juego (al reconectar)
 */
restoreGameState() {
    console.log('🔄 [TuJuego] Restoring game state...', this.gameState);

    // Restaurar fase actual
    const phase = this.gameState?.phase;
    if (phase) {
        // Simular evento de fase para restaurar UI
        // (esto lo hace BaseGameClient automáticamente)
    }

    // Restaurar estado de bloqueado
    this.restorePlayerLockedState();
}

/**
 * Restaurar estado de jugador bloqueado
 */
restorePlayerLockedState() {
    if (!this.gameState || !this.gameState.player_system) {
        return;
    }

    const lockedPlayers = this.gameState.player_system.locked_players || [];
    const isLocked = lockedPlayers.includes(this.config.playerId);

    if (isLocked) {
        console.log('🔄 [TuJuego] Restoring locked state');
        this.onPlayerLocked({
            player_id: this.config.playerId,
            player_name: 'Current Player'
        });
    }
}
```

### 🔧 Paso 6.5: Acciones de Jugador

```javascript
/**
 * Elegir palabra (fase preparation)
 */
async chooseWord(word) {
    console.log('📝 [TuJuego] Choosing word:', word);

    try {
        const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                action: 'choose_word',
                word: word
            })
        });

        const data = await response.json();

        if (!response.ok) {
            console.error('❌ [TuJuego] Choose word failed:', data);
            return;
        }

        console.log('✅ [TuJuego] Word chosen successfully:', data);
    } catch (error) {
        console.error('❌ [TuJuego] Error choosing word:', error);
    }
}

/**
 * Votar (fase voting)
 */
async vote(voteValue) {
    console.log('🗳️ [TuJuego] Voting:', voteValue);

    try {
        const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                action: 'vote',
                vote: voteValue
            })
        });

        const data = await response.json();

        if (!response.ok) {
            console.error('❌ [TuJuego] Vote failed:', data);
            return;
        }

        console.log('✅ [TuJuego] Vote successful:', data);
    } catch (error) {
        console.error('❌ [TuJuego] Error voting:', error);
    }
}
```

### ✅ Checklist Frontend Completo

- [ ] Cliente extiende `BaseGameClient`
- [ ] `setupEventManager()` registra todos los handlers
- [ ] Cada handler de fase muestra/oculta UI correctamente
- [ ] Métodos auxiliares de UI bien organizados
- [ ] Acciones de jugador usan `fetch()` a `/api/rooms/{code}/action`
- [ ] Restauración de estado implementada
- [ ] Cliente exportado globalmente: `window.TuJuegoClient = TuJuegoClient`

---

## FASE 7: Vistas y UI

### 🔧 Paso 7.1: Vista Principal (game.blade.php)

```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $gameConfig['name'] ?? 'Tu Juego' }} - {{ $room->code }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <div id="app" class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold">🎨 {{ $gameConfig['name'] ?? 'Tu Juego' }}</h1>
                <p class="text-gray-400">Sala: <span class="font-mono text-yellow-400">{{ $room->code }}</span></p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-400">Ronda</p>
                <p class="text-2xl font-bold">
                    <span id="current-round">1</span>/<span id="total-rounds">5</span>
                </p>
            </div>
        </div>

        <!-- Fase y Timer -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <div class="text-center mb-4">
                <p class="text-sm text-gray-400 mb-2">Fase Actual</p>
                <p id="current-phase" class="text-3xl font-bold text-yellow-400">
                    {{ $match->game_state['phase'] ?? 'waiting' }}
                </p>
            </div>

            <!-- Timer -->
            <div id="timer-container" class="text-center">
                <p id="timer-message" class="text-sm text-gray-400 mb-2">Tiempo restante</p>
                <p id="timer" class="text-5xl font-bold text-green-400">00:00</p>
            </div>
        </div>

        <!-- UI de Fase de Preparación -->
        <div id="preparation-ui" class="bg-gray-800 rounded-lg p-6 mb-6" style="display: none;">
            <h2 class="text-2xl font-bold mb-4 text-center">Elige una palabra para dibujar</h2>
            <div id="word-list" class="flex flex-wrap justify-center">
                <!-- Palabras se generan en JS -->
            </div>
        </div>

        <!-- UI de Fase de Dibujo -->
        <div id="drawing-ui" class="bg-gray-800 rounded-lg p-6 mb-6" style="display: none;">
            <h2 class="text-2xl font-bold mb-4 text-center">¡Dibuja!</h2>
            <p id="current-word" class="text-xl text-center text-yellow-400 mb-4" style="display: none;"></p>
            <canvas id="drawing-canvas" class="bg-white mx-auto" width="600" height="400"></canvas>
        </div>

        <!-- UI de Fase de Votación -->
        <div id="voting-ui" class="bg-gray-800 rounded-lg p-6 mb-6" style="display: none;">
            <h2 class="text-2xl font-bold mb-4 text-center">¿Está bien dibujado?</h2>
            <canvas id="final-drawing" class="bg-white mx-auto mb-4" width="600" height="400"></canvas>

            <div id="vote-buttons" class="grid grid-cols-2 gap-4">
                <button id="vote-yes" class="bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-8 rounded-lg">
                    👍 Sí
                </button>
                <button id="vote-no" class="bg-red-600 hover:bg-red-700 text-white font-bold py-4 px-8 rounded-lg">
                    👎 No
                </button>
            </div>
        </div>

        <!-- Mensaje de jugador bloqueado -->
        <div id="locked-message" class="bg-blue-900/30 border-2 border-blue-500 rounded-lg p-6 text-center" style="display: none;">
            <p class="text-3xl mb-2">✅</p>
            <p class="text-xl font-bold text-blue-400">Ya realizaste tu acción</p>
            <p class="text-sm text-gray-400 mt-2">Esperando a los demás jugadores...</p>
        </div>
    </div>

    @vite(['resources/js/app.js', 'games/tu-juego/js/TuJuegoClient.js'])

    <script>
        // Pasar datos al frontend
        window.tuJuegoData = {
            roomCode: '{{ $room->code }}',
            playerId: {{ $playerId ?? 'null' }},
            userId: {{ $userId ?? 'null' }},
            gameSlug: 'tu-juego',
            gameState: @json($match->game_state ?? null),
            csrfToken: '{{ csrf_token() }}'
        };
    </script>

    <script type="module">
        const config = {
            roomCode: '{{ $room->code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId ?? 'null' }},
            userId: {{ $userId ?? 'null' }},
            gameSlug: 'tu-juego',
            players: [],
            scores: {},
            gameState: @json($match->game_state ?? null),
            eventConfig: @json($eventConfig ?? null),
        };

        const tuJuegoClient = new window.TuJuegoClient(config);
        window.tuJuegoClient = tuJuegoClient;
    </script>

    @stack('scripts')

    {{-- Popups --}}
    @include('tu-juego::partials.round_end_popup')
    @include('tu-juego::partials.game_end_popup')
    @include('tu-juego::partials.player_disconnected_popup')
</body>
</html>
```

### ✅ Checklist game.blade.php

- [ ] Incluir `@vite(['resources/css/app.css'])` en head
- [ ] Incluir CSRF token: `<meta name="csrf-token" content="{{ csrf_token() }}">`
- [ ] Mostrar código de sala: `{{ $room->code }}`
- [ ] Timer principal con id="timer"
- [ ] UI de cada fase con `id="{fase}-ui"` y `style="display: none;"`
- [ ] Mensaje de bloqueado con id="locked-message"
- [ ] Pasar datos a JS:
  - `window.tuJuegoData` con roomCode, playerId, gameState, etc.
  - Crear instancia de `TuJuegoClient` con config completo
- [ ] Incluir partials de popups (round_end, game_end, player_disconnected)

### 🔧 Paso 7.2: Popups (Partials)

**round_end_popup.blade.php:**

```blade
<div id="round-end-popup" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-2xl w-full mx-4">
        <h2 class="text-4xl font-bold text-center text-green-400 mb-6">
            🎉 Ronda <span id="popup-round-number">1</span> Finalizada
        </h2>

        <div id="popup-scores-list" class="space-y-3 mb-6">
            <!-- Scores generados dinámicamente -->
        </div>

        <div id="popup-countdown" class="text-center">
            <p class="text-gray-400">Siguiente ronda en</p>
            <p id="countdown-timer" class="text-6xl font-bold text-yellow-400">3</p>
        </div>
    </div>
</div>
```

**game_end_popup.blade.php:**

```blade
<div id="game-end-popup" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-2xl w-full mx-4 border-4 border-green-500">
        <h2 class="text-5xl font-bold text-center text-green-400 mb-6">
            🏆 ¡Partida Finalizada!
        </h2>

        <div id="game-end-winner" class="mb-6 text-center bg-gradient-to-r from-yellow-600 to-yellow-500 rounded-lg p-6">
            <h3 class="text-3xl font-bold text-white mb-2">🥇 Ganador</h3>
            <p id="winner-name" class="text-4xl font-bold text-yellow-100"></p>
            <p id="winner-score" class="text-2xl text-yellow-200 mt-2"></p>
        </div>

        <div id="game-end-rankings" class="mb-6">
            <h3 class="text-2xl font-bold text-white mb-4 text-center">Clasificación Final</h3>
            <div id="game-end-rankings-list" class="space-y-3">
                <!-- Rankings generados dinámicamente -->
            </div>
        </div>

        <div class="text-center">
            <button id="back-to-lobby-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-8 rounded-lg">
                ← Volver al Lobby
            </button>
        </div>
    </div>
</div>
```

---

## FASE 8: Testing y Validación

### 🧪 Paso 8.1: Verificar Compilación de Assets

```bash
# Compilar assets (si no está npm run dev corriendo)
npm run build

# Verificar que el archivo se generó
ls public/build/assets/ | grep TuJuegoClient
```

### ✅ Checklist Compilación

- [ ] `npm run build` ejecuta sin errores
- [ ] Archivo `.js` generado en `public/build/assets/`
- [ ] No hay errores de sintaxis en consola

### 🧪 Paso 8.2: Validar config.json

```bash
# Validar JSON syntax
php -r "json_decode(file_get_contents('games/tu-juego/config.json'));"

# Debe no mostrar nada (sin errores)
```

### ✅ Checklist config.json

- [ ] JSON válido (sin errores de sintaxis)
- [ ] Todas las fases tienen `name`, `duration`, `on_start`, `on_end`, `on_end_callback`
- [ ] `event_config.events` tiene entrada para cada evento personalizado
- [ ] Nombres de handlers coinciden entre config.json y TuJuegoClient.js

### 🧪 Paso 8.3: Testing Manual

```bash
# 1. Iniciar servidor Laravel
php artisan serve

# 2. Iniciar Reverb (WebSockets)
php artisan reverb:start

# 3. Compilar assets en modo watch
npm run dev

# 4. Abrir navegador en http://localhost:8000
```

### 📋 Checklist de Testing

#### Crear Sala

- [ ] Navegar a `/games`
- [ ] Seleccionar tu juego
- [ ] Crear sala
- [ ] Verificar código de sala generado

#### Unirse y Iniciar

- [ ] Abrir sala en 2+ navegadores (jugadores)
- [ ] Verificar que todos los jugadores aparecen
- [ ] Hacer clic en "Iniciar Partida"
- [ ] Verificar que `GameStartedEvent` llega a todos

#### Fase 1 (Preparation)

- [ ] Verificar timer comienza a contar (10s)
- [ ] Verificar UI de preparación se muestra
- [ ] Elegir palabra
- [ ] Verificar jugador se bloquea
- [ ] Verificar mensaje "Ya realizaste tu acción" aparece
- [ ] Esperar que timer expire
- [ ] Verificar que avanza a Fase 2

#### Fase 2 (Drawing)

- [ ] Verificar timer comienza (60s)
- [ ] Verificar canvas se muestra
- [ ] Dibujar en canvas
- [ ] Verificar dibujante ve la palabra
- [ ] Esperar que timer expire
- [ ] Verificar que avanza a Fase 3

#### Fase 3 (Voting)

- [ ] Verificar timer comienza (15s)
- [ ] Verificar botones de votación se muestran
- [ ] Votar (Sí/No)
- [ ] Verificar jugador se bloquea
- [ ] Verificar mensaje "Ya realizaste tu acción"
- [ ] Cuando todos votan → termina ronda
- [ ] Verificar popup de fin de ronda

#### Fin de Ronda

- [ ] Verificar scores mostrados correctamente
- [ ] Verificar countdown de 3s
- [ ] Verificar que inicia Ronda 2 automáticamente
- [ ] Verificar que jugadores se desbloquean
- [ ] Verificar que se resetea estado (nueva palabra, nuevos votos)

#### Fin de Juego

- [ ] Completar todas las rondas (5)
- [ ] Verificar popup de fin de juego
- [ ] Verificar ganador mostrado correctamente
- [ ] Verificar ranking final
- [ ] Verificar scores finales

### 🧪 Paso 8.4: Validación de Logs

```bash
# Ver logs del juego
tail -f storage/logs/laravel.log | grep "TuJuego"

# Ver logs de fases
tail -f storage/logs/laravel.log | grep "PhaseManager"

# Ver logs de eventos
tail -f storage/logs/laravel.log | grep "PreparationStartedEvent"
```

### ✅ Checklist de Logs

Buscar estos logs en orden:

- [ ] `[TuJuego] Initializing`
- [ ] `[TuJuego] ===== PARTIDA INICIADA =====`
- [ ] `[TuJuego] Starting new round`
- [ ] `[TuJuego] PREPARATION ENDED`
- [ ] `[TuJuego] DRAWING ENDED`
- [ ] `[TuJuego] VOTING ENDED`
- [ ] `[TuJuego] Ending current round`
- [ ] `[TuJuego] Round ended successfully`

### 🧪 Paso 8.5: Testing en Consola del Navegador

```javascript
// Ver cliente global
console.log(window.tuJuegoClient);

// Ver gameState
console.log(window.tuJuegoClient.gameState);

// Ver players
console.log(window.tuJuegoClient.players);

// Ver scores
console.log(window.tuJuegoClient.scores);

// Emitir acción manualmente
await fetch(`/api/rooms/${roomCode}/action`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
        action: 'choose_word',
        word: 'Test'
    })
});
```

---

## 🎯 Checklist Final Completo

### FASE 1: Planificación ✅

- [ ] Definir número y duración de fases
- [ ] Definir acciones de jugador por fase
- [ ] Decidir eventos personalizados vs genéricos
- [ ] Documentar diseño en DESIGN.md

### FASE 2: Estructura ✅

- [ ] Crear directorios (js/, views/, views/partials/)
- [ ] Crear archivos base (config.json, Engine.php, Client.js, game.blade.php)
- [ ] Verificar estructura con `ls -R`

### FASE 3: Configuración ✅

- [ ] config.json: Info básica (id, name, slug)
- [ ] config.json: Timing (round_ended con countdown)
- [ ] config.json: Módulos (round_system, phase_system, scoring_system, etc.)
- [ ] config.json: Fases (name, duration, on_start, on_end, on_end_callback)
- [ ] config.json: Event Config (eventos con name y handler)
- [ ] Validar JSON syntax con `php -r`

### FASE 4: Eventos ✅

- [ ] Crear clase PHP para cada evento personalizado
- [ ] Implementar: namespace, implements ShouldBroadcastNow, use traits
- [ ] Implementar: constructor, broadcastOn(), broadcastAs(), broadcastWith()
- [ ] Validar: broadcastAs() SIN punto inicial
- [ ] Validar: coincide con config.json event_config

### FASE 5: Engine ✅

- [ ] Implementar initialize() - configurar juego
- [ ] Implementar onGameStart() - iniciar primera ronda
- [ ] Implementar startNewRound() - desbloquear jugadores, limpiar estado
- [ ] Implementar processRoundAction() - procesar acciones de jugadores
- [ ] Implementar endCurrentRound() - finalizar ronda
- [ ] Implementar callbacks de fases (handle{Fase}Ended)
- [ ] ⚠️ CRÍTICO: Llamar `$phaseManager->setMatch($match)` en todos los callbacks
- [ ] Implementar métodos auxiliares (getAllPlayerResults, getRoundResults, getFinalScores)

### FASE 6: Frontend ✅

- [ ] Crear TuJuegoClient extends BaseGameClient
- [ ] Implementar setupEventManager() con customHandlers
- [ ] Implementar handlers de fases (onPreparationStarted, etc.)
- [ ] Implementar onPlayerLocked() y onPlayersUnlocked()
- [ ] Implementar métodos auxiliares de UI (showWordList, initializeCanvas, etc.)
- [ ] Implementar acciones de jugador (chooseWord, vote, etc.)
- [ ] Implementar restoreGameState() y restorePlayerLockedState()
- [ ] Exportar globalmente: `window.TuJuegoClient = TuJuegoClient`

### FASE 7: Vistas ✅

- [ ] game.blade.php: Header con sala y rondas
- [ ] game.blade.php: Timer principal
- [ ] game.blade.php: UI de cada fase (con display:none inicial)
- [ ] game.blade.php: Mensaje de bloqueado
- [ ] game.blade.php: Pasar config a JS (window.tuJuegoData)
- [ ] game.blade.php: Instanciar TuJuegoClient
- [ ] game.blade.php: Incluir popups (round_end, game_end, player_disconnected)
- [ ] Crear partials: round_end_popup.blade.php
- [ ] Crear partials: game_end_popup.blade.php
- [ ] Crear partials: player_disconnected_popup.blade.php

### FASE 8: Testing ✅

- [ ] Compilar assets: `npm run build`
- [ ] Validar config.json syntax
- [ ] Testing manual: Crear sala, unirse, iniciar
- [ ] Testing manual: Validar cada fase funciona
- [ ] Testing manual: Validar timers, acciones, bloqueos
- [ ] Testing manual: Validar fin de ronda con popup
- [ ] Testing manual: Validar fin de juego con ranking
- [ ] Validar logs en `storage/logs/laravel.log`
- [ ] Validar eventos en consola del navegador

---

## 🚨 Errores Comunes y Soluciones

### Error: Evento no llega al frontend

**Causa**: `broadcastAs()` tiene punto inicial

**Solución**:
```php
// ❌ MAL
public function broadcastAs(): string {
    return '.tu-juego.preparation.started';
}

// ✅ BIEN
public function broadcastAs(): string {
    return 'tu-juego.preparation.started';
}
```

### Error: Fase no avanza

**Causa**: Olvidaste llamar `$phaseManager->setMatch($match)` en callback

**Solución**:
```php
public function handlePreparationEnded(GameMatch $match, array $phaseData): void {
    $phaseManager = $roundManager->getTurnManager();

    // ⚠️ CRÍTICO: Agregar esta línea
    $phaseManager->setMatch($match);

    $nextPhaseInfo = $phaseManager->nextPhase();
}
```

### Error: Timer no aparece

**Causa**: Evento no incluye `duration`, `timer_id`, `server_time` en `broadcastWith()`

**Solución**:
```php
public function broadcastWith(): array {
    return [
        // ... otros campos
        'duration' => $this->duration,           // ← NECESARIO
        'timer_id' => $this->timerId,            // ← NECESARIO
        'server_time' => $this->serverTime,      // ← NECESARIO
        'event_class' => $this->phaseData['on_end'] ?? '...', // ← NECESARIO
    ];
}
```

### Error: Handler no se ejecuta

**Causa**: Nombre del handler en config.json no coincide con método en Client.js

**Solución**:
```json
// config.json
"PreparationStartedEvent": {
  "handler": "handlePreparationStarted"  // ← Debe coincidir exactamente
}
```

```javascript
// TuJuegoClient.js
this.customHandlers = {
    handlePreparationStarted: (event) => {  // ← Mismo nombre
        this.onPreparationStarted(event);
    }
};
```

---

## 🎉 ¡Listo!

Si completaste todas las fases y checklists, tu juego debería estar funcionando correctamente.

Para cualquier duda, revisa:
- `GUIA_COMPLETA_MOCKUP_GAME.md` - Documentación completa
- `games/mockup/` - Ejemplo de referencia
- `storage/logs/laravel.log` - Logs del sistema
