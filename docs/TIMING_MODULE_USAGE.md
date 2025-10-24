# TimingModule - Guía de Uso

## Resumen

El TimingModule es un sistema frontend-controlado para manejar countdowns y delays entre eventos de juego, con protección contra race conditions cuando múltiples jugadores terminan su countdown simultáneamente.

## Arquitectura

```
Backend (Laravel)                     Frontend (JavaScript)
─────────────────                     ─────────────────────

1. Evento termina ronda
   ↓
2. Emite RoundEndedEvent
   {
     results: {...},
     timing: {
       auto_next: true,
       delay: 5,
       action: 'next_round',
       message: 'Siguiente pregunta'
     }
   }
   ↓ [WebSocket via Reverb]
   ↓
                                      3. BaseGameClient recibe evento
                                         ↓
                                      4. TimingModule.processTimingPoint()
                                         ↓
                                      5. Muestra countdown "Siguiente pregunta en 5s..."
                                         ↓
                                      6. Countdown termina (5 segundos)
                                         ↓
                                      7. POST /api/games/{match}/start-next-round
8. GameController::startNextRound()   ←─
   ↓
9. Lock mechanism (Cache::add)
   - Solo primer cliente avanza
   - Otros reciben 409 Conflict
   ↓
10. engine->advancePhase($match)
    ↓
11. Emite RoundStartedEvent
    ↓ [WebSocket]
    ↓
                                      12. Todos los clientes se sincronizan
                                          ↓
                                      13. Muestra nueva pregunta/ronda
```

---

## Componentes del Sistema

### 1. Backend: Timing Metadata en Eventos

Los eventos pueden incluir metadata de timing:

```php
event(new RoundEndedEvent(
    match: $match,
    roundNumber: $currentRound,
    results: $results,
    scores: $scores,
    timing: [
        'auto_next' => true,           // ¿Auto-continuar?
        'delay' => 5,                  // Segundos a esperar
        'action' => 'next_round',      // Acción a realizar
        'message' => 'Siguiente pregunta'  // Mensaje para countdown (opcional)
    ]
));
```

**Eventos soportados:**
- `GameStartedEvent` - Timing para iniciar primera ronda
- `RoundEndedEvent` - Timing para siguiente ronda
- `RoundStartedEvent` - Timing para duración de la ronda

### 2. Frontend: TimingModule.js

Ubicación: `resources/js/modules/TimingModule.js`

**API Principal:**

```javascript
// Procesar timing point de un evento
await timing.processTimingPoint(timingMeta, callback, element);

// Countdown visual
await timing.delayWithCountdown(seconds, element, template, name);

// Delay simple sin UI
await timing.delay(seconds);

// Cancelar countdown
timing.cancelCountdown(name);
```

### 3. BaseGameClient - Integración Automática

`BaseGameClient` ya tiene TimingModule integrado:

```javascript
class BaseGameClient {
    constructor(config) {
        // ...
        this.timing = new TimingModule();
    }

    async handleRoundEnded(event) {
        // Procesa timing automáticamente
        if (event.timing) {
            await this.timing.processTimingPoint(
                event.timing,
                () => this.notifyReadyForNextRound(),
                this.getCountdownElement()
            );
        }
    }

    async notifyReadyForNextRound() {
        // Llama al endpoint con protección de race condition
        await fetch(`/api/games/${this.matchId}/start-next-round`, {...});
    }

    getCountdownElement() {
        // Los juegos sobrescriben esto
        return null;
    }
}
```

### 4. Backend: Lock Mechanism

Protección contra race conditions en `GameMatch`:

```php
// Intenta adquirir lock (solo el primero lo consigue)
if (!$match->acquireRoundLock()) {
    return response()->json([
        'success' => false,
        'error' => 'Another client is already starting the round'
    ], 409);
}

try {
    $engine->advancePhase($match);
    return response()->json(['success' => true]);
} finally {
    $match->releaseRoundLock();
}
```

---

## Implementación en un Juego

### Paso 1: Backend - Añadir Timing a Eventos

En `TriviaEngine::endCurrentRound()`:

