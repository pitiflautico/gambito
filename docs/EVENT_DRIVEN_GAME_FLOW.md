# Arquitectura Event-Driven para Juegos

> **📌 NOTA**: Este documento describe el flujo básico de eventos.
> Para la **estrategia híbrida completa** con todos los detalles de implementación, ver:
> **[HYBRID_EVENT_STRATEGY.md](./HYBRID_EVENT_STRATEGY.md)** ← **DOCUMENTO PRINCIPAL**

## Filosofía

**TODO en el juego funciona con eventos**. No hay polling, no hay `location.reload()`, no hay consultas periódicas.

El flujo es siempre:
1. **Backend** detecta un cambio → Emite evento
2. **WebSocket** transmite el evento a todos los clientes
3. **Frontend** escucha el evento → Actualiza UI

## Flujo Completo: Lobby → Game Room → Playing

### 1. LOBBY (Estado: `waiting`)

**Objetivo**: Conectar jugadores, configurar equipos, validar mínimos

**Eventos que se emiten**:
- `players.all-connected` → Cuando se alcanza el mínimo de jugadores
- `teams.balanced` → Cuando se distribuyen jugadores en equipos
- `teams.config-updated` → Cuando se cambia configuración de equipos
- `player.moved` → Cuando un jugador se mueve de equipo
- `player.removed` → Cuando se remueve un jugador de un equipo

**Evento de salida**:
```javascript
// Al hacer click en "Iniciar Partida"
// Backend emite:
{
  event: 'game.started',
  room_code: 'ABC123',
  game_name: 'UNO',
  players: [
    { id: 1, name: 'Player 1', user_id: 1 },
    { id: 2, name: 'Player 2', user_id: 5 },
    { id: 3, name: 'Player 3', user_id: 7 }
  ],
  total_players: 3,
  timestamp: '2025-10-25T10:00:00Z'
}

// Frontend escucha y redirige:
window.location.replace(`/rooms/${roomCode}`);
```

---

### 2. GAME ROOM - Fase de Conexión (Estado: `active`)

**Objetivo**: Verificar que TODOS los jugadores del lobby están conectados en el room

**IMPORTANTE**: En este punto todavía NO se ha cargado el engine del juego.

**El room escucha Presence Channel**:
```javascript
// Cuando todos los jugadores del evento game.started están presentes:
Echo.join(`room.${roomCode}`)
  .here((users) => {
    const expectedPlayers = 3; // Del evento game.started
    const connectedPlayers = users.length;

    if (connectedPlayers === expectedPlayers) {
      // TODOS conectados → Backend emite evento de countdown
      fetch(`/api/rooms/${roomCode}/ready`)
        .then(() => {
          // Backend emite: game.countdown
        });
    }
  });
```

**Evento emitido cuando todos conectan**:
```javascript
{
  event: 'game.countdown',
  seconds: 3,
  message: 'El juego comenzará en 3 segundos...'
}

// Frontend muestra countdown:
// 3... 2... 1... ¡Empieza!
```

---

### 3. GAME ROOM - Countdown

**El frontend escucha**:
```javascript
channel.listen('.game.countdown', (data) => {
  showCountdown(data.seconds); // 3... 2... 1...

  // Cuando llega a 0, espera el siguiente evento
});
```

**Cuando countdown llega a 0, backend**:
1. Carga el engine: `$match->initializeEngine()`
2. Cambia estado a `playing`
3. El engine emite `game.initialized`

---

### 4. GAME ROOM - Juego Iniciado (Estado: `playing`)

**Engine ya está cargado**. Ahora empiezan los eventos del juego específico.

**Eventos genéricos que TODOS los juegos deben emitir**:

#### `game.initialized`
```javascript
{
  event: 'game.initialized',
  game: 'uno',
  phase: 'playing',
  initial_state: {
    current_turn: 1,
    turn_order: [1, 2, 3],
    // ... estado inicial del juego
  }
}
```

#### `turn.started`
```javascript
{
  event: 'turn.started',
  player_id: 1,
  player_name: 'Player 1',
  turn_number: 1,
  time_limit: 30 // segundos (null si no hay límite)
}

// Frontend muestra: "Turno de Player 1"
```

#### `turn.played`
```javascript
{
  event: 'turn.played',
  player_id: 1,
  player_name: 'Player 1',
  action: { type: 'play_card', card: { color: 'red', value: '5' } },
  new_state: {
    // ... estado actualizado
  }
}

// Frontend actualiza UI con la nueva acción
```

