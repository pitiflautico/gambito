# Investigación: Client → Server via WebSocket en Laravel Reverb

**Fecha:** 2025-10-26
**Objetivo:** Eliminar HTTP POST durante el juego y usar solo WebSockets + game_state en memoria

---

## 📋 Resumen Ejecutivo

**Laravel Reverb PUEDE recibir acciones del cliente via WebSocket** usando dos mecanismos:

1. **Client Events (whisper)** - Para comunicación cliente-a-cliente SIN tocar el backend
2. **MessageReceived Event** - Para interceptar TODOS los mensajes WebSocket en el backend

### Estado Actual del Proyecto

Actualmente el proyecto usa **HTTP POST** para enviar acciones del jugador:

```javascript
// resources/js/trivia-game.js:428-441
async sendAnswer(optionIndex) {
    const response = await fetch(`/api/trivia/answer`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            room_code: this.roomCode,
            player_id: this.playerId,
            answer: optionIndex
        })
    });
}
```

**Ruta API actual:**
```php
// routes/api.php:56-58
Route::post('/{code}/action', [\App\Http\Controllers\PlayController::class, 'apiProcessAction'])
    ->middleware(['web'])
    ->name('action');
```

---

## 🔍 1. Client Events (Whisper) - Pusher Protocol

### ¿Qué son los Client Events?

Los **client events** permiten que los clientes se comuniquen entre sí **sin pasar por el backend Laravel**. Es perfecto para features como "typing indicators" pero **NO es adecuado para acciones de juego** que requieren validación y procesamiento en el servidor.

### Documentación Oficial

