---
description: Asistente IA para crear juegos - analiza descripción y genera arquitectura completa
---

# Comando: Crear Nuevo Juego con IA

Eres un asistente experto en la arquitectura de este proyecto. Tu objetivo es crear un nuevo juego de forma **inteligente, guiada y siguiendo todos los patrones establecidos**.

## Sintaxis

```
/create-game [ruta-a-descripcion.md]
```

**Dos modos de operación:**

1. **Modo IA (recomendado)**: `/create-game docs/game-ideas/mi-juego.md`
   - Analiza archivo con descripción del juego
   - Infiere automáticamente módulos y arquitectura
   - Pregunta SOLO lo ambiguo o crítico
   - Genera estructura completa

2. **Modo Interactivo**: `/create-game` (sin argumentos)
   - Hace 12 preguntas paso a paso
   - Útil para explorar opciones

---

## 📚 Arquitectura Actualizada (IMPORTANTE)

Antes de empezar, debes conocer la arquitectura actual del proyecto:

### Módulos del Sistema (14 configurables)

**Core (siempre activos):**
- `game_core` - Ciclo de vida del juego
- `room_manager` - Gestión de salas

**Opcionales (se activan según necesidad):**
- `guest_system` - Invitados sin registro
- `turn_system` - Turnos (sequential/simultaneous/free)
- `scoring_system` - Puntuación y ranking
- `teams_system` - Agrupación en equipos
- `timer_system` - Temporizadores con auto-advance
- `roles_system` - Roles específicos del juego
- `card_deck_system` - Gestión de mazos
- `board_grid_system` - Tableros de juego
- `spectator_mode` - Observadores
- `ai_players` - Bots/IA
- `replay_history` - Grabación de partidas
- `real_time_sync` - WebSockets (Laravel Reverb)

### PlayerManager Unificado (CRÍTICO)

**USAR PlayerManager, NO PlayerStateManager**

PlayerManager combina:
- Scores (puntuación)
- Player state (locks, actions, custom states)
- Roles (persistent y round-based)

```php
// ✅ CORRECTO - Inicializar en initialize()
$playerManager = new PlayerManager(
    $playerIds,
    $this->scoreCalculator, // ← Propiedad de clase
    [
        'available_roles' => ['drawer', 'guesser'], // Si usa roles
        'allow_multiple_persistent_roles' => false,
        'track_score_history' => false,
    ]
);
$this->savePlayerManager($match, $playerManager);

// ✅ CORRECTO - Sumar puntos (emite evento automáticamente)
$points = $playerManager->awardPoints($playerId, 'correct_answer', $context, $match);

// ✅ CORRECTO - Bloquear jugador
$playerManager->lockPlayer($playerId, $match, $player, $metadata);
$this->savePlayerManager($match, $playerManager);

// ✅ CORRECTO - Reset en startNewRound()
$playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
$playerManager->reset($match); // Emite PlayersUnlockedEvent automáticamente
$this->savePlayerManager($match, $playerManager); // ← CRÍTICO: Guardar inmediatamente
```

### BaseGameEngine - Métodos Heredados

**NO duplicar estos métodos** - se heredan automáticamente:

```php
// ✅ Heredado de BaseGameEngine
protected function getGameConfig(): array
// Carga automáticamente games/{slug}/config.json

// ✅ Heredado de BaseGameEngine
protected function getFinalScores(GameMatch $match): array
// Obtiene scores desde PlayerManager automáticamente
```

### Round Lifecycle Protocol

**Flujo estándar que TODOS los juegos siguen:**

1. **Inicio de Ronda** (`handleNewRound`):
   ```php
   // BaseGameEngine automáticamente:
   // 1. Avanza contador de ronda
   // 2. Inicia timer de ronda
   // 3. Llama a startNewRound() del juego
   // 4. Filtra game_state con filterGameStateForBroadcast()
   // 5. Emite RoundStartedEvent con timing

   protected function startNewRound(GameMatch $match): void
   {
       // 1. Reset locks y acciones
       $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
       $playerManager->reset($match);
       $this->savePlayerManager($match, $playerManager); // ← CRÍTICO

       // 2. Lógica específica del juego
       $this->loadNextQuestion($match);
       $this->assignRoles($match); // Si usa roles
   }
   ```

