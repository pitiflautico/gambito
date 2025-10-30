# ⚠️ Eventos y Errores Críticos - Guía Definitiva

> **Documentación de todos los problemas reales** encontrados durante el desarrollo de MockupGame y cómo evitarlos.

---

## 🚨 Regla de Oro

```
SI EL EVENTO NO LLEGA AL FRONTEND, 99% DE LAS VECES:
❌ Olvidaste registrarlo en capabilities.json
```

---

## 📚 Índice

1. [Sistema de Eventos: Genéricos vs Custom](#sistema-de-eventos)
2. [capabilities.json vs config.json](#capabilitiesjson-vs-configjson)
3. [Catálogo Completo de Eventos](#catálogo-completo-de-eventos)
4. [Errores Críticos y Soluciones](#errores-críticos-y-soluciones)
5. [Hooks y Callbacks](#hooks-y-callbacks)
6. [Plantillas Personalizadas (Round End / Game End)](#plantillas-personalizadas)
7. [game_state: Cómo Modificarlo Correctamente](#gamestate-cómo-modificarlo)
8. [Checklist Antes de Crear un Evento](#checklist-antes-de-crear-un-evento)

---

## Sistema de Eventos

### Arquitectura de Eventos

```
┌─────────────────────────────────────────────────────────────┐
│                    TIPOS DE EVENTOS                         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  📢 EVENTOS GENÉRICOS (Del Sistema)                        │
│     - Definidos en app/Events/Game/                        │
│     - Usados por todos los juegos                          │
│     - Registrados automáticamente                          │
│                                                             │
│     Ejemplos:                                               │
│     • GameStartedEvent                                      │
│     • RoundStartedEvent                                     │
│     • RoundEndedEvent                                       │
│     • PhaseStartedEvent                                     │
│     • PhaseEndedEvent                                       │
│     • PlayerLockedEvent                                     │
│                                                             │
│  🎯 EVENTOS CUSTOM (Del Juego)                             │
│     - Definidos en app/Events/{TuJuego}/                   │
│     - Específicos de un juego                              │
│     - DEBEN registrarse en capabilities.json y config.json │
│                                                             │
│     Ejemplos:                                               │
│     • Phase1StartedEvent (Mockup)                          │
│     • Phase2StartedEvent (Mockup)                          │
│     • DrawingStartedEvent (Pictionary)                     │
│     • VotingStartedEvent (Pictionary)                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Cuándo Usar Cada Tipo

| Situación | Tipo de Evento | Razón |
|-----------|----------------|-------|
| Iniciar juego | Genérico (`GameStartedEvent`) | Todos los juegos inician igual |
| Iniciar ronda | Genérico (`RoundStartedEvent`) | Todos los juegos tienen rondas |
| Terminar ronda | Genérico (`RoundEndedEvent`) | Mismo popup en todos los juegos |
| Fase con lógica única | **Custom** (`Phase1StartedEvent`) | Cada fase hace cosas diferentes |
| Fase genérica | Genérico (`PhaseStartedEvent`) | Solo mostrar timer |
| Bloquear jugador | Genérico (`PlayerLockedEvent`) | Comportamiento estándar |
| Terminar juego | Genérico (`GameEndedEvent`) | Mismo popup de ganador |

### Ejemplo Práctico: Mockup Game

```
FASE 1 (phase1):
  🎯 USA EVENTO CUSTOM: Phase1StartedEvent
  ¿Por qué? Necesita ocultar botones específicos

FASE 2 (phase2):
  🎯 USA EVENTO CUSTOM: Phase2StartedEvent
  ¿Por qué? Necesita mostrar botones Good/Bad Answer

FASE 3 (phase3):
  📢 USA EVENTO GENÉRICO: PhaseStartedEvent
  ¿Por qué? Solo muestra un mensaje, no necesita lógica especial
```

---

## capabilities.json vs config.json

### ⚠️ IMPORTANTE: Diferencia Crítica

```
config.json:
  - Define qué eventos se usan (on_start, on_end)
  - Configura duración, timers, fases
  - Define handlers del FRONTEND

capabilities.json:
  - Registra eventos para el EventManager
  - El Controller lo lee y lo pasa al frontend
  - SIN capabilities.json = EVENTO NO LLEGA
```

### Flujo de Eventos

```
┌────────────────────────────────────────────────────────────────┐
│                    FLUJO DE REGISTRO                           │
└────────────────────────────────────────────────────────────────┘

1. Backend emite evento:
   event(new Phase2StartedEvent($match, $phaseConfig));

2. Evento broadcast a Reverb (Laravel WebSocket):
   PresenceChannel: "room.{roomCode}"
   Nombre: "mockup.phase2.started" (sin punto inicial)

3. EventManager (frontend) recibe:
   ❓ ¿Está registrado en capabilities.json?
      ✅ SÍ → Buscar handler → Ejecutar handlePhase2Started()
      ❌ NO → ⚠️ EVENTO IGNORADO ⚠️

4. Handler se ejecuta:
   handlePhase2Started() → onPhase2Started() → Mostrar UI
```

### Ejemplo Real de Error

```json
// ❌ ERROR: Solo registramos en config.json
// games/mockup/config.json
{
  "event_config": {
    "events": {
      "Phase2StartedEvent": {
        "name": ".mockup.phase2.started",
        "handler": "handlePhase2Started"
      }
    }
  }
}

// ⚠️ FALTA capabilities.json
// Resultado: Evento NO llega al frontend
```

```json
// ✅ CORRECTO: Registrar en AMBOS archivos

// config.json
{
  "event_config": {
    "events": {
      "Phase2StartedEvent": {
        "name": ".mockup.phase2.started",
        "handler": "handlePhase2Started"
      }
    }
  }
}

// capabilities.json
{
  "events": {
    "Phase2StartedEvent": {
      "name": "mockup.phase2.started",  // ← SIN punto inicial aquí
      "description": "Mockup: Fase 2 iniciada",
      "handler": "handlePhase2Started"
    }
  }
}
```

---

## Catálogo Completo de Eventos

### 📢 Eventos Genéricos del Sistema

Estos eventos están disponibles para TODOS los juegos y NO necesitan crearse.

#### 1. GameStartedEvent

**Ubicación**: `app/Events/Game/GameStartedEvent.php`

**Cuándo se emite**: Al iniciar el juego (después del countdown de inicio)

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  match_id: 42,
  game_state: { ... }
}
```

**Handler en Frontend**: `handleGameStarted(event)`

**Uso**:
```javascript
handleGameStarted: (event) => {
    console.log('🎮 Juego iniciado', event);
    // Resetear estado, mostrar UI inicial, etc.
}
```

#### 2. RoundStartedEvent

**Ubicación**: `app/Events/Game/RoundStartedEvent.php`

**Cuándo se emite**: Al iniciar cada ronda

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  round: 1,
  total_rounds: 5,
  timer_id: null,  // No tiene timer
  additional_data: { ... }
}
```

**Handler en Frontend**: `handleRoundStarted(event)`

**Uso**:
```javascript
handleRoundStarted: (event) => {
    console.log('▶️ Ronda', event.round, 'de', event.total_rounds);
    // Actualizar UI de ronda actual
    document.getElementById('current-round').textContent = event.round;
}
```

#### 3. RoundEndedEvent

**Ubicación**: `app/Events/Game/RoundEndedEvent.php`

**Cuándo se emite**: Al terminar cada ronda

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  round_number: 1,
  scores: { 1: 50, 2: 30, 3: 20 },
  results: { ... },
  timer_id: "countdown-timer",
  duration: 3,  // Countdown de 3s
  server_time: 1234567890,
  event_class: "App\\Events\\Game\\RoundCountdownExpiredEvent"
}
```

**Handler en Frontend**: `handleRoundEnded(event)`

**Uso**:
```javascript
handleRoundEnded: (event) => {
    console.log('🏁 Ronda terminada', event.scores);
    // BaseGameClient automáticamente:
    // 1. Muestra popup con scores
    // 2. Inicia countdown de 3s
    // 3. Al expirar, llama /api/rooms/{code}/next-round
}
```

#### 4. PhaseStartedEvent (Genérico)

**Ubicación**: `app/Events/Game/PhaseStartedEvent.php`

**Cuándo se emite**: Al iniciar una fase (si se configura `on_start` como genérico)

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  phase_name: "phase3",
  duration: 4,
  timer_id: "timer",
  server_time: 1234567890,
  event_class: "App\\Events\\Game\\PhaseEndedEvent"
}
```

**Handler en Frontend**: `handlePhaseStarted(event)`

**Uso**:
```javascript
handlePhaseStarted: (event) => {
    console.log('🎬 Fase genérica:', event.phase_name);

    // Lógica condicional según fase
    if (event.phase_name === 'phase3') {
        this.showPhase3Message();
    }
}
```

#### 5. PhaseEndedEvent

**Ubicación**: `app/Events/Game/PhaseEndedEvent.php`

**Cuándo se emite**: Al terminar una fase

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  phase_name: "phase1",
  additional_data: { ... }
}
```

**Handler en Frontend**: `handlePhaseEnded(event)`

#### 6. PlayerLockedEvent

**Ubicación**: `app/Events/Game/PlayerLockedEvent.php`

**Cuándo se emite**: Cuando un jugador es bloqueado (ya realizó su acción)

**Datos que envía**:
```javascript
{
  player_id: 1,
  player_name: "Player 1",
  additional_data: { ... }
}
```

**Handler en Frontend**: `handlePlayerLocked(event)`

**Uso**:
```javascript
handlePlayerLocked: (event) => {
    this.onPlayerLocked(event);
}

onPlayerLocked(event) {
    if (event.player_id !== this.config.playerId) return;

    // Ocultar botones, mostrar mensaje "Ya has votado"
    this.hideActionButtons();
    this.showLockedMessage();
}
```

#### 7. PlayersUnlockedEvent

**Ubicación**: `app/Events/Game/PlayersUnlockedEvent.php`

**Cuándo se emite**: Al iniciar nueva ronda (desbloquear a todos)

**Datos que envía**:
```javascript
{
  room_code: "ABC123",
  from_new_round: true
}
```

**Handler en Frontend**: `handlePlayersUnlocked(event)`

#### 8. GameEndedEvent

**Ubicación**: `app/Events/Game/GameEndedEvent.php`

**Cuándo se emite**: Al terminar el juego completo

**Datos que envía**:
```javascript
{
  winner: 1,
  ranking: [
    { position: 1, player_id: 1, score: 100 },
    { position: 2, player_id: 2, score: 70 }
  ],
  scores: { 1: 100, 2: 70, 3: 50 }
}
```

**Handler en Frontend**: `handleGameFinished(event)`

**Uso**:
```javascript
handleGameFinished: (event) => {
    console.log('🏆 Juego terminado. Ganador:', event.winner);
    // BaseGameClient automáticamente muestra popup con ranking
}
```

---

## Errores Críticos y Soluciones

### ❌ Error #1: Evento NO Llega al Frontend (El Más Crítico)

**Síntoma**:
- Backend emite evento correctamente
- Logs muestran "Broadcasting"
- Frontend NO recibe el evento

**Causa**: Olvidaste registrar en `capabilities.json`

**Solución**:
```json
// games/tu-juego/capabilities.json
{
  "events": {
    "Phase2StartedEvent": {
      "name": "tu-juego.phase2.started",   // SIN punto inicial
      "description": "Fase 2 iniciada",
      "handler": "handlePhase2Started"
    }
  }
}
```

### ❌ Error #2: Punto Inicial Inconsistente

**Síntoma**:
- Evento se emite pero no se detecta

**Causa**: Confusión entre `broadcastAs()` y `capabilities.json` con el punto inicial

**Reglas**:
```php
// Event PHP - broadcastAs() SIN punto inicial
public function broadcastAs(): string {
    return 'tu-juego.phase2.started';  // ← SIN punto
}
```

```json
// config.json - CON punto inicial
{
  "event_config": {
    "events": {
      "Phase2StartedEvent": {
        "name": ".tu-juego.phase2.started"  // ← CON punto
      }
    }
  }
}
```

```json
// capabilities.json - SIN punto inicial
{
  "events": {
    "Phase2StartedEvent": {
      "name": "tu-juego.phase2.started"  // ← SIN punto
    }
  }
}
```

**Tabla Resumen**:

| Archivo | ¿Punto Inicial? | Ejemplo |
|---------|-----------------|---------|
| `Event::broadcastAs()` | ❌ NO | `"tu-juego.phase2.started"` |
| `config.json` | ✅ SÍ | `".tu-juego.phase2.started"` |
| `capabilities.json` | ❌ NO | `"tu-juego.phase2.started"` |

### ❌ Error #3: Handler No Definido en Frontend

**Síntoma**:
- Evento llega al frontend
- Console muestra warning: "Handler not found"

**Causa**: Handler no definido en `customHandlers`

**Solución**:
```javascript
// games/tu-juego/js/TuJuegoClient.js

setupEventManager() {
    this.customHandlers = {
        // ⚠️ DEBE coincidir con capabilities.json
        handlePhase2Started: (event) => {
            this.onPhase2Started(event);
        }
    };

    super.setupEventManager(this.customHandlers);
}
```

### ❌ Error #4: Timer No Funciona

**Síntoma**:
- Fase inicia pero timer no cuenta

**Causa**: Evento no incluye datos de timer

**Solución**:
```php
// Event PHP - broadcastWith() DEBE incluir estos campos
public function broadcastWith(): array {
    return [
        'room_code' => $this->roomCode,
        'phase_name' => $this->phase,

        // ⚠️ CRÍTICO para TimingModule:
        'duration' => $this->duration,           // ← NECESARIO
        'timer_id' => $this->timerId,            // ← NECESARIO
        'server_time' => $this->serverTime,      // ← NECESARIO
        'event_class' => $this->phaseData['on_end'] ?? 'App\\Events\\Game\\PhaseTimerExpiredEvent',  // ← NECESARIO
    ];
}
```

### ❌ Error #5: Canal Incorrecto

**Síntoma**:
- Evento no llega a ningún jugador

**Causa**: Usar `Channel` en vez de `PresenceChannel`

**Solución**:
```php
// ❌ MAL
use Illuminate\Broadcasting\Channel;

public function broadcastOn(): Channel {
    return new Channel("room.{$this->roomCode}");
}

// ✅ BIEN
use Illuminate\Broadcasting\PresenceChannel;

public function broadcastOn(): PresenceChannel {
    return new PresenceChannel("room.{$this->roomCode}");
}
```

### ❌ Error #6: Modificar game_state Directamente

**Síntoma**:
- Cambios en `game_state` no se guardan
- Estado se pierde al reconectar

**Causa**: Modificar `game_state` sin reasignar

**❌ INCORRECTO**:
```php
// NO FUNCIONA - Laravel no detecta cambio en JSON cast
$match->game_state['actions'][$playerId] = 'vote';
$match->save();
```

**✅ CORRECTO**:
```php
// FUNCIONA - Obtener, modificar, reasignar
$gameState = $match->game_state;           // 1. Obtener
$gameState['actions'][$playerId] = 'vote';  // 2. Modificar
$match->game_state = $gameState;            // 3. Reasignar
$match->save();                             // 4. Guardar
```

### ❌ Error #7: Olvidar `setMatch()` en PhaseManager

**Síntoma**:
- Fase no avanza
- Eventos `on_start` no se emiten

**Causa**: No asignar `$match` al `PhaseManager` antes de `nextPhase()`

**Solución**:
```php
public function handlePhase2Ended(GameMatch $match, array $phaseData): void {
    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    // ⚠️ CRÍTICO: Asignar match ANTES de nextPhase()
    $phaseManager->setMatch($match);

    $nextPhaseInfo = $phaseManager->nextPhase();
}
```

---

## Hooks y Callbacks

### Tipos de Callbacks

```
┌───────────────────────────────────────────────────────────┐
│                    CALLBACKS                              │
├───────────────────────────────────────────────────────────┤
│                                                           │
│  🔧 CALLBACKS DEL ENGINE (Backend)                       │
│     - Métodos del {TuJuego}Engine                        │
│     - Se ejecutan cuando expira una fase                 │
│                                                           │
│     Definición en config.json:                           │
│       "on_end_callback": "handlePhase2Ended"            │
│                                                           │
│     Método en Engine:                                     │
│       public function handlePhase2Ended(...) { ... }    │
│                                                           │
│  🎨 HANDLERS DEL FRONTEND (JavaScript)                   │
│     - Métodos del {TuJuego}Client                       │
│     - Se ejecutan cuando llega un evento                 │
│                                                           │
│     Definición en capabilities.json:                     │
│       "handler": "handlePhase2Started"                  │
│                                                           │
│     Método en Client:                                     │
│       handlePhase2Started: (event) => { ... }           │
│                                                           │
└───────────────────────────────────────────────────────────┘
```

### Flujo Completo de Callbacks

```
FASE 2 INICIA:
  1. PhaseManager emite Phase2StartedEvent
     ↓
  2. Evento llega al frontend
     ↓
  3. handlePhase2Started(event) ejecutado  ← HANDLER FRONTEND
     ↓
  4. onPhase2Started(event) ejecutado
     ↓
  5. Mostrar UI, iniciar timer

TIMER EXPIRA (12s):
  6. TimingModule detecta countdown = 0
     ↓
  7. Emite PhaseTimerExpiredEvent al backend
     ↓
  8. handlePhase2Ended($match, $phaseData) ejecutado  ← CALLBACK BACKEND
     ↓
  9. PhaseManager::nextPhase()
     ↓
 10. Avanza a fase 3 o termina ronda
```

### Ejemplo Completo de Fase

```json
// config.json
{
  "phases": [
    {
      "name": "phase2",
      "duration": 12,
      "on_start": "App\\Events\\Mockup\\Phase2StartedEvent",  // Evento al iniciar
      "on_end": "App\\Events\\Game\\PhaseEndedEvent",         // Evento al terminar
      "on_end_callback": "handlePhase2Ended"                   // Callback al expirar
    }
  ]
}
```

```php
// MockupEngine.php

/**
 * Callback cuando expira la fase 2
 */
public function handlePhase2Ended(GameMatch $match, array $phaseData): void
{
    Log::info("🏁 [Mockup] FASE 2 FINALIZADA");

    // Obtener PhaseManager
    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    // ⚠️ CRÍTICO: Asignar match
    $phaseManager->setMatch($match);

    // Avanzar a siguiente fase
    $nextPhaseInfo = $phaseManager->nextPhase();

    // Guardar cambios
    $this->saveRoundManager($match, $roundManager);

    // Verificar si completó ciclo
    if ($nextPhaseInfo['cycle_completed']) {
        $this->endCurrentRound($match);
    }
}
```

```javascript
// MockupGameClient.js

this.customHandlers = {
    // Handler al iniciar fase 2
    handlePhase2Started: (event) => {
        console.log('🎯 FASE 2 INICIADA');
        this.showAnswerButtons();
        this.restorePlayerLockedState();
    }
};
```

---

## Plantillas Personalizadas

### Round End Popup Personalizado

Por defecto, `BaseGameClient::showRoundEndPopup()` muestra un popup genérico. Puedes personalizarlo.

#### Opción 1: Sobrescribir Método

```javascript
// games/tu-juego/js/TuJuegoClient.js

showRoundEndPopup(event) {
    // Llamar al padre para mostrar popup base
    super.showRoundEndPopup(event);

    // Agregar contenido personalizado
    const customContent = document.getElementById('custom-round-content');
    if (customContent && event.custom_data) {
        customContent.innerHTML = `
            <p>Palabra correcta: ${event.custom_data.word}</p>
            <p>Dibujante: ${event.custom_data.drawer_name}</p>
        `;
    }
}
```

#### Opción 2: Template Completo Custom

```blade
{{-- games/tu-juego/views/partials/round_end_popup.blade.php --}}

<div id="round-end-popup" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-gray-800 rounded-lg p-8 max-w-2xl w-full">
        <h2 class="text-4xl font-bold text-center mb-6">
            🎨 Ronda <span id="popup-round-number">1</span> - Resultados
        </h2>

        {{-- Contenido personalizado --}}
        <div id="round-results-custom" class="mb-6">
            <div class="bg-blue-900/30 p-4 rounded">
                <p class="text-xl text-center">Palabra: <span id="round-word" class="font-bold"></span></p>
                <p class="text-lg text-center">Dibujante: <span id="round-drawer" class="font-bold"></span></p>
            </div>
        </div>

        {{-- Scores --}}
        <div id="popup-scores-list" class="space-y-3 mb-6">
            <!-- Generado por JS -->
        </div>

        {{-- Countdown --}}
        <div id="popup-countdown" class="text-center">
            <p class="text-gray-400">Siguiente ronda en</p>
            <p id="countdown-timer" class="text-6xl font-bold text-yellow-400">3</p>
        </div>
    </div>
</div>
```

```javascript
// TuJuegoClient.js

showRoundEndPopup(event) {
    // Mostrar popup base
    super.showRoundEndPopup(event);

    // Rellenar datos personalizados
    const wordEl = document.getElementById('round-word');
    const drawerEl = document.getElementById('round-drawer');

    if (wordEl && event.results && event.results.word) {
        wordEl.textContent = event.results.word;
    }

    if (drawerEl && event.results && event.results.drawer_name) {
        drawerEl.textContent = event.results.drawer_name;
    }
}
```

### Game End Popup Personalizado

Mismo concepto que Round End:

```javascript
// TuJuegoClient.js

showGameEndPopup(event) {
    // Llamar al padre
    super.showGameEndPopup(event);

    // Agregar estadísticas personalizadas
    const statsContainer = document.getElementById('game-stats-custom');
    if (statsContainer) {
        statsContainer.innerHTML = `
            <h3 class="text-xl font-bold mb-2">Estadísticas</h3>
            <p>Total de palabras dibujadas: ${event.statistics.total_words}</p>
            <p>Votos positivos: ${event.statistics.positive_votes}</p>
        `;
    }
}
```

---

## game_state: Cómo Modificarlo

### ⚠️ Problema: JSON Cast en Laravel

Laravel usa `cast` para convertir `game_state` a array/JSON. El problema es que Laravel NO detecta cambios internos en arrays.

### ❌ NO FUNCIONA:

```php
// Laravel NO detecta cambio
$match->game_state['actions'][$playerId] = 'vote';
$match->save();
// ⚠️ Cambio NO se guarda en BD
```

### ✅ FUNCIONA:

```php
// 1. Obtener game_state completo
$gameState = $match->game_state;

// 2. Modificar el array
$gameState['actions'][$playerId] = 'vote';

// 3. Reasignar game_state completo
$match->game_state = $gameState;

// 4. Guardar
$match->save();
// ✅ Cambio SÍ se guarda en BD
```

### Patrón Recomendado

```php
/**
 * Guardar acción de jugador
 */
private function savePlayerAction(GameMatch $match, int $playerId, string $action): void
{
    // Patrón: Obtener → Modificar → Reasignar → Guardar
    $gameState = $match->game_state;
    $gameState['actions'][$playerId] = $action;
    $match->game_state = $gameState;
    $match->save();
}

/**
 * Guardar palabra elegida
 */
private function saveChosenWord(GameMatch $match, string $word, int $playerId): void
{
    $gameState = $match->game_state;
    $gameState['current_word'] = $word;
    $gameState['current_drawer'] = $playerId;
    $match->game_state = $gameState;
    $match->save();
}
```

---

## Checklist Antes de Crear un Evento

### 📋 Checklist Completo

#### Paso 1: Archivo PHP del Evento

- [ ] Ubicación: `app/Events/{TuJuego}/NombreEvento.php`
- [ ] Namespace: `App\Events\{TuJuego}`
- [ ] Implements: `ShouldBroadcastNow` (NO `ShouldBroadcast`)
- [ ] Use traits: `Dispatchable, InteractsWithSockets, SerializesModels`
- [ ] `broadcastOn()` retorna `PresenceChannel("room.{$this->roomCode}")`
- [ ] `broadcastAs()` retorna nombre SIN punto inicial (ej: `"tu-juego.phase2.started"`)
- [ ] `broadcastWith()` incluye:
  - `room_code`
  - `phase_name`
  - `duration` (si tiene timer)
  - `timer_id` (si tiene timer)
  - `server_time` (si tiene timer)
  - `event_class` (evento que se emite al expirar)

#### Paso 2: capabilities.json

- [ ] Registrar evento en `games/{tu-juego}/capabilities.json`
- [ ] Nombre del evento SIN punto inicial
- [ ] Handler definido (ej: `"handlePhase2Started"`)
- [ ] Descripción clara

```json
{
  "events": {
    "Phase2StartedEvent": {
      "name": "tu-juego.phase2.started",  // SIN punto
      "description": "Fase 2 iniciada",
      "handler": "handlePhase2Started"
    }
  }
}
```

#### Paso 3: config.json

- [ ] Registrar evento en `games/{tu-juego}/config.json`
- [ ] Nombre del evento CON punto inicial
- [ ] Handler definido (mismo que capabilities.json)

```json
{
  "event_config": {
    "events": {
      "Phase2StartedEvent": {
        "name": ".tu-juego.phase2.started",  // CON punto
        "handler": "handlePhase2Started"
      }
    }
  }
}
```

#### Paso 4: Frontend (TuJuegoClient.js)

- [ ] Registrar handler en `setupEventManager()`
- [ ] Nombre del handler coincide con capabilities.json y config.json
- [ ] Implementar método (ej: `onPhase2Started(event)`)

```javascript
this.customHandlers = {
    handlePhase2Started: (event) => {
        this.onPhase2Started(event);
    }
};

onPhase2Started(event) {
    console.log('🎯 Fase 2 iniciada');
    this.showPhase2UI();
}
```

#### Paso 5: Verificación

- [ ] Compilar assets: `npm run build`
- [ ] Validar JSON: `php -r "json_decode(file_get_contents('games/tu-juego/config.json'));"`
- [ ] Iniciar Reverb: `php artisan reverb:start`
- [ ] Verificar en consola: Evento llega y handler se ejecuta

---

## 🛠️ Debugging

### Comandos Útiles

```bash
# Ver logs del juego
tail -f storage/logs/laravel.log | grep "TuJuego"

# Ver logs de eventos
tail -f storage/logs/laravel.log | grep "Phase2StartedEvent"

# Ver logs de PhaseManager
tail -f storage/logs/laravel.log | grep "PhaseManager"

# Ver logs de broadcasting
tail -f storage/logs/laravel.log | grep "Broadcasting"

# Emitir evento manualmente (testing)
php artisan test:emit-event Phase2Started ROOM_CODE
```

### Consola del Navegador

```javascript
// Ver si evento llegó
// EventManager logea todos los eventos recibidos

// Ver cliente
console.log(window.tuJuegoClient);

// Ver gameState
console.log(window.tuJuegoClient.gameState);

// Ver handlers registrados
console.log(window.tuJuegoClient.eventManager);

// Ver si canal está conectado
window.Echo.connector.pusher.connection.state; // "connected"
```

---

## 🎯 Resumen de Reglas de Oro

1. **capabilities.json es CRÍTICO**: Sin él, el evento no llega
2. **Punto Inicial**: SIN punto en `broadcastAs()` y `capabilities.json`, CON punto en `config.json`
3. **PresenceChannel**: Siempre usar para rooms
4. **game_state**: Obtener → Modificar → Reasignar → Guardar
5. **setMatch()**: Siempre llamar antes de `nextPhase()`
6. **Timer**: Incluir `duration`, `timer_id`, `server_time`, `event_class`
7. **Handlers**: Deben coincidir en capabilities.json, config.json y TuJuegoClient.js
8. **Eventos Custom**: Solo para lógica única, sino usar genéricos

---

## 📖 Referencias

- `GUIA_COMPLETA_MOCKUP_GAME.md` - Documentación general
- `CREAR_JUEGO_PASO_A_PASO.md` - Guía paso a paso
- `games/mockup/` - Ejemplo de referencia completo