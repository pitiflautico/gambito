# Estrategia de Timeout para Timers

## Estado Actual

### ¿Cómo funciona actualmente el timeout en Pictionary?

**Actualmente NO hay auto-timeout implementado.**

El flujo actual es:
1. Timer se inicia cuando comienza el turno
2. Frontend **debería** mostrar countdown visual (pero no está implementado)
3. Frontend **debería** llamar a `/api/pictionary/advance-phase` cuando el tiempo expira (pero no está implementado)
4. **El backend espera acción manual** - no avanza automáticamente

**Problemas identificados:**
- ❌ Si nadie llama a `advance-phase`, el turno se queda colgado indefinidamente
- ❌ El timer expira en el backend pero el juego no hace nada
- ❌ Jugadores pueden seguir dibujando/respondiendo aunque el tiempo acabó
- ❌ No hay sincronización automática frontend-backend

## Opciones de Implementación

### Opción 1: Middleware/Check en cada acción ⭐ RECOMENDADO

**Concepto:** Verificar si el timer expiró antes de procesar cualquier acción.

**Implementación:**
```php
// PictionaryEngine.php
public function processAction(GameMatch $match, Player $player, string $action, array $data): array
{
    // CRÍTICO: Verificar timeout ANTES de procesar acción
    $this->checkAndHandleTimeout($match);

    // Luego procesar la acción normal
    return match ($action) {
        'draw' => $this->handleDrawAction($match, $player, $data),
        'answer' => $this->handleAnswerAction($match, $player, $data),
        'confirm_answer' => $this->handleConfirmAnswer($match, $player, $data),
        default => ['success' => false, 'error' => 'Unknown action'],
    };
}

private function checkAndHandleTimeout(GameMatch $match): void
{
    $gameState = $match->game_state;

    // Solo verificar si estamos en fase de juego
    if ($gameState['phase'] !== 'playing') {
        return;
    }

    $timerService = TimerService::fromArray($gameState);

    if ($timerService->hasTimer('turn_timer') && $timerService->isExpired('turn_timer')) {
        Log::info("Timer expired - auto-advancing turn", [
            'match_id' => $match->id,
            'drawer_id' => $gameState['current_drawer_id'],
        ]);

        // Auto-avanzar a la siguiente fase
        $this->advancePhase($match);

        // Broadcast evento de timeout
        $roomCode = $match->room->code ?? 'UNKNOWN';
        event(new TurnExpiredEvent($roomCode, $gameState['current_drawer_id']));
    }
}
```

**Ventajas:**
- ✅ Simple de implementar
- ✅ No requiere infraestructura adicional
- ✅ Funciona con la arquitectura actual
- ✅ Se verifica en cada interacción (draw, answer, etc.)
- ✅ Reactivo pero efectivo

**Desventajas:**
- ⚠️ Solo se verifica cuando hay acción del jugador
- ⚠️ Si nadie interactúa, el timeout no se detecta hasta la siguiente acción
- ⚠️ Puede haber lag de segundos/minutos si todos están AFK

**Cuándo usar:**
- Juegos donde hay interacción constante
- Pictionary (dibujando continuamente)
- Juegos de turnos rápidos

---

### Opción 2: Laravel Queue/Scheduler

**Concepto:** Programar un job que se ejecute automáticamente cuando el timer expire.

**Implementación:**
```php
// Al iniciar el timer
$timerService->startTimer('turn_timer', 90);

// Programar job para ejecutar en 90 segundos
dispatch(new CheckTimerExpiredJob($match->id, 'turn_timer'))
    ->delay(now()->addSeconds(90));

// CheckTimerExpiredJob.php
class CheckTimerExpiredJob implements ShouldQueue
{
    public function __construct(
        private int $matchId,
        private string $timerName
    ) {}

    public function handle(): void
    {
        $match = GameMatch::find($this->matchId);
        if (!$match) return;

        $gameState = $match->game_state;
        $timerService = TimerService::fromArray($gameState);

        if ($timerService->isExpired($this->timerName)) {
            // Auto-avanzar turno
            $engine = new PictionaryEngine();
            $engine->advancePhase($match);

            // Broadcast
            event(new TurnExpiredEvent(...));
        }
    }
}
```