> "You may wish to broadcast an event to other connected clients without hitting your Laravel application at all."
> — [Laravel Broadcasting Docs](https://laravel.com/docs/12.x/broadcasting)

### Cómo Funciona

**Frontend (Enviar):**
```javascript
Echo.private(`room.${roomCode}`)
    .whisper('typing', {
        name: this.user.name
    });
```

**Frontend (Recibir):**
```javascript
Echo.private(`room.${roomCode}`)
    .listenForWhisper('typing', (e) => {
        console.log(e.name);
    });
```

### Restricciones Importantes

1. **Prefijo obligatorio:** Los eventos deben empezar con `client-`
2. **Solo canales privados/presence:** No funciona en canales públicos
3. **Pusher Channels:** Requiere habilitar "Client Events" en el dashboard
4. **No llega al backend:** Los mensajes NO pasan por Laravel, no hay validación ni procesamiento

### Código de Reverb

```php
// vendor/laravel/reverb/src/Protocols/Pusher/ClientEvent.php:22-24
if (! Str::startsWith($event['event'], 'client-')) {
    return null; // Solo procesa eventos que empiezan con "client-"
}
```

```php
// vendor/laravel/reverb/src/Protocols/Pusher/ClientEvent.php:39-46
public static function whisper(Connection $connection, array $payload): void
{
    EventDispatcher::dispatch(
        $connection->app(),
        $payload,
        $connection
    );
}
```

### ❌ Por qué NO es adecuado para acciones de juego

- ❌ No pasa por el backend (no se puede validar)
- ❌ No hay procesamiento server-side
- ❌ No se puede guardar en game_state
- ❌ No se pueden verificar reglas del juego
- ❌ No se pueden detectar trampas
- ❌ Solo broadcast a otros clientes

**Conclusión:** Whisper es útil para UI/UX (typing, mouse movements) pero NO para gameplay.

---

## ✅ 2. MessageReceived Event - Interceptar Mensajes WebSocket

### ¿Qué es MessageReceived?

Es un **evento de Laravel Reverb** que se dispara **CADA VEZ** que el servidor WebSocket recibe un mensaje de cualquier cliente. Esto permite interceptar y procesar mensajes en el backend.

### Cómo Funciona

**1. Cliente envía mensaje via WebSocket:**
```javascript
// Usando el API de bajo nivel de Pusher
Echo.connector.pusher.send_event(
    'player-action',           // Nombre del evento
    JSON.stringify({           // Payload
        action: 'submit-answer',
        data: { answer: 2 }
    }),
    'presence-room.ABC123'     // Canal
);
```

**2. Reverb despacha MessageReceived:**
```php
// vendor/laravel/reverb/src/Protocols/Pusher/Server.php:69-74
match (Str::startsWith($event['event'], 'pusher:')) {
    true => $this->handler->handle($from, $event['event'], ...),
    default => ClientEvent::handle($from, $event) // Client events o custom events
};

MessageReceived::dispatch($from, $message);
```

**3. Backend escucha el evento:**
```php
// app/Listeners/HandlePlayerAction.php
namespace App\Listeners;

use Laravel\Reverb\Events\MessageReceived;

class HandlePlayerAction
{
    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message);

        // Solo procesar eventos de acciones del jugador
        if (!str_starts_with($message->event, 'player-action')) {
            return;
        }

        $data = json_decode($message->data);

        // Extraer información
        $playerId = $data->player_id;
        $action = $data->action;
        $actionData = $data->data;

        // Procesar acción (SIN query a BD, usar game_state en memoria)
        $this->processPlayerAction($playerId, $action, $actionData);
    }

    private function processPlayerAction($playerId, $action, $data)
    {
        // TODO: Implementar procesamiento sin BD
        // 1. Obtener game_state de Redis/Cache
        // 2. Validar acción
        // 3. Actualizar game_state
        // 4. Broadcast resultado via WebSocket
    }
}
```

**4. Registrar Listener:**
```php
// app/Providers/EventServiceProvider.php
use Illuminate\Support\Facades\Event;
use Laravel\Reverb\Events\MessageReceived;
use App\Listeners\HandlePlayerAction;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(
            MessageReceived::class,
            HandlePlayerAction::class
        );
    }
}
```

### Información Adicional Disponible

```php
// app/Listeners/HandlePlayerAction.php
public function handle(MessageReceived $event): void
{
    // ID de la conexión WebSocket
    $socketId = $event->connection->id();

    // Aplicación (multi-tenant)
    $app = $event->connection->app();

    // Mensaje completo (JSON string)
    $rawMessage = $event->message;

    // Parsear mensaje
    $message = json_decode($rawMessage);

    // Acceder a campos
    $eventName = $message->event;      // 'player-action'
    $channel = $message->channel;      // 'presence-room.ABC123'
    $data = json_decode($message->data);
}
```

---

## 🎯 3. Propuesta de Implementación

### Arquitectura Recomendada

```
┌─────────────┐                    ┌──────────────────┐
│   Cliente   │  WebSocket (WS)    │  Laravel Reverb  │
│  (Browser)  ├───────────────────>│   WS Server      │
└─────────────┘                    └────────┬─────────┘
                                           │
                                           │ MessageReceived Event
                                           │
                                           v
                                    ┌──────────────────┐
                                    │ HandlePlayerAction│
                                    │    (Listener)     │
                                    └────────┬─────────┘
                                           │
                                           v
                                    ┌──────────────────┐
                                    │  GameEngine      │
                                    │  (In Memory)     │
                                    └────────┬─────────┘
                                           │
                                           │ Broadcast resultado
                                           v
                                    ┌──────────────────┐
                                    │  Todos los       │
                                    │  Clientes        │
                                    └──────────────────┘
```

### Paso 1: Modificar Cliente (JavaScript)

**Antes (HTTP POST):**
```javascript
// resources/js/trivia-game.js
async sendAnswer(optionIndex) {
    const response = await fetch(`/api/trivia/answer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            room_code: this.roomCode,
            player_id: this.playerId,
            answer: optionIndex
        })
    });
}
```

**Después (WebSocket):**
```javascript
// resources/js/trivia-game.js
sendAnswer(optionIndex) {
    // Enviar acción via WebSocket
    Echo.connector.pusher.send_event(
        'player-action',
        JSON.stringify({
            player_id: this.playerId,
            action: 'submit-answer',
            data: { answer: optionIndex }
        }),
        `presence-room.${this.roomCode}`
    );

    // Marcar como respondido localmente (UI instantánea)
    this.hasAnswered = true;
    this.selectedOption = optionIndex;
}
```

### Paso 2: Crear Listener

```php
// app/Listeners/HandlePlayerAction.php
<?php

namespace App\Listeners;

