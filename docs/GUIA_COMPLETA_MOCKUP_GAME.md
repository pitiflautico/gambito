# 🎮 Guía Completa: Sistema de Fases y Eventos - MockupGame

> **Documentación profunda** del sistema de fases con eventos personalizados usando MockupGame como modelo de referencia.

---

## 📚 Índice

1. [Visión General](#visión-general)
2. [Arquitectura del Sistema](#arquitectura-del-sistema)
3. [Estructura de Archivos](#estructura-de-archivos)
4. [Sistema de Fases](#sistema-de-fases)
5. [Eventos: Genéricos vs Personalizados](#eventos-genéricos-vs-personalizados)
6. [Flujo Completo de Ejecución](#flujo-completo-de-ejecución)
7. [Frontend: MockupGameClient](#frontend-mockupgameclient)
8. [Cómo Crear un Juego con Fases](#cómo-crear-un-juego-con-fases)
9. [Debugging y Testing](#debugging-y-testing)

---

## Visión General

MockupGame es un juego de prueba diseñado para validar el sistema completo de **fases**, **eventos personalizados** y **módulos**. Sirve como:

- ✅ **Modelo de referencia** para implementar nuevos juegos
- ✅ **Engine de testing** sin lógica compleja
- ✅ **Documentación viva** de las convenciones de arquitectura
- ✅ **Ejemplo de eventos custom** vs eventos genéricos

### Características de MockupGame

- **3 Fases por ronda**: phase1 (3s) → phase2 (12s) → phase3 (4s)
- **3 Rondas totales**: Ciclo completo de fases en cada ronda
- **Eventos personalizados**: Phase1StartedEvent, Phase2StartedEvent
- **Eventos genéricos**: PhaseStartedEvent (phase3), PhaseEndedEvent
- **Sistema de puntuación**: 10 puntos por "Good Answer"
- **Sistema de bloqueo**: Jugadores bloqueados con "Bad Answer"
- **Timers sincronizados**: Frontend muestra countdown en tiempo real

---

## Arquitectura del Sistema

```
┌─────────────────────────────────────────────────────────────────┐
│                         ARQUITECTURA                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐         ┌──────────────┐                     │
│  │  config.json │────────▶│ MockupEngine │                     │
│  └──────────────┘         └──────┬───────┘                     │
│        │                         │                              │
│        │                         │ extiende                     │
│        │                         ▼                              │
│        │                  ┌─────────────────┐                  │
│        │                  │ BaseGameEngine  │                  │
│        │                  └────────┬────────┘                  │
│        │                           │                            │
│        │                           │ usa                        │
│        │                           │                            │
│        ▼                           ▼                            │
│  ┌─────────────────────────────────────────┐                   │
│  │          MÓDULOS DEL SISTEMA            │                   │
│  ├─────────────────────────────────────────┤                   │
│  │ • RoundManager    (round_system)        │                   │
│  │ • PhaseManager    (phase_system)        │◀─── config.json   │
│  │ • ScoreManager    (scoring_system)      │                   │
│  │ • PlayerManager   (player_system)       │                   │
│  │ • TimerSystem     (timer_system)        │                   │
│  └─────────────────────────────────────────┘                   │
│                           │                                     │
│                           │ emite                               │
│                           ▼                                     │
│  ┌─────────────────────────────────────────┐                   │
│  │             EVENTOS                     │                   │
│  ├─────────────────────────────────────────┤                   │
│  │ 📢 GameStartedEvent                     │                   │
│  │ 📢 RoundStartedEvent                    │                   │
│  │ 📢 Phase1StartedEvent     (CUSTOM)      │                   │
│  │ 📢 Phase2StartedEvent     (CUSTOM)      │                   │
│  │ 📢 PhaseStartedEvent      (GENÉRICO)    │                   │
│  │ 📢 PhaseEndedEvent        (GENÉRICO)    │                   │
│  │ 📢 PlayerLockedEvent                    │                   │
│  │ 📢 RoundEndedEvent                      │                   │
│  │ 📢 GameEndedEvent                       │                   │
│  └─────────────────────────────────────────┘                   │
│                           │                                     │
│                           │ WebSocket (Laravel Reverb)          │
│                           ▼                                     │
│  ┌─────────────────────────────────────────┐                   │
│  │          FRONTEND                       │                   │
│  ├─────────────────────────────────────────┤                   │
│  │  MockupGameClient                       │                   │
│  │    ├─ EventManager (suscribe eventos)   │                   │
│  │    ├─ TimingModule (maneja timers)      │                   │
│  │    └─ Handlers personalizados           │                   │
│  │         • handlePhase1Started()         │                   │
│  │         • handlePhase2Started()         │                   │
│  │         • handlePhaseStarted()          │                   │
│  │         • handlePlayerLocked()          │                   │
│  └─────────────────────────────────────────┘                   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Estructura de Archivos

```
games/mockup/
├── config.json                    # ⚙️  Configuración del juego
├── capabilities.json              # 📋 Capacidades del juego
├── MockupEngine.php              # 🎮 Lógica del backend
├── MockupScoreCalculator.php     # 🏆 Cálculo de puntos
│
├── js/
│   └── MockupGameClient.js       # 🖥️  Cliente JavaScript
│
├── views/
│   ├── game.blade.php            # 📄 Vista principal
│   └── partials/
│       ├── game_end_popup.blade.php
│       ├── round_end_popup.blade.php
│       └── player_disconnected_popup.blade.php
│
└── Events/                        # 📢 Ubicados en app/Events/Mockup/
    ├── Phase1StartedEvent.php    # Evento custom Fase 1
    ├── Phase2StartedEvent.php    # Evento custom Fase 2
    └── Phase1EndedEvent.php      # Evento custom fin Fase 1
```

---

## Sistema de Fases

### 🔧 Configuración en `config.json`

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
          "duration": 12,
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

### 📊 Parámetros de Fase

| Parámetro | Descripción | Ejemplo |
|-----------|-------------|---------|
| `name` | Nombre único de la fase | `"phase1"` |
| `duration` | Duración en segundos | `3` |
| `on_start` | Evento emitido al iniciar | `Phase1StartedEvent` o `PhaseStartedEvent` (genérico) |
| `on_end` | Evento emitido al terminar | `PhaseEndedEvent` (genérico) |
| `on_end_callback` | Método del Engine que se ejecuta cuando expira | `"handlePhase1Ended"` |

### 🔄 Ciclo de Fases

```
┌───────────────────────────────────────────────────────────┐
│                    CICLO DE 1 RONDA                       │
└───────────────────────────────────────────────────────────┘

  RONDA 1 INICIA
       │
       ├─▶ PHASE1 (3s)  ──▶ PhaseManager emite Phase1StartedEvent
       │                     │
       │                     │ Timer de 3s
       │                     │
       │                     ▼
       │                   Timer expira ──▶ PhaseEndedEvent
       │                     │
       │                     ▼
       │                   handlePhase1Ended() ejecutado
       │                     │
       │                     ▼
       │                   PhaseManager.nextPhase()
       │
       ├─▶ PHASE2 (12s) ──▶ PhaseManager emite Phase2StartedEvent
       │                     │
       │                     │ Timer de 12s
       │                     │
       │                     ▼
       │                   Timer expira ──▶ PhaseEndedEvent
       │                     │
       │                     ▼
       │                   handlePhase2Ended() ejecutado
       │                     │
       │                     ▼
       │                   PhaseManager.nextPhase()
       │
       ├─▶ PHASE3 (4s)  ──▶ PhaseManager emite PhaseStartedEvent (GENÉRICO)
       │                     │
       │                     │ Timer de 4s
       │                     │
       │                     ▼
       │                   Timer expira ──▶ PhaseEndedEvent
       │                     │
       │                     ▼
       │                   handlePhase3Ended() ejecutado
       │                     │
       │                     ▼
       │                   PhaseManager.nextPhase() → cycle_completed: true
       │                     │
       │                     ▼
       │                   MockupEngine.endCurrentRound()
       │
  RONDA 1 TERMINA
       │
       │ Countdown 3s (configurado en timing.round_ended)
       │
       ▼
  RONDA 2 INICIA (repite el ciclo)
```

### ⚙️ PhaseManager

El `PhaseManager` se encarga de:

1. **Gestionar el ciclo de fases** definidas en `config.json`
2. **Emitir eventos `on_start`** cuando inicia cada fase
3. **Crear timers automáticos** con la duración especificada
4. **Ejecutar callbacks `on_end_callback`** cuando expira una fase
5. **Detectar `cycle_completed`** cuando todas las fases terminan

```php
// En MockupEngine::handlePhase1Ended()
$phaseManager = $roundManager->getTurnManager();
$phaseManager->setMatch($match); // IMPORTANTE: asignar match para emitir eventos

$nextPhaseInfo = $phaseManager->nextPhase();

// nextPhaseInfo contiene:
// [
//   'phase_name' => 'phase2',
//   'phase_index' => 1,
//   'duration' => 12,
//   'cycle_completed' => false  // true si terminaron todas las fases
// ]
```

---

## Eventos: Genéricos vs Personalizados

### 🎯 Eventos Personalizados (Custom Events)

**Cuándo usar:** Cuando necesitas lógica específica y diferente para cada fase.

#### Ejemplo: `Phase1StartedEvent`

```php
<?php
namespace App\Events\Mockup;

class Phase1StartedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $roomCode;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->phase = 'phase1';
        $this->duration = $phaseConfig['duration'] ?? null;
        $this->timerId = 'timer';
        $this->serverTime = now()->timestamp;
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('room.' . $this->roomCode);
    }

    public function broadcastAs(): string
    {
        return 'mockup.phase1.started';
    }

    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
        ];
    }
}
```

#### Registrar en `config.json`:

```json
{
  "event_config": {
    "events": {
      "Phase1StartedEvent": {
        "name": ".mockup.phase1.started",
        "handler": "handlePhase1Started"
      }
    }
  }
}
```

#### Handler en `MockupGameClient.js`:

```javascript
handlePhase1Started: (event) => {
    console.log('🎯 [Mockup] FASE 1 INICIADA - Timer de 3s comenzando');
    this.hideAnswerButtons();
}
```

### 🌐 Eventos Genéricos (Generic Events)

**Cuándo usar:** Cuando múltiples fases comparten la misma lógica.

#### Ejemplo: `PhaseStartedEvent` (usado en phase3)

```javascript
handlePhaseStarted: (event) => {
    console.log('🎬 [Mockup] FASE INICIADA (GENERIC HANDLER)');

    // Lógica condicional según la fase
    if (event.phase_name === 'phase3') {
        console.log('🎯 [Mockup] FASE 3 DETECTADA - Mostrando mensaje');
        this.hideAnswerButtons();
        this.showPhase3Message();
    }
}
```

### 📋 Comparación

| Aspecto | Eventos Personalizados | Eventos Genéricos |
|---------|------------------------|-------------------|
| **Archivo** | Clase PHP separada | Clase PHP reutilizable |
| **Registro** | En `event_config` | En `event_config` |
| **Handler** | Método específico | Método con lógica condicional |
| **Uso** | Lógica compleja diferente | Lógica similar compartida |
| **Ejemplo** | `Phase1StartedEvent` | `PhaseStartedEvent` |

---

## Flujo Completo de Ejecución

### 🚀 Inicio del Juego

```
1. Usuario crea sala y selecciona MockupGame
   │
   ▼
2. GameController::create()
   │
   ├─▶ MockupEngine::initialize($match)
   │     │
   │     ├─ Cargar config.json
   │     ├─ Inicializar módulos (RoundManager, ScoreManager, etc.)
   │     ├─ Crear PhaseManager con 3 fases
   │     └─ Guardar game_state con phase = 'starting'
   │
   ▼
3. Jugadores entran a /game/{roomCode}
   │
   ├─▶ MockupGameController::show()
   │     │
   │     ├─ Cargar $match, $room, $player
   │     ├─ Pasar config a MockupGameClient
   │     └─ Renderizar game.blade.php
   │
   ▼
4. Frontend: MockupGameClient constructor
   │
   ├─▶ BaseGameClient constructor
   │     │
   │     ├─ PresenceMonitor.start() → conectar a canal WebSocket
   │     └─ emitDomLoaded() → POST /api/rooms/{code}/dom-loaded
   │
   ▼
5. Backend: DomLoadedController cuenta jugadores listos
   │
   ├─ Si todos listos → BaseGameEngine::startGame()
   │
   ▼
6. MockupEngine::startGame()
   │
   ├─▶ RoundManager::reset()
   ├─▶ ScoreManager::reset()
   ├─▶ PlayerManager::reset()
   │
   ├─▶ event(GameStartedEvent) → 📢 Frontend recibe evento
   │
   ├─▶ onGameStart() → handleNewRound(advanceRound: false)
   │
   ▼
7. BaseGameEngine::handleNewRound()
   │
   ├─▶ RoundManager::startRound() → incrementa round a 1
   │
   ├─▶ PhaseManager::startCycle() → inicia phase1
   │     │
   │     ├─ Emitir Phase1StartedEvent 📢
   │     └─ Crear timer de 3s
   │
   ├─▶ event(RoundStartedEvent) → 📢 Frontend recibe evento
   │
   ▼
8. Frontend recibe Phase1StartedEvent
   │
   ├─▶ EventManager detecta evento
   ├─▶ TimingModule detecta timer (duration, timer_id, server_time)
   ├─▶ TimingModule inicia countdown visual
   ├─▶ handlePhase1Started() ejecutado → oculta botones
```

### ⏱️ Durante Phase1 (3 segundos)

```
Frontend: TimingModule cuenta regresiva
  3... 2... 1... 0

Cuando timer expira:
  │
  ├─▶ TimingModule emite PhaseTimerExpiredEvent (según event_class en Phase1StartedEvent)
  │
  ├─▶ Backend recibe PhaseTimerExpiredEvent
  │
  ├─▶ PhaseTimerExpiredListener
  │     │
  │     ├─ Buscar el callback configurado: "handlePhase1Ended"
  │     └─ Ejecutar MockupEngine::handlePhase1Ended()
  │
  ▼
MockupEngine::handlePhase1Ended()
  │
  ├─ PhaseManager::nextPhase() → avanza a phase2
  │
  ├─ Emitir Phase2StartedEvent 📢
  │
  ├─ Crear timer de 12s
  │
  └─ Emitir PhaseChangedEvent 📢
```

### 🎮 Durante Phase2 (12 segundos)

```
Frontend recibe Phase2StartedEvent
  │
  ├─▶ handlePhase2Started() ejecutado
  │     │
  │     ├─ Mostrar botones "Good Answer" / "Bad Answer"
  │     └─ Restaurar estado de bloqueado si aplica
  │
  ├─▶ TimingModule inicia countdown de 12s
  │
  ▼

Jugador hace clic en "Good Answer":
  │
  ├─▶ fetch('/api/rooms/{code}/action', { action: 'good_answer' })
  │
  ├─▶ RoomActionController::handleAction()
  │
  ├─▶ MockupEngine::processRoundAction($match, $player, ['action' => 'good_answer'])
  │
  ├─▶ ScoreManager::awardPoints($playerId, 'good_answer', ['points' => 10])
  │
  ├─▶ Retornar { force_end: true, end_reason: 'good_answer' }
  │
  ├─▶ BaseGameEngine::handleAction() detecta force_end = true
  │
  ├─▶ PhaseManager::cancelPhaseTimer() → cancela timer restante
  │
  ├─▶ MockupEngine::endCurrentRound()
  │
  └─▶ Siguiente ronda...

Jugador hace clic en "Bad Answer":
  │
  ├─▶ fetch('/api/rooms/{code}/action', { action: 'bad_answer' })
  │
  ├─▶ MockupEngine::processRoundAction($match, $player, ['action' => 'bad_answer'])
  │
  ├─▶ PlayerManager::lockPlayer($playerId, $match, $player)
  │     │
  │     └─ event(PlayerLockedEvent) 📢
  │
  ├─▶ Frontend recibe PlayerLockedEvent
  │     │
  │     ├─ onPlayerLocked() ejecutado
  │     ├─ Ocultar botones
  │     └─ Mostrar mensaje "Ya has votado"
  │
  ├─▶ Verificar si todos bloqueados
  │
  └─▶ Si todos bloqueados → { force_end: true, end_reason: 'all_players_locked' }
```

### ⏲️ Phase2 Timer Expira

```
Si ningún jugador presiona botón antes de 12s:
  │
  ├─▶ TimingModule detecta countdown = 0
  │
  ├─▶ Emitir PhaseTimerExpiredEvent
  │
  ├─▶ MockupEngine::handlePhase2Ended()
  │     │
  │     ├─ PhaseManager::nextPhase() → avanza a phase3
  │     │
  │     ├─ Emitir PhaseStartedEvent (GENÉRICO) 📢
  │     │
  │     └─ Crear timer de 4s
  │
  ▼

Frontend recibe PhaseStartedEvent (genérico)
  │
  ├─▶ handlePhaseStarted(event)
  │     │
  │     └─ if (event.phase_name === 'phase3') {
  │           this.showPhase3Message();
  │         }
  │
  └─▶ TimingModule inicia countdown de 4s
```

### 🏁 Fin de Ronda

```
Phase3 timer expira (4s):
  │
  ├─▶ MockupEngine::handlePhase3Ended()
  │     │
  │     ├─ PhaseManager::nextPhase() → cycle_completed: true
  │     │
  │     └─ MockupEngine::endCurrentRound()
  │
  ├─▶ BaseGameEngine::completeRound($match, $results, $scores)
  │     │
  │     ├─ Obtener configuración de timing: round_ended { type: "countdown", delay: 3 }
  │     │
  │     ├─ event(RoundEndedEvent con scores, countdown: 3) 📢
  │     │
  │     └─ Crear timer de 3s para siguiente ronda
  │
  ▼

Frontend recibe RoundEndedEvent:
  │
  ├─▶ handleRoundEnded(event)
  │     │
  │     ├─ Mostrar popup de resultados de ronda
  │     ├─ Mostrar scores actuales
  │     └─ Iniciar countdown visual de 3s
  │
  ▼

Countdown de ronda expira (3s):
  │
  ├─▶ TimingModule detecta countdown = 0
  │
  ├─▶ Verificar si quedan rondas (currentRound < totalRounds)
  │
  ├─▶ Si quedan rondas:
  │     │
  │     ├─ POST /api/rooms/{code}/next-round
  │     │
  │     ├─ BaseGameEngine::handleNewRound(advanceRound: true)
  │     │
  │     ├─ RoundManager::advanceRound() → round++
  │     │
  │     ├─ MockupEngine::startNewRound() → desbloquear jugadores
  │     │
  │     ├─ event(PlayersUnlockedEvent) 📢
  │     │
  │     ├─ PhaseManager::startCycle() → volver a phase1
  │     │
  │     └─ event(RoundStartedEvent con round: 2) 📢
  │
  └─▶ Si NO quedan rondas:
        │
        ├─ MockupEngine::finalize($match)
        │     │
        │     ├─ ScoreManager::getScores()
        │     ├─ Crear ranking ordenado
        │     ├─ Determinar ganador
        │     └─ event(GameEndedEvent con winner, ranking, scores) 📢
        │
        └─▶ Frontend recibe GameEndedEvent
              │
              ├─ handleGameFinished(event)
              │
              └─ showGameEndPopup(event) → mostrar ganador y ranking final
```

---

## Frontend: MockupGameClient

### 📁 Estructura del Cliente

```javascript
// games/mockup/js/MockupGameClient.js
export class MockupGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.config = config;
        this.customHandlers = null;
        this.setupEventManager();
        // setupTestControls se llama en handleDomLoaded
    }

    setupEventManager() {
        this.customHandlers = {
            handleDomLoaded: (event) => {
                super.handleDomLoaded(event);
                this.setupTestControls(); // Configurar botones
                this.restorePlayerLockedState(); // Restaurar estado
            },
            handlePhase1Started: (event) => {
                console.log('🎯 FASE 1 INICIADA');
                this.hideAnswerButtons();
            },
            handlePhase2Started: (event) => {
                console.log('🎯 FASE 2 INICIADA');
                this.showAnswerButtons();
                this.restorePlayerLockedState();
            },
            handlePhaseStarted: (event) => {
                // Handler genérico para phase3
                if (event.phase_name === 'phase3') {
                    this.showPhase3Message();
                }
            },
            handlePlayerLocked: (event) => {
                this.onPlayerLocked(event);
            },
            handlePlayersUnlocked: (event) => {
                this.onPlayerUnlocked(event);
            }
        };

        super.setupEventManager(this.customHandlers);
    }
}
```

### 🎛️ EventManager

El `EventManager` (en `resources/js/core/EventManager.js`) se encarga de:

1. **Conectar al canal de WebSocket** usando Laravel Echo
2. **Suscribirse a eventos** registrados en `event_config`
3. **Llamar handlers** cuando llegan eventos
4. **Pasar eventos a TimingModule** para procesar timers

```javascript
// Ejemplo de cómo EventManager procesa eventos
class EventManager {
    constructor({ roomCode, eventConfig, handlers, timingModule }) {
        this.roomCode = roomCode;
        this.eventConfig = eventConfig;
        this.handlers = handlers;
        this.timingModule = timingModule;

        this.subscribeToEvents();
    }

    subscribeToEvents() {
        Object.entries(this.eventConfig.events).forEach(([eventClass, config]) => {
            window.Echo.join(`room.${this.roomCode}`)
                .listen(config.name, (event) => {
                    // Pasar a TimingModule si contiene timer
                    if (this.timingModule && event.duration) {
                        this.timingModule.processTimerEvent(event);
                    }

                    // Ejecutar handler registrado
                    const handler = this.handlers[config.handler];
                    if (handler) {
                        handler(event);
                    }
                });
        });
    }
}
```

### ⏰ TimingModule

El `TimingModule` (en `resources/js/modules/TimingModule.js`) maneja:

1. **Detectar eventos con timers** (duration, timer_id, server_time)
2. **Crear countdown visual** sincronizado con el servidor
3. **Emitir eventos cuando expiran** (PhaseTimerExpiredEvent, etc.)

```javascript
// Ejemplo simplificado
class TimingModule {
    processTimerEvent(event) {
        if (!event.duration || !event.timer_id) return;

        const serverTime = event.server_time || Math.floor(Date.now() / 1000);
        const now = Math.floor(Date.now() / 1000);
        const elapsed = now - serverTime;
        const remaining = Math.max(0, event.duration - elapsed);

        this.startTimer({
            timerId: event.timer_id,
            duration: remaining,
            onExpire: () => {
                // Emitir evento configurado en event_class
                const eventClass = event.event_class || 'PhaseTimerExpiredEvent';
                this.emitTimerExpiredEvent(eventClass, event);
            }
        });
    }

    startTimer({ timerId, duration, onExpire }) {
        let remaining = duration;
        const element = document.getElementById(timerId);

        const interval = setInterval(() => {
            remaining--;
            if (element) {
                element.textContent = this.formatTime(remaining);
            }

            if (remaining <= 0) {
                clearInterval(interval);
                onExpire();
            }
        }, 1000);
    }
}
```

---

## Cómo Crear un Juego con Fases

### 📝 Paso 1: Crear estructura de archivos

```bash
games/tu-juego/
├── config.json
├── capabilities.json
├── TuJuegoEngine.php
├── TuJuegoScoreCalculator.php
├── js/
│   └── TuJuegoClient.js
└── views/
    └── game.blade.php
```

### 📝 Paso 2: Configurar fases en `config.json`

```json
{
  "id": "tu-juego",
  "name": "Tu Juego",
  "slug": "tu-juego",
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
          "name": "playing",
          "duration": 30,
          "on_start": "App\\Events\\TuJuego\\PlayingStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handlePlayingEnded"
        },
        {
          "name": "results",
          "duration": 5,
          "on_start": "App\\Events\\Game\\PhaseStartedEvent",
          "on_end": "App\\Events\\Game\\PhaseEndedEvent",
          "on_end_callback": "handleResultsEnded"
        }
      ]
    },
    "round_system": {
      "enabled": true,
      "total_rounds": 5
    },
    "scoring_system": {
      "enabled": true
    },
    "player_system": {
      "enabled": true
    }
  },
  "event_config": {
    "events": {
      "PreparationStartedEvent": {
        "name": ".tu-juego.preparation.started",
        "handler": "handlePreparationStarted"
      },
      "PlayingStartedEvent": {
        "name": ".tu-juego.playing.started",
        "handler": "handlePlayingStarted"
      },
      "PhaseStartedEvent": {
        "name": ".game.phase.started",
        "handler": "handlePhaseStarted"
      }
    }
  }
}
```

### 📝 Paso 3: Crear eventos personalizados

```php
<?php
// app/Events/TuJuego/PreparationStartedEvent.php

