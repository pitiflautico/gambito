# Catálogo de Convenciones y Arquitectura

Este documento define las normas y convenciones arquitectónicas del proyecto. **DEBE seguirse estrictamente** para mantener la consistencia y evitar errores estructurales.

---

## 1. Arquitectura General

### 1.1. Separación de Responsabilidades

**REGLA FUNDAMENTAL**: La plataforma es **GENÉRICA**. Los juegos son **ESPECÍFICOS**.

```
❌ MAL: Lógica de juego en código genérico
✅ BIEN: Lógica de juego solo en games/{slug}/

❌ MAL: Endpoints específicos en rutas genéricas
✅ BIEN: Endpoints específicos en games/{slug}/routes.php
```

### 1.2. Flujo de Eventos

**REGLA**: Los eventos siempre fluyen en esta dirección:

```
Frontend → Endpoint Genérico → Evento Genérico → Engine Específico → Evento Broadcast
```

**Ejemplo correcto (Turn Timeout)**:
```
1. Frontend detecta timer=0
2. Frontend llama: POST /api/games/{match}/turn-timeout (genérico)
3. Backend emite: TurnTimeoutEvent (genérico, interno)
4. TriviaEngine escucha TurnTimeoutEvent
5. TriviaEngine ejecuta endCurrentRound()
6. TriviaEngine emite RoundEndedEvent (broadcast al frontend)
```

**❌ Ejemplo INCORRECTO**:
```
1. Frontend llama: POST /api/trivia/question-timeout (específico)
2. TriviaController termina la ronda directamente
```

---

## 2. Endpoints y Rutas

### 2.1. Endpoints Genéricos vs Específicos

**SOLO estos endpoints pueden ser específicos**:
- Acciones de juego (responder, dibujar, votar, etc.)
- Vistas del juego

**TODOS estos endpoints DEBEN ser genéricos**:
- Control de flujo (start, pause, resume)
- Gestión de turnos (timeout, skip)
- Gestión de rondas (advance, end)
- Sincronización de clientes (player-connected, game-ready)

### 2.2. Ubicación de Endpoints

```php
// ✅ CORRECTO: Endpoint genérico en routes/api.php
Route::post('/games/{match}/turn-timeout', [GameController::class, 'turnTimeout']);
Route::post('/games/{match}/start-next-round', [GameController::class, 'startNextRound']);
Route::post('/games/{match}/game-ready', [GameController::class, 'gameReady']);

// ✅ CORRECTO: Endpoint específico en games/trivia/routes.php
Route::post('/answer', [TriviaController::class, 'answer']);

// ❌ INCORRECTO: Endpoint de control en ruta específica
Route::post('/countdown-ended', [TriviaController::class, 'countdownEnded']); // DEBE estar en api.php
```

---

## 3. Eventos

### 3.1. Tipos de Eventos

**Eventos Genéricos** (en `app/Events/Game/`):
- `GameStartedEvent` - Juego iniciado
- `RoundStartedEvent` - Nueva ronda iniciada
- `RoundEndedEvent` - Ronda terminada
- `TurnChangedEvent` - Cambio de turno
- `TurnTimeoutEvent` - Tiempo del turno expiró (interno)
- `PhaseChangedEvent` - Cambio de fase
- `PlayerActionEvent` - Acción de jugador
- `GameFinishedEvent` - Juego terminado

**Eventos Específicos** (en `games/{slug}/Events/`):
- SOLO si representan conceptos únicos del juego
- Ejemplo: `CanvasDrawEvent` (Pictionary), `WordGuessedEvent` (Wordle)

### 3.2. Eventos Internos vs Broadcast

```php
// ✅ Evento INTERNO (no se emite al frontend)
event(new TurnTimeoutEvent($match, $turnIndex, $gameState));

// ✅ Evento BROADCAST (sí se emite al frontend)
event(new RoundEndedEvent($match, $roundNumber, $results, $scores));
```

**REGLA**: Los eventos internos se usan para comunicación entre módulos del backend. Los engines escuchan estos eventos y emiten eventos broadcast como respuesta.

---

## 4. Módulos del Sistema

### 4.1. Responsabilidades de Módulos

Cada módulo tiene **UNA sola responsabilidad**:

| Módulo | Responsabilidad | NO hace |
|--------|----------------|---------|
| `TurnManager` | Orden de turnos, timer | Decidir si termina ronda, calcular puntos |
| `RoundManager` | Contador de rondas | Lógica de juego, scoring |
| `ScoreManager` | Cálculo de puntos | Decidir ganadores, terminar juego |
| `TimerService` | Tracking de tiempo | Ejecutar acciones al expirar |
| `SessionManager` | Identificar jugadores | Gestionar estado de juego |