2. **Fin de Ronda** (`endCurrentRound`):
   ```php
   public function endCurrentRound(GameMatch $match): void
   {
       // Obtener resultados
       $results = $this->getAllPlayerResults($match);

       // Delegar al base (emite RoundEndedEvent automáticamente)
       $this->completeRound($match, $results);
   }
   ```

3. **Filtrar Información Sensible**:
   ```php
   protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
   {
       $filtered = $gameState;

       // Remover información que no todos deben ver
       unset($filtered['current_answer']); // Solo mostrar al terminar

       return $filtered;
   }
   ```

### Protocolo de Refresh/Reconexión (F5)

**Problema**: Cuando jugador refresca (F5), pierde estado local del frontend.

**Solución Backend** - `/api/rooms/{code}/state`:
```php
// Retornar game_state COMPLETO (SIN filtrar)
// El frontend decide qué mostrar según rol del jugador
return response()->json([
    'game_state' => $match->game_state, // NO usar filterGameStateForBroadcast aquí
    'players' => $players,
]);
```

**Solución Frontend** - `game.blade.php`:
```javascript
// 1. Cargar estado desde API
const response = await fetch(`/api/rooms/${roomCode}/state`);
const { game_state, players } = await response.json();

// 2. Cargar players y scores
gameClient.players = players;
gameClient.scores = extractScores(game_state.player_system);

// 3. Si juego está en 'playing', simular evento de ronda
if (game_state?.phase === 'playing') {
    const eventData = {
        current_round: game_state.round_system?.current_round,
        total_rounds: game_state.round_system?.total_rounds,
        game_state: game_state,
        timing: extractTimingFromActiveTimer(game_state.timer_system)
    };

    gameClient.handleRoundStarted(eventData);

    // 4. Restaurar información privada según rol
    if (hasPrivateRole(game_state, playerId)) {
        // Restaurar info privada (ej: palabra del drawer, cartas, etc.)
        const privateInfo = game_state.private_data_for_role;
        gameClient.handlePrivateInfo(privateInfo);
    }

    // 5. Restaurar locks
    if (game_state.player_system?.players?.[playerId]?.locked) {
        gameClient.isLocked = true;
        gameClient.showLockedUI();
    }

    // 6. Restaurar elementos visuales (canvas, tablero, etc.)
    if (game_state.canvas_data) {
        gameClient.restoreCanvas(game_state.canvas_data);
    }
}

// 7. Si juego está en 'finished', simular evento de fin
if (game_state?.phase === 'finished') {
    const finishedEvent = {
        winner: game_state.winner,
        ranking: game_state.ranking,
        scores: gameClient.scores,
        game_state: game_state
    };
    gameClient.handleGameFinished(finishedEvent);
}
```

**⚠️ CRÍTICO**:
- `/api/rooms/{code}/state` NO debe filtrar información (retorna todo)
- El frontend decide qué mostrar según el rol del jugador
- Eventos públicos (RoundStartedEvent) SÍ deben usar `filterGameStateForBroadcast()`

### Protocolo de Desconexión/Reconexión

**Flujo Automático en BaseGameEngine:**

1. **Jugador se desconecta** → `onPlayerDisconnected()`:
```php
// BaseGameEngine automáticamente:
// 1. Pausa timer de ronda
// 2. Marca juego como pausado
// 3. Emite PlayerDisconnectedEvent
// Los juegos pueden override para comportamiento custom
```

2. **Jugador se reconecta** → `onPlayerReconnected()`:
```php
// BaseGameEngine por defecto REINICIA la ronda actual
// Los juegos pueden override para solo resumir:

public function onPlayerReconnected(GameMatch $match, Player $player): void
{
    // Quitar pausa
    $gameState = $match->game_state;
    $gameState['paused'] = false;
    unset($gameState['paused_reason']);
    $match->game_state = $gameState;
    $match->save();

    // Resumir timer
    $timerService = $this->getTimerService($match);
    if ($timerService->hasTimer('round')) {
        $timerService->resumeTimer('round');
        $this->saveTimerService($match, $timerService);
    }

    // Emitir evento de reconexión (sin reiniciar ronda)
    event(new PlayerReconnectedEvent($match, $player, false));
}
```

