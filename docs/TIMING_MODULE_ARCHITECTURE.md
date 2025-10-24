# TimingModule - Arquitectura de Sistema de Timing

## Problema

Actualmente el manejo de tiempos está disperso y no es consistente:
- Backend: `TimerService` maneja timers pero es básico
- Frontend: Código de countdown manual en cada juego
- No hay sincronización clara entre backend/frontend
- No hay configuración centralizada de tiempos

**Necesitamos:**
1. Sistema unificado de timing para backend y frontend
2. Configuración declarativa de tiempos en `config.json`
3. Delays automáticos entre eventos (ej: esperar 5s antes de siguiente pregunta)
4. Countdowns visuales sincronizados
5. Callbacks cuando expira el tiempo
6. Fácil de extender y reutilizar

---

## Arquitectura Propuesta

```
┌─────────────────────────────────────────────────┐
│           TimingModule (Backend)                │
│  - Configurar tiempos del juego                 │
│  - Programar delays automáticos                 │
│  - Enviar eventos de tiempo al frontend         │
└─────────────────────────────────────────────────┘
           │
           │ WebSocket Events
           ▼
┌─────────────────────────────────────────────────┐
│           TimingModule (Frontend)               │
│  - Recibir configuración de tiempos             │
│  - Mostrar countdowns visuales                  │
│  - Ejecutar delays entre pantallas              │
│  - Callbacks cuando expira                      │
└─────────────────────────────────────────────────┘
```

---

## 1. Backend - TimingModule

### Ubicación
`app/Services/Modules/TimingSystem/TimingModule.php`

### Responsabilidades
1. **Configurar tiempos del juego**: Leer `config.json` y establecer duraciones
2. **Programar delays automáticos**: Esperar X segundos antes de siguiente evento
3. **Emitir eventos de timing**: Notificar al frontend sobre tiempos
4. **Integración con Jobs**: Usar Laravel Jobs para delays (opcional)

### API Propuesta

```php
<?php

namespace App\Services\Modules\TimingSystem;

class TimingModule
{
    protected array $config;
    protected array $scheduledEvents = [];

    /**
     * Configurar tiempos del juego
     *
     * @param array $config ['round_duration' => 15, 'delay_between_rounds' => 5, ...]
     */
    public function configure(array $config): void;

    /**
     * Programar un delay automático antes de ejecutar callback
     *
     * @param string $name Nombre del delay (ej: 'next_round', 'show_results')
     * @param int $seconds Segundos a esperar
     * @param callable $callback Función a ejecutar después del delay
     * @param bool $broadcast Si debe emitir eventos al frontend
     */
    public function scheduleDelay(
        string $name,
        int $seconds,
        callable $callback,
        bool $broadcast = true
    ): void;

    /**
     * Cancelar un delay programado
     */
    public function cancelDelay(string $name): void;

    /**
     * Obtener configuración de timing
     */
    public function getConfig(): array;

    /**
     * Obtener delay restante
     */
    public function getRemainingDelay(string $name): ?int;

    /**
     * Serializar a array para game_state
     */
    public function toArray(): array;

    /**
     * Crear desde array (deserializar)
     */
    public static function fromArray(array $data): self;
}
```

### Eventos que Emite

**TimingDelayStarted**:
```php
event(new TimingDelayStarted(
    match: $match,
    delayName: 'next_round',
    duration: 5,
    purpose: 'Esperar antes de siguiente ronda'
));
```

**TimingDelayCompleted**:
```php
event(new TimingDelayCompleted(
    match: $match,
    delayName: 'next_round'
));
```

**TimingCountdownTick** (cada segundo):
```php
event(new TimingCountdownTick(
    match: $match,
    delayName: 'next_round',
    remaining: 3
));
```

### Configuración en config.json

```json
{
    "timing": {
        "round_duration": 15,              // Duración de cada ronda (segundos)
        "delay_between_rounds": 5,         // Delay antes de siguiente ronda
        "delay_show_results": 3,           // Delay antes de mostrar resultados
        "countdown_warning_threshold": 5,  // Cambiar color cuando quedan X segundos
        "autostart_next_round": true,      // Auto-iniciar siguiente ronda
        "autostart_delay": 5               // Delay para auto-inicio
    }
}
```

### Ejemplo de Uso en TriviaEngine

```php
class TriviaEngine extends BaseGameEngine
{
    public function endCurrentRound(GameMatch $match): void
    {
        // Calcular resultados...
        $this->calculateResults($match);

        // Emitir RoundEndedEvent
        event(new RoundEndedEvent(...));

        // Programar delay automático antes de siguiente ronda
        $timingModule = TimingModule::fromArray($match->game_state);
        $timingModule->scheduleDelay(
            name: 'next_round',
            seconds: $timingModule->getConfig()['delay_between_rounds'] ?? 5,
            callback: fn() => $this->startNewRound($match),
            broadcast: true
        );

        // Guardar timing module
        $match->game_state = array_merge(
            $match->game_state,
            $timingModule->toArray()
        );
        $match->save();
    }
}
```

---

## 2. Frontend - TimingModule

