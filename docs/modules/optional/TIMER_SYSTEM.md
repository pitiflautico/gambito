# Timer System Module

## Descripción

El **Timer System Module** es un módulo opcional que proporciona gestión genérica de temporizadores/cronómetros para juegos. Permite crear, gestionar y consultar múltiples timers simultáneos con soporte para pausas, cálculo de tiempo transcurrido/restante, y detección de expiración.

## Casos de Uso

Este módulo es útil para juegos que requieren:
- **Límites de tiempo por turno**: Cada jugador tiene X segundos para realizar su acción
- **Timeouts por pregunta**: Responder antes de que expire el tiempo
- **Cooldowns de habilidades**: Tiempo de espera entre usos de poderes especiales
- **Tiempo total de partida**: Duración máxima del juego
- **Fases cronometradas**: Diferentes fases del juego con duraciones específicas

## Características

- ✅ **Múltiples timers simultáneos**: Gestionar varios timers al mismo tiempo con nombres únicos
- ✅ **Pause/Resume**: Pausar y reanudar timers con acumulación correcta del tiempo pausado
- ✅ **Cálculo preciso**: Tiempo transcurrido y restante calculado automáticamente
- ✅ **Detección de expiración**: Verificar si un timer ha llegado a cero
- ✅ **Serialización completa**: Guardar y restaurar estado completo en game_state
- ✅ **No requiere cron/jobs**: Cálculos on-demand sin procesos en background

## Arquitectura

### Clases Principales

```
TimerService (Servicio principal)
  ├── startTimer()      - Crear nuevo timer
  ├── pauseTimer()      - Pausar timer
  ├── resumeTimer()     - Reanudar timer
  ├── getRemainingTime()- Obtener tiempo restante
  ├── getElapsedTime()  - Obtener tiempo transcurrido
  ├── isExpired()       - Verificar si expiró
  ├── cancelTimer()     - Eliminar timer
  ├── restartTimer()    - Reiniciar timer
  └── toArray()/fromArray() - Serialización

Timer (Value Object)
  ├── pause()           - Pausar
  ├── resume()          - Reanudar
  ├── getElapsedTime()  - Tiempo transcurrido
  ├── getRemainingTime()- Tiempo restante
  ├── isExpired()       - Verificar expiración
  └── toArray()/fromArray() - Serialización
```

### Patrón de Serialización

Al igual que otros módulos, TimerService se serializa completamente en `game_state`:

```json
{
  "timers": {
    "turn_timer": {
      "name": "turn_timer",
      "duration": 90,
      "started_at": "2025-01-15 12:00:00",
      "is_paused": false,
      "paused_at": null,
      "total_paused_seconds": 0
    },
    "round_timer": {
      "name": "round_timer",
      "duration": 300,
      "started_at": "2025-01-15 12:00:00",
      "is_paused": false,
      "paused_at": null,
      "total_paused_seconds": 0
    }
  }
}
```

## API Completa

### TimerService

#### `startTimer(string $timerName, int $durationSeconds, ?DateTime $startTime = null): Timer`

Inicia un nuevo timer.

**Parámetros:**
- `$timerName`: Nombre único del timer
- `$durationSeconds`: Duración en segundos (debe ser > 0)
- `$startTime`: Timestamp de inicio (opcional, default: now)

**Excepciones:**
- `\InvalidArgumentException`: Si el timer ya existe o la duración es <= 0

**Ejemplo:**
```php
$timerService = new TimerService();
$timerService->startTimer('turn_timer', 90); // 90 segundos
```

#### `pauseTimer(string $timerName): void`

Pausa un timer. El tiempo pausado no se cuenta en el tiempo transcurrido.

**Ejemplo:**
```php
$timerService->pauseTimer('turn_timer');
```

#### `resumeTimer(string $timerName): void`

Reanuda un timer pausado. Acumula el tiempo que estuvo pausado.

**Ejemplo:**
```php
$timerService->resumeTimer('turn_timer');
```

#### `getRemainingTime(string $timerName): int`

Obtiene el tiempo restante en segundos. Retorna 0 si el timer expiró.

**Ejemplo:**
```php
$remaining = $timerService->getRemainingTime('turn_timer');
// $remaining = 45 (quedan 45 segundos)
```

#### `getElapsedTime(string $timerName): int`

Obtiene el tiempo transcurrido en segundos (excluyendo tiempo pausado).