#### `turn.ended`
```javascript
{
  event: 'turn.ended',
  player_id: 1,
  player_name: 'Player 1',
  next_player_id: 2,
  next_player_name: 'Player 2'
}

// Frontend cambia indicador de turno
```

#### `round.started` (si el juego usa rondas)
```javascript
{
  event: 'round.started',
  round_number: 1,
  message: 'Ronda 1 - ¡Comienza!'
}
```

#### `round.ended`
```javascript
{
  event: 'round.ended',
  round_number: 1,
  winner_id: 2,
  winner_name: 'Player 2',
  scores: {
    1: 50,
    2: 100,
    3: 30
  }
}
```

#### `game.finished`
```javascript
{
  event: 'game.finished',
  winner_id: 2,
  winner_name: 'Player 2',
  final_scores: {
    1: 150,
    2: 300,
    3: 100
  },
  total_rounds: 3,
  duration_seconds: 600
}

// Frontend redirige a /rooms/{code}/results
window.location.replace(`/rooms/${roomCode}/results`);
```

---

## Convenciones para Eventos de Juego

### Naming Convention

**Eventos genéricos** (todos los juegos):
- `game.*` → Eventos del juego completo
- `turn.*` → Eventos de turnos
- `round.*` → Eventos de rondas
- `phase.*` → Eventos de fases

**Eventos específicos del juego** (ejemplo UNO):
- `uno.card-played` → Carta jugada
- `uno.draw-cards` → Robar cartas
- `uno.say-uno` → Decir UNO
- `uno.color-changed` → Cambio de color

**Eventos específicos del juego** (ejemplo Trivia):
- `trivia.question-shown` → Mostrar pregunta
- `trivia.answer-submitted` → Respuesta enviada
- `trivia.answer-revealed` → Revelar respuesta correcta
- `trivia.scores-updated` → Actualizar puntuaciones

### Estructura de Eventos

```javascript
{
  event: 'nombre.del.evento',  // SIEMPRE presente
  timestamp: 'ISO8601',         // SIEMPRE presente
  phase: 'playing',             // Estado actual del juego

  // Datos específicos del evento
  player_id: 1,
  action: { ... },
  new_state: { ... }
}
```

---

## Implementación en el Engine

### BaseGameEngine

Todos los engines heredan de `BaseGameEngine` que provee:

```php
// Emitir evento genérico
$this->emitGenericEvent($match, 'turn.started', [
    'player_id' => $player->id,
    'player_name' => $player->name,
]);

// Emitir evento específico del juego
$this->emitGameEvent($match, 'uno.card-played', [
    'card' => $card,
    'player_id' => $player->id,
]);
```

### Ejemplo: MockupEngine

```php
public function startGame(GameMatch $match): void
{
    // 1. Emitir evento de inicio con countdown
    $this->emitGenericEvent($match, 'starting', [
        'countdown_seconds' => 3,
        'message' => 'El juego comenzará en 3 segundos...',
    ]);

    // 2. Inicializar estado
    $this->setState($match, [
        'phase' => 'playing',
        'current_turn' => 1,
        'scores' => [...],
    ]);

    // 3. Emitir evento de inicio real
    $this->emitGenericEvent($match, 'started', [
        'message' => '¡Juego iniciado!',
        'players_count' => $match->players()->count(),
    ]);

    // 4. Emitir primer turno
    $firstPlayer = $this->getFirstPlayer($match);
    $this->emitGenericEvent($match, 'turn.started', [
        'player_id' => $firstPlayer->id,
        'player_name' => $firstPlayer->name,
    ]);
}

public function playTurn(GameMatch $match, Player $player, array $action): array
{
    // 1. Validar acción
    if (!$this->validateAction($match, $player, $action)) {
        return ['success' => false, 'error' => 'Invalid action'];
    }

    // 2. Aplicar acción al estado
    $state = $this->getState($match);
    $state = $this->applyAction($state, $player, $action);
    $this->setState($match, $state);

    // 3. Emitir evento de turno jugado
    $this->emitGenericEvent($match, 'turn.played', [
        'player_id' => $player->id,
        'player_name' => $player->name,
        'action' => $action,
        'new_state' => $state,
    ]);

    // 4. Siguiente turno
    $nextPlayer = $this->getNextPlayer($match);
    $this->emitGenericEvent($match, 'turn.ended', [
        'player_id' => $player->id,
        'next_player_id' => $nextPlayer->id,
    ]);

    $this->emitGenericEvent($match, 'turn.started', [
        'player_id' => $nextPlayer->id,
        'player_name' => $nextPlayer->name,
    ]);

    return ['success' => true];
}
```