**Ejemplo en generación**:
```php
// Si el juego necesita comportamiento especial al reconectar:
// 1. Pictionary: Resume sin reiniciar (mantiene dibujo y palabra)
// 2. Trivia: Resume sin reiniciar (mantiene pregunta)
// 3. Juego de turnos: Podría reiniciar turno actual
```

### Juegos de Referencia

**Estudia estos juegos como ejemplos:**

1. **Pictionary** (`games/pictionary/`):
   - ✅ Usa PlayerManager correctamente
   - ✅ Roles de ronda (drawer/guesser rotando)
   - ✅ filterGameStateForBroadcast() (oculta palabra)
   - ✅ Eventos privados (WordRevealedEvent solo al drawer)
   - ✅ Canvas de dibujo
   - ✅ Claim + validation pattern

2. **Trivia** (`games/trivia/`):
   - ✅ Usa PlayerManager correctamente
   - ✅ Sin roles (todos responden)
   - ✅ filterGameStateForBroadcast() (oculta correct_answer)
   - ✅ Speed bonus con timer
   - ✅ Lock cuando responde (correcto o incorrecto)

### Documentación de Referencia

**Leer según necesidad:**
- `docs/ROUND_LIFECYCLE_PROTOCOL.md` - Protocolo completo con checklist
- `docs/GAME_MODULES_REFERENCE.md` - Detalles técnicos de módulos
- `docs/CREATE_GAME_GUIDE.md` - Templates y convenciones
- `docs/CONVENTIONS.md` - Convenciones de código
- `docs/TIMER_SYSTEM_INTEGRATION.md` - Timer implementation
- `docs/BASE_ENGINE_CLIENT_DESIGN.md` - Arquitectura base

---

## 🤖 Modo IA: Análisis Inteligente

### Paso 1: Leer Archivo de Descripción

El usuario proporciona un archivo markdown con la descripción del juego.

**Ejemplo de archivo:**

```markdown
# Speed Math Challenge

## Descripción
Juego de matemáticas rápidas donde los jugadores compiten para resolver operaciones lo más rápido posible.

## Mecánica
1. Se muestra una operación matemática (suma, resta, multiplicación)
2. Todos los jugadores responden al mismo tiempo
3. El primero en acertar gana más puntos
4. 10 preguntas por partida
5. Cada pregunta tiene 15 segundos

## Puntuación
- Respuesta correcta: 10 puntos base
- Speed bonus: +5 puntos si responde en primeros 5 segundos
- Respuesta incorrecta: 0 puntos

## Jugadores
- Mínimo 2, máximo 8 jugadores
- Permite invitados

## Configuración opcional
- Dificultad: fácil/medio/difícil
- Número de preguntas: 5/10/15
```

### Paso 2: Análisis Automático

Analiza el archivo e infiere automáticamente:

#### 2.1 Información Básica
- **Nombre**: Speed Math Challenge
- **Slug**: `speed-math-challenge`
- **Tipo**: Preguntas y respuestas (Q&A)
- **Descripción**: [extraer del archivo]

#### 2.2 Módulos Necesarios (Inferencia)

Analiza las palabras clave y mecánicas para inferir módulos:

| Palabra clave en descripción | Módulo a activar | Configuración |
|------------------------------|------------------|---------------|
| "compiten" | `scoring_system` | enabled: true |
| "10 preguntas por partida" | `round_system` | total_rounds: 10 |
| "al mismo tiempo" | `turn_system` | mode: "simultaneous" |
| "15 segundos" | `timer_system` | round_duration: 15 |
| "permite invitados" | `guest_system` | enabled: true |
| "primeros en acertar" | PlayerManager | uses_locks: true |
| "speed bonus" | ScoreCalculator | speed_bonus: true |

