# Convenciones de Desarrollo - GroupsGames

Este documento establece las reglas y convenciones que DEBES seguir al crear o modificar funcionalidades en la plataforma GroupsGames. **Consulta este checklist en CADA iteración** para asegurar consistencia y calidad.

---

## 🚫 REGLA FUNDAMENTAL: NO MODIFICAR EL CORE SIN CONSULTAR

**⚠️ CRÍTICO**: Antes de modificar CUALQUIER archivo en estas ubicaciones, DEBES consultar primero:

**Core del Sistema** (NO modificar sin aprobación):
- `app/Contracts/BaseGameEngine.php`
- `app/Services/Modules/` (RoundManager, TurnManager, ScoringManager, etc.)
- `app/Events/Game/` (eventos genéricos)
- `app/Providers/GameServiceProvider.php`

**Razón**: Modificar el core afecta TODOS los juegos. Los cambios pueden romper:
- Otros juegos existentes (Pictionary, Trivia)
- Futuros juegos en desarrollo
- Encapsulación y principios SOLID
- Tests existentes

**Alternativas correctas**:
1. **Extender**, no modificar (herencia, interfaces)
2. **Wrappers públicos** en tu Engine para exponer funcionalidad protegida
3. **Sobrescribir métodos** en tu Engine específico
4. **Consultar primero** si realmente necesitas cambiar el core

---

## 📋 Checklist General para CADA Feature

### 1. ✅ Arquitectura y Diseño

- [ ] **Desacoplamiento**: ¿La lógica del juego está separada de los módulos base?
- [ ] **Módulos Genéricos**: Los módulos (TurnSystem, RoundSystem, ScoringSystem, etc.) NO deben contener lógica específica de juegos
- [ ] **BaseGameEngine**: NO debe tomar decisiones específicas de juegos (ej. delays, timing)
- [ ] **Extensibilidad**: Cada juego debe poder extender/sobrescribir comportamiento sin modificar el core

**❌ MAL EJEMPLO:**
```php
// En BaseGameEngine o RoundManager
protected function scheduleNextRound() {
    // Esperar 5 segundos y avanzar
    sleep(5);
    $this->startNewRound();
}
```

**✅ BUEN EJEMPLO:**
```php
// BaseGameEngine solo coordina, no decide timing
protected function endCurrentRound() {
    // Emitir evento, pero NO programar la siguiente ronda
    // Cada juego decide cómo/cuándo avanzar
}

// En TriviaEngine (game-specific)
public function nextRound() {
    // Trivia controla su propio timing vía frontend
    $this->startNewRound($match);
}
```

---

## 📁 Convenciones de Estructura de Archivos

### 2. ✅ Ubicación de Archivos por Juego

Cada juego DEBE tener esta estructura en `games/{slug}/`:

```
games/
└── {slug}/                          # Nombre en minúsculas (ej: trivia, pictionary)
    ├── {GameName}Engine.php         # Motor del juego (ej: TriviaEngine.php)
    ├── {GameName}Controller.php     # Controlador HTTP
    ├── routes.php                   # ⚠️ IMPORTANTE: Rutas del juego
    ├── capabilities.json            # ⚠️ IMPORTANTE: Declaración de capacidades
    ├── config.json                  # Configuración del juego
    ├── views/                       # Vistas Blade del juego
    │   └── game.blade.php
    └── assets/                      # CSS/JS específicos (opcional)
```

**🔴 REGLA CRÍTICA:**
- Las rutas SIEMPRE van en `games/{slug}/routes.php` - NUNCA en `routes/web.php` o `routes/api.php`
- Las rutas se cargan automáticamente por `GameServiceProvider::loadGameRoutes()`

---

## 🛣️ Convenciones de Rutas

### 3. ✅ Definición de Rutas

**Archivo**: `games/{slug}/routes.php`

**Estructura obligatoria:**
```php
<?php

use Games\{GameName}\{GameName}Controller;
use Illuminate\Support\Facades\Route;

// API Routes - Para acciones del juego
Route::prefix('api/{slug}')->name('api.{slug}.')->middleware('api')->group(function () {
    Route::post('/action1', [{GameName}Controller::class, 'action1'])->name('action1');
    Route::post('/action2', [{GameName}Controller::class, 'action2'])->name('action2');
});

// Web Routes - Para vistas del juego
Route::prefix('{slug}')->name('{slug}.')->middleware('web')->group(function () {
    Route::get('/{roomCode}', [{GameName}Controller::class, 'game'])->name('game');
});
```

