# WebSockets y Broadcasting

**Última actualización:** 21 de octubre de 2025

---

## Resumen

Gambito utiliza **Laravel Reverb** para WebSockets en tiempo real, permitiendo sincronización instantánea entre jugadores durante las partidas.

### Stack Tecnológico
- **Backend:** Laravel 11 + Laravel Reverb
- **Frontend:** Laravel Echo (JavaScript client)
- **Protocolo:** WebSocket nativo (ws://)
- **Broadcasting:** Canales públicos (producción: privados)

---

## Configuración

### 1. Broadcasting Config

**Archivo:** `config/broadcasting.php`

```php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST', '127.0.0.1'),
        'port' => env('REVERB_PORT', 8080),
        'scheme' => env('REVERB_SCHEME', 'http'),
        'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
    ],
    // IMPORTANTE: Timeout para evitar bloqueos
    'client_options' => [
        'timeout' => 2,          // 2 segundos
        'connect_timeout' => 1,  // 1 segundo
    ],
],
```

### 2. Variables de Entorno

**Archivo:** `.env`

```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=sync

REVERB_APP_ID=666481
REVERB_APP_KEY=xr8jtwtk2zplymz9f8rb
REVERB_APP_SECRET=mgafg8waxu8uluxwcsgq
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### 3. Frontend Setup

**Archivo:** `resources/js/bootstrap.js`

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

---

## Arquitectura de Eventos

### Estructura de un Evento

```php
<?php

namespace Games\Pictionary\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerAnsweredEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public int $playerId;
    public string $playerName;

    public function __construct($roomCode, $playerId, $playerName)
    {
        $this->roomCode = $roomCode;
        $this->playerId = $playerId;
        $this->playerName = $playerName;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('room.' . $this->roomCode),
        ];
    }

    public function broadcastAs(): string
    {
        return 'player.answered';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->playerId,
            'player_name' => $this->playerName,
            'message' => "🙋 {$this->playerName} dice: ¡YA LO SÉ!",
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
```

### Componentes Clave

1. **`ShouldBroadcast`**: Interface que marca el evento para broadcasting
2. **`broadcastOn()`**: Define en qué canales se emite
3. **`broadcastAs()`**: Nombre del evento (prefijo con `.`)
4. **`broadcastWith()`**: Datos que se envían al frontend

---

## Canales

### Canal de Sala: `room.{code}`

**Uso:** Broadcasting de eventos de juego a todos los jugadores de una sala

**Tipo:** Público (TODO: cambiar a privado en producción)

**Eventos emitidos:**
- `player.answered`
- `player.eliminated`
- `round.ended`
- `turn.changed`
- `game.finished`
- `game.state.updated`
- `canvas.draw`

**Ejemplo:**
```php
// Backend
event(new PlayerAnsweredEvent($roomCode, $playerId, $playerName));

// Frontend
Echo.channel(`room.${roomCode}`)
    .listen('.player.answered', (data) => {
        console.log('Player answered:', data);
    });
```

---

## Frontend: Escuchar Eventos

### Conectarse a un Canal

```javascript
const channel = window.Echo.channel(`room.${roomCode}`);
```

### Escuchar Eventos

```javascript
// Jugador presionó "¡YA LO SÉ!"
channel.listen('.player.answered', (data) => {
    console.log(`${data.player_name} quiere responder`);
    // Mostrar panel de confirmación al dibujante
});

// Jugador eliminado
channel.listen('.player.eliminated', (data) => {
    console.log(`${data.player_name} fue eliminado`);
    // Marcar jugador como eliminado en la UI
});

// Ronda terminada
channel.listen('.round.ended', (data) => {
    console.log(`Ronda ${data.round} terminada`);
    // Mostrar modal de resultados
});

// Turno cambiado
channel.listen('.turn.changed', (data) => {
    console.log(`Nuevo dibujante: ${data.new_drawer_name}`);
    // Actualizar UI con nuevo dibujante
    // Cambiar rol del jugador actual si aplica
});

// Juego terminado
channel.listen('.game.finished', (data) => {
    console.log(`Ganador: ${data.winner_name}`);
    // Mostrar resultados finales
});
```

### Desconectarse

```javascript
Echo.leave(`room.${roomCode}`);
```

---

## Backend: Emitir Eventos

### Método 1: Función `event()`

```php
use Games\Pictionary\Events\PlayerAnsweredEvent;

event(new PlayerAnsweredEvent($roomCode, $playerId, $playerName));
```

### Método 2: `broadcast()`

```php
broadcast(new PlayerAnsweredEvent($roomCode, $playerId, $playerName));
```

### Método 3: `dispatch()`

```php
PlayerAnsweredEvent::dispatch($roomCode, $playerId, $playerName);
```

**Recomendación:** Usar `event()` por simplicidad y claridad.

---

## Patrones de Uso

### 1. Sincronización de Estado

**Caso:** Actualizar puntuaciones en tiempo real

```php
// Backend
event(new GameStateUpdatedEvent($match, 'score_update'));

// Frontend
channel.listen('.game.state.updated', (data) => {
    if (data.update_type === 'score_update') {
        updateScores(data.scores);
    }
});
```

### 2. Cambio de Roles

**Caso:** Cambiar dibujante al siguiente turno

```php
// Backend
event(new TurnChangedEvent(
    $roomCode,
    $newDrawerId,
    $newDrawerName,
    $round,
    $turn,
    $scores
));

// Frontend
channel.listen('.turn.changed', (data) => {
    const currentPlayerId = window.gameData.playerId;
    this.isDrawer = (currentPlayerId === data.new_drawer_id);

    if (this.isDrawer) {
        // Mostrar herramientas de dibujo
        // Obtener palabra secreta
    } else {
        // Mostrar botón "¡YA LO SÉ!"
        // Ocultar palabra
    }
});
```

### 3. Sincronización de Canvas

**Caso:** Dibujo en tiempo real

```php
// Backend
event(new CanvasDrawEvent($roomCode, $strokeData));

// Frontend
channel.listen('.canvas.draw', (data) => {
    if (data.action === 'clear') {
        clearCanvas();
    } else {
        drawRemoteStroke(data.stroke);
    }
});
```

---

## Mejores Prácticas

### ✅ DO

1. **Incluir timestamp** en todos los eventos
```php
'timestamp' => now()->toIso8601String()
```

2. **Validar datos** antes de emitir
```php
if (!$roomCode || !$playerId) {
    return;
}
event(new PlayerAnsweredEvent($roomCode, $playerId, $playerName));
```

3. **Logs descriptivos**
```php
Log::info("Player answered", [
    'match_id' => $match->id,
    'player_id' => $playerId,
    'player_name' => $playerName
]);
event(new PlayerAnsweredEvent($match->room->code, $playerId, $playerName));
```

4. **Manejo de errores** en frontend
```javascript
channel.listen('.player.answered', (data) => {
    try {
        handlePlayerAnswered(data);
    } catch (error) {
        console.error('Error handling player answered:', error);
    }
});
```

### ❌ DON'T

1. **No enviar datos sensibles** (contraseñas, tokens)
2. **No bloquear** la ejecución esperando respuesta WebSocket
3. **No emitir** eventos dentro de loops sin throttling
4. **No olvidar** desconectarse al salir de la sala

---

## Debugging

### Ver conexiones activas

```bash
php artisan reverb:status
```

### Logs de eventos

```php
// Habilitar logging en config/logging.php
'channels' => [
    'broadcasting' => [
        'driver' => 'single',
        'path' => storage_path('logs/broadcasting.log'),
        'level' => 'debug',
    ],
],
```

### Console del navegador

```javascript
// Ver todos los eventos
Echo.channel(`room.${roomCode}`)
    .listen('.player.answered', console.log)
    .listen('.player.eliminated', console.log)
    .listen('.round.ended', console.log);
```

---

## Problemas Comunes

### 1. Timeout / Página lenta

**Síntoma:** La página tarda 5-8 segundos en cargar

**Causa:** Reverb no está corriendo o timeout muy alto

**Solución:**
```php
// config/broadcasting.php
'client_options' => [
    'timeout' => 2,
    'connect_timeout' => 1,
],
```

```bash
php artisan reverb:start
```

### 2. Eventos no se reciben

**Checklist:**
- [ ] Reverb está corriendo (`php artisan reverb:start`)
- [ ] Variables de entorno correctas en `.env`
- [ ] Echo inicializado en `bootstrap.js`
- [ ] Nombre del evento correcto (con `.` prefijo)
- [ ] Canal correcto (`room.{code}`)

### 3. "Echo is not defined"

**Causa:** Vite no compiló el código o no se importó `bootstrap.js`

**Solución:**
```javascript
// resources/js/app.js
import './bootstrap';

// Luego compilar
npm run build
```

---

## Seguridad (Producción)

### Cambiar a Canales Privados

```php
// Event
public function broadcastOn(): array
{
    return [
        new PrivateChannel('room.' . $this->roomCode),
    ];
}
```

```php
// routes/channels.php
Broadcast::channel('room.{code}', function ($user, $code) {
    $room = Room::where('code', $code)->first();
    return $room && $room->hasPlayer($user);
});
```

```javascript
// Frontend
Echo.private(`room.${roomCode}`)
    .listen('.player.answered', (data) => {
        // ...
    });
```

### Rate Limiting

```php
// config/broadcasting.php
'options' => [
    'max_connections' => 100,
    'max_requests_per_minute' => 60,
],
```

---

## Ejemplo Completo: Flujo de Respuesta

### 1. Jugador hace clic en "¡YA LO SÉ!"

```javascript
// Frontend
fetch('/api/pictionary/player-answered', {
    method: 'POST',
    body: JSON.stringify({
        room_code: roomCode,
        match_id: matchId,
        player_id: playerId,
        player_name: playerName
    })
});
```

### 2. Backend procesa y emite evento

```php
// PictionaryController
public function playerAnswered(Request $request)
{
    // Procesar lógica
    $engine->processAction($match, $player, 'answer', $data);

    // Emitir evento
    event(new PlayerAnsweredEvent(
        $match->room->code,
        $player->id,
        $player->name
    ));

    return response()->json(['success' => true]);
}
```

### 3. Todos los clientes reciben el evento

```javascript
// Frontend (todos los jugadores)
channel.listen('.player.answered', (data) => {
    console.log(`${data.player_name} quiere responder`);

    // Si soy el dibujante
    if (this.isDrawer) {
        showConfirmationPanel(data.player_id, data.player_name);
    }

    // Si soy el que respondió
    if (data.player_id === myPlayerId) {
        showWaitingMessage();
    }
});
```

### 4. Dibujante confirma

```javascript
// Frontend (dibujante)
fetch('/api/pictionary/confirm-answer', {
    method: 'POST',
    body: JSON.stringify({
        drawer_id: drawerId,
        guesser_id: guesserId,
        is_correct: true
    })
});
```

### 5. Backend emite resultado

```php
// PictionaryController
public function confirmAnswer(Request $request)
{
    $result = $engine->processAction(/* ... */);

    if ($result['round_ended']) {
        event(new RoundEndedEvent(/* ... */));
    } else {
        event(new PlayerEliminatedEvent(/* ... */));
    }
}
```

### 6. Todos actualizan UI

```javascript
// Frontend (todos)
channel.listen('.round.ended', (data) => {
    showRoundResults(data);
});

channel.listen('.player.eliminated', (data) => {
    markPlayerAsEliminated(data.player_id);
});
```

---

**Fin del documento**
