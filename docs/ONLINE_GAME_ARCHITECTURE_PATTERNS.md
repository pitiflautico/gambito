# Arquitecturas de Juegos Online - Patrones y Mejores Prácticas

**Fecha:** 26 Octubre 2025
**Investigación:** Basada en Agar.io, Among Us, Kahoot!, Socket.io + Redis, y tutoriales de Gabriel Gambetta

---

## 📋 Resumen Ejecutivo

Este documento recopila los patrones y mejores prácticas de arquitecturas reales de juegos multijugador online, aplicados al contexto de **GroupsGames** (plataforma para juegos presenciales con dispositivos móviles).

**Conclusión Principal:** Migrar de HTTP POST a WebSocket bidireccional + Redis state puede reducir la latencia **~10x** (de 100-500ms a <50ms) y eliminar queries a BD durante el juego.

---

## 🎮 Juegos Analizados

### 1. **Agar.io** (MMO de acción rápida)
- **Stack:** Node.js + Socket.io + WebSocket
- **Arquitectura:** Authoritative Server
- **Estado:** Todo el game logic en servidor
- **Performance:** 1 core = 190 jugadores concurrentes
- **Network:** "Tick-tock" messaging (estado continuo)

### 2. **Among Us** (Multiplayer social)
- **Stack:** Unity + Custom UDP netcode
- **Arquitectura:** Room-based con servidor centralizado
- **Puertos:** UDP 22023-22923
- **Rooms:** Máximo 10-15 jugadores por partida
- **Sync:** Lightweight architecture con sincronización eficiente

### 3. **Kahoot!** (Quiz en tiempo real)
- **Stack:** Node.js + Socket.io + Redis + MySQL + Kafka
- **Arquitectura:** Two-view (Host + Players) sincronizados
- **Escalabilidad:** Auto-scaling para millones de usuarios
- **State:** Redis para real-time, MySQL para persistencia
- **Frontend:** React/Angular/Vue + WebSockets

---

## 🏗️ Patrones Arquitectónicos

### Patrón #1: **Authoritative Server** (Servidor Autoritativo)

**Regla de oro:** "Don't trust the client"

```
❌ MAL:
Cliente dice: "Tengo 100 puntos"
Servidor: "OK, guardado"

✅ BIEN:
Cliente dice: "Respondí opción 2"
Servidor:
  1. Valida que no esté bloqueado
  2. Verifica respuesta contra pregunta actual
  3. Calcula puntos si es correcta
  4. Actualiza state
  5. Broadcast resultado a todos
```

**Por qué:**
- Previene hacking/cheating
- Garantiza fairness
- Single source of truth
- Permite rollback/replay

**Aplicación en GroupsGames:**
```php
// ❌ NUNCA hacer:
public function submitAnswer(Request $request) {
    $points = $request->input('points'); // ← Cliente dice sus puntos
    $player->score += $points;
}

// ✅ SIEMPRE hacer:
public function submitAnswer(Request $request) {
    $answer = $request->input('answer_index');
    $isCorrect = $this->engine->validateAnswer($player, $answer);
    if ($isCorrect) {
        $points = $this->engine->calculatePoints($question);
        $this->engine->addScore($player, $points);
    }
}
```

---

### Patrón #2: **WebSocket Bidireccional**

**HTTP POST (actual):**
```javascript
// Cliente → Servidor
fetch('/api/trivia/ABC123/answer', {
    method: 'POST',
    body: JSON.stringify({ answer_index: 2 })
});
// Latencia: 100-500ms
// Overhead: Headers HTTP, parsing JSON, routing, etc.
```

**WebSocket (recomendado):**
```javascript
// Cliente → Servidor
Echo.private(`room.${code}`)
    .whisper('game.action', {
        type: 'answer',
        answer_index: 2
    });
// Latencia: 10-50ms
// Overhead: Mínimo (conexión persistente)
```

**Comparativa:**

| Métrica              | HTTP POST       | WebSocket      | Mejora |
|---------------------|-----------------|----------------|--------|
| Latencia            | 100-500ms       | 10-50ms        | ~10x   |
| Overhead            | ~1KB headers    | ~10 bytes      | ~100x  |
| Conexiones          | 1 por request   | 1 persistente  | ∞      |
| Server load         | Alto (routing)  | Bajo (directo) | ~5x    |
| Queries BD          | 2-3 por request | 0 (Redis)      | ∞      |

---

### Patrón #3: **Redis como State Store**