**Ventajas:**
- ✅ Timeout se detecta automáticamente sin interacción
- ✅ Preciso al segundo
- ✅ Desacoplado del flujo principal
- ✅ Escalable para muchas partidas simultáneas

**Desventajas:**
- ⚠️ Requiere Laravel Queue configurado (Redis, Database, etc.)
- ⚠️ Más complejo de debuggear
- ⚠️ Si el job falla, el timeout no se procesa
- ⚠️ Overhead de infraestructura

**Cuándo usar:**
- Producción con muchas partidas simultáneas
- Juegos donde los jugadores pueden estar AFK
- Cuando se requiere precisión exacta

---

### Opción 3: WebSocket Heartbeat

**Concepto:** El servidor envía "pings" periódicos vía WebSocket, verificando timers en cada ping.

**Implementación:**
```php
// Scheduler (app/Console/Kernel.php)
protected function schedule(Schedule $schedule)
{
    // Cada segundo, verificar todos los timers activos
    $schedule->call(function () {
        $activeMatches = GameMatch::whereIn('status', ['waiting', 'in_progress'])->get();

        foreach ($activeMatches as $match) {
            $this->checkMatchTimers($match);
        }
    })->everySecond();
}

private function checkMatchTimers(GameMatch $match): void
{
    $gameState = $match->game_state;

    if (!isset($gameState['timers'])) return;

    $timerService = TimerService::fromArray($gameState);

    foreach ($timerService->getTimers() as $timer) {
        if ($timer->isExpired()) {
            // Broadcast tiempo restante = 0
            event(new TimerExpiredEvent($match->room->code, $timer->getName()));

            // Auto-avanzar si es necesario
            $engine = $this->getEngine($match);
            $engine->checkAndHandleTimeout($match);
        } else {
            // Broadcast tiempo restante cada segundo
            event(new TimerUpdateEvent(
                $match->room->code,
                $timer->getName(),
                $timer->getRemainingTime()
            ));
        }
    }
}
```

**Frontend recibe actualizaciones:**
```javascript
// Echo listener
window.Echo.channel(`room.${roomCode}`)
    .listen('TimerUpdateEvent', (e) => {
        // Actualizar UI cada segundo con datos del servidor
        updateTimerDisplay(e.timer_name, e.time_remaining);
    })
    .listen('TimerExpiredEvent', (e) => {
        // Mostrar alerta de timeout
        showTimeoutAlert();
    });
```

**Ventajas:**
- ✅ Actualización en tiempo real perfecta
- ✅ Frontend sincronizado al 100% con backend
- ✅ No depende de interacción del usuario
- ✅ Broadcast automático a todos los jugadores
- ✅ Óptimo para experiencia de usuario

**Desventajas:**
- ⚠️ Muy costoso en recursos (verificar cada segundo)
- ⚠️ Muchos broadcasts (1 por segundo por partida)
- ⚠️ Requiere Reverb/Pusher corriendo constantemente
- ⚠️ Complejo de implementar correctamente

**Cuándo usar:**
- Juegos ultra-competitivos donde cada segundo importa
- Producción con infraestructura robusta
- Cuando la UX en tiempo real es crítica

---

## Estrategia Híbrida Recomendada 🎯

**Combinar Opción 1 + Frontend Countdown + Broadcast ocasional**

### Backend: Opción 1 (Check en cada acción)
```php
// En cada processAction(), verificar timeout
public function processAction(...)
{
    $this->checkAndHandleTimeout($match);
    // ... resto del código
}
```