```php
protected function endCurrentRound(GameMatch $match): void
{
    // 1. Calcular resultados
    $results = $this->calculateResults($match);

    // 2. Cambiar fase
    $gameState = $match->game_state;
    $gameState['phase'] = 'results';
    $match->game_state = $gameState;
    $match->save();

    // 3. Emitir evento CON timing metadata
    event(new RoundEndedEvent(
        match: $match,
        roundNumber: $this->getCurrentRound($match),
        results: $results,
        scores: $this->getScores($gameState),
        timing: [
            'auto_next' => true,
            'delay' => 5,
            'action' => 'next_round',
            'message' => 'Siguiente pregunta'
        ]
    ));

    // ⚠️ NO programar nada más aquí - el frontend controlará el timing
}
```

### Paso 2: Frontend - Implementar en Juego Específico

En `trivia-game.js`:

```javascript
class TriviaGame extends BaseGameClient {
    constructor(config) {
        super(config);

        // Elementos DOM
        this.questionWaiting = document.getElementById('question-waiting');
    }

    /**
     * Sobrescribir para proporcionar elemento de countdown
     */
    getCountdownElement() {
        // Retornar elemento donde mostrar "Siguiente pregunta en 5s..."
        return this.questionWaiting.querySelector('p');
    }

    /**
     * Handler específico de Trivia
     */
    async handleRoundEndedTrivia(event) {
        console.log('🏁 [Trivia] Round ended:', event);

        // 1. Llamar al handler base (procesa scores y timing)
        await super.handleRoundEnded(event);

        // 2. Mostrar resultados (lógica específica del juego)
        this.showResults(event.results);

        // El countdown se mostrará automáticamente si hay timing metadata
    }

    handleRoundStartedTrivia(event) {
        console.log('🎬 [Trivia] Round started:', event);

        // Mostrar nueva pregunta
        this.showQuestion(event.game_state.current_question);
    }
}
```

### Paso 3: Configuración Opcional en config.json

```json
{
    "name": "Trivia",
    "timing": {
        "delay_between_rounds": 5,
        "auto_next_round": true,
        "countdown_warning_threshold": 3
    }
}
```

---

## Casos de Uso

### Caso 1: Auto-Avanzar a Siguiente Ronda (Trivia)

```php
// Backend
event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => true,
        'delay' => 5,
        'message' => 'Siguiente pregunta'
    ]
));
```

Resultado:
1. Muestra resultados
2. Countdown "Siguiente pregunta en 5s..."
3. Automáticamente inicia siguiente ronda

### Caso 2: NO Auto-Avanzar (Pictionary)

```php
// Backend
event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => false,  // NO avanzar automáticamente
        'delay' => 0
    ]
));
```

Resultado:
1. Muestra resultados
2. NO hay countdown
3. Espera acción manual (botón "Siguiente ronda")

### Caso 3: Delay Variable según Condición

```php
// Backend
$isLastRound = $currentRound === $totalRounds;
$delay = $isLastRound ? 10 : 5;  // Más tiempo en última ronda

event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => true,
        'delay' => $delay,
        'message' => $isLastRound ? 'Juego terminado' : 'Siguiente pregunta'
    ]
));
```

### Caso 4: Countdown de Duración de Ronda

```php
// Backend - al iniciar ronda
event(new RoundStartedEvent(
    // ...
    timing: [
        'duration' => 15,          // Duración de la ronda
        'countdown_visible' => true,
        'warning_threshold' => 5   // Cambiar color cuando quedan 5s
    ]
));
```

---

## Protección contra Race Conditions

### El Problema

Cuando 5 jugadores terminan su countdown simultáneamente:

```
Player 1: POST /start-next-round  ─┐
Player 2: POST /start-next-round  ─┤
Player 3: POST /start-next-round  ─┼─> Sin protección: ¡5 rondas avanzadas!
Player 4: POST /start-next-round  ─┤
Player 5: POST /start-next-round  ─┘
```

### La Solución

Lock mechanism con `Cache::add()` (operación atómica):