### Ubicación
`resources/js/modules/TimingModule.js`

### Responsabilidades
1. **Recibir eventos de timing**: Escuchar TimingDelayStarted, etc.
2. **Mostrar countdowns visuales**: Renderizar "Siguiente pregunta en 5s..."
3. **Ejecutar delays locales**: `await timing.delay(3)`
4. **Callbacks cuando expira**: Ejecutar función al terminar countdown

### API Propuesta

```javascript
class TimingModule {
    constructor(config = {}) {
        this.config = {
            countdownWarningThreshold: config.countdownWarningThreshold || 5,
            tickInterval: config.tickInterval || 1000,
            ...config
        };

        this.activeCountdowns = new Map();
        this.callbacks = new Map();
    }

    /**
     * Configurar el módulo con timing del juego
     */
    configure(config) {
        this.config = { ...this.config, ...config };
    }

    /**
     * Iniciar countdown visual
     *
     * @param {string} name - Nombre del countdown
     * @param {number} duration - Duración en segundos
     * @param {Object} options - Opciones
     * @param {HTMLElement} options.element - Elemento donde mostrar
     * @param {Function} options.onTick - Callback cada segundo
     * @param {Function} options.onComplete - Callback al terminar
     * @param {string} options.template - Template del texto
     */
    startCountdown(name, duration, options = {}) {
        const countdown = {
            name,
            duration,
            remaining: duration,
            startTime: Date.now(),
            element: options.element,
            onTick: options.onTick,
            onComplete: options.onComplete,
            template: options.template || 'Tiempo restante: {seconds}s',
            interval: null
        };

        // Guardar countdown
        this.activeCountdowns.set(name, countdown);

        // Iniciar interval
        countdown.interval = setInterval(() => {
            this.tickCountdown(name);
        }, this.config.tickInterval);

        // Renderizar inicial
        this.renderCountdown(countdown);

        return countdown;
    }

    /**
     * Tick de countdown (cada segundo)
     */
    tickCountdown(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) return;

        const elapsed = Math.floor((Date.now() - countdown.startTime) / 1000);
        countdown.remaining = Math.max(0, countdown.duration - elapsed);

        // Callback de tick
        if (countdown.onTick) {
            countdown.onTick(countdown.remaining);
        }

        // Renderizar
        this.renderCountdown(countdown);

        // Si terminó
        if (countdown.remaining <= 0) {
            this.completeCountdown(name);
        }
    }

    /**
     * Renderizar countdown en elemento
     */
    renderCountdown(countdown) {
        if (!countdown.element) return;

        const text = countdown.template.replace('{seconds}', countdown.remaining);
        countdown.element.textContent = text;

        // Añadir clase de warning si quedan pocos segundos
        if (countdown.remaining <= this.config.countdownWarningThreshold) {
            countdown.element.classList.add('countdown-warning');
        } else {
            countdown.element.classList.remove('countdown-warning');
        }
    }

    /**
     * Completar countdown
     */
    completeCountdown(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) return;

        // Limpiar interval
        if (countdown.interval) {
            clearInterval(countdown.interval);
        }

        // Callback de completado
        if (countdown.onComplete) {
            countdown.onComplete();
        }

        // Eliminar countdown
        this.activeCountdowns.delete(name);
    }

    /**
     * Cancelar countdown
     */
    cancelCountdown(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) return;

        if (countdown.interval) {
            clearInterval(countdown.interval);
        }

        this.activeCountdowns.delete(name);
    }

    /**
     * Delay simple (Promise)
     *
     * @param {number} seconds - Segundos a esperar
     * @returns {Promise}
     */
    delay(seconds) {
        return new Promise(resolve => {
            setTimeout(resolve, seconds * 1000);
        });
    }

    /**
     * Delay con countdown visual
     *
     * @param {number} seconds - Segundos a esperar
     * @param {HTMLElement} element - Elemento donde mostrar
     * @param {string} template - Template del texto
     * @returns {Promise}
     */
    delayWithCountdown(seconds, element, template) {
        return new Promise(resolve => {
            this.startCountdown('delay', seconds, {
                element,
                template,
                onComplete: resolve
            });
        });
    }

    /**
     * Obtener countdown activo
     */
    getCountdown(name) {
        return this.activeCountdowns.get(name);
    }

    /**
     * Verificar si hay countdown activo
     */
    hasActiveCountdown(name) {
        return this.activeCountdowns.has(name);
    }

    /**
     * Limpiar todos los countdowns
     */
    clearAll() {
        for (const name of this.activeCountdowns.keys()) {
            this.cancelCountdown(name);
        }
    }
}

export default TimingModule;
```

---

## 3. Integración con BaseGameClient