namespace App\Events\TuJuego;

use App\Models\GameMatch;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PreparationStartedEvent implements ShouldBroadcastNow
{
    public string $roomCode;
    public string $phase;
    public ?int $duration;
    public string $timerId;
    public int $serverTime;
    public array $phaseData;

    public function __construct(GameMatch $match, array $phaseConfig)
    {
        $this->roomCode = $match->room->code;
        $this->phase = 'preparation';
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
        return 'tu-juego.preparation.started';
    }

    public function broadcastWith(): array
    {
        return [
            'room_code' => $this->roomCode,
            'phase_name' => $this->phase,
            'duration' => $this->duration,
            'timer_id' => $this->timerId,
            'server_time' => $this->serverTime,
            'phase_data' => $this->phaseData,
            'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',
        ];
    }
}
```

### 📝 Paso 4: Implementar Engine

```php
<?php
// games/tu-juego/TuJuegoEngine.php

namespace Games\TuJuego;

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;

class TuJuegoEngine extends BaseGameEngine
{
    public function initialize(GameMatch $match): void
    {
        $gameConfig = $this->getGameConfig();

        $match->game_state = [
            '_config' => [
                'game' => 'tu-juego',
                'modules' => $gameConfig['modules'] ?? [],
            ],
            'phase' => 'starting',
        ];
        $match->save();

        $this->initializeModules($match, [
            'round_system' => [
                'total_rounds' => 5
            ],
            'scoring_system' => [
                'calculator' => new TuJuegoScoreCalculator()
            ]
        ]);

        $playerIds = $match->players->pluck('id')->toArray();
        $playerManager = new \App\Services\Modules\PlayerSystem\PlayerManager($playerIds);
        $this->savePlayerManager($match, $playerManager);
    }