**Ejemplo:**
```php
$elapsed = $timerService->getElapsedTime('turn_timer');
// $elapsed = 45 (han pasado 45 segundos activos)
```

#### `isExpired(string $timerName): bool`

Verifica si el timer ha expirado (tiempo restante = 0).

**Ejemplo:**
```php
if ($timerService->isExpired('turn_timer')) {
    // El turno ha terminado por tiempo
}
```

#### `cancelTimer(string $timerName): void`

Cancela y elimina un timer.

**Ejemplo:**
```php
$timerService->cancelTimer('turn_timer');
```

#### `restartTimer(string $timerName, ?int $newDuration = null): void`

Reinicia un timer desde cero. Opcionalmente puede cambiar la duración.

**Ejemplo:**
```php
// Reiniciar con la misma duración
$timerService->restartTimer('turn_timer');

// Reiniciar con nueva duración
$timerService->restartTimer('turn_timer', 120); // Ahora dura 120 segundos
```

#### `hasTimer(string $timerName): bool`

Verifica si existe un timer con ese nombre.

**Ejemplo:**
```php
if ($timerService->hasTimer('turn_timer')) {
    // El timer existe
}
```

#### `getAllTimersInfo(): array`

Obtiene información de todos los timers activos.

**Retorna:**
```php
[
    'turn_timer' => [
        'name' => 'turn_timer',
        'duration' => 90,
        'elapsed' => 45,
        'remaining' => 45,
        'is_expired' => false,
        'is_paused' => false,
    ],
    // ... otros timers
]
```

#### `cancelAllTimers(): void`

Cancela todos los timers.

**Ejemplo:**
```php
$timerService->cancelAllTimers();
```

#### `toArray(): array`

Serializa el estado completo a array para guardar en `game_state`.

**Retorna:**
```php
[
    'timers' => [
        'turn_timer' => [
            'name' => 'turn_timer',
            'duration' => 90,
            'started_at' => '2025-01-15 12:00:00',
            'is_paused' => false,
            'paused_at' => null,
            'total_paused_seconds' => 0,
        ],
    ],
]
```

#### `static fromArray(array $data): self`

Restaura el servicio desde un array serializado.

**Ejemplo:**
```php
$gameState = $match->game_state;
$timerService = TimerService::fromArray($gameState);
```

## Ejemplos de Implementación

### Ejemplo 1: Pictionary - Timer por Turno

Pictionary usa un timer por turno que se reinicia cada vez que cambia el dibujante.

**initialize():**
```php
// Crear TimerService con timer de turno
$turnDuration = $roomSettings['turn_duration'] ?? 90;
$timerService = new TimerService();
$timerService->startTimer('turn_timer', $turnDuration);

// Guardar en game_state
$match->game_state = array_merge([
    'phase' => 'playing',
    'turn_duration' => $turnDuration, // Para referencia en cálculos de puntos
    // ... otros campos
], $timerService->toArray());
```

**getGameStateForPlayer():**
```php
// Obtener tiempo restante del turno
$timerService = TimerService::fromArray($gameState);
$timeRemaining = null;

if ($timerService->hasTimer('turn_timer') && $gameState['phase'] === 'playing') {
    $timeRemaining = $timerService->getRemainingTime('turn_timer');
}

return [
    'time_remaining' => $timeRemaining,
    // ... otros campos
];
```

**confirmAnswer() - Calcular tiempo transcurrido para puntos:**
```php
// Obtener tiempo transcurrido para cálculo de puntuación
$timerService = TimerService::fromArray($gameState);
$secondsElapsed = $timerService->getElapsedTime('turn_timer');

// Usar en ScoreManager
$guesserPoints = $scoreManager->awardPoints($guesserPlayerId, 'correct_answer', [
    'seconds_elapsed' => $secondsElapsed,
    'turn_duration' => $gameState['turn_duration'],
]);
```

**nextTurnInternal() - Reiniciar timer:**
```php
// Reiniciar timer para el nuevo turno
$timerService = TimerService::fromArray($gameState);
$timerService->restartTimer('turn_timer');

// Actualizar game_state
$gameState = array_merge($gameState, $timerService->toArray());
```

### Ejemplo 2: Trivia - Timer por Pregunta

Trivia podría usar un timer diferente para cada pregunta.

