# Sistema de Timers Integrado con RoundManager

## Visión General

El sistema de timers está completamente integrado con BaseGameEngine y disponible automáticamente para todos los juegos. Permite:

1. **Timers automáticos por ronda**: Se inician automáticamente cuando comienza una ronda
2. **Elapsed time tracking**: Rastreo del tiempo transcurrido para scoring por rapidez
3. **Server-side sync**: Sincronización cliente-servidor vía timestamps UNIX
4. **Zero configuration**: Los juegos solo necesitan configurar duración en `config.json`

## Arquitectura

### Backend: TimerService

```
app/Services/Modules/TimerSystem/
├── TimerService.php      # Servicio principal con múltiples timers
└── Timer.php             # Clase individual de timer
```

**Características**:
- Múltiples timers simultáneos
- Stateless (timestamp-based)
- Pause/resume individual
- Serializable a game_state
- Cálculo de elapsed/remaining time

### Frontend: TimingModule.js

```
resources/js/modules/TimingModule.js
```

**Características**:
- Server-synced countdown (60fps)
- Compensación automática de lag/drift
- RequestAnimationFrame para suavidad
- Gaming industry standard

### Integración: BaseGameEngine

```
app/Contracts/BaseGameEngine.php
```

**Helpers disponibles**:
```php
// Automático - NO requiere llamada manual
protected function startRoundTimer(GameMatch $match): bool

// Helpers para usar en tu juego
protected function getElapsedTime(GameMatch $match, string $name): int
protected function getTimeRemaining(GameMatch $match, string $name): int
protected function isTimerExpired(GameMatch $match, string $name): bool
```

## Configuración en config.json

### Paso 1: Habilitar timer_system

```json
{
  "modules": {
    "timer_system": {
      "enabled": true,
      "round_duration": 15  // Segundos por ronda
    }
  }
}
```

Eso es todo. El timer se iniciará automáticamente en cada ronda.

### Paso 2 (Opcional): Personalizar timing metadata

Si necesitas countdown visible o threshold personalizado:

```json
{
  "timing": {
    "round_start": {
      "duration": 15,
      "countdown_visible": true,
      "warning_threshold": 3
    }
  }
}
```

## Uso en Juegos

### Ejemplo 1: Scoring con Speed Bonus

Trivia otorga puntos bonus por responder rápido:

```php
// games/trivia/TriviaEngine.php - processRoundAction()

// Preparar context con elapsed time
$context = [
    'difficulty' => $question['difficulty'],
];

// Obtener elapsed time si hay timer
$timerDuration = $gameConfig['modules']['timer_system']['round_duration'] ?? null;
if ($timerDuration) {
    try {
        $elapsedTime = $this->getElapsedTime($match, 'round');
        $context['time_taken'] = $elapsedTime;
        $context['time_limit'] = $timerDuration;
    } catch (\Exception $e) {
        // Timer no disponible - sin bonus
    }
}

// El calculator usa elapsed time para calcular bonus
$totalPoints = $calculator->calculate('correct_answer', $context);
```

**TriviaScoreCalculator.php**:
```php
protected function calculateSpeedBonus(array $context): int
{
    $timeTaken = $context['time_taken'] ?? null;
    $timeLimit = $context['time_limit'] ?? null;

    if ($timeTaken === null || $timeLimit === null) {
        return 0;
    }

    // Bonus inversamente proporcional
    $timeUsedPercent = min(1.0, $timeTaken / $timeLimit);
    $bonusPercent = 1.0 - $timeUsedPercent;

    return (int) round($bonusPercent * $this->config['speed_bonus_max']);
}
```

### Ejemplo 2: Verificar si Timer Expiró

```php
if ($this->isTimerExpired($match, 'round')) {
    // El tiempo se agotó - aplicar penalty o acción
    $this->handleTimeout($match);
}
```

### Ejemplo 3: Obtener Tiempo Restante

```php
$remaining = $this->getTimeRemaining($match, 'round');
Log::info("Quedan {$remaining} segundos");
```

## Flujo Automático

### 1. Inicio de Ronda