    protected function onGameStart(GameMatch $match): void
    {
        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
        ]);
        $match->save();

        $this->handleNewRound($match, advanceRound: false);
    }

    protected function startNewRound(GameMatch $match): void
    {
        $playerManager = $this->getPlayerManager($match);
        $playerManager->unlockAllPlayers($match);
        $this->savePlayerManager($match, $playerManager);

        // Limpiar estado de la ronda anterior
        $gameState = $match->game_state;
        $gameState['actions'] = [];
        $match->game_state = $gameState;
        $match->save();
    }

    public function handlePreparationEnded(GameMatch $match, array $phaseData): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();

        if (!$phaseManager) return;

        $phaseManager->setMatch($match);
        $nextPhaseInfo = $phaseManager->nextPhase();

        $this->saveRoundManager($match, $roundManager);

        event(new \App\Events\Game\PhaseChangedEvent(
            match: $match,
            newPhase: $nextPhaseInfo['phase_name'],
            previousPhase: 'preparation'
        ));
    }

    public function handlePlayingEnded(GameMatch $match, array $phaseData): void
    {
        $roundManager = $this->getRoundManager($match);
        $phaseManager = $roundManager->getTurnManager();

        if (!$phaseManager) return;

        $phaseManager->setMatch($match);
        $nextPhaseInfo = $phaseManager->nextPhase();

        $this->saveRoundManager($match, $roundManager);

        if ($nextPhaseInfo['cycle_completed']) {
            $this->endCurrentRound($match);
        }
    }

    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // Tu lógica de acciones aquí
        return [
            'success' => true,
            'player_id' => $player->id,
            'force_end' => false,
        ];
    }

    public function endCurrentRound(GameMatch $match): void
    {
        $scoreManager = $this->getScoreManager($match);
        $scores = $scoreManager->getScores();

        $this->completeRound($match, [], $scores);
    }

    protected function getAllPlayerResults(GameMatch $match): array
    {
        return $match->game_state['actions'] ?? [];
    }

    protected function getRoundResults(GameMatch $match): array
    {
        return [];
    }

    protected function getFinalScores(GameMatch $match): array
    {
        return $this->getScoreManager($match)->getScores();
    }
}
```

### 📝 Paso 5: Implementar Frontend

```javascript
// games/tu-juego/js/TuJuegoClient.js