**Checklist de Rutas:**
- [ ] Rutas API usan `prefix('api/{slug}')`
- [ ] Rutas web usan `prefix('{slug}')`
- [ ] Nombres de rutas usan `name('api.{slug}.*')` o `name('{slug}.*')`
- [ ] Todas las rutas están declaradas en `capabilities.json`

---

## 📄 Convenciones de capabilities.json

### 4. ✅ Declaración de Capacidades

**Archivo**: `games/{slug}/capabilities.json`

**Estructura obligatoria:**
```json
{
  "slug": "game-slug",
  "version": "1.0",
  "requires": {
    "modules": {
      "session_manager": "^1.0",
      "event_manager": "^1.0"
      // ... otros módulos requeridos
    }
  },
  "provides": {
    "events": [
      "GameSpecificEvent"  // Solo eventos propios del juego
    ],
    "routes": [
      "/api/game-slug/action1",
      "/api/game-slug/action2"
      // ... todas las rutas del juego
    ],
    "views": [
      "games/game-slug/game"
    ]
  },
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "RoundStartedEvent": {
        "name": ".game.round.started",
        "handler": "handleRoundStarted"
      }
      // ... mapeo de eventos genéricos a handlers
    }
  }
}
```

**Checklist de capabilities.json:**
- [ ] `slug` coincide con el nombre de la carpeta
- [ ] Todos los módulos requeridos están en `requires.modules`
- [ ] Todas las rutas están en `provides.routes` (incluyendo las nuevas)
- [ ] `event_config` mapea eventos genéricos a handlers del juego
- [ ] Eventos custom del juego (si hay) están en `provides.events`

---

## 🎮 Convenciones de GameEngine

### 5. ✅ Implementación de GameEngine

**Archivo**: `games/{slug}/{GameName}Engine.php`

**Checklist:**
- [ ] Extiende `BaseGameEngine`
- [ ] Implementa TODOS los métodos abstractos obligatorios:
  - `initialize(GameMatch $match): void`
  - `processRoundAction(GameMatch $match, Player $player, array $data): array`
  - `startNewRound(GameMatch $match): void` ← PROTECTED (hereda de BaseGameEngine)
  - `endCurrentRound(GameMatch $match): void` ← PROTECTED (hereda de BaseGameEngine)
- [ ] **API Pública del Engine**: Expone métodos públicos wrapper para que Controllers puedan usar:
  - `advanceToNextRound(GameMatch $match): void` - Wrapper de `startNewRound()`
  - `checkIfGameComplete(GameMatch $match): bool` - Wrapper de `isGameComplete()`
- [ ] NO sobrescribe `processAction()` a menos que sea absolutamente necesario
- [ ] Usa módulos (RoundManager, TurnManager, ScoringManager) en vez de reimplementar

**❌ MAL EJEMPLO (rompe encapsulación):**
```php
// En BaseGameEngine - ❌ NO HACER
abstract public function startNewRound(GameMatch $match): void;  // ❌ PUBLIC rompe encapsulación

// En TriviaController - ❌ NO HACER
$engine = new TriviaEngine();
$engine->startNewRound($match);  // ❌ Llamando método protegido directamente
```

**✅ BUEN EJEMPLO (respeta encapsulación):**
```php
// En TriviaEngine - métodos protegidos + API pública
class TriviaEngine extends BaseGameEngine
{
    // Métodos PROTECTED (herencia de BaseGameEngine)
    protected function startNewRound(GameMatch $match): void
    {
        $this->nextQuestion($match);
    }

    protected function isGameComplete(GameMatch $match): bool
    {
        return parent::isGameComplete($match);
    }

    // API PÚBLICA para Controllers
    public function advanceToNextRound(GameMatch $match): void
    {
        $this->startNewRound($match);
    }

    public function checkIfGameComplete(GameMatch $match): bool
    {
        return $this->isGameComplete($match);
    }
}

// En TriviaController - usa API pública
$engine = new TriviaEngine();
if ($engine->checkIfGameComplete($match)) {  // ✅ API pública
    return response()->json(['game_complete' => true]);
}
$engine->advanceToNextRound($match);  // ✅ API pública
```

---

## 🎯 Convenciones de Eventos

### 6. ✅ Uso de Eventos Genéricos vs Custom