**Resultado del análisis:**
```json
{
  "modules": {
    "round_system": {"enabled": true, "total_rounds": 10},
    "turn_system": {"enabled": true, "mode": "simultaneous"},
    "scoring_system": {"enabled": true},
    "timer_system": {"enabled": true, "round_duration": 15},
    "guest_system": {"enabled": true}
  },
  "player_config": {
    "min": 2,
    "max": 8,
    "uses_locks": true,
    "uses_roles": false
  },
  "scoring": {
    "base_points": 10,
    "speed_bonus": true,
    "speed_threshold": 5
  }
}
```

#### 2.3 Identificar Ambigüedades

Busca información que falta o está ambigua:

**Preguntas pendientes:**
- ✅ Tiene rondas: Sí (10 preguntas)
- ✅ Tiene timer: Sí (15s por ronda)
- ✅ Turnos: Simultáneo
- ✅ Puntuación: Definida (10 base + 5 bonus)
- ❓ **Ambiguo**: ¿Todos pueden seguir respondiendo o se bloquea al primero que acierta?
- ❓ **Ambiguo**: ¿Qué pasa cuando expira el timer?
- ❓ **Falta**: ¿Cómo se generan las operaciones matemáticas?
- ❓ **Falta**: ¿Se permiten equipos?

### Paso 3: Preguntas Inteligentes

Usa `AskUserQuestion` para preguntar SOLO lo ambiguo/faltante:

```javascript
AskUserQuestion({
  questions: [
    {
      question: "Cuando el timer expira (15s), ¿qué sucede?",
      header: "Timer expira",
      multiSelect: false,
      options: [
        {
          label: "Termina la ronda, nadie gana puntos",
          description: "La pregunta se pierde si nadie responde a tiempo"
        },
        {
          label: "Termina la ronda, quien haya respondido gana",
          description: "Los que respondieron (correcto/incorrecto) mantienen resultado"
        }
      ]
    },
    {
      question: "Cuando alguien acierta, ¿los demás pueden seguir respondiendo?",
      header: "Lock behavior",
      multiSelect: false,
      options: [
        {
          label: "No - termina la ronda inmediatamente",
          description: "El primero en acertar gana, otros pierden oportunidad"
        },
        {
          label: "Sí - todos responden, se rankea por velocidad",
          description: "Todos los que acierten ganan puntos (más rápido = más puntos)"
        }
      ]
    },
    {
      question: "¿Cómo se generan las operaciones matemáticas?",
      header: "Generación",
      multiSelect: false,
      options: [
        {
          label: "Pre-cargadas desde JSON",
          description: "Crear questions.json con operaciones"
        },
        {
          label: "Generadas aleatoriamente en runtime",
          description: "El Engine genera operaciones dinámicamente"
        }
      ]
    },
    {
      question: "¿Se permiten equipos?",
      header: "Equipos",
      multiSelect: false,
      options: [
        {
          label: "Solo individual",
          description: "Cada jugador compite solo"
        },
        {
          label: "Solo equipos",
          description: "Jugadores se agrupan en equipos"
        },
        {
          label: "Ambos (configurable)",
          description: "El host elige en lobby"
        }
      ]
    }
  ]
})
```

### Paso 4: Completar Configuración

Con las respuestas, completa la configuración:

```json
{
  "game": {
    "name": "Speed Math Challenge",
    "slug": "speed-math-challenge",
    "type": "questions_answers",
    "description": "Juego de matemáticas rápidas donde los jugadores compiten para resolver operaciones lo más rápido posible"
  },
  "players": {
    "min": 2,
    "max": 8,
    "guest_support": true
  },
  "teams": {
    "enabled": false // Según respuesta
  },
  "modules": {
    "game_core": {"enabled": true},
    "room_manager": {"enabled": true},
    "guest_system": {"enabled": true},
    "round_system": {
      "enabled": true,
      "total_rounds": 10,
      "customizable": true,
      "min": 5,
      "max": 15
    },
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous"
    },
    "scoring_system": {
      "enabled": true,
      "calculator": "SpeedMathScoreCalculator"
    },
    "timer_system": {
      "enabled": true,
      "round_duration": 15,
      "countdown_visible": true,
      "warning_threshold": 5,
      "auto_advance_on_expire": true
    },
    "real_time_sync": {"enabled": true}
  },
  "scoring": {
    "base_correct": 10,
    "speed_bonus_enabled": true,
    "speed_threshold": 5,
    "speed_bonus_amount": 5,
    "incorrect_penalty": 0
  },
  "timing": {
    "round_start": {
      "duration": 15,
      "countdown_visible": true,
      "warning_threshold": 5
    },
    "round_ended": {
      "auto_next": true,
      "delay": 3,
      "message": "Siguiente pregunta"
    }
  },
  "customizableSettings": {
    "questions_per_game": {
      "type": "number",
      "min": 5,
      "max": 15,
      "default": 10,
      "step": 5
    },
    "difficulty": {
      "type": "select",
      "options": ["easy", "medium", "hard"],
      "default": "medium"
    }
  }
}
```