```
PlayController::startNextRound()
    ↓
BaseGameEngine::handleNewRound()
    ↓
RoundManager::advanceToNextRound()
    ↓
[GAME]::startNewRound()  // Lógica del juego
    ↓
BaseGameEngine::startRoundTimer()  ← ✅ AUTOMÁTICO
    ↓ (lee config.json)
TimerService::startTimer('round', duration)
    ↓ (guarda en game_state)
GameMatch::game_state['timer_system']
```

### 2. Emisión de Evento

```
BaseGameEngine::getRoundStartTiming()
    ↓ (lee timer_system config)
    ↓ (agrega server_time = microtime(true))
RoundStartedEvent
    ↓ (WebSocket broadcast)
Frontend recibe timing = {
    duration: 15,
    countdown_visible: true,
    warning_threshold: 3,
    server_time: 1735234567.123  ← UNIX timestamp
}
```

### 3. Frontend: Countdown

```javascript
// BaseGameClient.js - handleRoundStarted()
if (event.timing) {
    await this.timing.processTimingPoint(
        event.timing,
        () => this.notifyReadyForNextRound(fromRound),
        this.getCountdownElement()
    );
}
```

**TimingModule.js** usa el `server_time` para:
- Calcular `endTime = server_time + duration`
- Compensar lag entre cliente y servidor
- Actualizar UI a 60fps con `requestAnimationFrame`
- Llamar callback cuando expira

## Ventajas del Sistema

### ✅ Zero Duplication
Los juegos NO necesitan:
- Llamar manualmente `startRoundTimer()`
- Configurar timers en `startNewRound()`
- Gestionar lifecycle del timer
- Incluir metadata en eventos

Todo es automático.

### ✅ DRY Principle
La lógica de timing está centralizada en:
- BaseGameEngine (backend)
- TimingModule.js (frontend)

### ✅ Timestamp-Based
NO usa:
- setTimeout/setInterval (impreciso)
- Cron jobs (overhead)
- Polling (ineficiente)

Usa `microtime(true)` y cálculo matemático directo.

### ✅ Gaming Industry Standard

Misma arquitectura que:
- Fortnite
- CS:GO
- Rocket League

Un solo evento con timestamp, cliente calcula localmente, compensa lag.

## Casos de Uso Comunes

### Scoring por Rapidez
**Juegos**: Trivia, Quiz, Speed Challenges

```php
$elapsed = $this->getElapsedTime($match, 'round');
$bonus = $this->calculateSpeedBonus($elapsed, $timeLimit);
```

### Timeout Automático
**Juegos**: Turnos con límite de tiempo

```php
public function onTurnTimeout(GameMatch $match): void
{
    // BaseGameEngine llama este método cuando expira
    $this->skipCurrentPlayer($match);
    $this->nextTurn($match);
}
```

### Presión Temporal
**Juegos**: Escape rooms, Puzzles

```php
$remaining = $this->getTimeRemaining($match, 'round');
if ($remaining < 10) {
    event(new TimeRunningOutEvent($match));
}
```

## Testing

### Verificar Timer Iniciado

```php
$match->refresh();
$timerData = $match->game_state['timer_system'] ?? null;
$this->assertNotNull($timerData);
$this->assertArrayHasKey('round', $timerData['timers']);
```

### Simular Tiempo Transcurrido

```php
// El TimerService usa timestamps, puedes manipular directamente
$timerService = TimerService::fromArray($match->game_state);
$elapsed = $timerService->getElapsedTime('round');
$this->assertGreaterThan(0, $elapsed);
```

## Troubleshooting

### Timer no se inicia

**Causa**: `timer_system.enabled = false` o `round_duration` no configurado

**Solución**:
```json
{
  "modules": {
    "timer_system": {
      "enabled": true,
      "round_duration": 15
    }
  }
}
```

### Speed bonus siempre 0

**Causa**: No se pasa `time_taken` y `time_limit` al calculator

**Solución**:
```php
$context = [
    'time_taken' => $this->getElapsedTime($match, 'round'),
    'time_limit' => $timerDuration
];
```

### Countdown no aparece en frontend

**Causa**: `timing` no incluido en evento o `countdown_visible: false`