**Principio**: Usa eventos genéricos siempre que sea posible. Solo crea eventos custom cuando sea absolutamente necesario.

**🚨 ANTES DE CREAR UN EVENTO NUEVO - CHECKLIST OBLIGATORIO:**

1. [ ] **Revisé TODOS los eventos en `app/Events/Game/`**
2. [ ] **Leí el método `broadcastWith()` de cada evento para ver qué datos proporciona**
3. [ ] **Verifiqué que ningún evento existente cubre mi caso de uso**
4. [ ] **Consulté la tabla de Catálogo de Eventos (abajo) para ver si ya existe**
5. [ ] **Si existe un evento similar, agregué datos al evento genérico en vez de crear uno nuevo**

---

### 📋 Catálogo Completo de Eventos Genéricos

**Ubicación**: `app/Events/Game/`

#### 1️⃣ RoundStartedEvent
**Cuándo usarlo**: Nueva ronda/pregunta/nivel comenzó

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'current_round' => int,      // Número de ronda actual
    'total_rounds' => int,       // Total de rondas del juego
    'phase' => string,           // Fase actual del juego
    'game_state' => array        // Estado completo del juego
]
```

**Casos de uso**:
- Trivia: Nueva pregunta disponible
- Pictionary: Nueva palabra para dibujar
- Juegos por niveles: Nuevo nivel iniciado

**Frontend handler típico**:
```javascript
handleRoundStarted(event) {
    this.currentRound = event.current_round;
    this.totalRounds = event.total_rounds;
    this.updateRoundCounter();
    this.renderQuestion(event.game_state);
}
```

---

#### 2️⃣ RoundEndedEvent
**Cuándo usarlo**: Ronda/pregunta/nivel terminó

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'round_number' => int,       // Número de ronda que terminó
    'results' => array,          // Resultados de la ronda (ganadores, respuestas, etc.)
    'scores' => array            // ⭐ IMPORTANTE: Scores actualizados de todos los jugadores
]
```

**Casos de uso**:
- Trivia: Mostrar respuesta correcta y quién ganó
- Pictionary: Mostrar quién adivinó
- **Actualizar scores en tiempo real** (ya vienen en el evento)

**Frontend handler típico**:
```javascript
handleRoundEnded(event) {
    this.scores = event.scores;           // ✅ Scores ya vienen aquí
    this.updateScores(event.scores);      // ✅ Actualizar UI de scores
    this.showRoundResults(event.results); // Mostrar resultados
}
```

**⚠️ IMPORTANTE**: Este evento **YA incluye `scores`** actualizados. **NO necesitas crear un evento separado** para actualizar scores.

---

#### 3️⃣ TurnChangedEvent
**Cuándo usarlo**: Cambió el turno del jugador activo

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'current_player_id' => int,      // ID del jugador con el turno
    'current_player_name' => string, // Nombre del jugador con el turno
    'current_round' => int           // Ronda actual
]
```

**Casos de uso**:
- Pictionary: Cambio de dibujante
- Juegos por turnos: Siguiente jugador

**Frontend handler típico**:
```javascript
handleTurnChanged(event) {
    this.currentPlayer = event.current_player_id;
    this.updateTurnIndicator(event.current_player_name);
}
```

---

#### 4️⃣ PlayerActionEvent
**Cuándo usarlo**: Jugador realizó una acción (respuesta, adivinanza, movimiento)

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'player_id' => int,         // ID del jugador que actuó
    'player_name' => string,    // Nombre del jugador
    'action_type' => string,    // Tipo de acción (answer, guess, draw, etc.)
    'action_data' => array      // Datos específicos de la acción
]
```

**Casos de uso**:
- Trivia: Jugador respondió
- Pictionary: Jugador adivinó o dibujó
- Mostrar "X está escribiendo..."

**Frontend handler típico**:
```javascript
handlePlayerAction(event) {
    this.showPlayerActivity(event.player_name, event.action_type);
    this.updateAnswerProgress();
}
```

---