### Paso 5: Generar Estructura

Genera TODOS los archivos necesarios:

#### 5.1 Engine (con PlayerManager)

```php
<?php

namespace Games\SpeedMathChallenge;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class SpeedMathChallengeEngine extends BaseGameEngine
{
    protected SpeedMathChallengeScoreCalculator $scoreCalculator;

    public function __construct()
    {
        $gameConfig = $this->getGameConfig(); // ← Heredado de BaseGameEngine
        $scoringConfig = $gameConfig['scoring'] ?? [];
        $this->scoreCalculator = new SpeedMathChallengeScoreCalculator($scoringConfig);
    }

    public function initialize(GameMatch $match): void
    {
        // TODO: Cargar preguntas (JSON o generadas)
        $questions = $this->loadQuestions($match);

        // Config inicial
        $match->game_state = [
            '_config' => [
                'game' => 'speed-math-challenge',
                'initialized_at' => now()->toDateTimeString(),
                // ... más config
            ],
            'phase' => 'waiting',
            'questions' => $questions,
            'current_question' => null,
        ];
        $match->save();

        // Cachear players
        $this->cachePlayersInState($match);

        // Inicializar módulos automáticamente
        $this->initializeModules($match, [
            'scoring_system' => [
                'calculator' => $this->scoreCalculator
            ],
            'round_system' => [
                'total_rounds' => count($questions)
            ]
        ]);

        // Inicializar PlayerManager (NO PlayerStateManager)
        $playerIds = $match->players->pluck('id')->toArray();
        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager(
            $playerIds,
            $this->scoreCalculator,
            [
                'available_roles' => [], // Sin roles
                'allow_multiple_persistent_roles' => false,
                'track_score_history' => false,
            ]
        );
        $this->savePlayerManager($match, $playerManager);
    }

    protected function onGameStart(GameMatch $match): void
    {
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
        ]);
        $match->save();

        // Iniciar primera ronda (automático con RoundManager)
        $this->handleNewRound($match, advanceRound: false);
    }

    protected function startNewRound(GameMatch $match): void
    {
        // 1. Reset locks (emite PlayersUnlockedEvent automáticamente)
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $playerManager->reset($match);
        $this->savePlayerManager($match, $playerManager); // ← CRÍTICO

        // 2. Cargar siguiente pregunta
        $question = $this->loadNextQuestion($match);

        // Timer ya se inició automáticamente por handleNewRound()
    }

    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Validar respuesta
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);

        if ($playerManager->isPlayerLocked($player->id)) {
            return ['success' => false, 'message' => 'Ya respondiste'];
        }

        $currentQuestion = $this->getCurrentQuestion($match);
        $answer = $data['answer'] ?? null;
        $isCorrect = ($answer === $currentQuestion['correct_answer']);

        $forceEnd = false;

        if ($isCorrect) {
            // Sumar puntos con speed bonus
            $context = [
                'time_taken' => $this->getElapsedTime($match, 'round'),
                'time_limit' => 15,
            ];
            $points = $playerManager->awardPoints($player->id, 'correct_answer', $context, $match);

            // TODO: Según configuración, ¿terminar ronda o seguir?
            // $forceEnd = true; // Si solo el primero gana
        }

        // Bloquear jugador
        $playerManager->lockPlayer($player->id, $match, $player, ['is_correct' => $isCorrect]);
        $this->savePlayerManager($match, $playerManager);

        return [
            'success' => true,
            'is_correct' => $isCorrect,
            'force_end' => $forceEnd,
        ];
    }

    public function endCurrentRound(GameMatch $match): void
    {
        $results = $this->getAllPlayerResults($match);
        $this->completeRound($match, $results); // Automático
    }

    protected function getAllPlayerResults(GameMatch $match): array
    {
        $playerManager = $this->getPlayerManager($match, $this->scoreCalculator);
        $allActions = $playerManager->getAllActions();
        $currentQuestion = $this->getCurrentQuestion($match);

        // TODO: Formatear resultados
        return [
            'question' => $currentQuestion,
            'players' => $allActions,
        ];
    }

    protected function filterGameStateForBroadcast(array $gameState, GameMatch $match): array
    {
        $filtered = $gameState;

        // Ocultar respuesta correcta hasta que termine la ronda
        if (isset($filtered['current_question']['correct_answer'])) {
            unset($filtered['current_question']['correct_answer']);
        }

        return $filtered;
    }

    // getGameConfig() y getFinalScores() se heredan automáticamente

    // TODO: Implementar helpers específicos del juego
}
```