**Problema con BD SQL durante el juego:**
```php
// ❌ Cada acción hace queries:
$room = Room::where('code', $code)
    ->with('match.players')
    ->first();
// Query 1: SELECT * FROM rooms WHERE code = ?
// Query 2: SELECT * FROM game_matches WHERE room_id = ?
// Query 3: SELECT * FROM players WHERE match_id = ?
// Tiempo: ~20-50ms POR ACCIÓN
```

**Solución con Redis:**
```php
// ✅ Todo en memoria:
$state = Redis::get("game:match:{$matchId}:state");
$gameState = json_decode($state, true);
// Tiempo: ~1-2ms
```

**Arquitectura recomendada:**

```
┌─────────────┐
│   Inicio    │ ← MySQL (persistir match, players, config)
│   de Juego  │
└──────┬──────┘
       │
       ▼
┌─────────────┐
│   Durante   │ ← Redis (game_state, player actions, scores)
│   Juego     │   WebSocket (broadcast events)
└──────┬──────┘
       │
       ▼
┌─────────────┐
│  Final de   │ ← MySQL (guardar resultados, rankings, stats)
│   Juego     │
└─────────────┘
```

**Ventajas:**
- **Speed:** Redis ~1ms vs MySQL ~20ms
- **Scalability:** Redis horizontal scaling fácil
- **Consistency:** Atomic operations (INCR, LPUSH, etc.)
- **Persistence:** Redis AOF/RDB para recovery

---

### Patrón #4: **Room-Based Architecture**

**Concepto:**
- Cada partida = 1 Room
- Cada Room = Estado aislado
- Broadcast solo dentro del Room

**Implementación:**

```javascript
// Cliente se une al room
Echo.join(`room.${code}`)
    .listen('.game.round.started', (data) => {
        // Solo este room recibe el evento
    });
```

```php
// Servidor broadcast solo al room
broadcast(new RoundStartedEvent($match))
    ->toOthers(); // Solo a room.{code}
```

**Ventajas:**
- Aislamiento de partidas
- Reducción de tráfico de red
- Escalabilidad (N rooms = N cores)
- Fácil debugging

---

### Patrón #5: **State Synchronization**

**NO enviar todo el estado en cada update:**

```javascript
// ❌ MAL (enviando TODO):
broadcast({
    game_state: {
        phase: 'playing',
        questions: [...100 preguntas...],
        current_question: {...},
        scores: {...},
        round_system: {...},
        timer_system: {...},
        player_states: {...}
    }
});
// Tamaño: ~50KB por update
```

**✅ BIEN (solo cambios):**

```javascript
broadcast({
    event: 'round.started',
    data: {
        current_round: 2,
        current_question: {
            id: 5,
            question: "¿Cuál es...?",
            options: ["A", "B", "C", "D"]
        },
        timer: 30
    }
});
// Tamaño: ~1KB por update
```

**Delta Updates:**
```javascript
// Solo enviar lo que cambió
broadcast({
    event: 'score.updated',
    player_id: 123,
    delta: +10,  // ← Solo el cambio
    new_total: 50
});
```

---

### Patrón #6: **Event-Driven Architecture**

**Todo el juego funciona con eventos:**

```
Cliente                 Servidor                  Todos los clientes
   │                       │                             │
   │──game.action──────▶  │                             │
   │  {answer: 2}          │                             │
   │                       │                             │
   │                       ├─ Validar                    │
   │                       ├─ Calcular puntos            │
   │                       ├─ Actualizar state (Redis)   │
   │                       │                             │
   │                       ├──game.answer.correct───────▶│
   │◀──────────────────────┤  {player: 123, points: 10} │
   │                       │                             │
```

**Eventos típicos:**

**Server → Client (broadcast):**
- `game.initialized`
- `game.countdown`
- `game.started`
- `round.started`
- `round.ended`
- `game.ended`

**Client → Server (actions):**
- `game.action.answer`
- `game.action.draw`
- `game.action.vote`
- `player.ready`

---

### Patrón #7: **Throttling y Batching**

**Problema:** En juegos de acción rápida (Pictionary), el cliente puede enviar 60 events/segundo.

**Solución 1: Client-side throttling**
```javascript
// ❌ Enviar cada trazo (60 fps = 60 events/s)
canvas.on('mousemove', (e) => {
    socket.emit('draw', { x: e.x, y: e.y });
});

// ✅ Throttle a 10 events/s
const throttledDraw = _.throttle((point) => {
    socket.emit('draw', point);
}, 100); // Max 1 cada 100ms

canvas.on('mousemove', (e) => {
    throttledDraw({ x: e.x, y: e.y });
});
```

**Solución 2: Server-side batching**
```javascript
const batch = [];
const BATCH_INTERVAL = 50; // 50ms

socket.on('draw', (point) => {
    batch.push(point);
});

setInterval(() => {
    if (batch.length > 0) {
        io.to(roomId).emit('draw.batch', batch);
        batch.length = 0; // Clear
    }
}, BATCH_INTERVAL);
```

