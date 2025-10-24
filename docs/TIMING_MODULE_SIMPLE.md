# TimingModule - Enfoque Simplificado (Frontend-Controlled)

## Filosofía

**Frontend controla el timing visual, Backend es fuente de verdad del estado.**

- ✅ Backend: Emite eventos con metadata de timing (`autoNext`, `delay`)
- ✅ Frontend: Muestra countdowns y ejecuta delays
- ✅ Frontend: Notifica al backend cuando está listo para continuar
- ✅ Backend: Valida y procesa la continuación

**Sin Jobs, sin queue workers. Solo setTimeout en el frontend.**

---

## Arquitectura

```
Backend                           Frontend
───────                           ────────

emit RoundEndedEvent ────────────> Recibe evento
{                                    │
  autoNext: true,                    │ Muestra resultados
  delay: 5                           │ Inicia countdown (5s)
}                                    │
                                     ▼
                              Countdown termina
                                     │
POST /start-next-round <─────────── Frontend notifica
│
├─ Validar estado
├─ Avanzar ronda
└─ emit RoundStartedEvent ───────> Recibe evento
                                     │
                                     ▼
                              Muestra pregunta
```

---

## 1. Backend - Metadata en Eventos

En lugar de programar delays en backend, **solo enviamos metadata**:

### RoundEndedEvent

```php
class RoundEndedEvent implements ShouldBroadcast
{
    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->roundNumber,
            'results' => $this->results,
            'scores' => $this->scores,

            // ⭐ Metadata de timing
            'timing' => [
                'auto_next' => true,      // ¿Auto-continuar?
                'delay' => 5,             // Segundos a esperar
                'action' => 'next_round'  // Qué hacer después
            ]
        ];
    }
}
```

### GameStartedEvent

```php
class GameStartedEvent implements ShouldBroadcast
{
    public function broadcastWith(): array
    {
        return [
            'game_state' => $this->gameState,
            'players' => $this->players,

            // ⭐ Metadata de timing
            'timing' => [
                'auto_start' => true,
                'delay' => 3,
                'action' => 'start_first_round'
            ]
        ];
    }
}
```

---

## 2. Frontend - TimingModule

### Ubicación
`resources/js/modules/TimingModule.js`

### API Simplificada

```javascript
class TimingModule {
    constructor() {
        this.activeCountdowns = new Map();
    }

    /**
     * Procesar metadata de timing de un evento
     *
     * @param {Object} timingMeta - {auto_next: true, delay: 5, action: 'next_round'}
     * @param {Function} callback - Función a ejecutar después del delay
     * @param {HTMLElement} element - Elemento para mostrar countdown (opcional)
     */
    async processEventTiming(timingMeta, callback, element = null) {
        if (!timingMeta || !timingMeta.auto_next) {
            return; // No hay auto-continuación
        }

        const { delay, action } = timingMeta;

        // Si hay elemento, mostrar countdown visual
        if (element) {
            await this.delayWithCountdown(delay, element, `Continuando en {seconds}s...`);
        } else {
            await this.delay(delay);
        }

        // Ejecutar callback
        if (callback) {
            callback();
        }
    }

    /**
     * Countdown visual simple
     */
    async delayWithCountdown(seconds, element, template = '{seconds}s') {
        return new Promise(resolve => {
            let remaining = seconds;

            const updateElement = () => {
                if (element) {
                    element.textContent = template.replace('{seconds}', remaining);

                    // Añadir clase warning si quedan pocos segundos
                    if (remaining <= 3) {
                        element.classList.add('countdown-warning');
                    }
                }
            };

            updateElement(); // Inicial

            const interval = setInterval(() => {
                remaining--;
                updateElement();

                if (remaining <= 0) {
                    clearInterval(interval);
                    resolve();
                }
            }, 1000);
        });
    }

    /**
     * Delay simple sin UI
     */
    delay(seconds) {
        return new Promise(resolve => setTimeout(resolve, seconds * 1000));
    }
}

export default TimingModule;
```

---

## 3. Integración en BaseGameClient

```javascript
class BaseGameClient {
    constructor(config) {
        // ... existing

        // Inicializar TimingModule
        this.timing = new TimingModule();
    }

    /**
     * Handler genérico para RoundEnded
     */
    async handleRoundEnded(event) {
        console.log('🏁 [Base] Round ended:', event);

        // Procesar timing metadata
        if (event.timing) {
            await this.timing.processEventTiming(
                event.timing,
                () => this.notifyReadyForNextRound(),
                this.getCountdownElement() // Elemento donde mostrar countdown
            );
        }
    }

    /**
     * Notificar al backend que frontend está listo para continuar
     */
    notifyReadyForNextRound() {
        fetch(`/api/games/${this.matchId}/start-next-round`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.getCsrfToken()
            },
            body: JSON.stringify({
                room_code: this.roomCode
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                console.error('Error starting next round:', data.error);
            }
            // RoundStartedEvent llegará por WebSocket
        })
        .catch(err => {
            console.error('Network error starting next round:', err);
        });
    }

    /**
     * Obtener elemento para countdown (sobrescribir en juegos específicos)
     */
    getCountdownElement() {
        return document.getElementById('countdown-message');
    }
}
```

---

## 4. Backend - Endpoint para Continuar

### Route