#### 5.2 ScoreCalculator

```php
<?php

namespace Games\SpeedMathChallenge;

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class SpeedMathChallengeScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_correct' => 10,
            'speed_bonus_enabled' => true,
            'speed_threshold' => 5,
            'speed_bonus_amount' => 5,
        ], $config);
    }

    public function calculate(string $reason, array $context = []): int
    {
        return match($reason) {
            'correct_answer' => $this->calculateCorrectAnswer($context),
            default => 0,
        };
    }

    private function calculateCorrectAnswer(array $context): int
    {
        $points = $this->config['base_correct'];

        // Speed bonus
        if ($this->config['speed_bonus_enabled']) {
            $timeTaken = $context['time_taken'] ?? PHP_INT_MAX;
            $threshold = $this->config['speed_threshold'];

            if ($timeTaken <= $threshold) {
                $points += $this->config['speed_bonus_amount'];
            }
        }

        return $points;
    }
}
```

#### 5.3 config.json

```json
{
  "name": "Speed Math Challenge",
  "slug": "speed-math-challenge",
  "description": "Juego de matemáticas rápidas",
  "type": "questions_answers",
  "version": "1.0.0",
  "players": {
    "min": 2,
    "max": 8
  },
  "modules": {
    "guest_system": {"enabled": true},
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous"
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 10
    },
    "scoring_system": {
      "enabled": true,
      "calculator": "SpeedMathChallengeScoreCalculator"
    },
    "timer_system": {
      "enabled": true,
      "round_duration": 15,
      "countdown_visible": true,
      "warning_threshold": 5
    },
    "real_time_sync": {"enabled": true}
  },
  "scoring": {
    "base_correct": 10,
    "speed_bonus_enabled": true,
    "speed_threshold": 5,
    "speed_bonus_amount": 5
  },
  "timing": {
    "round_start": {
      "duration": 15,
      "countdown_visible": true,
      "warning_threshold": 5
    },
    "round_ended": {
      "auto_next": true,
      "delay": 3,
      "message": "Siguiente pregunta"
    }
  },
  "customizableSettings": {
    "questions_per_game": {
      "type": "number",
      "min": 5,
      "max": 15,
      "default": 10,
      "step": 5
    },
    "difficulty": {
      "type": "select",
      "options": ["easy", "medium", "hard"],
      "default": "medium"
    }
  }
}
```

#### 5.4 Frontend (GameClient.js)

```javascript
class SpeedMathChallengeGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.currentQuestion = null;
    }

    // Heredado automáticamente:
    // - handleRoundStarted() (inicia timer automáticamente)
    // - handleRoundEnded() (muestra resultados + countdown auto-next)
    // - handlePlayersUnlocked() (resetea locks)

    handleRoundStarted(event) {
        super.handleRoundStarted(event); // ← IMPORTANTE: Llamar al base

        // Lógica específica del juego
        this.currentQuestion = event.game_state.current_question;
        this.renderQuestion(this.currentQuestion);
        this.showElement('playing-state');
    }

    handleRoundEnded(event) {
        super.handleRoundEnded(event); // ← Actualiza scores + inicia countdown

        // Mostrar resultados específicos
        this.showCorrectAnswer(event.results.question.correct_answer);
        this.showPlayerResults(event.results.players);
    }

    handlePlayersUnlocked(event) {
        this.isLocked = false;
        this.hideElement('waiting-validation');
        this.showElement('answer-input');
    }

    // TODO: Implementar UI específica
    renderQuestion(question) { }
    showCorrectAnswer(answer) { }
    showPlayerResults(players) { }

    getTimerElement() {
        return document.getElementById('round-timer');
    }

    getCountdownElement() {
        return document.getElementById('next-round-countdown');
    }
}
```