const { BaseGameClient } = window;

export class TuJuegoClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.config = config;
        this.setupEventManager();
    }

    setupEventManager() {
        this.customHandlers = {
            handleDomLoaded: (event) => {
                super.handleDomLoaded(event);
                this.setupGameControls();
            },

            handlePreparationStarted: (event) => {
                console.log('🎯 PREPARATION PHASE');
                // Mostrar instrucciones
                this.showInstructions();
            },

            handlePlayingStarted: (event) => {
                console.log('🎮 PLAYING PHASE');
                // Mostrar controles del juego
                this.showGameControls();
            },

            handlePhaseStarted: (event) => {
                // Handler genérico para results
                if (event.phase_name === 'results') {
                    console.log('📊 RESULTS PHASE');
                    this.showRoundResults();
                }
            }
        };

        super.setupEventManager(this.customHandlers);
    }

    setupGameControls() {
        // Configurar botones, listeners, etc.
    }

    showInstructions() {
        // Mostrar instrucciones del juego
    }

    showGameControls() {
        // Mostrar controles interactivos
    }

    showRoundResults() {
        // Mostrar resultados de la ronda
    }
}

window.TuJuegoClient = TuJuegoClient;
```

---

## Debugging y Testing

### 🔍 Logs Importantes

```bash
# Ver logs del juego
tail -f storage/logs/laravel.log | grep "Mockup"