### 4.2. Configuración de Módulos

```json
// ✅ CORRECTO: Configuración en config.json del juego
{
  "modules": {
    "turn_system": {
      "enabled": true,
      "mode": "simultaneous",
      "time_limit": 15
    }
  }
}
```

```php
// ❌ INCORRECTO: Hardcodear configuración en el engine
$turnManager = new TurnManager($players, 'simultaneous', 15);
```

---

## 5. Flujo de Timing Automático

### 5.1. Inicialización

**REGLA**: Los timers se inician AUTOMÁTICAMENTE cuando el módulo se resetea.

```php
// ✅ CORRECTO: BaseGameEngine.initializeModules()
$turnManager = new TurnManager(
    playerIds: $playerIds,
    mode: $mode,
    timeLimit: $timeLimit  // ⬅️ CRÍTICO: Pasar timeLimit aquí
);

// Cuando se llama a reset(), el timer se inicia automáticamente
$turnManager->reset(); // ⬅️ Inicia timer si timeLimit != null
```

**❌ INCORRECTO**: Olvidar pasar `timeLimit` al constructor.

### 5.2. Detección de Timeout

```
1. TurnManager crea timer con TimerService
2. Frontend muestra countdown basado en timing metadata
3. Cuando countdown llega a 0:
   - Frontend llama /api/games/{match}/turn-timeout
   - Backend verifica con TurnManager.isTimeExpired()
   - Si expiró, emite TurnTimeoutEvent
4. Engine específico escucha TurnTimeoutEvent
5. Engine ejecuta su lógica (ej: endCurrentRound())
6. Engine emite evento broadcast (ej: RoundEndedEvent)
```

---

## 6. Frontend

### 6.1. BaseGameClient

**REGLA**: Toda lógica común va en `BaseGameClient`. Los juegos solo sobrescriben lo específico.

```javascript
// ✅ CORRECTO: Juego específico extiende BaseGameClient
class TriviaGame extends BaseGameClient {
    handleRoundStarted(event) {
        // Lógica específica de Trivia
    }
}

// ❌ INCORRECTO: Reimplementar lógica genérica en cada juego
class TriviaGame {
    constructor() {
        // Reimplementar WebSocket, timing, etc.
    }
}
```

### 6.2. Handlers de Eventos

**Handlers Genéricos** (ya implementados en `BaseGameClient`):
- `handleGameStarted`
- `handleRoundStarted`
- `handleRoundEnded`
- `handleTurnChanged`
- `handlePhaseChanged`
- `handlePlayerAction`
- `handleGameFinished`

**Los juegos SOLO sobrescriben si necesitan lógica adicional**.

---

## 7. Configuración de Eventos

### 7.1. Registro de Eventos

```php
// ✅ CORRECTO: Eventos genéricos en config/game-events.php
'base_events' => [
    'events' => [
        'RoundStartedEvent' => [...],
        'RoundEndedEvent' => [...],
        'TurnTimeoutEvent' => [...], // Interno, no broadcast
    ]
]

// ✅ CORRECTO: Solo eventos específicos en games/{slug}/capabilities.json
{
  "event_config": {
    "events": {
      "CanvasDrawEvent": {...}  // Solo si es único del juego
    }
  }
}

// ❌ INCORRECTO: Duplicar eventos genéricos en capabilities.json
{
  "event_config": {
    "events": {
      "GameFinishedEvent": {...}  // YA está en game-events.php
    }
  }
}
```

---

## 8. Race Conditions

### 8.1. Protección con Locks

**TODOS los endpoints que modifican estado DEBEN usar locks**:

```php
// ✅ CORRECTO
public function turnTimeout(Request $request, GameMatch $match)
{
    if (!$match->acquireRoundLock()) {
        return response()->json([
            'success' => true,
            'already_processing' => true
        ], 200);
    }

    try {
        // Procesar timeout
    } finally {
        $match->releaseRoundLock();
    }
}

// ❌ INCORRECTO: Sin lock
public function turnTimeout(Request $request, GameMatch $match)
{
    // Procesar directamente (múltiples clientes ejecutarán esto)
}
```

---

## 9. Testing

### 9.1. Tests Obligatorios

Para cada juego, DEBE existir:

```
tests/Feature/{Game}CompleteGameRegressionTest.php
tests/Feature/{Game}ConventionComplianceTest.php
tests/Feature/{Game}EngineModuleUsageTest.php
```

### 9.2. Validación de Convenciones