```javascript
class BaseGameClient {
    constructor(config) {
        // ... existing code

        // Inicializar TimingModule
        this.timing = new TimingModule(config.timing || {});
    }

    /**
     * Handler para evento de delay iniciado
     */
    handleTimingDelayStarted(event) {
        const { delay_name, duration, purpose } = event;

        console.log(`⏰ [Timing] Delay started: ${delay_name} (${duration}s)`);

        // Iniciar countdown visual si hay elemento
        const countdownElement = document.getElementById(`countdown-${delay_name}`);
        if (countdownElement) {
            this.timing.startCountdown(delay_name, duration, {
                element: countdownElement,
                template: purpose ? `${purpose}: {seconds}s` : '{seconds}s'
            });
        }
    }

    /**
     * Handler para tick de countdown
     */
    handleTimingCountdownTick(event) {
        const { delay_name, remaining } = event;

        // Actualizar countdown si existe
        const countdown = this.timing.getCountdown(delay_name);
        if (countdown) {
            countdown.remaining = remaining;
            this.timing.renderCountdown(countdown);
        }
    }

    /**
     * Handler para delay completado
     */
    handleTimingDelayCompleted(event) {
        const { delay_name } = event;

        console.log(`✅ [Timing] Delay completed: ${delay_name}`);

        // Completar countdown
        this.timing.completeCountdown(delay_name);
    }
}
```

---

## 4. Ejemplo Completo: Trivia

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

    // 3. Emitir RoundEndedEvent
    event(new RoundEndedEvent($match, $results));

    // 4. Programar delay automático para siguiente ronda
    $timingModule = TimingModule::fromArray($gameState);
    $timingModule->scheduleDelay(
        name: 'next_round',
        seconds: 5,
        callback: function() use ($match) {
            $this->startNewRound($match);
        },
        broadcast: true
    );

    // 5. Guardar timing
    $match->game_state = array_merge($match->game_state, $timingModule->toArray());
    $match->save();
}
```

### Frontend - handleRoundEnded()

```javascript
handleRoundEndedTrivia(event) {
    console.log('🏁 [Trivia] Round ended:', event);

    // Llamar al handler base
    super.handleRoundEnded(event);

    // Mostrar resultados
    this.showResults(event.results);

    // El countdown "Siguiente pregunta en 5s..." se mostrará automáticamente
    // cuando llegue TimingDelayStarted desde el backend
}

handleTimingDelayStarted(event) {
    if (event.delay_name === 'next_round') {
        // Mostrar countdown en el elemento de espera
        const waitingElement = this.questionWaiting.querySelector('p');

        this.timing.startCountdown('next_round', event.duration, {
            element: waitingElement,
            template: 'Siguiente pregunta en {seconds}s...',
            onComplete: () => {
                // Cuando termine el countdown, mostrar pantalla de espera
                this.showQuestionWaiting();
            }
        });
    }
}
```

---

## 5. Configuración de Timing en Juegos

### Trivia - config.json

```json
{
    "name": "Trivia",
    "timing": {
        "round_duration": 15,
        "delay_between_rounds": 5,
        "delay_show_results": 2,
        "countdown_warning_threshold": 5,
        "autostart_next_round": true
    }
}
```

### Pictionary - config.json

```json
{
    "name": "Pictionary",
    "timing": {
        "round_duration": 60,
        "delay_between_rounds": 3,
        "delay_show_results": 5,
        "countdown_warning_threshold": 10,
        "autostart_next_round": false
    }
}
```

---

## 6. Eventos de Timing

### TimingDelayStarted

```php
class TimingDelayStarted implements ShouldBroadcast
{
    public string $roomCode;
    public string $delayName;
    public int $duration;
    public string $purpose;

    public function broadcastAs(): string {
        return 'timing.delay.started';
    }

    public function broadcastWith(): array {
        return [
            'delay_name' => $this->delayName,
            'duration' => $this->duration,
            'purpose' => $this->purpose
        ];
    }
}
```

### TimingCountdownTick

```php
class TimingCountdownTick implements ShouldBroadcast
{
    public string $roomCode;
    public string $delayName;
    public int $remaining;

    public function broadcastAs(): string {
        return 'timing.countdown.tick';
    }

    public function broadcastWith(): array {
        return [
            'delay_name' => $this->delayName,
            'remaining' => $this->remaining
        ];
    }
}
```

### TimingDelayCompleted

```php
class TimingDelayCompleted implements ShouldBroadcast
{
    public string $roomCode;
    public string $delayName;

    public function broadcastAs(): string {
        return 'timing.delay.completed';
    }

    public function broadcastWith(): array {
        return [
            'delay_name' => $this->delayName
        ];
    }
}
```

---

## 7. Ventajas de esta Arquitectura

1. **Centralizado**: Toda la lógica de timing en un lugar
2. **Declarativo**: Configuración en JSON, fácil de ajustar
3. **Reutilizable**: Funciona para cualquier juego
4. **Sincronizado**: Backend y frontend trabajando juntos
5. **Flexible**: Fácil añadir nuevos tipos de delays
6. **Testeable**: Módulos independientes fáciles de testear
7. **Extensible**: Se pueden añadir features como pausar, acelerar, etc.

---

## 8. Próximos Pasos

1. Implementar `TimingModule` backend
2. Implementar `TimingModule` frontend
3. Integrar con `BaseGameClient`
4. Añadir configuración de timing a `config.json` de juegos
5. Refactorizar `TriviaEngine` para usar `TimingModule`
6. Probar flujo completo con delays automáticos
7. Documentar convenciones de uso