#### 5️⃣ PhaseChangedEvent
**Cuándo usarlo**: Cambió la fase del juego (waiting → playing → results → finished)

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'new_phase' => string,        // Nueva fase
    'previous_phase' => string,   // Fase anterior
    'additional_data' => array    // Datos adicionales según la fase
]
```

**Casos de uso**:
- Transición de lobby a juego
- Transición de juego a resultados finales
- Pausas o reintentos

**Frontend handler típico**:
```javascript
handlePhaseChanged(event) {
    this.currentPhase = event.new_phase;
    this.renderPhase(event.new_phase);
}
```

---

#### 6️⃣ TimerTickEvent
**Cuándo usarlo**: Actualización de temporizador

**Datos que proporciona** (`broadcastWith()`):
```php
[
    'time_remaining' => int,  // Segundos restantes
    'timer_type' => string    // Tipo de timer (round, turn, action)
]
```

**Casos de uso**:
- Trivia: Tiempo para responder pregunta
- Pictionary: Tiempo para dibujar
- Cualquier countdown

**Frontend handler típico**:
```javascript
handleTimerTick(event) {
    this.updateTimer(event.time_remaining);
}
```

---

### ✅ Checklist de Eventos

Antes de implementar un evento:

- [ ] **¿Usé eventos genéricos en vez de crear nuevos?** (revisa catálogo arriba)
- [ ] **Si necesito actualizar scores**, ¿usé `RoundEndedEvent.scores`?
- [ ] **Si necesito notificar nueva ronda**, ¿usé `RoundStartedEvent`?
- [ ] **Si necesito notificar cambio de turno**, ¿usé `TurnChangedEvent`?
- [ ] **Si necesito notificar acción de jugador**, ¿usé `PlayerActionEvent`?
- [ ] Eventos custom están declarados en `capabilities.json → provides.events`
- [ ] Eventos custom usan naming: `.{slug}.event.name`
- [ ] Eventos genéricos están mapeados en `capabilities.json → event_config`
- [ ] Eventos NO incluyen lógica de negocio (solo datos)

---

### ❌ ERRORES COMUNES - NO HACER ESTO

#### Error 1: Crear evento para actualizar scores
```php
// ❌ MAL - evento innecesario
class GameStateUpdatedEvent implements ShouldBroadcast {
    public function broadcastWith(): array {
        return ['scores' => $this->scores];
    }
}
```

**✅ CORRECTO**: Usar `RoundEndedEvent` que **YA incluye scores**:
```php
// En TriviaEngine.php
event(new RoundEndedEvent(
    $match->room->code,
    $roundNumber,
    $results,
    $this->scoreManager->getScores($match)  // ✅ Scores ya vienen aquí
));
```

#### Error 2: Crear evento custom para rondas
```json
// ❌ MAL - capabilities.json
"provides": {
  "events": [
    "TriviaQuestionStartedEvent",  // ❌ Usar RoundStartedEvent
    "TriviaQuestionEndedEvent"     // ❌ Usar RoundEndedEvent
  ]
}
```

**✅ CORRECTO**: Mapear eventos genéricos:
```json
// capabilities.json
"event_config": {
  "events": {
    "RoundStartedEvent": {
      "name": ".game.round.started",
      "handler": "handleRoundStarted"  // ✅ Handler específico del juego
    },
    "RoundEndedEvent": {
      "name": ".game.round.ended",
      "handler": "handleRoundEnded"
    }
  }
}
```

---

### 🔍 Cómo Verificar Qué Datos Proporciona un Evento

1. Abre el archivo del evento: `app/Events/Game/{EventName}.php`
2. Busca el método `broadcastWith()`:
```php
public function broadcastWith(): array
{
    return [
        'campo1' => $this->campo1,  // ✅ Estos son los datos que llegan al frontend
        'campo2' => $this->campo2,
    ];
}
```
3. Esos son los datos disponibles en `event.campo1`, `event.campo2` en el frontend

---

### 📝 Cuándo SÍ Crear un Evento Custom

Solo crea eventos custom cuando:

1. **No existe evento genérico** que cubra el caso
2. **El evento es específico del juego** y no aplicable a otros juegos
3. **Consultaste con el equipo** y confirmaron que es necesario

**Ejemplo válido de evento custom**:
```php
// GameFinishedEvent - específico del juego, no genérico
class TriviaGameFinishedEvent implements ShouldBroadcast {
    public function broadcastWith(): array {
        return [
            'winner' => $this->winner,
            'final_scores' => $this->finalScores,
            'total_rounds_played' => $this->totalRounds
        ];
    }
}
```

---

### 🎓 Lección Aprendida: Caso Real

**Problema**: Frontend de Trivia no actualizaba scores en tiempo real.

**❌ Solución incorrecta**: Crear `GameStateUpdatedEvent` para emitir scores.

**✅ Solución correcta**: Usar `RoundEndedEvent.scores` que **ya existía**:
```javascript
// trivia-game.js
handleRoundEnded(event) {
    console.log('💯 Scores:', event.scores);  // ✅ Ya vienen aquí
    this.updateScores(event.scores);          // ✅ Solo usar los datos
}
```

**Moraleja**: **SIEMPRE revisa los eventos existentes y sus datos ANTES de crear nuevos**.

---

## 🖥️ Convenciones de Frontend

### 7. ✅ Estructura de Clases JavaScript

**Archivo**: `resources/js/{slug}-game.js`

**Checklist:**
- [ ] Clase usa `window.EventManager` para WebSockets
- [ ] Handlers mapeados desde `capabilities.json → event_config`
- [ ] Usa `fetch()` con CSRF token para llamadas API
- [ ] Paths API coinciden con `routes.php`
- [ ] Frontend controla el timing (delays, countdowns) cuando sea apropiado
- [ ] Se exporta a `window` para acceso global: `window.{GameName}Game = {GameName}Game`

**Estructura obligatoria:**
```javascript
class GameNameGame {
    constructor(config) {
        this.roomCode = config.roomCode;
        this.playerId = config.playerId;
        // ...

        this.setupEventManager();
    }