**Solución**: Verificar que `getRoundStartTiming()` retorna metadata correcta

## Timer Expiration: Comportamiento Extensible

### Arquitectura de Hooks

Cuando un timer expira, BaseGameEngine proporciona un flujo robusto con tres niveles de extensibilidad:

```
Timer expira
    ↓
Frontend: onTimerExpired() → POST /api/rooms/{code}/check-timer
    ↓
Backend: checkTimerAndAutoAdvance()
    ↓
    1. Emite RoundTimerExpiredEvent
    ↓
    2. Llama onRoundTimerExpired() del juego
       ├─ (por defecto) beforeTimerExpiredAdvance() ← Hook opcional
       └─ (por defecto) completeRound() → advance
```

### Nivel 1: Comportamiento por Defecto (Zero Config)

**BaseGameEngine** implementa el comportamiento más común:

```php
// app/Contracts/BaseGameEngine.php
protected function onRoundTimerExpired(GameMatch $match, string $timerName = 'round'): void
{
    // 1. Hook opcional del juego
    $this->beforeTimerExpiredAdvance($match, $timerName);

    // 2. Completar ronda y avanzar (comportamiento estándar)
    $this->completeRound($match, ['reason' => 'timer_expired']);

    // 3. Timer se limpia automáticamente en handleNewRound()
}
```

**¿Qué juegos lo usan?**
- Trivia (nadie respondió → siguiente pregunta)
- Quiz (timeout → avanzar)
- Speed challenges (se acabó el tiempo → siguiente ronda)

**Ventaja**: No escribes NADA en tu juego, todo funciona automáticamente.

### Nivel 2: Lógica Pre-Advance (Hook Intermedio)

Si necesitas ejecutar lógica ANTES de avanzar (sin cambiar el comportamiento):

```php
// games/trivia/TriviaEngine.php
protected function beforeTimerExpiredAdvance(GameMatch $match, string $timerName = 'round'): void
{
    // Ejemplo: Registrar estadística
    $question = $match->game_state['current_question'];
    $this->recordUnansweredQuestion($question['id']);

    // Ejemplo: Penalizar todos los jugadores
    $scoreManager = $this->getScoreManager($match);
    foreach ($match->players as $player) {
        $scoreManager->addScore($player->id, -5);
    }
    $this->saveScoreManager($match, $scoreManager);

    // Ejemplo: Emitir evento custom
    event(new QuestionTimeoutEvent($match, $question));

    // BaseGameEngine completará la ronda automáticamente después
}
```

**Casos de uso**:
- Logging/estadísticas
- Penalizaciones
- Achievements/badges
- Notificaciones custom
- Modificar estado antes de avanzar

### Nivel 3: Comportamiento Completamente Custom

Si tu juego necesita algo radicalmente diferente:

```php
// games/pictionary/PictionaryEngine.php
protected function onRoundTimerExpired(GameMatch $match, string $timerName = 'round'): void
{
    Log::info("[Pictionary] Turn timeout - passing to next drawer");

    // NO completamos ronda - solo pasamos turno
    $turnManager = $this->getTurnManager($match);
    $nextDrawer = $turnManager->advanceTurn($match);
    $this->saveTurnManager($match, $turnManager);

    // Resetear canvas para el nuevo drawer
    $this->clearCanvas($match);

    // Iniciar nuevo timer de turno (NO de ronda)
    $this->startRoundTimer($match); // Mismo método, nuevo timer

    // Emitir evento de cambio de turno
    event(new TurnChangedEvent($match, $nextDrawer));
}
```

**Casos de uso**:
- Juegos por turnos (pasar turno en lugar de terminar ronda)
- Juegos con fases (pasar a siguiente fase)
- Juegos con penalties (continuar pero con castigo)

### Eventos Disponibles

```php
// Emitido automáticamente cuando timer expira
use App\Events\Game\RoundTimerExpiredEvent;

event(new RoundTimerExpiredEvent(
    match: $match,
    roundNumber: $currentRound,
    timerName: 'round'
));
```

El evento se broadcast a todos los clientes vía WebSocket como `game.round.timer.expired`.

### Frontend: Manejo de Timer Expiration

