# Estrategia Híbrida de Eventos: Lobby → Transition → Game Room

## 📋 Tabla de Contenidos
- [Filosofía](#filosofía)
- [Flujo Completo](#flujo-completo)
- [Fases del Juego](#fases-del-juego)
- [Eventos por Fase](#eventos-por-fase)
- [Implementación en Engines](#implementación-en-engines)
- [Frontend: Listeners por Fase](#frontend-listeners-por-fase)
- [Migraciones de Estado](#migraciones-de-estado)

---

## Filosofía

La arquitectura usa una **estrategia híbrida** que combina:

1. **Eventos genéricos del BaseGameEngine** → Para flujo de lobby y transiciones
2. **Eventos de infraestructura (countdown, initialized)** → Para fase de transición
3. **Eventos específicos del juego** → Para la lógica del juego en sí

**¿Por qué híbrida?**
- ✅ Reutiliza eventos comunes entre todos los juegos
- ✅ Permite validaciones de conexión ANTES de cargar el engine
- ✅ Separa responsabilidades: transición vs juego
- ✅ No duplica código entre juegos

---

## Flujo Completo

```
┌─────────────────────────────────────────────────────────────────────┐
│                          FASE 1: LOBBY                               │
│  Estado Room: waiting                                                │
│  Engine: NO cargado                                                  │
├─────────────────────────────────────────────────────────────────────┤
│  1. Jugadores se conectan via Presence Channel                      │
│  2. Master configura opciones (equipos, rondas, etc.)               │
│  3. Master hace click "Iniciar Partida"                             │
│     → Backend: GameMatch::start()                                   │
│     → Backend: Cambia estado a ACTIVE                               │
│     → Backend: Emite game.started (BaseGameEngine)                  │
│  4. Frontend: LobbyManager escucha .game.started                    │
│     → Redirige a /rooms/{code}                                      │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│                      FASE 2: TRANSITION                              │
│  Estado Room: active                                                 │
│  Engine: NO cargado (aún no existe)                                 │
│  Vista: resources/views/rooms/transition.blade.php                  │
├─────────────────────────────────────────────────────────────────────┤
│  1. RoomController::show() detecta estado ACTIVE                    │
│     → Renderiza vista transition con lista de jugadores esperados   │
│  2. Presence Channel detecta jugadores conectándose                 │
│     → Muestra badges: "Conectado ✓" / "Esperando..."               │
│  3. Cuando TODOS los jugadores esperados están presentes:           │
│     → Frontend: POST /api/rooms/{code}/ready                        │
│     → Backend: Emite GameCountdownEvent (3 segundos)                │
│  4. Frontend escucha .game.countdown                                │
│     → Muestra countdown visual: "3... 2... 1..."                    │
│  5. Cuando countdown = 0:                                           │
│     → Frontend: POST /api/rooms/{code}/initialize-engine            │
│     → Backend: GameMatch::initializeEngine()                        │
│     → Backend: Carga el engine del juego                            │
│     → Backend: Llama engine->initialize() y engine->startGame()     │
│     → Backend: Cambia estado a PLAYING                              │
│     → Backend: Engine emite GameInitializedEvent                    │
│  6. Frontend escucha .game.initialized                              │
│     → Redirige a /rooms/{code} (ahora con engine cargado)           │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│                       FASE 3: GAME ROOM                              │
│  Estado Room: playing                                                │
│  Engine: CARGADO y ejecutándose                                     │
│  Vista: {slug}::game (ej: trivia::game)                             │
├─────────────────────────────────────────────────────────────────────┤
│  1. RoomController::show() detecta estado PLAYING                   │
│     → Carga vista del juego específico                              │
│  2. Frontend: BaseGameClient escucha eventos genéricos:             │
│     - .turn.started                                                 │
│     - .turn.played                                                  │
│     - .turn.ended                                                   │
│     - .round.started                                                │
│     - .round.ended                                                  │
│     - .game.finished                                                │
│  3. Frontend: GameClient específico escucha eventos del juego:      │
│     - .trivia.question-shown                                        │
│     - .trivia.answer-submitted                                      │
│     - .trivia.answer-revealed                                       │
│     - .trivia.scores-updated                                        │
│  4. Juego continúa emitiendo eventos según acciones de jugadores    │
│  5. Cuando juego termina:                                           │
│     → Backend: Emite game.finished                                  │
│     → Backend: Cambia estado a FINISHED                             │
│     → Frontend: Redirige a /rooms/{code}/results                    │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Fases del Juego

### Estado de la Room en Base de Datos

```php
// database/migrations/2025_10_20_181109_create_rooms_table.php
$table->enum('status', ['waiting', 'active', 'playing', 'finished'])
      ->default('waiting');
```

| Estado | Descripción | Engine cargado | Vista |
|--------|-------------|----------------|-------|
| `waiting` | Lobby - esperando jugadores | ❌ NO | `rooms.lobby` |
| `active` | Transición - verificando conexiones | ❌ NO | `rooms.transition` |
| `playing` | Juego en curso | ✅ SÍ | `{slug}::game` |
| `finished` | Juego terminado | ✅ SÍ (pero inactivo) | `rooms.results` |

---

## Eventos por Fase

### 📍 **Fase 1: LOBBY** (Estado: `waiting`)

**Eventos emitidos:**
- `player.joined` - Cuando un jugador se une
- `player.left` - Cuando un jugador sale
- `players.all-connected` - Cuando se alcanza mínimo de jugadores
- `teams.config-updated` - Configuración de equipos cambió
- `teams.balanced` - Equipos balanceados automáticamente

**Evento de salida:**
```javascript
{
  event: 'game.started',  // ← BaseGameEngine (App\Events\Game\GameStartedEvent)
  game_slug: 'trivia',
  game_state: { phase: 'starting', ... },
  total_players: 3,
  players: [
    { id: 1, name: 'Player 1' },
    { id: 2, name: 'Player 2' },
    { id: 3, name: 'Player 3' }
  ],
  timing: null  // Sin countdown aún
}
```

**Responsabilidad del evento:**
- Redirigir a TODOS los jugadores del lobby al room
- NO inicia el juego, solo la transición

---

### 📍 **Fase 2: TRANSITION** (Estado: `active`)

**Eventos emitidos:**

#### 1. `game.countdown`
```javascript
// App\Events\Game\GameCountdownEvent
{
  event: 'game.countdown',
  room_code: 'ABC123',
  seconds: 3,
  message: 'El juego comenzará en 3 segundos...',
  timestamp: '2025-10-25T10:07:20Z'
}
```

**Cuándo se emite:**
- Después de `POST /api/rooms/{code}/ready`
- Cuando todos los jugadores del evento `game.started` están conectados en el Presence Channel

**Responsabilidad:**
- Mostrar countdown visual (3...2...1...)
- Preparar al usuario para el inicio del juego

---

#### 2. `game.initialized`
```javascript
// App\Events\Game\GameInitializedEvent
{
  event: 'game.initialized',
  room_code: 'ABC123',
  game: 'trivia',
  phase: 'playing',
  initial_state: {
    current_round: 1,
    scores: { 1: 0, 2: 0, 3: 0 },
    // ... estado inicial específico del juego
  },
  timestamp: '2025-10-25T10:07:24Z'
}
```

**Cuándo se emite:**
- Después de `POST /api/rooms/{code}/initialize-engine`
- Cuando countdown llega a 0
- Después de que `GameMatch::initializeEngine()` carga el engine

**Responsabilidad:**
- Confirmar que el engine está cargado
- Redirigir del transition al game room
- Proporcionar estado inicial del juego

---

### 📍 **Fase 3: GAME ROOM** (Estado: `playing`)

**Eventos genéricos** (todos los juegos):

```javascript
// Turnos
turn.started    → Turno de un jugador comenzó
turn.played     → Jugador realizó una acción
turn.ended      → Turno terminó

// Rondas
round.started   → Nueva ronda comenzó
round.ended     → Ronda terminó (con resultados)

// Juego
game.finished   → Juego terminó (con ganador)
```

**Eventos específicos del juego** (ejemplo: Trivia):

```javascript
trivia.question-shown     → Nueva pregunta mostrada
trivia.answer-submitted   → Jugador envió respuesta
trivia.answer-revealed    → Se revela respuesta correcta
trivia.scores-updated     → Puntuaciones actualizadas
```

---

## Implementación en Engines

### BaseGameEngine (Padre)

Todos los engines heredan de `App\Contracts\BaseGameEngine`.

**Responsabilidades del BaseGameEngine:**

1. **Método `startGame()`** - Emite `game.started` con timing metadata
2. **Método `emitGenericEvent()`** - Emitir eventos genéricos
3. **Método `emitGameEvent()`** - Emitir eventos específicos del juego

**Código simplificado:**

```php
// app/Contracts/BaseGameEngine.php

abstract class BaseGameEngine implements GameEngineInterface
{
    /**
     * Iniciar el juego (fase "starting")
     */
    public function startGame(GameMatch $match): void
    {
        // 1. Resetear módulos
        $this->resetModules($match);

        // 2. Setear fase a "starting"
        $gameState = $match->game_state ?? [];
        $gameState['phase'] = 'starting';
        $match->game_state = $gameState;
        $match->save();

        // 3. Emitir evento game.started (sin timing)
        event(new \App\Events\Game\GameStartedEvent(
            match: $match,
            gameState: $gameState,
            timing: null  // ← Sin countdown, solo redirección
        ));

        // 4. Espera a que todos los jugadores lleguen al room
        // 5. RoomController llama a initializeEngine() después
    }

    /**
     * Emitir evento genérico (turn, round, game)
     */
    protected function emitGenericEvent(GameMatch $match, string $eventName, array $data): void
    {
        $eventClass = $this->getGenericEventClass($eventName);
        event(new $eventClass($match, $data));
    }

    /**
     * Emitir evento específico del juego
     */
    protected function emitGameEvent(GameMatch $match, string $eventName, array $data): void
    {
        // Construir nombre del evento: "{slug}.{eventName}"
        $slug = $match->room->game->slug;
        $fullEventName = "{$slug}.{$eventName}";

        // Emitir evento custom
        broadcast(new GameEvent($match->room->code, $fullEventName, $data))->toOthers();
    }
}
```

---

### Engine Específico (Hijo)

Ejemplo: `TriviaEngine`

```php
// games/trivia/TriviaEngine.php

namespace Games\Trivia;

use App\Contracts\BaseGameEngine;

class TriviaEngine extends BaseGameEngine
{
    /**
     * Hook específico del juego para iniciar
     */
    protected function onGameStart(GameMatch $match): void
    {
        // Cargar preguntas
        $questions = $this->loadQuestions($match);

        // Guardar en estado
        $gameState = $match->game_state;
        $gameState['questions'] = $questions;
        $gameState['current_question_index'] = 0;
        $match->game_state = $gameState;
        $match->save();

        // Emitir primera pregunta
        $this->showNextQuestion($match);
    }

    /**
     * Mostrar siguiente pregunta
     */
    private function showNextQuestion(GameMatch $match): void
    {
        $state = $match->game_state;
        $questionIndex = $state['current_question_index'];
        $question = $state['questions'][$questionIndex];

        // Emitir evento específico de Trivia
        $this->emitGameEvent($match, 'question-shown', [
            'question' => $question['question'],
            'options' => $question['options'],
            'question_number' => $questionIndex + 1,
            'total_questions' => count($state['questions']),
        ]);

        // Emitir evento genérico de ronda
        $this->emitGenericEvent($match, 'round.started', [
            'round_number' => $questionIndex + 1,
        ]);
    }

    /**
     * Procesar respuesta de jugador
     */
    public function submitAnswer(GameMatch $match, Player $player, int $answerIndex): void
    {
        // Validar respuesta
        $state = $match->game_state;
        $question = $state['questions'][$state['current_question_index']];
        $isCorrect = ($answerIndex === $question['correct']);

        // Calcular puntos
        if ($isCorrect) {
            $points = $this->calculatePoints($player, $question);
            $this->scoreManager->addPoints($match, $player->id, $points);
        }

        // Emitir evento específico
        $this->emitGameEvent($match, 'answer-submitted', [
            'player_id' => $player->id,
            'player_name' => $player->name,
            'answer_index' => $answerIndex,
            'is_correct' => $isCorrect,
        ]);

        // Emitir evento genérico
        $this->emitGenericEvent($match, 'turn.played', [
            'player_id' => $player->id,
            'action' => ['type' => 'submit_answer', 'answer' => $answerIndex],
        ]);

        // Verificar si todos respondieron
        if ($this->allPlayersAnswered($match)) {
            $this->revealAnswer($match);
        }
    }
}
```

---

## Frontend: Listeners por Fase

### Fase 1: LOBBY

```javascript
// resources/js/core/LobbyManager.js

export class LobbyManager {
    initializeWebSocket() {
        const channel = window.Echo.channel(`room.${this.roomCode}`);

        // Evento de inicio de juego → Redirigir al room
        channel.listen('.game.started', (data) => {
            console.log('🎮 Game started, redirecting to transition...');
            window.location.replace(`/rooms/${this.roomCode}`);
        });
    }
}
```

---

### Fase 2: TRANSITION

```javascript
// resources/views/rooms/transition.blade.php

// 1. Presence Channel para detectar conexiones
const presenceChannel = window.Echo.join(`room.${roomCode}`);

presenceChannel.here((users) => {
    updatePlayerStatus(users);
    checkAllConnected();
});

// 2. Cuando todos conectados → Llamar al backend
function checkAllConnected() {
    if (connectedUsers.length >= totalPlayers) {
        fetch(`/api/rooms/${roomCode}/ready`, { method: 'POST' });
    }
}

// 3. Escuchar countdown
const channel = window.Echo.channel(`room.${roomCode}`);

channel.listen('.game.countdown', (data) => {
    showCountdown(data.seconds); // 3... 2... 1...

    if (data.seconds === 0) {
        // Cuando llega a 0, inicializar engine
        fetch(`/api/rooms/${roomCode}/initialize-engine`, { method: 'POST' });
    }
});

// 4. Escuchar inicialización → Redirigir al juego
channel.listen('.game.initialized', (data) => {
    console.log('✅ Game initialized, loading game view...');
    setTimeout(() => {
        window.location.replace(`/rooms/${roomCode}`);
    }, 1000);
});
```

---

### Fase 3: GAME ROOM

```javascript
// resources/js/games/trivia/TriviaClient.js

import { BaseGameClient } from '../../core/BaseGameClient.js';

export class TriviaClient extends BaseGameClient {
    constructor(roomCode) {
        super(roomCode); // ← Hereda listeners genéricos
        this.setupTriviaListeners();
    }

    // Listeners de eventos GENÉRICOS (heredados de BaseGameClient)
    // - .turn.started
    // - .turn.played
    // - .turn.ended
    // - .round.started
    // - .round.ended
    // - .game.finished

    // Listeners de eventos ESPECÍFICOS de Trivia
    setupTriviaListeners() {
        const channel = window.Echo.channel(`room.${this.roomCode}`);

        channel.listen('.trivia.question-shown', (data) => {
            this.showQuestion(data.question, data.options);
        });

        channel.listen('.trivia.answer-submitted', (data) => {
            this.showPlayerAnswer(data.player_id, data.is_correct);
        });

        channel.listen('.trivia.answer-revealed', (data) => {
            this.revealCorrectAnswer(data.correct_index);
        });

        channel.listen('.trivia.scores-updated', (data) => {
            this.updateScoreboard(data.scores);
        });
    }

    showQuestion(question, options) {
        // Renderizar pregunta en UI
    }

    // ... métodos específicos de Trivia
}
```

---

## Migraciones de Estado

### Room Status Migration

```php
// database/migrations/2025_10_25_100528_add_active_status_to_rooms_table.php

DB::statement("ALTER TABLE rooms MODIFY COLUMN status
    ENUM('waiting', 'active', 'playing', 'finished')
    NOT NULL DEFAULT 'waiting'");
```

### GameMatch State Transitions

```php
// app/Models/GameMatch.php

/**
 * Iniciar transición (Lobby → Transition)
 * NO carga el engine
 */
public function start(): void
{
    $this->update(['started_at' => now()]);
    $this->room->update(['status' => Room::STATUS_ACTIVE]);

    // Emitir evento para redirigir jugadores
    event(new \App\Events\GameStartedEvent($this->room));
}

/**
 * Inicializar engine (Transition → Game Room)
 * SÍ carga el engine
 */
public function initializeEngine(): void
{
    $game = $this->room->game;
    $engineClass = $game->getEngineClass();

    $engine = app($engineClass);
    $engine->initialize($this);
    $engine->startGame($this);

    $this->room->update(['status' => Room::STATUS_PLAYING]);
}
```

---

## Resumen: ¿Qué evento usar cuándo?

| Fase | Estado Room | Evento | Propósito |
|------|-------------|--------|-----------|
| LOBBY → TRANSITION | `waiting` → `active` | `game.started` | Redirigir jugadores al room |
| TRANSITION (espera) | `active` | - | Verificar conexiones via Presence |
| TRANSITION (ready) | `active` | `game.countdown` | Mostrar countdown (3,2,1) |
| TRANSITION → GAME | `active` → `playing` | `game.initialized` | Engine cargado, ir al juego |
| GAME (jugando) | `playing` | `turn.*`, `round.*`, `{game}.*` | Lógica del juego |
| GAME → RESULTS | `playing` → `finished` | `game.finished` | Juego terminado |

---

## Beneficios de la Estrategia Híbrida

✅ **Validación de conexiones ANTES del engine**
- No cargamos el engine si no están todos conectados
- Ahorro de recursos

✅ **Feedback visual al usuario**
- Lista de jugadores conectándose
- Countdown antes de empezar
- Experiencia más fluida

✅ **Separación de responsabilidades**
- `GameMatch::start()` → Solo transición
- `GameMatch::initializeEngine()` → Solo carga del engine
- Cada método tiene un propósito claro

✅ **Reutilización de código**
- Eventos genéricos compartidos entre juegos
- Solo eventos específicos varían por juego

✅ **Debuggeable y testeable**
- Cada evento es rastreable en logs
- Estados de Room claros y verificables

---

## Para Desarrolladores Futuros

**Cuando crees un nuevo juego:**

1. **Hereda de BaseGameEngine**
   ```php
   class MyGameEngine extends BaseGameEngine { ... }
   ```

2. **Implementa el hook `onGameStart()`**
   ```php
   protected function onGameStart(GameMatch $match): void {
       // Tu lógica de inicio
   }
   ```

3. **Usa `emitGenericEvent()` para eventos comunes**
   ```php
   $this->emitGenericEvent($match, 'turn.started', [...]);
   $this->emitGenericEvent($match, 'round.ended', [...]);
   ```

4. **Usa `emitGameEvent()` para eventos específicos**
   ```php
   $this->emitGameEvent($match, 'card-played', [...]);
   $this->emitGameEvent($match, 'dice-rolled', [...]);
   ```

5. **Crea tu cliente JS heredando de BaseGameClient**
   ```javascript
   class MyGameClient extends BaseGameClient {
       setupMyGameListeners() { ... }
   }
   ```

**NO necesitas:**
- ❌ Crear eventos de transición (ya existen)
- ❌ Manejar Presence Channel manualmente
- ❌ Implementar countdown (ya existe)
- ❌ Cambiar estados de Room manualmente

**Los eventos genéricos y la transición son automáticos** ✅