```php
// GameController::startNextRound()
if (!$match->acquireRoundLock()) {
    return response()->json([
        'success' => false,
        'error' => 'Another client is already starting the round'
    ], 409); // 409 Conflict
}

try {
    // Solo el primer cliente llega aquí
    $engine->advancePhase($match);
} finally {
    $match->releaseRoundLock();
}
```

Resultado:
```
Player 1: POST → 200 OK (avanza la ronda)     ✅
Player 2: POST → 409 Conflict (lock ocupado)  ⏸️
Player 3: POST → 409 Conflict (lock ocupado)  ⏸️
Player 4: POST → 409 Conflict (lock ocupado)  ⏸️
Player 5: POST → 409 Conflict (lock ocupado)  ⏸️

Todos reciben RoundStartedEvent y se sincronizan ✅
```

---

## Testing de Race Conditions

### Método 1: Abrir Múltiples Tabs

1. Abrir 5 tabs del mismo juego
2. Dejar que el countdown termine en todas
3. Verificar logs:
   - Solo 1 tab debe ver "✅ Successfully started next round"
   - Otras 4 deben ver "⏸️ Another client is starting the round"
   - Todas deben sincronizarse con RoundStartedEvent

### Método 2: Script de Prueba

```bash
# Enviar 10 requests simultáneos
for i in {1..10}; do
  curl -X POST http://gambito.test/api/games/1/start-next-round \
    -H "Content-Type: application/json" \
    -d '{"room_code":"ABC123"}' &
done
wait
```

Verificar logs:
- Solo 1 request debe adquirir el lock
- Otros 9 deben recibir 409 Conflict

---

## Debugging

### Logs del TimingModule

```javascript
// TimingModule tiene logging detallado
this.timing.configure({ debug: true });

// Console output:
// ⏰ [TimingModule] Processing timing point: {...}
// ⏰ [TimingModule] Countdown default: 5s remaining
// ⏰ [TimingModule] Countdown default: 4s remaining
// ...
// ⏰ [TimingModule] Countdown default completed
```

### Logs del Backend

```php
// GameController logs
\Log::info('📥 [API] startNextRound request received');
\Log::info('🔒 [API] Lock acquired, advancing to next round');
\Log::info('✅ [API] Next round started successfully');

// GameMatch logs
\Log::info('🔒 [Lock] Round lock acquired');
\Log::info('🔓 [Lock] Round lock released');
\Log::warning('⏸️ [Lock] Round lock already held by another client');
```

---

## Checklist de Implementación

### Backend

- [ ] Añadir timing metadata a eventos (RoundEndedEvent, GameStartedEvent)
- [ ] Configurar timing en config.json del juego
- [ ] NO programar delays manuales (eliminar Jobs o setTimeout)
- [ ] Emitir eventos con metadata correcta

### Frontend

- [ ] Extender BaseGameClient (ya tiene TimingModule integrado)
- [ ] Implementar `getCountdownElement()` para retornar elemento DOM
- [ ] Llamar a `super.handleRoundEnded()` en handlers específicos
- [ ] Verificar que elementos DOM existen en el HTML

### Testing

- [ ] Probar countdown visual (se muestra correctamente)
- [ ] Probar auto-avance (ronda avanza automáticamente)
- [ ] Probar race condition (abrir múltiples tabs)
- [ ] Verificar logs del lock mechanism
- [ ] Probar con 0 delay (avance inmediato)
- [ ] Probar con auto_next = false (sin auto-avance)

---

## Próximos Pasos

1. **Implementar en Trivia** - Añadir timing metadata a TriviaEngine
2. **Testing con múltiples jugadores** - Verificar race condition protection
3. **Implementar en Pictionary** - Sin auto-avance, delay manual
4. **Documentar convenciones** - Añadir a DEVELOPMENT_CONVENTIONS.md
5. **Crear ejemplos** - Casos de uso comunes para otros juegos

---

## Soporte

Si tienes problemas:

1. Verifica que el evento incluye `timing` metadata
2. Revisa logs del navegador (⏰ [TimingModule])
3. Revisa logs de Laravel (storage/logs/laravel.log)
4. Verifica que `getCountdownElement()` retorna un elemento válido
5. Prueba con `debug: true` en TimingModule config