```javascript
// BaseGameClient.js
async onTimerExpired(roundNumber) {
    // 1. Mostrar UI de timeout
    const timerElement = this.getTimerElement();
    if (timerElement) {
        timerElement.textContent = '¡Tiempo agotado!';
        timerElement.classList.add('timer-expired');
    }

    // 2. Notificar backend
    const response = await fetch(`/api/rooms/${this.roomCode}/check-timer`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ from_round: roundNumber })
    });
}
```

Los juegos pueden sobrescribir `onTimerExpired()` para efectos visuales custom:

```javascript
// TriviaGameClient.js
async onTimerExpired(roundNumber) {
    // Efecto de shake
    this.shakeScreen();

    // Sonido de timeout
    this.playSound('timeout');

    // Llamar implementación base
    await super.onTimerExpired(roundNumber);
}
```

### Flujo Completo de Timer Expiration

```
1. Frontend: TimingModule countdown llega a 0
   ↓
2. Frontend: BaseGameClient.onTimerExpired()
   ↓
3. Frontend: POST /api/rooms/{code}/check-timer
   ↓
4. Backend: PlayController::apiCheckTimer()
   ↓
5. Backend: BaseGameEngine::checkTimerAndAutoAdvance()
   - Verifica que timer realmente expiró (timestamp-based)
   - Emite RoundTimerExpiredEvent (broadcast WebSocket)
   ↓
6. Backend: BaseGameEngine::onRoundTimerExpired()
   - Llama beforeTimerExpiredAdvance() (hook opcional)
   - Completa ronda (comportamiento por defecto)
   ↓
7. Backend: completeRound() → RoundEndedEvent
   ↓
8. Backend: RoundManager::advanceToNextRound()
   ↓
9. Backend: startNewRound() → RoundStartedEvent
   ↓
10. Frontend: Recibe RoundStartedEvent y renderiza nueva ronda
```

### Ejemplo Completo: Trivia

```php
// games/trivia/TriviaEngine.php

// ✅ Opción 1: No hacer nada (usa default)
// Timer expira → completeRound() → siguiente pregunta
// (ningún código necesario)

// ✅ Opción 2: Solo logging
protected function beforeTimerExpiredAdvance(GameMatch $match, string $timerName = 'round'): void
{
    Log::info("[Trivia] Question timed out", [
        'question_id' => $match->game_state['current_question']['id'],
        'round' => $match->game_state['round_system']['current_round']
    ]);
}
```

### Testing

```php
// tests/Feature/TimerExpirationTest.php

public function test_timer_expiration_completes_round()
{
    $match = $this->createMatchWithTimer(duration: 1);

    // Simular que el tiempo pasó
    sleep(2);

    // Llamar check-timer
    $response = $this->post("/api/rooms/{$match->room->code}/check-timer");

    $response->assertOk();
    $response->assertJson(['completed' => true]);

    // Verificar que avanzó ronda
    $match->refresh();
    $this->assertEquals(2, $match->game_state['round_system']['current_round']);
}
```

### Resumen de Flexibilidad

| Nivel | Método | Cuándo usar |
|-------|--------|-------------|
| 1 | Ninguno (default) | Timer expira → completar ronda (90% de casos) |
| 2 | `beforeTimerExpiredAdvance()` | Timer expira → lógica custom → completar ronda |
| 3 | `onRoundTimerExpired()` | Timer expira → comportamiento completamente diferente |

**Principio de diseño**:
- BaseGameEngine provee el caso común (completar ronda)
- Los juegos pueden extender o reemplazar según necesidad
- Zero boilerplate para casos simples

## Resumen

El sistema de timers está **completamente integrado** y requiere **cero código adicional** en los juegos individuales:

1. ✅ Configura `round_duration` en `config.json`
2. ✅ Los timers se inician automáticamente
3. ✅ Usa `getElapsedTime()` para scoring
4. ✅ El frontend recibe timing metadata automáticamente
5. ✅ Timer expiration maneja automáticamente (completa ronda)
6. ✅ Hooks opcionales para customización sin boilerplate

**Ejemplo completo en**: `games/trivia/` (speed bonus y timer expiration implementados)
