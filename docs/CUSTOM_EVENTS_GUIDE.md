# Guía Completa: Eventos Custom en GroupsGames

## Índice
1. [Introducción](#introducción)
2. [Eventos Genéricos vs Custom](#eventos-genéricos-vs-custom)
3. [Pasos para Crear un Evento Custom](#pasos-para-crear-un-evento-custom)
4. [Errores Comunes](#errores-comunes)
5. [Flujo Completo](#flujo-completo)
6. [Ejemplo Completo: Phase2StartedEvent](#ejemplo-completo-phase2startedevent)

---

## Introducción

GroupsGames usa un sistema de eventos basado en Laravel Broadcasting + Reverb WebSockets para comunicación en tiempo real entre backend y frontend. Hay dos tipos de eventos:

- **Eventos Genéricos**: Eventos del sistema que todos los juegos pueden usar (`PhaseStartedEvent`, `RoundStartedEvent`, etc.)
- **Eventos Custom**: Eventos específicos de un juego para lógica especializada (`Phase2StartedEvent`, `AnswerSubmittedEvent`, etc.)

---

## Eventos Genéricos vs Custom

### ✅ Cuándo usar Eventos Genéricos

Usa eventos genéricos cuando:
- La lógica es simple y puede manejarse con condicionales
- No necesitas datos específicos adicionales
- Quieres reutilizar código entre juegos

**Ejemplo:**
```javascript
handlePhaseStarted: (event) => {
    if (event.phase_name === 'phase3') {
        this.hideAnswerButtons();
        this.showPhase3Message();
    }
}
```

### ⚡ Cuándo usar Eventos Custom

Usa eventos custom cuando:
- Necesitas lógica compleja específica de una fase
- Quieres datos adicionales específicos del contexto
- Necesitas ejecutar callbacks del engine en backend
- Quieres mejor separación de responsabilidades

**Ejemplo:**
```php
class Phase2StartedEvent implements ShouldBroadcastNow
{
    public array $availableAnswers;
    public int $questionId;
    // ... datos específicos de esta fase
}
```

---

## Pasos para Crear un Evento Custom

### Paso 1: Crear el Archivo de Evento PHP

**Ubicación**: `app/Events/{GameName}/{EventName}.php`

```php
<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Phase2StartedEvent implements ShouldBroadcastNow
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
        $this->phase = 'phase2';
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer';
        $this->serverTime = now()->timestamp;
        $this->phaseData = $phaseConfig;
    }

    /**
     * Canal de broadcast - IMPORTANTE: Usar PresenceChannel
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    /**
     * Nombre del evento - IMPORTANTE: Sin el punto inicial
     */
    public function broadcastAs(): string
    {
        // Agregar logs para debug
        \Log::debug("[Phase2StartedEvent] Broadcasting", [
            'room' => $this->roomCode,
            'phase' => $this->phase,
            'channel' => 'presence-room.' . $this->roomCode,
            'event_name' => 'mockup.phase2.started'
        ]);

        return 'mockup.phase2.started'; // SIN punto inicial
    }

    /**
     * Datos que se envían al frontend
     * IMPORTANTE: Incluir datos para TimingModule si hay timer
     */
    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'match_id' => $this->matchId,
            'phase' => $this->phase,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            // DATOS PARA TIMINGMODULE (requeridos si hay timer)
            'timer_id' => $this->timerId,
            'timer_name' => $this->phase,
            'server_time' => $this->serverTime,
            // DATOS DEL JUEGO
            'phase_data' => $this->phaseData,
            // EVENTO A EMITIR CUANDO EXPIRE (para frontend)
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
```

### Paso 2: Configurar en config.json

**Ubicación**: `games/{game-slug}/config.json`

```json
{
  "modules": {
    "phase_system": {
      "enabled": true,
      "phases": [
        {
          "name": "phase2",
          "duration": 6,
          "on_start": "App\\Events\\Mockup\\Phase2StartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePhase2Ended"
        }
      ]
    }
  }
}
```

### Paso 3: ⚠️ CRÍTICO - Registrar en capabilities.json

**Ubicación**: `games/{game-slug}/capabilities.json`

**❌ ERROR COMÚN #1**: Olvidar este paso. El evento NO se suscribirá en el frontend.

```json
{
  "provides": {
    "events": [
      "Phase2StartedEvent"  // ← AGREGAR AQUÍ
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "Phase2StartedEvent": {  // ← AGREGAR AQUÍ
        "name": "mockup.phase2.started",  // CON punto inicial
        "description": "Mockup: Fase 2 iniciada (evento custom)",
        "handler": "handlePhase2Started"
      }
    }
  }
}
```

**⚡ IMPORTANTE**:
- En `broadcastAs()` del evento PHP: SIN punto → `"mockup.phase2.started"`
- En `capabilities.json`: CON punto → `".mockup.phase2.started"` (EventManager lo añade automáticamente)

### Paso 4: Implementar Handler en Frontend

**Ubicación**: `games/{game-slug}/js/{Game}Client.js`

```javascript
export class MockupGameClient extends BaseGameClient {
    setupEventManager() {
        this.customHandlers = {
            // Handler específico para Phase2StartedEvent
            handlePhase2Started: (event) => {
                console.log('🎯 [Mockup] FASE 2 INICIADA', event);

                // Lógica específica de la fase 2
                this.showAnswerButtons();

                // Acceder a datos custom del evento
                console.log('Phase data:', event.phase_data);
                console.log('Timer duration:', event.duration);
            }
        };

        // Llamar al setupEventManager del padre
        super.setupEventManager(this.customHandlers);
    }

    showAnswerButtons() {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'block';
        }
    }
}
```

### Paso 5: Emitir el Evento desde Backend

El evento se emite automáticamente desde PhaseManager cuando usas `on_start` en config.json.

**Manual (si lo necesitas)**:
```php
event(new Phase2StartedEvent($match, $phaseConfig));
```

---

## Errores Comunes

### ❌ Error #1: Olvidar capabilities.json

**Síntoma**: El evento se emite en backend pero no llega al frontend. No hay errores en consola.

**Causa**: El frontend carga eventos desde `capabilities.json`, NO desde `config.json`.

**Solución**:
1. Añadir evento a `provides.events[]`
2. Añadir configuración a `event_config.events`

```json
// capabilities.json
{
  "provides": {
    "events": ["Phase2StartedEvent"]  // ← AÑADIR
  },
  "event_config": {
    "events": {
      "Phase2StartedEvent": {  // ← CONFIGURAR
        "name": "mockup.phase2.started",
        "handler": "handlePhase2Started"
      }
    }
  }
}
```

### ❌ Error #2: Punto inicial inconsistente

**Síntoma**: EventManager no encuentra el evento o Laravel no lo broadcastea.

**Causa**: Confusión sobre cuándo usar punto inicial.

**Regla**:
- `broadcastAs()` en PHP: **SIN punto** → `"mockup.phase2.started"`
- `capabilities.json`: **CON punto** → `".mockup.phase2.started"` (EventManager añade el punto)
- EventManager registra internamente: **CON punto** → `".mockup.phase2.started"`

```php
// ✅ CORRECTO en PHP
public function broadcastAs(): string
{
    return 'mockup.phase2.started'; // SIN punto
}
```

```json
// ✅ CORRECTO en capabilities.json
{
  "Phase2StartedEvent": {
    "name": ".mockup.phase2.started"  // CON punto
  }
}
```

### ❌ Error #3: Handler no definido

**Síntoma**: Warning en consola: "Handler 'handlePhase2Started' no encontrado"

**Causa**: Olvidaste definir el handler en `customHandlers`.

**Solución**:
```javascript
this.customHandlers = {
    handlePhase2Started: (event) => {  // ← DEFINIR AQUÍ
        console.log('Fase 2 iniciada', event);
    }
};
```

### ❌ Error #4: Timer no funciona

**Síntoma**: El evento llega pero el countdown no aparece.

**Causa**: Faltan datos requeridos por TimingModule en `broadcastWith()`.

**Solución**: Incluir estos campos obligatorios:
```php
public function broadcastWith(): array
{
    return [
        'timer_id' => 'timer',           // ID del elemento HTML
        'timer_name' => $this->phase,    // Nombre del timer
        'duration' => $this->duration,    // Duración en segundos
        'server_time' => now()->timestamp, // Timestamp del servidor
        'event_class' => 'App\\Events\\Game\\PhaseEndedEvent', // Evento al expirar
    ];
}
```

### ❌ Error #5: Canal incorrecto

**Síntoma**: Evento no llega a los clientes.

**Causa**: Usar canal incorrecto o no usar PresenceChannel.

**Solución**: Siempre usar PresenceChannel para rooms:
```php
public function broadcastOn(): PresenceChannel
{
    return new PresenceChannel('room.' . $this->roomCode);
}
```

---

## Flujo Completo

### Backend → Frontend

```
┌─────────────────────────────────────────────────────────────┐
│                        BACKEND                               │
└─────────────────────────────────────────────────────────────┘
  1. PhaseManager detecta on_start en config.json
     ↓
  2. PhaseManager::startPhase()
     └─> event(new Phase2StartedEvent($match, $phaseConfig))
     ↓
  3. Laravel Broadcasting
     └─> Reverb WebSocket Server
     ↓
     └─> Broadcast a canal: presence-room.{roomCode}
     └─> Evento: .mockup.phase2.started

┌─────────────────────────────────────────────────────────────┐
│                       FRONTEND                               │
└─────────────────────────────────────────────────────────────┘
  4. PlayController carga capabilities.json
     └─> Pasa eventConfig al blade
     ↓
  5. BaseGameClient crea EventManager
     └─> EventManager.registerListeners()
     └─> Lee eventConfig.events
     └─> Para cada evento: channel.listen(eventName, handler)
     ↓
  6. Echo (Laravel WebSockets Client)
     └─> Recibe evento: .mockup.phase2.started
     ↓
  7. EventManager ejecuta handler
     └─> handlePhase2Started(event)
     ↓
  8. TimingModule detecta timer (autoProcessEvent)
     └─> Inicia countdown visual
     └─> Cuando expira → envía notificación a backend
```

### Frontend → Backend (Timer Expiry)

```
┌─────────────────────────────────────────────────────────────┐
│                       FRONTEND                               │
└─────────────────────────────────────────────────────────────┘
  1. TimingModule detecta timer expirado
     ↓
  2. Envía POST a /api/rooms/{code}/timer-expired
     {
       timer_name: 'phase2',
       event_class: 'App\\Events\\Game\\PhaseEndedEvent'
     }

┌─────────────────────────────────────────────────────────────┐
│                        BACKEND                               │
└─────────────────────────────────────────────────────────────┘
  3. PlayController::notifyTimerExpired()
     └─> Instancia event_class
     └─> event(new PhaseEndedEvent($match, $phaseConfig))
     ↓
  4. PhaseEndedEvent ejecuta callback
     └─> engine->handlePhase2Ended($match, $phaseData)
     ↓
  5. Engine avanza fase o termina ronda
     └─> phaseManager->nextPhase()
     └─> Si cycle_completed → endCurrentRound()
```

---

## Ejemplo Completo: Phase2StartedEvent

### Archivos Modificados

```
app/Events/Mockup/
  └── Phase2StartedEvent.php          [CREAR]

games/mockup/
  ├── config.json                      [MODIFICAR - añadir on_start]
  ├── capabilities.json                [MODIFICAR - registrar evento]
  └── js/MockupGameClient.js          [MODIFICAR - añadir handler]

games/Mockup/
  └── MockupEngine.php                 [MODIFICAR - añadir callback]
```

### 1. Event PHP

```php
// app/Events/Mockup/Phase2StartedEvent.php
<?php

namespace App\Events\Mockup;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Phase2StartedEvent implements ShouldBroadcastNow
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
        $this->phase = 'phase2';
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
        return 'mockup.phase2.started'; // SIN punto
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

### 2. config.json

```json
{
  "modules": {
    "phase_system": {
      "enabled": true,
      "phases": [
        {
          "name": "phase1",
          "duration": 3,
          "on_start": "App\\Events\\Mockup\\Phase1StartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePhase1Ended"
        },
        {
          "name": "phase2",
          "duration": 6,
          "on_start": "App\\Events\\Mockup\\Phase2StartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePhase2Ended"
        },
        {
          "name": "phase3",
          "duration": 4,
          "on_start": "App\\Events\\Game\\PhaseStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePhase3Ended"
        }
      ]
    }
  }
}
```

### 3. capabilities.json

```json
{
  "provides": {
    "events": [
      "Phase1StartedEvent",
      "Phase1EndedEvent",
      "Phase2StartedEvent"
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "Phase1StartedEvent": {
        "name": ".mockup.phase1.started",
        "handler": "handlePhase1Started"
      },
      "Phase2StartedEvent": {
        "name": ".mockup.phase2.started",
        "handler": "handlePhase2Started"
      },
      "PhaseStartedEvent": {
        "name": ".game.phase.started",
        "handler": "handlePhaseStarted"
      }
    }
  }
}
```

### 4. MockupGameClient.js

```javascript
export class MockupGameClient extends BaseGameClient {
    setupEventManager() {
        this.customHandlers = {
            handlePhase1Started: (event) => {
                console.log('🎯 [Mockup] FASE 1 INICIADA', event);
                this.hideAnswerButtons();
            },

            handlePhase2Started: (event) => {
                console.log('🎯 [Mockup] FASE 2 INICIADA', event);
                this.showAnswerButtons();
            },

            handlePhaseStarted: (event) => {
                console.log('🎬 [Mockup] FASE GENÉRICA', event);
                if (event.phase_name === 'phase3') {
                    this.hideAnswerButtons();
                    this.showPhase3Message();
                }
            }
        };

        super.setupEventManager(this.customHandlers);
    }

    showAnswerButtons() {
        const buttons = document.getElementById('answer-buttons');
        if (buttons) buttons.style.display = 'block';
    }

    hideAnswerButtons() {
        const buttons = document.getElementById('answer-buttons');
        if (buttons) buttons.style.display = 'none';
    }

    showPhase3Message() {
        const message = document.getElementById('phase3-message');
        if (message) message.style.display = 'block';
    }
}
```

### 5. MockupEngine.php

```php
class MockupEngine extends BaseGameEngine
{
    public function handlePhase2Ended(GameMatch $match, array $phaseData): void
    {
        Log::info("🏁 [Mockup] FASE 2 FINALIZADA", [
            'match_id' => $match->id,
            'phase_data' => $phaseData
        ]);

        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();
        $phaseManager->setMatch($match);

        $nextPhaseInfo = $phaseManager->nextPhase();
        $this->saveRoundManager($match, $roundManager);

        if ($nextPhaseInfo['cycle_completed']) {
            Log::info("✅ [Mockup] Ciclo completado - Finalizando ronda");
            $this->endCurrentRound($match);
        }
    }
}
```

---

## Checklist para Eventos Custom

Antes de probar tu evento custom, verifica:

- [ ] ✅ Archivo PHP creado en `app/Events/{Game}/`
- [ ] ✅ Implementa `ShouldBroadcastNow`
- [ ] ✅ `broadcastOn()` retorna `PresenceChannel`
- [ ] ✅ `broadcastAs()` retorna nombre SIN punto inicial
- [ ] ✅ `broadcastWith()` incluye todos los datos necesarios
- [ ] ✅ Si hay timer: incluir `timer_id`, `duration`, `server_time`, `event_class`
- [ ] ✅ Evento añadido a `provides.events[]` en `capabilities.json`
- [ ] ✅ Evento configurado en `event_config.events` en `capabilities.json`
- [ ] ✅ Nombre en capabilities.json CON punto inicial
- [ ] ✅ Handler definido en `{Game}Client.js`
- [ ] ✅ Handler añadido a `customHandlers` en `setupEventManager()`
- [ ] ✅ Configurado `on_start` en `config.json` si es automático
- [ ] ✅ Callback `on_end_callback` implementado en Engine si es necesario

---

## Debugging

### Ver logs de eventos

```bash
tail -f storage/logs/laravel.log | grep -E "(Broadcasting|Phase|Event)"
```

### Ver eventos en Reverb

```bash
php artisan reverb:start --debug
```

### Ver eventos en frontend

```javascript
// En consola del navegador
window.Echo.connector.pusher.connection.bind('message', (event) => {
    console.log('Reverb message:', event);
});
```

### Verificar suscripción

```javascript
// En consola del navegador
console.log(window.mockupClient.eventManager.listeners);
```

---

## Resumen

### Para Eventos Custom:
1. Crear archivo PHP del evento
2. Configurar en `config.json` (opcional, para automático)
3. **⚠️ CRÍTICO**: Registrar en `capabilities.json`
4. Implementar handler en frontend
5. Implementar callback en Engine (si on_end_callback)

### Para Eventos Genéricos:
1. Usar eventos existentes del sistema
2. Implementar handler con lógica condicional
3. No necesitas modificar `capabilities.json`

### Regla de Oro:
**Si el evento no llega al frontend, 99% de las veces olvidaste `capabilities.json`**

---

**Fecha**: 2025-10-30
**Versión**: 1.0
**Juego de Referencia**: Mockup (con 3 fases demostrando ambos patrones)