use Laravel\Reverb\Events\MessageReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HandlePlayerAction
{
    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message);

        // Solo procesar player-action
        if ($message->event !== 'player-action') {
            return;
        }

        $data = json_decode($message->data);
        $channel = $message->channel; // 'presence-room.ABC123'
        $roomCode = str_replace('presence-room.', '', $channel);

        Log::info('[WebSocket] Player action received', [
            'room_code' => $roomCode,
            'player_id' => $data->player_id,
            'action' => $data->action,
            'socket_id' => $event->connection->id(),
        ]);

        // Obtener game_state de Redis (SIN query a BD)
        $gameStateKey = "game_state:{$roomCode}";
        $gameState = Cache::get($gameStateKey);

        if (!$gameState) {
            Log::warning('[WebSocket] Game state not found in cache', [
                'room_code' => $roomCode,
            ]);
            return;
        }

        // Procesar acción según tipo
        match ($data->action) {
            'submit-answer' => $this->handleSubmitAnswer($roomCode, $gameState, $data),
            'draw' => $this->handleDraw($roomCode, $gameState, $data),
            'guess' => $this->handleGuess($roomCode, $gameState, $data),
            default => Log::warning('[WebSocket] Unknown action', ['action' => $data->action])
        };
    }

    private function handleSubmitAnswer(string $roomCode, array $gameState, object $data): void
    {
        $playerId = $data->player_id;
        $answer = $data->data->answer;

        // 1. Validar que no haya respondido ya
        if (isset($gameState['answers'][$playerId])) {
            Log::info('[WebSocket] Player already answered', [
                'player_id' => $playerId,
            ]);
            return;
        }

        // 2. Validar respuesta
        $currentQuestion = $gameState['current_question'];
        $isCorrect = ($answer === $currentQuestion['correct_answer']);

        // 3. Actualizar game_state en memoria
        $gameState['answers'][$playerId] = $answer;
        if ($isCorrect) {
            $gameState['scores'][$playerId] = ($gameState['scores'][$playerId] ?? 0) + 100;
        }

        // 4. Guardar en Redis
        $gameStateKey = "game_state:{$roomCode}";
        Cache::put($gameStateKey, $gameState, now()->addHours(2));

        // 5. Broadcast resultado via WebSocket
        broadcast(new \App\Events\Game\PlayerActionEvent([
            'player_id' => $playerId,
            'action' => 'submit-answer',
            'is_correct' => $isCorrect,
            'scores' => $gameState['scores'],
            'answered_count' => count($gameState['answers']),
            'total_players' => count($gameState['players']),
        ]))->toOthers();

        // 6. Verificar si todos respondieron
        if (count($gameState['answers']) === count($gameState['players'])) {
            $this->endRound($roomCode, $gameState);
        }
    }

    private function endRound(string $roomCode, array $gameState): void
    {
        // Broadcast RoundEnded
        broadcast(new \App\Events\Game\RoundEndedEvent([
            'room_code' => $roomCode,
            'scores' => $gameState['scores'],
            'correct_answer' => $gameState['current_question']['correct_answer'],
            'results' => $gameState['answers'],
        ]));
    }
}
```

### Paso 3: Registrar Listener

```php
// app/Providers/EventServiceProvider.php
use Illuminate\Support\Facades\Event;
use Laravel\Reverb\Events\MessageReceived;
use App\Listeners\HandlePlayerAction;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(
            MessageReceived::class,
            [HandlePlayerAction::class, 'handle']
        );
    }
}
```

### Paso 4: Eliminar Ruta HTTP

```php
// routes/api.php
// ❌ ELIMINAR:
// Route::post('/{code}/action', [\App\Http\Controllers\PlayController::class, 'apiProcessAction']);