```php
// Inicializar timer de pregunta
$timerService = new TimerService();
$timerService->startTimer('question_timer', 30); // 30 segundos por pregunta

// Verificar si expiró
if ($timerService->isExpired('question_timer')) {
    // Nadie respondió a tiempo - pasar a la siguiente pregunta
    $this->nextQuestion($match);
}

// Siguiente pregunta - reiniciar timer
$timerService->restartTimer('question_timer');
```

### Ejemplo 3: Batalla por Turnos - Múltiples Timers

Un juego de batalla podría tener múltiples timers simultáneos.

```php
$timerService = new TimerService();

// Timer del turno principal
$timerService->startTimer('turn_timer', 60);

// Timer de habilidad en cooldown
$timerService->startTimer('fireball_cooldown', 10);

// Timer de efecto temporal
$timerService->startTimer('poison_effect', 15);

// Verificar cada uno
if ($timerService->isExpired('turn_timer')) {
    // Fin del turno
}

if (!$timerService->isExpired('fireball_cooldown')) {
    // Aún no puede usar fireball
    $cooldownRemaining = $timerService->getRemainingTime('fireball_cooldown');
}
```

### Ejemplo 4: Pausar Juego

Pausar el juego cuando un jugador se desconecta.

```php
// Pausar todos los timers
$timerService = TimerService::fromArray($gameState);

if ($timerService->hasTimer('turn_timer')) {
    $timerService->pauseTimer('turn_timer');
}

if ($timerService->hasTimer('round_timer')) {
    $timerService->pauseTimer('round_timer');
}

// Actualizar game_state
$gameState = array_merge($gameState, $timerService->toArray());

// Reanudar cuando vuelve
$timerService->resumeTimer('turn_timer');
$timerService->resumeTimer('round_timer');
```

## Mecánica de Pause/Resume

El sistema de pause acumula correctamente el tiempo pausado:

```php
$timerService = new TimerService();
$timerService->startTimer('test', 60);

// Pasan 10 segundos
sleep(10);
echo $timerService->getElapsedTime('test'); // 10 segundos

// Pausar por 5 segundos
$timerService->pauseTimer('test');
sleep(5);
echo $timerService->getElapsedTime('test'); // Sigue siendo 10 segundos (congelado)

// Reanudar y esperar 5 segundos más
$timerService->resumeTimer('test');
sleep(5);
echo $timerService->getElapsedTime('test'); // 15 segundos (10 + 5, excluyendo los 5 de pausa)
```

**Múltiples pausas:**
```php
$timerService->startTimer('test', 60);

// 10 segundos activo
sleep(10);

// Pausa 1: 5 segundos
$timerService->pauseTimer('test');
sleep(5);
$timerService->resumeTimer('test');

// 10 segundos activo
sleep(10);

// Pausa 2: 3 segundos
$timerService->pauseTimer('test');
sleep(3);
$timerService->resumeTimer('test');

// Total elapsed: 20 segundos (excluyendo 8 segundos de pausa)
echo $timerService->getElapsedTime('test'); // 20
echo $timerService->getRemainingTime('test'); // 40
```

## Tests

### TimerService Tests (23 tests, 73 assertions)

**Cobertura:**
- ✅ Iniciar timer con validación
- ✅ No permitir duración <= 0
- ✅ No permitir nombres duplicados
- ✅ Pause/resume idempotente
- ✅ Cálculo de tiempo transcurrido
- ✅ Cálculo de tiempo restante
- ✅ Detección de timers expirados y activos
- ✅ Cancelación de timers
- ✅ Reinicio de timers (misma o nueva duración)
- ✅ Gestión de múltiples timers simultáneos
- ✅ Cancelar todos los timers
- ✅ Información de todos los timers
- ✅ Excepciones para timers inexistentes
- ✅ Serialización y restauración
- ✅ Round-trip de serialización

**Ejecutar:**
```bash
php artisan test --filter=TimerServiceTest
```

### Timer Tests (21 tests, 57 assertions)

**Cobertura:**
- ✅ Constructor e inicialización
- ✅ Pause/resume mecánica
- ✅ Pause idempotente
- ✅ Resume idempotente
- ✅ Cálculo de tiempo transcurrido
- ✅ Cálculo de tiempo restante
- ✅ Detección de expiración
- ✅ Timer pausado congela el tiempo
- ✅ Resume acumula tiempo pausado correctamente
- ✅ Múltiples pause/resume acumulan correctamente
- ✅ Timer expirado mientras está pausado
- ✅ Serialización de timer activo y pausado
- ✅ Restauración desde array
- ✅ Round-trip de serialización
- ✅ Tiempo transcurrido nunca negativo
- ✅ Tiempo restante nunca negativo
- ✅ Constructor con parámetros de pause