    setupEventManager() {
        this.eventManager = new window.EventManager({
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            eventConfig: this.eventConfig,  // Desde capabilities.json
            handlers: {
                handleRoundStarted: (event) => this.handleRoundStarted(event),
                handleRoundEnded: (event) => this.handleRoundEnded(event),
                // ...
            }
        });
    }

    async sendAction(actionData) {
        const response = await fetch(`/api/{slug}/action`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(actionData)
        });
        // ...
    }
}

window.GameNameGame = GameNameGame;
```

---

## 🔄 Convenciones de Control de Flujo

### 8. ✅ Quién Controla el Timing

**Principio**: BaseGameEngine NO debe decidir timing. Cada juego decide dónde poner delays.

**Opciones válidas:**
1. **Frontend-controlled** (preferido para delays cortos): JavaScript setTimeout → llama endpoint backend
2. **Backend Jobs** (solo para delays largos): Laravel Queue Jobs con `dispatch()->delay()`

**Checklist:**
- [ ] ¿El delay es corto (< 10 segundos)? → Frontend
- [ ] ¿El delay es largo (> 10 segundos)? → Backend Queue Job
- [ ] BaseGameEngine NO programa siguiente ronda automáticamente
- [ ] Cada juego tiene su propio endpoint para avanzar (ej: `/api/trivia/next-round`)

**✅ BUEN EJEMPLO (Trivia - delay corto vía frontend):**

**Backend (`TriviaController.php`):**
```php
public function nextRound(Request $request)
{
    $room = Room::where('code', $request->room_code)->firstOrFail();
    $match = GameMatch::where('room_id', $room->id)->active()->firstOrFail();

    $engine = new TriviaEngine();

    if ($engine->isGameComplete($match)) {
        return response()->json(['success' => false, 'game_complete' => true]);
    }

    $engine->startNewRound($match);  // ✅ Método público

    return response()->json(['success' => true]);
}
```

**Frontend (`trivia-game.js`):**
```javascript
startNextQuestionCountdown() {
    let seconds = 5;
    const interval = setInterval(() => {
        seconds--;
        if (seconds <= 0) {
            clearInterval(interval);
            this.requestNextRound();  // ✅ Frontend controla el delay
        }
    }, 1000);
}