---

### Patrón #8: **Graceful Disconnect/Reconnect**

**Problema:** Usuario pierde WiFi momentáneamente.

**❌ MAL:** Kickear inmediatamente del juego.

**✅ BIEN:** Grace period + Reconnection

```javascript
// Cliente detecta desconexión
Echo.connector.socket.on('disconnect', () => {
    showReconnectingUI();
});

Echo.connector.socket.on('reconnect', () => {
    // Pedir estado actual
    fetch(`/api/rooms/${code}/state`)
        .then(state => resyncGameState(state));
    hideReconnectingUI();
});
```

```php
// Servidor
public function handlePlayerDisconnect(Player $player) {
    // No eliminar inmediatamente
    $this->setPlayerState($player->id, 'disconnected');

    // Esperar 30 segundos
    dispatch(new RemoveInactivePlayer($player))
        ->delay(now()->addSeconds(30));
}

public function handlePlayerReconnect(Player $player) {
    // Cancelar removal job
    $this->setPlayerState($player->id, 'active');

    // Enviar estado actual
    return $this->getGameStateForPlayer($player);
}
```

---

### Patrón #9: **No Queries Durante el Juego**

**Principio:** El `game_state` en Redis debe contener TODO lo necesario.

```php
// ❌ NUNCA durante el juego:
$players = $match->players;  // Query a BD
$player = Player::find($id);  // Query a BD
$room = Room::where('code', $code)->first();  // Query a BD

// ✅ SIEMPRE usar game_state:
$totalPlayers = $gameState['_config']['total_players'];
$playerName = $gameState['_config']['players'][$playerId]['name'];
$isLocked = $gameState['player_states'][$playerId]['locked'] ?? false;
```

**Estructura recomendada de game_state:**

```php
[
    '_config' => [
        'game' => 'trivia',
        'total_players' => 4,
        'players' => [
            123 => ['id' => 123, 'name' => 'Juan', 'avatar' => '...'],
            456 => ['id' => 456, 'name' => 'Maria', 'avatar' => '...'],
        ],
        'initialized_at' => '2025-10-26 10:00:00',
    ],

    'phase' => 'playing',
    'current_round' => 2,

    // Módulos
    'round_system' => [...],
    'timer_system' => [...],
    'scoring_system' => [...],
    'player_states' => [...],

    // Game-specific
    'questions' => [...],
    'current_question' => [...],
]
```

---

## 📊 Arquitectura Recomendada para GroupsGames

### Stack Tecnológico

```
Frontend:
├── Laravel Blade (SSR inicial)
├── Alpine.js / Vue.js (reactivity)
├── Laravel Echo (WebSocket client)
└── Pusher.js (protocol)

Backend:
├── Laravel 11
├── Laravel Reverb (WebSocket server)
├── Redis (state + pub/sub)
└── MySQL (persistencia)
```

### Flujo Completo

```
1. INICIALIZACIÓN (MySQL)
   └─ Crear Room, Match, Players
   └─ Cargar config del juego
   └─ Initialize engine
   └─ Guardar game_state inicial

2. LOBBY (WebSocket + Redis)
   └─ Players join Presence Channel
   └─ Real-time player list
   └─ Ready checks
   └─ Countdown

3. JUEGO (WebSocket + Redis ONLY)
   └─ Cargar game_state desde Redis
   └─ Cliente envía actions via WebSocket
   └─ Servidor valida y actualiza Redis
   └─ Broadcast eventos a room
   └─ Repetir hasta game over
   └─ 0 QUERIES A MYSQL

4. FINALIZACIÓN (MySQL)
   └─ Guardar resultados finales
   └─ Calcular rankings
   └─ Actualizar stats
   └─ Liberar room
```

### Componentes Base Necesarios

**Backend:**
```
BaseGameEngine
├── WebSocket Action Handler
├── Redis State Manager
├── Event Broadcaster
├── Reconnection Handler
└── Module System (Round, Timer, Score, etc.)
```

**Frontend:**
```
BaseGameJS
├── WebSocket Manager
├── Event Listener System
├── State Synchronizer
├── Reconnection Handler
├── UI Updater
└── Action Sender
```

---

## 🚀 Plan de Migración

### Fase 1: Preparar Base (2-3 días)
- [ ] Crear `BaseGameEngine` con WebSocket support
- [ ] Crear `BaseGame.js` para cliente
- [ ] Implementar Redis State Manager
- [ ] Setup bidirectional WebSocket (client → server)