**Ejecutar:**
```bash
php artisan test --filter=TimerTest
```

## Integración con Otros Módulos

### Turn System + Timer System

Combinar turnos con timers es muy común:

```php
// Inicialización
$turnManager = new TurnManager($playerIds, 'sequential', 3);
$timerService = new TimerService();
$timerService->startTimer('turn_timer', 60);

// Guardar en game_state
$match->game_state = array_merge(
    ['phase' => 'playing'],
    $turnManager->toArray(),
    $timerService->toArray()
);

// Verificar si expiró el turno
$timerService = TimerService::fromArray($gameState);
if ($timerService->isExpired('turn_timer')) {
    // Avanzar turno automáticamente por timeout
    $turnManager = TurnManager::fromArray($gameState);
    $turnManager->nextTurn();
    $timerService->restartTimer('turn_timer');

    // Actualizar game_state
    $gameState = array_merge($gameState, $turnManager->toArray(), $timerService->toArray());
}
```

### Scoring System + Timer System

Calcular puntos basados en velocidad de respuesta:

```php
// Obtener tiempo transcurrido
$timerService = TimerService::fromArray($gameState);
$secondsElapsed = $timerService->getElapsedTime('turn_timer');

// Calcular puntos según velocidad
$scoreManager = ScoreManager::fromArray($playerIds, $gameState, $calculator);
$points = $scoreManager->awardPoints($playerId, 'correct_answer', [
    'seconds_elapsed' => $secondsElapsed,
    'turn_duration' => 90,
]);
```

## Limitaciones

1. **No ejecuta callbacks automáticamente**: El módulo NO dispara eventos automáticamente cuando un timer expira. El juego debe verificar `isExpired()` periódicamente (ej. en cada acción o en un polling frontend).

2. **Precisión basada en PHP DateTime**: La precisión está limitada a segundos. No es apropiado para timers de milisegundos.

3. **No persiste entre reinicios del servidor**: Si el servidor se reinicia, los timers continúan desde donde estaban al restaurarse desde `game_state`, pero no hay mecanismo de recuperación automática.

4. **Sin notificaciones push**: Para notificar al frontend cuando expira un timer, se requiere:
   - Polling periódico desde el frontend
   - WebSockets con broadcast manual desde el backend
   - Queue jobs programados (no implementado en este módulo)

## Mejoras Futuras

1. **Callbacks opcionales**: Permitir registrar callbacks que se ejecuten cuando un timer expira
2. **Precisión de milisegundos**: Soporte para timers más precisos
3. **Eventos automáticos**: Broadcast automático cuando un timer expira
4. **Queue integration**: Integración con Laravel Queue para ejecutar acciones automáticas
5. **Timer templates**: Plantillas de timers preconfigurables (ej. "short_turn", "long_turn")

## Resumen de Archivos

```
app/Services/Modules/TimerSystem/
├── TimerService.php           # Servicio principal (290 líneas)
└── Timer.php                  # Value object (228 líneas)

tests/Unit/Services/Modules/TimerSystem/
├── TimerServiceTest.php       # 23 tests, 73 assertions
└── TimerTest.php              # 21 tests, 57 assertions

docs/modules/optional/
└── TIMER_SYSTEM.md            # Esta documentación
```

## Conclusión

El **Timer System Module** proporciona una forma genérica y robusta de gestionar temporizadores en juegos. Su diseño simple y serializable lo hace fácil de integrar con cualquier juego que requiera límites de tiempo, timeouts, o cooldowns.

**Ventajas:**
- ✅ API simple e intuitiva
- ✅ Soporte completo para pause/resume
- ✅ Múltiples timers simultáneos
- ✅ Serialización completa
- ✅ Tests exhaustivos (44 tests, 130 assertions)
- ✅ Zero dependencies externas

**Recomendado para:**
- Juegos con turnos cronometrados (Pictionary, Trivia, etc.)
- Juegos con cooldowns de habilidades
- Juegos con fases temporales
- Cualquier mecánica que requiera tracking de tiempo