Los tests deben verificar:
- ✅ No hay endpoints específicos donde deberían ser genéricos
- ✅ Eventos no están duplicados
- ✅ Módulos se inicializan correctamente
- ✅ timeLimit se pasa al TurnManager
- ✅ Eventos genéricos están registrados

---

## 10. Documentación Obligatoria

Cada juego DEBE tener:

```
games/{slug}/README.md          - Descripción del juego
games/{slug}/config.json        - Configuración completa
games/{slug}/capabilities.json  - Módulos y eventos
```

Cada endpoint DEBE tener:
```php
/**
 * Descripción clara del endpoint.
 *
 * Flujo:
 * 1. Paso 1
 * 2. Paso 2
 *
 * Race Condition Protection:
 * - Explicar el mecanismo de lock
 *
 * @param Request $request
 * @param GameMatch $match
 */
```

---

## 11. Checklist de Revisión

Antes de hacer commit, verificar:

- [ ] ¿Los endpoints genéricos están en `routes/api.php`?
- [ ] ¿Los endpoints específicos están en `games/{slug}/routes.php`?
- [ ] ¿Los eventos internos NO se emiten al frontend?
- [ ] ¿Los eventos broadcast SÍ se emiten al frontend?
- [ ] ¿Los módulos reciben configuración del `config.json`?
- [ ] ¿El `timeLimit` se pasa al `TurnManager`?
- [ ] ¿Los endpoints usan locks para race conditions?
- [ ] ¿Los handlers están en `BaseGameClient` si son genéricos?
- [ ] ¿Los tests de convención pasan?
- [ ] ¿La documentación está actualizada?

---

## 12. Flujo de Rondas: Responsabilidades Claras

### 12.1. REGLA DE ORO: BaseGameEngine coordina, Engine específico ejecuta

**❌ NUNCA hagas esto en el Engine específico:**
```php
// MAL: Avanzar ronda manualmente
$roundManager->nextRound();
event(new RoundStartedEvent(...));

// MAL: Emitir eventos genéricos desde el juego
event(new RoundEndedEvent(...));
```

**✅ SIEMPRE haz esto:**
```php
// BIEN: Delegar coordinación a BaseGameEngine
class TriviaEngine extends BaseGameEngine {
    protected function endCurrentRound(GameMatch $match): void {
        // 1. Solo lógica específica: calcular puntos
        $this->calculateScores($match);

        // 2. Delegar a BaseGameEngine
        $this->completeRound($match);
    }
}
```

### 12.2. Métodos del Engine y sus responsabilidades

**Métodos que DEBES implementar:**

| Método | Responsabilidad | Lo que NO debe hacer |
|--------|----------------|---------------------|
| `initialize()` | Cargar configuración del juego | Iniciar juego, resetear scores |
| `processRoundAction()` | Procesar acción del jugador | Avanzar rondas, terminar juego |
| `endCurrentRound()` | Calcular puntos, preparar resultados | Avanzar ronda, emitir eventos genéricos |
| `startNewRound()` | Cargar datos de la nueva ronda | Avanzar contador de ronda |

**Lo que BaseGameEngine hace por ti:**
- Avanzar `RoundManager`
- Emitir eventos genéricos (`RoundStartedEvent`, `RoundEndedEvent`)
- Verificar si el juego terminó
- Coordinar módulos (Timer, Scoring, etc.)

### 12.3. Flujo correcto de una ronda

```
[Usuario responde]
    ↓
processRoundAction()  ← Engine específico
    ↓
[¿Todos respondieron?]
    ↓ Sí
endCurrentRound()  ← Engine específico (calcula puntos)
    ↓
completeRound()  ← BaseGameEngine
    ↓
• Emite RoundEndedEvent
• Avanza RoundManager
• Verifica si terminó
    ↓ No terminó
startNewRound()  ← Engine específico (carga siguiente pregunta/turno)
    ↓
• Emite RoundStartedEvent  ← BaseGameEngine
```

### 12.4. Ejemplo correcto: Trivia

