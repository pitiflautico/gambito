# Arquitectura de Eventos y WebSockets

**Última actualización:** 24 de octubre de 2025

---

## 📋 Índice

1. [Visión General](#visión-general)
2. [Stack Tecnológico](#stack-tecnológico)
3. [Flujo Completo de un Juego](#flujo-completo-de-un-juego)
4. [Eventos Genéricos vs Específicos](#eventos-genéricos-vs-específicos)
5. [Backend: Emitir Eventos](#backend-emitir-eventos)
6. [Frontend: Suscribirse a Eventos](#frontend-suscribirse-a-eventos)
7. [BaseGameClient](#basegameclient)
8. [Protección contra Race Conditions](#protección-contra-race-conditions)
9. [Ejemplos Prácticos](#ejemplos-prácticos)

---

## Visión General

Gambito usa **Laravel Reverb** (WebSockets) para sincronizar el estado del juego en tiempo real entre todos los jugadores.

### Arquitectura en 3 Capas

```
┌─────────────────────────────────────────────────────────────┐
│                    FRONTEND (JavaScript)                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ BaseGameClient│  │ EventManager │  │ TimingModule │      │
│  │   (Todos)    │  │  (WebSocket) │  │  (Countdown) │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                           ▲ WebSocket Events
                           │
┌─────────────────────────────────────────────────────────────┐
│                 LARAVEL REVERB (WebSocket Server)           │
│                    Channel: room.{roomCode}                  │
└─────────────────────────────────────────────────────────────┘
                           ▲ Broadcasting
                           │
┌─────────────────────────────────────────────────────────────┐
│                    BACKEND (PHP Laravel)                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │ GameEngines  │  │ Controllers  │  │    Events    │      │
│  │ (Lógica)     │  │   (API)      │  │ (Broadcast)  │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
```

### Principios Clave

1. **Backend como Fuente de Verdad**: El backend contiene toda la lógica del juego
2. **Frontend Solo Renderiza**: El frontend muestra lo que el backend le dice
3. **Sincronización vía Eventos**: Todos los cambios se comunican mediante WebSocket
4. **Eventos Genéricos Primero**: Usar eventos base antes de crear eventos custom

---

## Stack Tecnológico

### Backend
- **Laravel 11**: Framework PHP
- **Laravel Reverb**: Servidor WebSocket nativo de Laravel
- **ShouldBroadcast**: Interface para eventos que se emiten por WebSocket

### Frontend
- **Laravel Echo**: Cliente JavaScript para WebSockets
- **Pusher.js**: Protocolo compatible con Reverb
- **BaseGameClient**: Clase base para todos los juegos (JavaScript)

### Configuración

**Variables de entorno (`.env`)**:
```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=sync

REVERB_APP_ID=666481
REVERB_APP_KEY=xr8jtwtk2zplymz9f8rb
REVERB_APP_SECRET=mgafg8waxu8uluxwcsgq
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

**Iniciar el servidor**:
```bash
php artisan reverb:start --host=0.0.0.0 --port=8080 --debug
```

---

## Flujo Completo de un Juego

### Fase 1: Lobby (Pre-Juego)

```
Master crea sala
   ↓
Jugadores se unen
   ↓
Master presiona "Iniciar Juego"
   ↓
Backend: GameMatch::start()
   ├─ $engine->initialize()     → Guarda configuración
   ├─ $engine->startGame()       → Resetea módulos
   └─ emit GameStartedEvent      → Notifica a frontends
   ↓
Todos los jugadores redirigen a /rooms/{code}
```

### Fase 2: Starting (Sincronización)

**Objetivo**: Asegurar que todos los jugadores están listos antes de empezar.

```
1. Jugador carga página del juego
   ↓
2. BaseGameClient auto-ejecuta notifyPlayerConnected()
   ↓
3. Backend: POST /api/rooms/{code}/player-connected
   ├─ Trackea conexión en Cache
   ├─ emit PlayerConnectedToGameEvent
   └─ Si todos conectados → transitionFromStarting()
   ↓
4. Frontend: Muestra "Esperando jugadores... (X/Y)"
   ↓
5. Cuando todos conectados:
   Backend: emit GameStartedEvent con countdown
   ↓
6. Frontend: Muestra countdown 3-2-1
   ↓
7. Frontend: Countdown termina → notifyGameReady()
   ↓
8. Backend: POST /api/games/{match}/game-ready (con lock protection)
   ├─ Solo el primer cliente ejecuta onGameStart()
   ├─ Los demás reciben 200 OK con flag already_processing
   └─ emit RoundStartedEvent (primer round)
   ↓
9. Frontend: Renderiza primera pregunta/ronda
```

**Diagrama de Secuencia**:

```
Jugador 1          Jugador 2          Backend                 Reverb
    │                  │                  │                       │
    │ notifyPlayerConnected()             │                       │
    ├──────────────────────────────────►  │                       │
    │                  │                  │ PlayerConnected(1/3)  │
    │                  │                  ├──────────────────────►│
    │◄─────────────────┼──────────────────┼───────────────────────┤
    │ "1/3"            │                  │                       │
    │                  │                  │                       │
    │                  │ notifyPlayerConnected()                  │
    │                  ├─────────────────►│                       │
    │                  │                  │ PlayerConnected(2/3)  │
    │                  │                  ├──────────────────────►│
    │◄─────────────────┼──────────────────┼───────────────────────┤
    │ "2/3"            │◄─────────────────┼───────────────────────┤
    │                  │ "2/3"            │                       │
    │                  │                  │                       │
    │                  │ [Cuando todos conectados]                │
    │                  │                  │ GameStartedEvent      │
    │                  │                  ├──────────────────────►│
    │◄─────────────────┼──────────────────┼───────────────────────┤
    │ Countdown 3      │◄─────────────────┼───────────────────────┤
    │ Countdown 2      │ Countdown 3      │                       │
    │ Countdown 1      │ Countdown 2      │                       │
    │                  │ Countdown 1      │                       │
    │ notifyGameReady()│                  │                       │
    ├──────────────────┼─────────────────►│ [Lock acquired]       │
    │                  │ notifyGameReady()│ onGameStart()         │
    │                  ├─────────────────►│ [Lock held]           │
    │                  │                  │ RoundStartedEvent     │
    │                  │                  ├──────────────────────►│
    │◄─────────────────┼──────────────────┼───────────────────────┤
    │ Pregunta 1       │◄─────────────────┼───────────────────────┤
    │                  │ Pregunta 1       │                       │
```

### Fase 3: Playing (Jugando)

```
Jugador realiza acción (responder, dibujar, etc.)
   ↓
Frontend: POST /api/{game}/action
   ↓
Backend: $engine->processAction()
   ├─ Valida acción
   ├─ Actualiza game_state
   ├─ emit PlayerActionEvent (opcional)
   └─ Si ronda termina:
      ├─ emit RoundEndedEvent
      └─ Programa siguiente ronda
   ↓
Frontend: Recibe evento y actualiza UI
```

### Fase 4: Results (Entre Rondas)

```
Backend: emit RoundEndedEvent con timing metadata
   ↓
Frontend: Muestra resultados + countdown
   ↓
Frontend: Countdown termina → notifyReadyForNextRound()
   ↓
Backend: POST /api/games/{match}/start-next-round (con lock protection)
   ├─ Solo el primer cliente avanza la ronda
   ├─ Los demás reciben 409 Conflict
   └─ emit RoundStartedEvent (siguiente ronda)
   ↓
Frontend: Renderiza siguiente pregunta/ronda
```

### Fase 5: Finished (Juego Terminado)

```
Backend: $engine->finalize()
   ├─ Calcula ganador
   ├─ Genera ranking
   └─ emit GameFinishedEvent
   ↓
Frontend: Muestra resultados finales
```

---

## Eventos Genéricos vs Específicos

### Eventos Genéricos (Base)

**Ubicación**: `app/Events/Game/`
**Configuración**: `config/game-events.php`
**Namespace**: `App\Events\Game\`

Estos eventos son **para TODOS los juegos** y cubren funcionalidades estándar:

| Evento | Broadcast Name | Cuándo Emitir |
|--------|---------------|---------------|
| **PlayerConnectedToGameEvent** | `player.connected` | Jugador se conecta a la sala |
| **GameStartedEvent** | `game.started` | Juego inicia (con countdown) |
| **RoundStartedEvent** | `game.round.started` | Nueva ronda/pregunta comienza |
| **RoundEndedEvent** | `game.round.ended` | Ronda/pregunta termina |
| **PlayerActionEvent** | `game.player.action` | Jugador realiza una acción |
| **PhaseChangedEvent** | `game.phase.changed` | Fase del juego cambia |
| **TurnChangedEvent** | `game.turn.changed` | Turno cambia (modo secuencial) |
| **GameFinishedEvent** | `game.finished` | Juego termina completamente |

### Eventos Específicos del Juego

**Ubicación**: `games/{slug}/Events/`
**Configuración**: `games/{slug}/capabilities.json`
**Namespace**: `Games\{GameName}\Events\`

Estos eventos son **solo para funcionalidades únicas** del juego:

| Juego | Evento | Broadcast Name | Propósito |
|-------|--------|---------------|-----------|
| **Pictionary** | `CanvasDrawEvent` | `.pictionary.canvas.draw` | Dibujo en tiempo real |
| **UNO** | `CardPlayedEvent` | `.uno.card.played` | Carta específica jugada |
| **Trivia** | (ninguno) | - | Usa solo eventos genéricos |

### Regla de Oro

**Pregúntate**: ¿Este evento podría usarse en OTROS juegos?
- **SÍ** → Usa evento genérico
- **NO** → Crea evento específico

**Ejemplos**:

| Evento | Genérico? | Razón |
|--------|-----------|-------|
| QuestionStartedEvent | ❌ NO → ✅ RoundStartedEvent | Todas las rondas son iguales conceptualmente |
| TurnChangedEvent (custom) | ❌ NO → ✅ TurnChangedEvent (genérico) | Ya existe en el sistema base |
| CanvasDrawEvent | ✅ SÍ (específico) | Solo Pictionary dibuja en canvas |

---

## Backend: Emitir Eventos

### 1. Estructura de un Evento

```php
<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoundStartedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $currentRound;
    public int $totalRounds;
    public string $phase;
    public array $gameState;
    public ?array $timing;

    public function __construct(
        GameMatch $match,
        int $currentRound,
        int $totalRounds,
        string $phase = 'playing',
        ?array $timing = null
    ) {
        $this->roomCode = $match->room->code;
        $this->currentRound = $currentRound;
        $this->totalRounds = $totalRounds;
        $this->phase = $phase;
        $this->gameState = $match->game_state;
        $this->timing = $timing;
    }

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->roomCode}");
    }

    public function broadcastAs(): string
    {
        return 'game.round.started';
    }

    public function broadcastWith(): array
    {
        $data = [
            'current_round' => $this->currentRound,
            'total_rounds' => $this->totalRounds,
            'phase' => $this->phase,
            'game_state' => $this->gameState,
        ];

        if ($this->timing !== null) {
            $data['timing'] = $this->timing;
        }

        return $data;
    }
}
```

### 2. Componentes Clave

| Método | Propósito |
|--------|-----------|
| `ShouldBroadcast` | Interface que marca el evento para WebSocket |
| `broadcastOn()` | Define en qué canal se emite (room.{code}) |
| `broadcastAs()` | Nombre del evento (sin punto inicial) |
| `broadcastWith()` | Datos que se envían al frontend |

### 3. Emitir un Evento

**Método recomendado**: `event()`

```php
use App\Events\Game\RoundStartedEvent;

// Emitir evento genérico
event(new RoundStartedEvent(
    match: $match,
    currentRound: 1,
    totalRounds: 10,
    phase: 'question'
));

Log::info("RoundStartedEvent emitted", [
    'match_id' => $match->id,
    'room_code' => $match->room->code,
    'round' => 1
]);
```

### 4. Timing Metadata

Para eventos que necesitan countdown o timing:

```php
event(new GameStartedEvent(
    match: $match,
    gameState: $match->game_state,
    timing: [
        'type' => 'countdown',
        'delay' => 3,  // 3 segundos
        'callback' => 'notifyGameReady'  // Método a llamar en el frontend
    ]
));
```

---

## Frontend: Suscribirse a Eventos

### 1. Arquitectura del Frontend

```
BaseGameClient (Clase base)
   ├─ EventManager (Gestiona suscripciones)
   ├─ TimingModule (Maneja countdowns)
   └─ Handlers genéricos (handleGameStarted, handleRoundStarted, etc.)
```

### 2. Configuración de Eventos

**Archivo**: `games/{slug}/capabilities.json`

```json
{
  "slug": "trivia",
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "GameStartedEvent": {
        "name": "game.started",
        "handler": "handleGameStarted"
      },
      "PlayerConnectedToGameEvent": {
        "name": "player.connected",
        "handler": "handlePlayerConnected"
      },
      "RoundStartedEvent": {
        "name": "game.round.started",
        "handler": "handleRoundStarted"
      },
      "RoundEndedEvent": {
        "name": "game.round.ended",
        "handler": "handleRoundEnded"
      }
    }
  }
}
```

**Nota**: Los eventos base se configuran en `config/game-events.php` y se combinan automáticamente.

### 3. Inicializar BaseGameClient

**Archivo**: `games/trivia/views/game.blade.php`

```html
<script>
// Configuración del juego desde el servidor
window.gameConfig = {
    roomCode: '{{ $room->code }}',
    playerId: {{ $playerId }},
    matchId: {{ $match->id }},
    gameSlug: '{{ $room->game->slug }}',
    players: @json($players),
    gameState: @json($match->game_state),
    eventConfig: @json($eventConfig),
};

// Esperar a que BaseGameClient esté disponible
document.addEventListener('DOMContentLoaded', () => {
    if (window.BaseGameClient) {
        console.log('🎮 Initializing BaseGameClient');
        window.game = new window.BaseGameClient(window.gameConfig);

        // Handlers custom del juego
        window.game.handleRoundStarted = function(event) {
            console.log('🎯 [TriviaGame] RoundStartedEvent received:', event);

            // Extraer datos específicos del juego desde game_state
            const currentQuestion = event.game_state.current_question;

            // Actualizar UI
            showQuestion(currentQuestion);
        };

        // Setup EventManager (suscribe a todos los eventos)
        window.game.setupEventManager();
    }
});
</script>
```

### 4. EventManager (Interno)

**Ubicación**: `resources/js/modules/EventManager.js`

El EventManager se encarga de:
1. Conectarse al canal WebSocket (`room.{roomCode}`)
2. Suscribirse a eventos configurados en `capabilities.json` + `config/game-events.php`
3. Mapear eventos a handlers del juego

```javascript
// Interno - No necesitas llamar esto directamente
class EventManager {
    constructor(config) {
        this.channel = window.Echo.channel(`room.${config.roomCode}`);

        // Suscribirse a todos los eventos
        Object.entries(config.eventConfig.events).forEach(([eventClass, config]) => {
            this.channel.listen(`.${config.name}`, (data) => {
                const handler = config.handlers[config.handler];
                if (handler) {
                    handler(data);
                }
            });
        });
    }
}
```

---

## BaseGameClient

### Propósito

BaseGameClient es una **clase base** que todos los juegos heredan para:
1. Gestionar WebSocket (EventManager)
2. Handlers genéricos (GameStarted, RoundStarted, etc.)
3. Sistema de timing (countdown)
4. Utilidades comunes (getPlayer, sendAction, etc.)

### Handlers Genéricos Incluidos

| Handler | Evento | Funcionalidad |
|---------|--------|---------------|
| `handlePlayerConnected()` | PlayerConnectedToGameEvent | Actualiza contador (X/Y) |
| `handleGameStarted()` | GameStartedEvent | Muestra countdown y notifica backend |
| `handleRoundStarted()` | RoundStartedEvent | Base vacía - sobrescribir en juego |
| `handleRoundEnded()` | RoundEndedEvent | Actualiza scores y procesa timing |
| `handlePlayerAction()` | PlayerActionEvent | Base vacía - para feedback visual |

### Métodos Automáticos

**No necesitas llamar estos manualmente**, se ejecutan automáticamente:

```javascript
// ✅ AUTOMÁTICO - Se ejecuta al cargar la página
notifyPlayerConnected() {
    // POST /api/rooms/{code}/player-connected
    // Notifica al backend que este jugador está conectado
}

// ✅ AUTOMÁTICO - Se ejecuta cuando countdown de GameStarted termina
notifyGameReady() {
    // POST /api/games/{match}/game-ready
    // Notifica al backend que frontend está listo para empezar
}

// ✅ AUTOMÁTICO - Se ejecuta cuando countdown de RoundEnded termina
notifyReadyForNextRound() {
    // POST /api/games/{match}/start-next-round
    // Notifica al backend que frontend está listo para siguiente ronda
}
```

### Sobrescribir Handlers

Los juegos pueden sobrescribir handlers para lógica específica:

```javascript
document.addEventListener('DOMContentLoaded', () => {
    window.game = new window.BaseGameClient(window.gameConfig);

    // Sobrescribir handler de GameStarted para UI custom
    const originalHandleGameStarted = window.game.handleGameStarted.bind(window.game);
    window.game.handleGameStarted = async function(event) {
        // Ocultar spinner
        document.getElementById('waiting-spinner').classList.add('hidden');
        document.getElementById('countdown-timer').classList.remove('hidden');

        // Llamar al handler original (que procesa el countdown)
        await originalHandleGameStarted(event);
    };

    // Sobrescribir handler de RoundStarted
    window.game.handleRoundStarted = function(event) {
        const question = event.game_state.current_question;
        showQuestion(question);
    };

    // Sobrescribir elemento donde mostrar countdown
    window.game.getCountdownElement = function() {
        return document.getElementById('countdown-timer');
    };

    window.game.setupEventManager();
});
```

### Utilidades Comunes

```javascript
// Obtener jugador por ID
const player = game.getPlayer(playerId);

// Obtener jugador actual
const me = game.getCurrentPlayer();

// Obtener score de un jugador
const score = game.getPlayerScore(playerId);

// Enviar acción al backend
game.sendAction('/api/trivia/answer', {
    player_id: playerId,
    answer: 2
});
```

---

## Protección contra Race Conditions

### Problema

Cuando múltiples clientes ejecutan countdown al mismo tiempo, todos pueden llamar al backend simultáneamente:

```
Jugador 1: countdown termina → POST /api/games/{match}/game-ready
Jugador 2: countdown termina → POST /api/games/{match}/game-ready (mismo tiempo)
Jugador 3: countdown termina → POST /api/games/{match}/game-ready (mismo tiempo)

❌ Sin protección: onGameStart() se ejecuta 3 veces → duplicación de datos
```

### Solución: Lock Mechanism

**Backend**: `GameMatch` modelo tiene métodos de lock:

```php
// Intenta adquirir el lock
if (!$match->acquireRoundLock()) {
    // Otro cliente ya está procesando, retornar OK con flag
    return response()->json([
        'success' => true,
        'already_processing' => true,
    ], 200);
}

// Lock adquirido, procesar la lógica
try {
    $engine->triggerGameStart($match);
} finally {
    // SIEMPRE liberar el lock
    $match->releaseRoundLock();
}
```

**Frontend**: Maneja respuesta silenciosamente:

```javascript
async notifyGameReady() {
    const response = await fetch(`/api/games/${this.matchId}/game-ready`, {
        method: 'POST',
        // ...
    });

    const data = await response.json();

    if (data.already_processing) {
        // Normal - otro cliente está procesando
        console.log('⏸️  Another client is starting the game, waiting for event...');
    } else {
        console.log('✅ Successfully started game');
    }

    // En ambos casos, el cliente se sincroniza con RoundStartedEvent
}
```

### Flujo con Lock

```
Jugador 1: notifyGameReady() → acquireLock() → ✅ Lock acquired → onGameStart()
Jugador 2: notifyGameReady() → acquireLock() → ❌ Lock held → return already_processing
Jugador 3: notifyGameReady() → acquireLock() → ❌ Lock held → return already_processing

Backend: emit RoundStartedEvent

Todos los jugadores: Reciben RoundStartedEvent → Sincronizados ✅
```

---

## Ejemplos Prácticos

### Ejemplo 1: Trivia - Flujo Completo

#### Backend

**TriviaEngine.php**:
```php
// 1. Cuando termina el countdown de inicio
protected function onGameStart(GameMatch $match): void
{
    // Leer configuración
    $config = $match->game_state['_config'];
    $questions = $config['questions'];
    $timePerQuestion = $config['time_per_question'];

    // Setear estado inicial
    $firstQuestion = $questions[0];
    $match->game_state = array_merge($match->game_state, [
        'phase' => 'question',
        'current_question_index' => 0,
        'current_question' => $firstQuestion,
        'player_answers' => [],
    ]);
    $match->save();

    // Emitir evento genérico
    $roundManager = RoundManager::fromArray($match->game_state);
    event(new RoundStartedEvent(
        match: $match,
        currentRound: $roundManager->getCurrentRound(),
        totalRounds: $roundManager->getTotalRounds(),
        phase: 'question'
    ));
}

// 2. Jugador responde
public function processAction(GameMatch $match, Player $player, string $action, array $data): array
{
    $result = $this->handleAnswer($match, $player, $data);

    // Si es correcta, terminar ronda
    if ($result['is_correct']) {
        $this->endCurrentRound($match);
    }

    return $result;
}

// 3. Terminar ronda
protected function endCurrentRound(GameMatch $match): void
{
    // Calcular puntos
    $scoreManager = $this->getScoreManager($match);
    $scoreManager->awardPoints($playerId, 'correct_answer', $data);
    $this->saveScoreManager($match, $scoreManager);

    // Emitir evento con timing metadata
    event(new RoundEndedEvent(
        match: $match,
        roundNumber: $currentRound,
        results: $results,
        scores: $scores,
        timing: [
            'type' => 'countdown',
            'delay' => 5,  // 5 segundos para ver resultados
            'callback' => 'notifyReadyForNextRound'
        ]
    ));
}
```

#### Frontend

**game.blade.php**:
```html
<div id="game-state">
    @if(($match->game_state['phase'] ?? '') === 'starting')
        <div id="waiting-spinner"></div>
        <p id="waiting-text">Esperando jugadores...</p>
        <p id="connection-status">(1/{{ $players->count() }})</p>
        <p id="countdown-timer" class="hidden"></p>
    @endif
</div>

<script>
window.gameConfig = {
    roomCode: '{{ $room->code }}',
    playerId: {{ $playerId }},
    matchId: {{ $match->id }},
    gameSlug: 'trivia',
    eventConfig: @json($eventConfig),
};

document.addEventListener('DOMContentLoaded', () => {
    window.game = new window.BaseGameClient(window.gameConfig);

    // 1. Sobrescribir GameStarted para UI
    const original = window.game.handleGameStarted.bind(window.game);
    window.game.handleGameStarted = async function(event) {
        document.getElementById('waiting-spinner').classList.add('hidden');
        document.getElementById('waiting-text').textContent = '¡Comienza en...!';
        document.getElementById('countdown-timer').classList.remove('hidden');
        await original(event);
    };

    // 2. Handler de RoundStarted
    window.game.handleRoundStarted = function(event) {
        const question = event.game_state.current_question;

        document.getElementById('game-state').innerHTML = `
            <h2>Pregunta ${event.current_round}/${event.total_rounds}</h2>
            <p>${question.question}</p>
            <div>
                ${question.options.map((opt, i) => `
                    <button onclick="answerQuestion(${i})">${i + 1}. ${opt}</button>
                `).join('')}
            </div>
        `;
    };

    // 3. Handler de RoundEnded
    window.game.handleRoundEnded = function(event) {
        showResults(event.results, event.scores);
    };

    window.game.getCountdownElement = () => document.getElementById('countdown-timer');

    window.game.setupEventManager();
});

// Función para enviar respuesta
function answerQuestion(answerIndex) {
    fetch('/api/trivia/answer', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            room_code: window.gameConfig.roomCode,
            player_id: window.gameConfig.playerId,
            answer: answerIndex
        })
    });
}
</script>
```

### Ejemplo 2: Pictionary - Evento Específico

**Backend**:
```php
// Evento específico de Pictionary
event(new CanvasDrawEvent(
    roomCode: $match->room->code,
    drawerId: $player->id,
    strokeData: $data['stroke']
));
```

**Frontend**:
```javascript
// En capabilities.json
{
  "CanvasDrawEvent": {
    "name": ".pictionary.canvas.draw",
    "handler": "handleCanvasDraw"
  }
}

// Handler custom
window.game.handleCanvasDraw = function(event) {
    if (event.action === 'clear') {
        clearCanvas();
    } else {
        drawRemoteStroke(event.stroke);
    }
};
```

---

## Resumen

### Flujo Simplificado

```
1. Jugador carga página → notifyPlayerConnected() automático
2. Backend trackea conexiones → emit PlayerConnectedToGameEvent
3. Todos conectados → emit GameStartedEvent con countdown
4. Countdown termina → notifyGameReady() automático
5. Backend ejecuta onGameStart() → emit RoundStartedEvent
6. Frontend renderiza primera ronda
7. Jugador actúa → Backend valida → emit eventos
8. Ronda termina → RoundEndedEvent con timing
9. Countdown termina → notifyReadyForNextRound() automático
10. Backend avanza ronda → emit RoundStartedEvent
11. Repetir pasos 6-10 hasta que juego termine
12. Backend finaliza → emit GameFinishedEvent
```

### Checklist para Implementar un Juego

**Backend**:
- [ ] Extender `BaseGameEngine`
- [ ] Implementar `onGameStart()` que emita `RoundStartedEvent`
- [ ] Implementar `processAction()` que valide acciones
- [ ] Implementar `endCurrentRound()` que emita `RoundEndedEvent` con timing
- [ ] Implementar `startNewRound()` que emita `RoundStartedEvent`
- [ ] Crear eventos específicos solo si es necesario

**Frontend**:
- [ ] Inicializar `BaseGameClient` en `game.blade.php`
- [ ] Sobrescribir `handleRoundStarted()` para renderizar estado
- [ ] Sobrescribir `handleRoundEnded()` para mostrar resultados
- [ ] Sobrescribir `getCountdownElement()` si usas countdown custom
- [ ] Configurar eventos en `capabilities.json`
- [ ] Implementar handlers custom para eventos específicos

**Eventos**:
- [ ] Configurar eventos genéricos en `capabilities.json`
- [ ] Crear eventos específicos solo si la funcionalidad es única
- [ ] Documentar propósito de cada evento

---

**Última actualización**: 24 de octubre de 2025
**Autores**: Claude Code + Daniel
