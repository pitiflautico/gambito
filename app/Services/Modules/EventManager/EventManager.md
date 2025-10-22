# Event Manager Module

## 📋 Descripción

El módulo **EventManager** es un sistema centralizado para gestionar comunicación en tiempo real via WebSockets entre el backend (Laravel/Reverb) y el frontend (JavaScript) de los juegos.

## 🎯 Responsabilidades

### 1. Conexión WebSocket
- Establecer y mantener conexión con Laravel Echo
- Reconexión automática en caso de desconexión
- Gestión de estados de conexión

### 2. Registro de Eventos
- Cargar configuración de eventos desde `capabilities.json`
- Registrar listeners automáticamente
- Mapear eventos a handlers del juego

### 3. Emisión de Eventos
- API unificada para emitir eventos al servidor
- Validación de payloads
- Manejo de errores de red

### 4. Sincronización de Estado
- Sincronizar estado inicial al cargar el juego
- Actualizar UI en respuesta a eventos
- Mantener consistencia entre clientes

## 📦 Estructura del Módulo

```
app/Services/Modules/EventManager/
├── EventManager.md                 # Esta documentación
└── (Backend - si necesario en el futuro)

resources/js/modules/
└── EventManager.js                 # Clase JavaScript del módulo
```

## 🎮 Configuración en capabilities.json

Cada juego declara los eventos que usa:

```json
{
  "slug": "trivia",
  "requires": {
    "modules": {
      "event_manager": "^1.0"
    }
  },
  "provides": {
    "events": [
      "QuestionStartedEvent",
      "PlayerAnsweredEvent",
      "QuestionEndedEvent"
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "QuestionStartedEvent": {
        "name": "trivia.question.started",
        "handler": "handleQuestionStarted"
      },
      "PlayerAnsweredEvent": {
        "name": "trivia.player.answered",
        "handler": "handlePlayerAnswered"
      },
      "QuestionEndedEvent": {
        "name": "trivia.question.ended",
        "handler": "handleQuestionEnded"
      }
    }
  }
}
```

## 💻 API del EventManager (JavaScript)

### Inicialización

```javascript
import EventManager from './modules/EventManager.js';

class TriviaGame {
    constructor(config) {
        this.eventManager = new EventManager({
            roomCode: config.roomCode,
            gameSlug: config.gameSlug,
            eventConfig: config.eventConfig,
            handlers: {
                handleQuestionStarted: (event) => this.onQuestionStarted(event),
                handlePlayerAnswered: (event) => this.onPlayerAnswered(event),
                handleQuestionEnded: (event) => this.onQuestionEnded(event),
            }
        });

        // Conectar y sincronizar estado inicial
        this.eventManager.connect();
    }
}
```

### Métodos Principales

#### `connect()`
Establece conexión con el canal de WebSocket.

```javascript
this.eventManager.connect();
```

#### `disconnect()`
Cierra la conexión (útil al salir del juego).

```javascript
this.eventManager.disconnect();
```

#### `emit(eventName, payload)`
Emite un evento al servidor (aunque normalmente se usan llamadas HTTP).

```javascript
this.eventManager.emit('player.action', {
    action: 'answer',
    data: { answer: 2 }
});
```

#### `getConnectionStatus()`
Retorna el estado de la conexión.

```javascript
const status = this.eventManager.getConnectionStatus();
// 'connected', 'connecting', 'disconnected', 'error'
```

## 🔧 Implementación del EventManager

### resources/js/modules/EventManager.js