---

## Frontend: BaseGameClient.js

Todos los juegos heredan de `BaseGameClient` que escucha eventos genéricos:

```javascript
export class BaseGameClient {
    constructor(roomCode) {
        this.roomCode = roomCode;
        this.setupGenericListeners();
    }

    setupGenericListeners() {
        const channel = Echo.channel(`room.${this.roomCode}`);

        // Eventos genéricos
        channel.listen('.game.initialized', (e) => this.onGameInitialized(e));
        channel.listen('.turn.started', (e) => this.onTurnStarted(e));
        channel.listen('.turn.played', (e) => this.onTurnPlayed(e));
        channel.listen('.turn.ended', (e) => this.onTurnEnded(e));
        channel.listen('.game.finished', (e) => this.onGameFinished(e));
    }

    // Métodos que los juegos específicos pueden sobrescribir
    onGameInitialized(event) {
        console.log('Game initialized:', event);
    }

    onTurnStarted(event) {
        console.log(`Turn started: ${event.player_name}`);
        this.highlightCurrentPlayer(event.player_id);
    }

    onTurnPlayed(event) {
        console.log('Turn played:', event.action);
        this.updateGameState(event.new_state);
    }

    onTurnEnded(event) {
        console.log('Turn ended, next:', event.next_player_name);
    }

    onGameFinished(event) {
        console.log('Game finished, winner:', event.winner_name);
        setTimeout(() => {
            window.location.replace(`/rooms/${this.roomCode}/results`);
        }, 3000);
    }
}
```

### Ejemplo: UnoGameClient

```javascript
import { BaseGameClient } from '../core/BaseGameClient.js';

export class UnoGameClient extends BaseGameClient {
    constructor(roomCode) {
        super(roomCode);
        this.setupUnoListeners();
    }

    setupUnoListeners() {
        const channel = Echo.channel(`room.${this.roomCode}`);

        // Eventos específicos de UNO
        channel.listen('.uno.card-played', (e) => this.onCardPlayed(e));
        channel.listen('.uno.draw-cards', (e) => this.onDrawCards(e));
        channel.listen('.uno.say-uno', (e) => this.onSayUno(e));
    }

    onCardPlayed(event) {
        this.animateCardToCenter(event.card);
        this.updatePlayerHandCount(event.player_id, event.cards_left);
    }

    onDrawCards(event) {
        this.animateDrawCards(event.player_id, event.count);
    }

    onSayUno(event) {
        this.showUnoAnimation(event.player_id);
    }
}
```

---

## Resumen del Flujo Completo

```
LOBBY (waiting)
  ├─ Presence Channel: jugadores conectándose
  ├─ Equipos configurándose
  ├─ Click "Iniciar"
  └─ → event: game.started { players: [...], total_players: 3 }

ROOM (active) - SIN ENGINE
  ├─ Redirigir todos los jugadores
  ├─ Presence Channel: verificar que todos llegaron
  ├─ Cuando todos presentes: POST /api/rooms/{code}/ready
  └─ → event: game.countdown { seconds: 3 }

COUNTDOWN
  ├─ Frontend muestra: "3... 2... 1..."
  ├─ Backend carga engine: initializeEngine()
  ├─ Cambia estado a: playing
  └─ → event: game.initialized { initial_state: {...} }

PLAYING (playing) - CON ENGINE
  ├─ event: turn.started { player_id, player_name }
  ├─ event: turn.played { action, new_state }
  ├─ event: turn.ended { next_player }
  ├─ event: round.started (si aplica)
  ├─ event: round.ended (si aplica)
  └─ → event: game.finished { winner, scores }

RESULTS (finished)
  └─ Mostrar ganador y estadísticas
```

---

## Beneficios de esta Arquitectura

1. **Sincronización perfecta**: Todos ven lo mismo al mismo tiempo
2. **Sin polling**: No hay consultas periódicas, solo eventos
3. **Escalable**: WebSockets maneja miles de conexiones
4. **Testeable**: Cada evento es una unidad testeable
5. **Debuggeable**: Cada evento se puede loguear y rastrear
6. **Extensible**: Fácil agregar nuevos eventos/features
7. **Separación de responsabilidades**: Backend = lógica, Frontend = UI