### Frontend: Countdown Local + Sync
```javascript
// Recibir tiempo inicial del backend
let timeRemaining = gameState.time_remaining; // 90

// Countdown local
const timerInterval = setInterval(() => {
    timeRemaining--;
    updateTimerUI(timeRemaining);

    if (timeRemaining <= 0) {
        clearInterval(timerInterval);
        // Llamar a advance-phase automáticamente
        fetch('/api/pictionary/advance-phase', {
            method: 'POST',
            body: JSON.stringify({ match_id, room_code })
        });
    }
}, 1000);

// Sincronizar cada 10 segundos con el backend
setInterval(() => {
    fetch(`/api/rooms/${roomCode}/game-state`)
        .then(res => res.json())
        .then(data => {
            // Ajustar si hay desfase
            const serverTime = data.time_remaining;
            if (Math.abs(timeRemaining - serverTime) > 2) {
                timeRemaining = serverTime; // Corregir desfase
            }
        });
}, 10000);
```

### Broadcast cuando hay eventos importantes
```php
// Solo broadcast cuando:
// - Timer inicia (nuevo turno)
// - Timer expira
// - Timer se pausa/reanuda

// NO broadcast cada segundo
```

**Ventajas de la estrategia híbrida:**
- ✅ Experiencia fluida en el frontend (cuenta atrás suave)
- ✅ Backend robusto (auto-timeout si frontend falla)
- ✅ Eficiente en recursos (no saturar con broadcasts)
- ✅ Sincronización periódica para evitar desfases
- ✅ Funciona incluso si WebSocket falla

---

## Tareas de Implementación

### Fase 1: Backend Auto-Timeout (CRÍTICO) 🔴

**Task 11.1: Implementar checkAndHandleTimeout en PictionaryEngine**
- [ ] Agregar método `checkAndHandleTimeout()` en PictionaryEngine
- [ ] Llamar en `processAction()` antes de procesar cualquier acción
- [ ] Crear evento `TurnExpiredEvent`
- [ ] Tests: Verificar que auto-avanza cuando timer expira
- [ ] Tests: Verificar que NO avanza si timer está activo

**Archivos afectados:**
- `games/pictionary/PictionaryEngine.php`
- `games/pictionary/Events/TurnExpiredEvent.php` (nuevo)
- `tests/Unit/Games/Pictionary/TimeoutTest.php` (nuevo)

**Prioridad:** ALTA - Sin esto, los juegos pueden quedarse colgados

---

### Fase 2: Frontend Countdown Visual 🟡

**Task 11.2: Implementar countdown visual en canvas**
- [ ] Agregar componente de timer en `canvas.blade.php`
- [ ] Countdown local con JavaScript/Vue
- [ ] Estilos: verde > 30s, amarillo 10-30s, rojo < 10s
- [ ] Alertas visuales/sonoras cuando quedan 10s, 5s
- [ ] Auto-llamar a `advance-phase` cuando llega a 0

**Archivos afectados:**
- `games/pictionary/views/canvas.blade.php`
- `resources/js/components/TimerCountdown.vue` (nuevo, opcional)

**Prioridad:** MEDIA - Mejora la UX pero no es crítico

---

### Fase 3: Sincronización Frontend-Backend 🟡

**Task 11.3: Polling periódico para sync**
- [ ] Llamada cada 10 segundos a `/api/rooms/{code}/game-state`
- [ ] Ajustar timer local si hay desfase > 2 segundos
- [ ] Manejar caso de reconexión (usuario perdió conexión)

**Archivos afectados:**
- `games/pictionary/views/canvas.blade.php`

**Prioridad:** MEDIA - Previene desfases

---

### Fase 4: WebSocket Events (Opcional) 🟢

**Task 11.4: Broadcast eventos de timer**
- [ ] Evento `TimerStartedEvent` cuando inicia nuevo turno
- [ ] Evento `TurnExpiredEvent` cuando expira (ya creado en Fase 1)
- [ ] Frontend escucha y sincroniza timer local
- [ ] NO broadcast cada segundo (solo en eventos importantes)

**Archivos afectados:**
- `games/pictionary/Events/TimerStartedEvent.php` (nuevo)
- `games/pictionary/views/canvas.blade.php`