#### 5.5 PRD (Product Requirements Document)

[Generación automática basada en toda la información recopilada]

---

## 🔄 Modo Interactivo (Backward Compatibility)

Si el usuario ejecuta `/create-game` sin argumentos, usar el flujo original de 12 preguntas interactivas (mantener compatibilidad con versión actual).

---

## ✅ Checklist de Generación

Antes de finalizar, verificar:

**Backend:**
- [ ] PlayerManager inicializado (NO PlayerStateManager)
- [ ] scoreCalculator como propiedad de clase
- [ ] startNewRound() incluye reset() + savePlayerManager()
- [ ] filterGameStateForBroadcast() implementado (si tiene info sensible)
- [ ] NO duplica getGameConfig() ni getFinalScores()
- [ ] Sigue Round Lifecycle Protocol
- [ ] onPlayerReconnected() implementado (si necesita comportamiento especial)
- [ ] config.json tiene timing para auto-next
- [ ] Sintaxis PHP válida (php -l)
- [ ] JSON válido (jq)

**Frontend:**
- [ ] GameClient hereda de BaseGameClient
- [ ] GameClient llama a super() en overrides (handleRoundStarted, handleRoundEnded, handleGameFinished)
- [ ] getTimerElement() y getCountdownElement() implementados
- [ ] game.blade.php incluye lógica de restauración para phase='playing'
- [ ] game.blade.php incluye lógica de restauración para phase='finished'
- [ ] Restaura información privada según rol (si aplica)
- [ ] Restaura elementos visuales (canvas, tablero, etc.) en refresh

**Módulos:**
- [ ] Todos los módulos configurados correctamente
- [ ] timing.round_ended con auto_next configurado

---

## 🎯 Output Final

Al terminar, mostrar:

```
✨ ¡Juego "{Game Name}" creado con éxito!

📂 Estructura generada:
✅ games/{slug}/{GameName}Engine.php (con PlayerManager)
✅ games/{slug}/{GameName}ScoreCalculator.php
✅ games/{slug}/config.json (módulos configurados)
✅ games/{slug}/questions.json (si Q&A)
✅ games/{slug}/views/game.blade.php
✅ games/{slug}/js/{GameName}GameClient.js
✅ prds/game-{slug}.md

🎮 Arquitectura Aplicada:
✅ PlayerManager unificado (scores + state + roles)
✅ Round Lifecycle Protocol completo
✅ filterGameStateForBroadcast() para seguridad
✅ Hereda getGameConfig() y getFinalScores() del base
✅ Protocolo de Refresh/Reconexión (F5)
✅ Protocolo de Desconexión/Reconexión
✅ Auto-next con timing configurado
✅ WebSockets (Laravel Reverb)

🔧 Módulos Configurados:
{Lista de módulos con sus configuraciones}

📋 Siguiente paso:

Usa /generate-tasks para crear lista detallada:
  /generate-tasks prds/game-{slug}.md

Luego implementa con /process-task-list

📚 Juegos de referencia:
- games/pictionary/ - Roles, canvas, claim pattern
- games/trivia/ - Sin roles, simultaneous, speed bonus
```

---

## 🚀 Ejecución

**Inicio:**
1. Detectar si hay archivo de descripción
2. Si hay archivo:
   - Leer y analizar contenido
   - Inferir módulos y configuración
   - Hacer preguntas solo sobre ambigüedades
3. Si NO hay archivo:
   - Flujo de 12 preguntas interactivo
4. Generar estructura COMPLETA
5. Validar sintaxis
6. Mostrar output con next steps

**¡Comencemos!** 🚀