async requestNextRound() {
    await fetch(`/api/trivia/next-round`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ room_code: this.roomCode })
    });
}
```

---

## 🧪 Convenciones de Testing

### 9. ✅ Tests Obligatorios

**Ubicación**: `tests/Feature/Games/{GameName}/`

**Checklist de Tests:**
- [ ] Test de inicialización del juego
- [ ] Test de procesamiento de acciones
- [ ] Test de transición de rondas/turnos
- [ ] Test de condiciones de victoria
- [ ] Test de validaciones (acciones inválidas, timing)
- [ ] Test de eventos emitidos

**Naming convention:**
```
{GameName}GameFlowTest.php
{GameName}ActionsTest.php
{GameName}EventsTest.php
```

---

## 📝 Convenciones de Documentación

### 10. ✅ Documentación Obligatoria

**Checklist:**
- [ ] Comentarios PHPDoc en todos los métodos públicos
- [ ] README en `games/{slug}/README.md` explicando mecánicas del juego
- [ ] Actualizar `MODULAR_ARCHITECTURE.md` si se agregan módulos nuevos
- [ ] Actualizar `TECHNICAL_DECISIONS.md` si se toman decisiones arquitectónicas

---

## 🚨 Checklist CRÍTICO antes de Commit

### ⚠️ VERIFICAR SIEMPRE:

1. [ ] **Rutas**: ¿Están todas en `games/{slug}/routes.php`?
2. [ ] **capabilities.json**: ¿Declaré todas las rutas nuevas?
3. [ ] **Visibilidad**: ¿`startNewRound()` e `isGameComplete()` son PUBLIC en el Engine?
4. [ ] **Eventos - CRÍTICO**: ¿Revisé `app/Events/Game/` y el Catálogo de Eventos ANTES de crear eventos nuevos?
5. [ ] **Eventos - Datos**: ¿Verifiqué que eventos existentes no proporcionan los datos que necesito? (lee `broadcastWith()`)
6. [ ] **Desacoplamiento**: ¿La lógica del juego está en el Engine, NO en BaseGameEngine?
7. [ ] **Frontend**: ¿Los paths coinciden con `routes.php`?
8. [ ] **CSRF Token**: ¿Todas las llamadas POST tienen CSRF token?
9. [ ] **EventManager**: ¿El frontend usa EventManager correctamente?
10. [ ] **Tests**: ¿Agregué tests para la nueva funcionalidad?
11. [ ] **Logs**: ¿Revisé `storage/logs/laravel.log` para verificar que no hay errores?

---

## 📖 Ejemplos de Referencia

**Juegos completos para consultar:**
- **Trivia**: Ejemplo de juego simultáneo con preguntas
- **Pictionary**: Ejemplo de juego con turnos y roles (drawer/guesser)

**Archivos clave para consultar:**
- `app/Contracts/BaseGameEngine.php` - Contract base
- `app/Services/Modules/RoundSystem/RoundManager.php` - Sistema de rondas
- `app/Services/Modules/TurnSystem/TurnManager.php` - Sistema de turnos
- `resources/js/core/EventManager.js` - Manejo de WebSockets

---

## 🔧 Troubleshooting Común

### Error: "Call to protected method"
**Causa**: Método del Engine es `protected` en vez de `public`
**Solución**: Cambiar visibilidad a `public` en `BaseGameEngine` y todas las implementaciones

### Error: "Route not found" / 500 en endpoint
**Causa**: Ruta no está en `games/{slug}/routes.php` o no está en `capabilities.json`
**Solución**:
1. Verificar que la ruta existe en `routes.php`
2. Verificar que está declarada en `capabilities.json → provides.routes`
3. Limpiar cache: `php artisan route:clear`

### Error: Evento no llega al frontend
**Causa**: Evento no está mapeado en `capabilities.json → event_config`
**Solución**: Agregar mapeo del evento en `event_config.events`

### Error: Frontend stuck / no avanza
**Causa**: Backend no emite evento o frontend no tiene delay para llamar siguiente ronda
**Solución**:
1. Verificar que backend emite el evento (revisar logs)
2. Verificar que frontend tiene countdown/delay antes de llamar next-round
3. Verificar que existe endpoint para next-round

---

## ✅ Resumen: Flujo de Creación de Feature

1. **Diseñar**: ¿Es lógica genérica (módulo) o específica (juego)?
2. **Estructura**: Crear archivos en `games/{slug}/`
3. **Rutas**: Definir en `games/{slug}/routes.php`
4. **Capabilities**: Declarar en `capabilities.json`
5. **Engine**: Implementar lógica en `{GameName}Engine.php` (métodos PUBLIC)
6. **Controller**: Crear endpoints en `{GameName}Controller.php`
7. **Frontend**: Implementar en `{slug}-game.js` con EventManager
8. **Tests**: Escribir tests en `tests/Feature/Games/{GameName}/`
9. **Verificar**: Ejecutar checklist crítico
10. **Commit**: Solo cuando TODO pasa

---

**¡IMPORTANTE!** Este documento es la fuente de verdad. Cuando tengas dudas, consulta aquí PRIMERO antes de implementar.