```javascript
class EventManager {
    constructor(config) {
        this.roomCode = config.roomCode;
        this.gameSlug = config.gameSlug;
        this.eventConfig = config.eventConfig;
        this.handlers = config.handlers;

        this.channel = null;
        this.status = 'disconnected';

        console.log('[EventManager] Initialized', {
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            events: Object.keys(this.eventConfig.events || {})
        });
    }

    connect() {
        if (!window.Echo) {
            console.error('[EventManager] Laravel Echo not available');
            this.status = 'error';
            return;
        }

        this.status = 'connecting';

        // Conectar al canal usando la configuración
        const channelName = this.eventConfig.channel.replace('{roomCode}', this.roomCode);
        this.channel = window.Echo.channel(channelName);

        // Registrar listeners automáticamente
        Object.entries(this.eventConfig.events).forEach(([eventClass, config]) => {
            const { name, handler } = config;

            if (this.handlers[handler]) {
                this.channel.listen(name, (event) => {
                    console.log(`[EventManager] Received: ${name}`, event);
                    this.handlers[handler](event);
                });
            } else {
                console.warn(`[EventManager] Handler not found: ${handler}`);
            }
        });

        this.status = 'connected';
        console.log('[EventManager] Connected to channel:', channelName);
    }

    disconnect() {
        if (this.channel) {
            window.Echo.leave(this.eventConfig.channel.replace('{roomCode}', this.roomCode));
            this.channel = null;
        }

        this.status = 'disconnected';
        console.log('[EventManager] Disconnected');
    }

    emit(eventName, payload) {
        // Nota: Normalmente usamos HTTP para enviar al servidor
        // Pero esto podría usarse para client-to-client events
        console.log('[EventManager] Emit not implemented (use HTTP API instead)');
    }

    getConnectionStatus() {
        return this.status;
    }
}

export default EventManager;
```

## 🎨 Carga de Configuración

El EventManager lee la configuración de eventos desde `capabilities.json` del juego.

### Backend: Pasar configuración a la vista

```php
// games/trivia/TriviaController.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->first();

    if (!$match) {
        abort(404, 'No hay una partida en progreso');
    }

    // Cargar configuración de eventos
    $capabilitiesPath = base_path("games/{$room->game->slug}/capabilities.json");
    $capabilities = json_decode(file_get_contents($capabilitiesPath), true);
    $eventConfig = $capabilities['event_config'] ?? null;

    return view('trivia::game', [
        'room' => $room,
        'match' => $match,
        'players' => $match->players,
        'playerId' => session('player_id'),
        'eventConfig' => $eventConfig,
    ]);
}
```

### Frontend: Inicializar con configuración

```blade
@push('scripts')
    @vite(['resources/js/trivia-game.js'])

    <script>
        window.gameData = {
            roomCode: '{{ $room->code }}',
            playerId: {{ $playerId ?? 'null' }},
            gameSlug: 'trivia',
            gameState: @json($match->game_state ?? null),
            eventConfig: @json($eventConfig ?? null),
        };

        document.addEventListener('DOMContentLoaded', function() {
            window.triviaGame = new window.TriviaGame(window.gameData);
        });
    </script>
@endpush
```

## ✅ Ventajas del EventManager

1. **DRY (Don't Repeat Yourself)**
   - Lógica de WebSockets escrita una vez
   - Reutilizable en todos los juegos

2. **Configuración Declarativa**
   - Eventos definidos en `capabilities.json`
   - No código hardcodeado

3. **Fácil Debugging**
   - Logs centralizados de eventos
   - Estado de conexión visible

4. **Manejo de Errores Robusto**
   - Reconexión automática
   - Handlers de error centralizados

5. **Testeable**
   - Mock del EventManager fácilmente
   - Tests unitarios del módulo

## 🔄 Flujo de Eventos

```
1. Backend: TriviaEngine emite QuestionStartedEvent
   ↓
2. Laravel Reverb: Broadcast a canal room.{code}
   ↓
3. Frontend: EventManager escucha trivia.question.started
   ↓
4. EventManager: Llama a handleQuestionStarted del juego
   ↓
5. TriviaGame: Actualiza UI con la nueva pregunta
```

## 🚨 Errores Comunes Evitados

### ❌ Sin EventManager (código duplicado)
```javascript
// En TriviaGame
setupWebSocket() {
    const channel = window.Echo.channel(`room.${this.roomCode}`);
    channel.listen('trivia.question.started', ...);
    channel.listen('trivia.player.answered', ...);
}

// En PictionaryGame
setupWebSocket() {
    const channel = window.Echo.channel(`room.${this.roomCode}`);
    channel.listen('pictionary.draw', ...);
    channel.listen('pictionary.clear', ...);
}
```

### ✅ Con EventManager (reutilizable)
```javascript
// En ambos juegos
this.eventManager = new EventManager({
    roomCode: this.roomCode,
    eventConfig: this.config.eventConfig,
    handlers: this.getHandlers()
});
```

## 📚 Ver También

- [GAMES_CONVENTION.md](../../../docs/GAMES_CONVENTION.md)
- [WebSocket Events Guide](../../../docs/WEBSOCKET_EVENTS.md) (TODO)
- [Laravel Echo Documentation](https://laravel.com/docs/broadcasting)

---

**Última actualización:** 2025-10-22