```php
// routes/api.php
Route::post('/games/{match}/start-next-round', [GameController::class, 'startNextRound']);
```

### Controller

```php
class GameController extends Controller
{
    public function startNextRound(Request $request, GameMatch $match)
    {
        try {
            // Validar que el juego está en estado correcto
            if ($match->game_state['phase'] !== 'results') {
                return response()->json([
                    'success' => false,
                    'error' => 'Game is not in results phase'
                ], 400);
            }

            // Obtener engine del juego
            $engine = $this->getGameEngine($match);

            // Iniciar siguiente ronda
            $engine->advanceToNextRound($match);

            return response()->json([
                'success' => true,
                'message' => 'Next round started'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

---

## 5. Ejemplo Completo: Trivia

### Backend - endCurrentRound()

```php
protected function endCurrentRound(GameMatch $match): void
{
    $gameState = $match->game_state;

    // 1. Calcular resultados
    $results = $this->calculateResults($match);

    // 2. Cambiar fase a 'results'
    $gameState['phase'] = 'results';
    $gameState['question_results'] = $results;
    $match->game_state = $gameState;
    $match->save();

    // 3. Emitir RoundEndedEvent CON metadata de timing
    event(new RoundEndedEvent(
        match: $match,
        roundNumber: $this->getCurrentRound($match),
        results: $results,
        scores: $this->getScores($gameState),
        timing: [
            'auto_next' => true,    // ⭐ Auto-continuar
            'delay' => 5,           // ⭐ Esperar 5 segundos
            'action' => 'next_round'
        ]
    ));

    // ⭐ NO programamos nada aquí. Frontend controlará el timing.
}
```

### Frontend - TriviaGame

```javascript
class TriviaGame extends BaseGameClient {
    async handleRoundEndedTrivia(event) {
        console.log('🏁 [Trivia] Round ended:', event);

        // 1. Mostrar resultados
        this.showResults(event.results);

        // 2. Procesar timing (si hay auto_next, hará countdown y llamará al backend)
        if (event.timing) {
            const countdownElement = this.questionWaiting.querySelector('p');

            await this.timing.processEventTiming(
                event.timing,
                () => this.notifyReadyForNextRound(),
                countdownElement // Mostrar "Siguiente pregunta en 5s..."
            );
        }
    }

    handleRoundStartedTrivia(event) {
        console.log('🎬 [Trivia] Round started:', event);

        // Mostrar nueva pregunta
        this.showQuestion(event.game_state.current_question);
    }
}
```

---

## 6. Configuración en config.json

```json
{
    "name": "Trivia",
    "timing": {
        "round_duration": 15,
        "delay_between_rounds": 5,
        "delay_show_results": 2,
        "countdown_warning_threshold": 3,
        "auto_next_round": true
    }
}
```

El backend lee esta configuración y la usa en los eventos:

```php
$timingConfig = $config['timing'];

event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => $timingConfig['auto_next_round'],
        'delay' => $timingConfig['delay_between_rounds']
    ]
));
```

---

## 7. Ventajas de este Enfoque

✅ **Simple**: No requiere Jobs ni queue workers
✅ **Frontend en control**: UX fluida sin lag del backend
✅ **Backend valida**: Sigue siendo fuente de verdad
✅ **Fácil de debuggear**: Todo el timing visible en browser console
✅ **Flexible**: Fácil ajustar delays sin tocar backend
✅ **Sincronizado**: WebSockets mantienen todo en sync

---

## 8. Flujo Completo Ejemplo

```
1. Jugador responde pregunta
         ↓
2. Backend: TriviaEngine::processAction()
         ↓
3. Backend emite RoundEndedEvent {timing: {auto_next: true, delay: 5}}
         ↓
4. Frontend recibe evento
         ↓
5. Frontend muestra resultados
         ↓
6. Frontend muestra countdown "Siguiente pregunta en 5s..."
         ↓
7. Countdown termina (5 segundos después)
         ↓
8. Frontend llama POST /api/games/{id}/start-next-round
         ↓
9. Backend valida y llama engine.advanceToNextRound()
         ↓
10. Backend emite RoundStartedEvent
         ↓
11. Frontend recibe evento y muestra nueva pregunta
```

---

## 9. Próximos Pasos

1. ✅ Diseño completado
2. Implementar TimingModule.js (frontend)
3. Añadir metadata `timing` a eventos (RoundEndedEvent, GameStartedEvent)
4. Crear endpoint `/api/games/{id}/start-next-round`
5. Integrar timing en BaseGameClient
6. Probar con Trivia
7. Documentar convenciones

---

## 10. Casos Especiales

### Sin Auto-Next (Pictionary)

```php
event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => false,  // NO auto-continuar
        'delay' => 0,
        'action' => null
    ]
));
```

Frontend NO hará countdown, esperará acción manual (botón "Siguiente ronda").

### Delay Variable según Condición

```php
$delay = $isLastRound ? 10 : 5; // Más tiempo en última ronda

event(new RoundEndedEvent(
    // ...
    timing: [
        'auto_next' => true,
        'delay' => $delay
    ]
));
```

Frontend respetará el delay especificado.

---

**Esta es la arquitectura final simplificada. Sin Jobs, todo controlado por el frontend con setTimeout, y el backend solo valida y emite eventos.**