```php
class TriviaEngine extends BaseGameEngine
{
    /**
     * Procesar respuesta del jugador.
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $actionData): array
    {
        // 1. Solo lógica de Trivia
        $this->saveAnswer($match, $player, $actionData);

        // 2. Verificar si todos respondieron
        if ($this->allPlayersAnswered($match)) {
            $this->endCurrentRound($match);
        }

        return ['success' => true];
    }

    /**
     * Terminar pregunta actual.
     */
    protected function endCurrentRound(GameMatch $match): void
    {
        // 1. SOLO lógica específica: calcular puntos
        $results = $this->calculateQuestionResults($match);
        $this->updateScores($match, $results);

        // 2. Guardar resultados en game_state (NO en módulos)
        $match->game_state['question_results'] = $results;
        $match->save();

        // 3. NO avanzar ronda aquí
        // 4. NO emitir eventos genéricos
        // BaseGameEngine lo hará
    }

    /**
     * Cargar siguiente pregunta.
     */
    protected function startNewRound(GameMatch $match): void
    {
        // 1. SOLO lógica específica: cargar siguiente pregunta
        $gameState = $match->game_state;
        $nextIndex = $gameState['current_question_index'] + 1;

        $gameState['current_question_index'] = $nextIndex;
        $gameState['current_question'] = $gameState['questions'][$nextIndex];
        $gameState['player_answers'] = [];

        $match->game_state = $gameState;
        $match->save();

        // 2. NO avanzar ronda aquí (ya lo hizo BaseGameEngine)
        // 3. NO emitir RoundStartedEvent (lo hará BaseGameEngine)
    }
}
```

### 12.5. Checklist antes de implementar

- [ ] ¿Estoy avanzando `RoundManager` manualmente? → ❌ Delegar a `BaseGameEngine`
- [ ] ¿Estoy emitiendo `RoundStartedEvent` o `RoundEndedEvent`? → ❌ Lo hace `BaseGameEngine`
- [ ] ¿Estoy llamando a `nextTurn()` o `nextRound()`? → ❌ Delegar a `BaseGameEngine`
- [ ] ¿Solo guardo datos específicos del juego en `game_state`? → ✅ Correcto
- [ ] ¿Delego coordinación a métodos de `BaseGameEngine`? → ✅ Correcto

## 13. Ejemplos Completos

### 12.1. Implementar Turn Timeout (Correcto)

**1. Evento Genérico** (`app/Events/Game/TurnTimeoutEvent.php`):
```php
class TurnTimeoutEvent
{
    public function __construct(
        public GameMatch $match,
        public int $turnIndex,
        public array $gameState
    ) {}
}
```

**2. Endpoint Genérico** (`routes/api.php`):
```php
Route::post('/games/{match}/turn-timeout', [GameController::class, 'turnTimeout']);
```

**3. GameController** (`app/Http/Controllers/GameController.php`):
```php
public function turnTimeout(Request $request, GameMatch $match)
{
    // Verificar timeout con TurnManager
    // Emitir TurnTimeoutEvent
}
```

**4. Listener en Engine** (`games/trivia/TriviaEngine.php`):
```php
// En el constructor o boot
Event::listen(TurnTimeoutEvent::class, function ($event) {
    if ($event->match->room->game->slug === 'trivia') {
        $this->handleTurnTimeout($event->match);
    }
});

protected function handleTurnTimeout(GameMatch $match)
{
    $this->endCurrentRound($match);
}
```

**5. Frontend** (`games/trivia/views/game.blade.php`):
```javascript
if (remaining <= 0) {
    fetch(`/api/games/${matchId}/turn-timeout`, {
        method: 'POST',
        headers: {...}
    });
}
```

---

## 13. Anti-Patrones Comunes

### ❌ Anti-Patrón 1: Endpoints Específicos para Control de Flujo
```php
// MAL: En games/trivia/routes.php
Route::post('/question-timeout', [TriviaController::class, 'questionTimeout']);
```

### ❌ Anti-Patrón 2: Lógica de Juego en Módulos Genéricos
```php
// MAL: TurnManager decide si terminar ronda
class TurnManager {
    public function checkIfRoundShouldEnd() {
        // ❌ Esto es responsabilidad del Engine
    }
}
```

### ❌ Anti-Patrón 3: Olvidar Pasar Configuración
```php
// MAL: No pasar timeLimit
$turnManager = new TurnManager($players, $mode); // ❌ Falta timeLimit
```

### ❌ Anti-Patrón 4: Duplicar Eventos
```json
// MAL: En capabilities.json
{
  "events": {
    "RoundStartedEvent": {...}  // ❌ Ya está en game-events.php
  }
}
```

---

## 14. Contacto y Revisión

**Antes de agregar nuevas features**:
1. Revisar este documento
2. Verificar que la arquitectura es correcta
3. Ejecutar tests de convención
4. Documentar en el catálogo si es un nuevo patrón

**Para dudas arquitectónicas**:
- Consultar `MODULAR_ARCHITECTURE.md`
- Consultar `TECHNICAL_DECISIONS.md`
- Verificar ejemplos en Trivia (el juego más completo)

---

**Última actualización**: 2025-01-24
**Versión**: 1.0