**Prioridad:** BAJA - Nice to have, no crítico

---

### Fase 5: Generalizar para todos los juegos 🔵

**Task 11.5: Crear TimeoutHandler genérico**
- [ ] Trait o clase base `HandlesTimeouts`
- [ ] Método `checkAndHandleTimeout()` reutilizable
- [ ] Documentar patrón en TIMER_SYSTEM.md
- [ ] Ejemplos para Trivia, UNO, etc.

**Archivos afectados:**
- `app/Services/Modules/TimerSystem/TimeoutHandler.php` (nuevo)
- `docs/modules/optional/TIMER_SYSTEM.md`

**Prioridad:** BAJA - Optimización futura

---

## Decisión Inmediata para Pictionary

**Recomendación: Implementar Fase 1 (Backend Auto-Timeout) AHORA**

1. **Crear Task 11.1** como siguiente tarea después de commit Task 11.0
2. **Implementar `checkAndHandleTimeout()`** en PictionaryEngine
3. **Tests completos** para verificar auto-timeout
4. **Documentar** el patrón para otros juegos

**Fases 2-4** pueden hacerse después, en paralelo con otros módulos.

**Fase 5** es optimización futura, no urgente.

---

## Comparación de Opciones

| Criterio | Opción 1: Middleware | Opción 2: Queue/Scheduler | Opción 3: WebSocket Heartbeat |
|----------|---------------------|---------------------------|-------------------------------|
| **Complejidad** | Baja ⭐⭐⭐ | Media ⭐⭐ | Alta ⭐ |
| **Recursos** | Mínimos ⭐⭐⭐ | Moderados ⭐⭐ | Altos ⭐ |
| **Precisión** | ±1-5s ⭐⭐ | Exacto ⭐⭐⭐ | Exacto ⭐⭐⭐ |
| **Sin interacción** | No ❌ | Sí ✅ | Sí ✅ |
| **Setup** | Ninguno ⭐⭐⭐ | Redis/Queue ⭐⭐ | Reverb/Pusher ⭐ |
| **Escalabilidad** | Alta ⭐⭐⭐ | Alta ⭐⭐⭐ | Media ⭐⭐ |
| **Debugging** | Fácil ⭐⭐⭐ | Medio ⭐⭐ | Difícil ⭐ |

**Ganador para MVP: Opción 1 (Middleware)**
**Ganador para Producción: Opción 1 + Frontend Countdown (Híbrido)**

---

## Próximos Pasos

1. ✅ **Commit Task 11.0** - Timer System Module base
2. 🔴 **Task 11.1** - Backend Auto-Timeout (CRÍTICO)
3. 🟡 **Task 11.2** - Frontend Countdown Visual
4. 🟡 **Task 11.3** - Sincronización periódica
5. ⬜ Continuar con otros módulos (Roles, etc.)

---

## Notas Técnicas

### ¿Por qué no usar cron jobs?

Los cron jobs tradicionales (minuto a minuto) son demasiado lentos para juegos. Laravel Scheduler permite `everySecond()`, pero verificar TODAS las partidas cada segundo es costoso.

### ¿Queue vs Scheduler?

- **Queue**: Para ejecutar acciones asíncronas bajo demanda (dispatch cuando se inicia el timer)
- **Scheduler**: Para ejecutar tareas periódicas (cada segundo, verificar todos los timers)

Para auto-timeout, **Queue es mejor** (Opción 2) porque solo ejecuta cuando es necesario.

### ¿Por qué Opción 1 es suficiente?

En Pictionary:
- El drawer está dibujando constantemente (muchas acciones `draw`)
- Los guessers están viendo y pensando
- Es raro que NADIE interactúe durante 90 segundos
- Si todos están AFK, el juego puede esperar hasta la siguiente interacción

Para juegos con menos interacción (ej. ajedrez), Opción 2 sería mejor.

---

**Documento creado:** 2025-01-21
**Autor:** Claude Code
**Versión:** 1.0