// ✅ Ya no es necesaria, todo via WebSocket
```

---

## 📊 4. Comparativa: HTTP vs WebSocket

| Aspecto | HTTP POST (Actual) | WebSocket (Propuesto) |
|---------|-------------------|---------------------|
| **Latencia** | ~50-200ms | ~5-20ms |
| **Overhead** | Headers HTTP completos | Frame WebSocket mínimo |
| **Conexión** | Nueva por cada request | Reutiliza conexión existente |
| **Queries BD** | 1-5 queries por acción | 0 queries (solo Redis) |
| **Escalabilidad** | Limitada por Apache/PHP-FPM | Alta (async event-driven) |
| **Tiempo Real** | Polling o long-polling | Verdadero tiempo real |
| **Complejidad** | Simple | Media (event listeners) |

### Benchmark Estimado (10 jugadores respondiendo simultáneamente)

**HTTP POST:**
- 10 requests × 100ms = 1000ms total
- 10 × 3 queries BD = 30 queries
- 10 × overhead HTTP = ~50KB datos

**WebSocket:**
- 10 mensajes × 10ms = 100ms total
- 0 queries BD (solo Cache)
- 10 × overhead WS = ~5KB datos

**Mejora:** ~10x más rápido, 0 queries BD

---

## ⚠️ 5. Consideraciones Importantes

### 5.1 Seguridad

❌ **Problema:** Los mensajes WebSocket NO pasan por middleware CSRF automáticamente.

✅ **Solución:**
1. **Autenticación del canal:** Ya implementado en `routes/channels.php`
2. **Validación en el listener:**
   ```php
   public function handle(MessageReceived $event): void
   {
       // Verificar que el jugador tenga acceso al canal
       $socketId = $event->connection->id();
       // Reverb ya autenticó el canal, confiar en eso
   }
   ```

### 5.2 Gestión de Estado

❌ **Problema:** `game_state` en BD puede quedar desincronizado.

✅ **Solución:**
1. **Redis como fuente de verdad durante el juego**
2. **Persistir solo al finalizar ronda/partida**
3. **Checksum para detectar desincronización**

```php
// Guardar checkpoint cada X acciones
private function saveCheckpoint(string $roomCode, array $gameState): void
{
    if ($gameState['actions_count'] % 10 === 0) {
        // Persistir en BD para recovery
        $match = Match::where('room_code', $roomCode)->first();
        $match->update(['game_state' => $gameState]);
    }
}
```

### 5.3 Manejo de Errores

**Problema:** Si el Listener falla, no hay respuesta HTTP para el cliente.

**Solución:**
```php
public function handle(MessageReceived $event): void
{
    try {
        $this->processAction($event);
    } catch (\Exception $e) {
        Log::error('[WebSocket] Error processing action', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Broadcast error al cliente específico
        broadcast(new \App\Events\ErrorEvent([
            'message' => 'Error al procesar acción',
            'code' => 'PROCESSING_ERROR',
        ]))->toOthers();
    }
}
```

### 5.4 Race Conditions

**Problema:** Múltiples acciones simultáneas pueden corromper el estado.

**Solución:** Usar locks de Redis
```php
use Illuminate\Support\Facades\Redis;

private function processWithLock(string $roomCode, callable $callback): void
{
    $lockKey = "game_lock:{$roomCode}";
    $lock = Redis::lock($lockKey, 5); // 5 segundos timeout

    if ($lock->get()) {
        try {
            $callback();
        } finally {
            $lock->release();
        }
    } else {
        Log::warning('[WebSocket] Failed to acquire lock', ['room_code' => $roomCode]);
    }
}
```

---

## 🚀 6. Plan de Migración

### Fase 1: Preparación (1-2 días)
- [ ] Crear `HandlePlayerAction` listener
- [ ] Implementar cache Redis para `game_state`
- [ ] Añadir logs detallados
- [ ] Tests unitarios del listener

### Fase 2: Migración Frontend (1 día)
- [ ] Modificar `TriviaGame.sendAnswer()` para usar WebSocket
- [ ] Actualizar handlers de eventos
- [ ] Mantener HTTP como fallback temporalmente

### Fase 3: Testing (2-3 días)
- [ ] Tests con 2 jugadores
- [ ] Tests con 10 jugadores
- [ ] Tests con 50 jugadores
- [ ] Stress testing (100+ acciones/segundo)
- [ ] Verificar no hay memory leaks

### Fase 4: Deploy y Monitoreo (1 día)
- [ ] Deploy en staging
- [ ] Monitorear latencias en Reverb logs
- [ ] Verificar uso de Redis
- [ ] Verificar no hay queries N+1

### Fase 5: Cleanup (1 día)
- [ ] Eliminar rutas HTTP de acciones
- [ ] Eliminar `PlayController::apiProcessAction()`
- [ ] Actualizar documentación
- [ ] Eliminar código legacy

**Tiempo Total Estimado:** 6-8 días

---

## 📚 7. Referencias

### Documentación Oficial
- [Laravel Broadcasting](https://laravel.com/docs/12.x/broadcasting)
- [Laravel Reverb](https://reverb.laravel.com/)
- [Laravel Echo](https://laravel.com/docs/12.x/broadcasting#client-side-installation)
- [Pusher Protocol](https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/)

### Tutoriales Relevantes
- [Bi-directional communication with Laravel Reverb](https://codecourse.com/articles/bi-directional-communication-with-laravel-reverb)
- [Laravel Reverb - Bidirectional Communication](https://dev.to/nikoladomazetovikj/laravel-reverb-bidirectional-communication-4hi9)

### Issues GitHub
- [Can Laravel Reverb support Client to Server Web Socket communication?](https://github.com/laravel/framework/discussions/51361)
- [Real-time bidirectional communication](https://github.com/laravel/framework/discussions/50661)

### Código Fuente Relevante
- `vendor/laravel/reverb/src/Protocols/Pusher/ClientEvent.php`
- `vendor/laravel/reverb/src/Events/MessageReceived.php`
- `vendor/laravel/reverb/src/Protocols/Pusher/Server.php`

---

## 🎯 Conclusión

**Sí, Laravel Reverb PUEDE recibir acciones del cliente via WebSocket usando `MessageReceived` event.**

### Ventajas
✅ Latencia 10x menor
✅ 0 queries a BD durante el juego
✅ Verdadero tiempo real
✅ Mejor escalabilidad
✅ Menor overhead de red

### Desventajas
❌ Mayor complejidad inicial
❌ Requiere Redis para estado
❌ Debugging más complejo
❌ Necesita manejo de locks

### Recomendación Final

**RECOMENDADO implementar** para:
- ✅ Acciones de juego frecuentes (respuestas, dibujos, movimientos)
- ✅ Features que requieren < 50ms latencia
- ✅ Juegos con 10+ jugadores simultáneos

**NO recomendado** para:
- ❌ Acciones administrativas (crear sala, configurar juego)
- ❌ Features que requieren transacciones BD complejas
- ❌ Operaciones que no son críticas en tiempo

---

**Autor:** Claude (Anthropic)
**Revisado por:** Daniel Pérez Pinazo
**Última actualización:** 2025-10-26