# Ver logs de fases
tail -f storage/logs/laravel.log | grep "PhaseManager"

# Ver logs de timers
tail -f storage/logs/laravel.log | grep "PhaseTimerExpired"

# Ver logs de eventos
tail -f storage/logs/laravel.log | grep "Phase1StartedEvent"
```

### 🧪 Comando de Testing

```bash
# Emitir eventos manualmente
php artisan test:emit-event GameStarted ROOM_CODE
php artisan test:emit-event Phase1Started ROOM_CODE
php artisan test:emit-event PlayerLocked ROOM_CODE
```

### 🔧 Console del Navegador

```javascript
// Ver estado del cliente
console.log(window.mockupClient);

// Ver gameState actual
console.log(window.mockupClient.gameState);

// Ver players
console.log(window.mockupClient.players);

// Ver scores
console.log(window.mockupClient.scores);

// Emitir acción manualmente
await fetch(`/api/rooms/${roomCode}/action`, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
        action: 'good_answer',
        data: {}
    })
});
```

### ✅ Checklist de Validación

- [ ] config.json tiene `phase_system` configurado correctamente
- [ ] Cada fase tiene `name`, `duration`, `on_start`, `on_end`, `on_end_callback`
- [ ] Eventos personalizados creados en `app/Events/TuJuego/`
- [ ] Eventos registrados en `event_config.events`
- [ ] Engine implementa callbacks `handleFaseXEnded()`
- [ ] Engine llama `PhaseManager::setMatch($match)` antes de `nextPhase()`
- [ ] Frontend tiene handlers registrados en `setupEventManager()`
- [ ] TimingModule procesa eventos automáticamente
- [ ] broadcastAs() retorna el mismo nombre que `event_config.events.*.name` (sin el punto inicial)

---

## 📚 Referencias

- **BaseGameEngine**: `/Users/danielperezpinazo/Projects/groupsgames/app/Contracts/BaseGameEngine.php`
- **PhaseManager**: `/Users/danielperezpinazo/Projects/groupsgames/app/Services/Modules/TurnSystem/PhaseManager.php`
- **EventManager**: `/Users/danielperezpinazo/Projects/groupsgames/resources/js/core/EventManager.js`
- **TimingModule**: `/Users/danielperezpinazo/Projects/groupsgames/resources/js/modules/TimingModule.js`
- **BaseGameClient**: `/Users/danielperezpinazo/Projects/groupsgames/resources/js/core/BaseGameClient.js`

---

## 🎯 Conclusión

El sistema de fases con eventos en MockupGame proporciona:

✅ **Flexibilidad**: Eventos custom o genéricos según necesidad
✅ **Modularidad**: Cada fase es independiente y configurable
✅ **Sincronización**: Timers sincronizados frontend-backend
✅ **Escalabilidad**: Fácil agregar nuevas fases o juegos
✅ **Testing**: Herramientas para debugging y validación

Este sistema es la base para crear juegos complejos con múltiples fases, turnos, y mecánicas avanzadas.