### Fase 2: Refactorizar Trivia (2 días)
- [ ] Migrar TriviaEngine a nuevo BaseEngine
- [ ] Migrar game.blade.php a BaseGame.js
- [ ] Eliminar HTTP POST endpoints
- [ ] Eliminar queries durante juego

### Fase 3: Testing (2 días)
- [ ] Test con 4 jugadores simultáneos
- [ ] Test disconnect/reconnect
- [ ] Test race conditions
- [ ] Benchmark latencia

### Fase 4: Documentar (1 día)
- [ ] Docs para crear nuevos juegos
- [ ] API reference BaseEngine/BaseJS
- [ ] Troubleshooting guide

**Total:** 7-8 días

---

## 📈 Métricas de Éxito

### Antes (HTTP POST + MySQL):

```
Action: Responder pregunta
├── HTTP POST request: ~50ms
├── Routing + middleware: ~10ms
├── Query Room: ~20ms
├── Query Match: ~15ms
├── Query Players: ~25ms
├── Process answer: ~5ms
├── Save to MySQL: ~30ms
├── Broadcast event: ~20ms
└── TOTAL: ~175ms + 3 queries
```

### Después (WebSocket + Redis):

```
Action: Responder pregunta
├── WebSocket message: ~5ms
├── Get state from Redis: ~2ms
├── Process answer: ~5ms
├── Update Redis: ~3ms
├── Broadcast event: ~5ms
└── TOTAL: ~20ms + 0 queries
```

**Mejora:** ~8.75x más rápido, 0 queries

---

## 🔒 Consideraciones de Seguridad

### 1. Validación en Servidor (SIEMPRE)
```php
// ❌ Confiar en el cliente
$points = $data['points'];

// ✅ Calcular en servidor
$points = $this->calculatePoints($question['difficulty']);
```

### 2. Rate Limiting
```php
// Prevenir spam de acciones
RateLimiter::attempt(
    "game-action:{$playerId}",
    $perMinute = 60,
    function() use ($action) {
        $this->processAction($action);
    }
);
```

### 3. State Validation
```php
// Verificar que el jugador puede hacer esta acción
if ($gameState['phase'] !== 'playing') {
    throw new InvalidActionException();
}

if ($this->isPlayerLocked($playerId)) {
    throw new PlayerLockedException();
}
```

### 4. Replay Attack Prevention
```php
// Timestamp + nonce
$action = [
    'type' => 'answer',
    'answer_index' => 2,
    'timestamp' => now()->timestamp,
    'nonce' => Str::random(16),
];

// Servidor verifica timestamp (max 5s old)
if (now()->timestamp - $action['timestamp'] > 5) {
    throw new ExpiredActionException();
}
```

---

## 📚 Referencias

**Tutoriales:**
- [Gabriel Gambetta - Client-Server Game Architecture](https://www.gabrielgambetta.com/client-server-game-architecture.html)
- [Socket.io + Redis Guide - DEV Community](https://dev.to/dowerdev/building-a-real-time-multiplayer-game-server-with-socketio-and-redis-architecture-and-583m)
- [Agar.io Clone - Game Architecture](https://github.com/huytd/agar.io-clone/wiki/Game-Architecture)

**Frameworks:**
- [Colyseus - Multiplayer Framework](https://colyseus.io/)
- [Laravel Reverb](https://reverb.laravel.com/)
- [Laravel Broadcasting](https://laravel.com/docs/12.x/broadcasting)

**Papers:**
- [Fast-Paced Multiplayer](https://www.gabrielgambetta.com/client-side-prediction-live-demo.html)
- [CRDT-Based State Sync](https://arxiv.org/html/2503.17826v1)

---

## 🎯 Conclusiones

**Para GroupsGames:**

1. ✅ **SÍ migrar a WebSocket bidireccional** - Latencia ~10x mejor
2. ✅ **SÍ usar Redis para game state** - 0 queries durante juego
3. ✅ **SÍ usar Authoritative Server** - Previene cheating
4. ✅ **SÍ usar Event-Driven** - Arquitectura escalable
5. ❌ **NO usar Client-Side Prediction** - No necesario para juegos por turnos
6. ❌ **NO confiar en el cliente** - Validar TODO en servidor

**ROI Estimado:**
- Latencia: 100-500ms → 10-50ms (~10x mejor)
- Queries: 3 por acción → 0 (∞ mejor)
- Escalabilidad: 50 jugadores → 500+ jugadores (10x mejor)
- Desarrollo: 7-8 días de refactor
- Mantenimiento: Más simple (1 sistema en lugar de 2)

**Recomendación:** Proceder con migración. El esfuerzo (7-8 días) justifica las mejoras de performance y escalabilidad.
