# Plan de Migración por Fases - Base Engine + Base Client

**Objetivo:** Migrar gradualmente de HTTP POST + MySQL a WebSocket + Redis sin romper nada
**Estrategia:** Fases incrementales con testing después de cada una
**Duración estimada:** 5 fases, ~2-3 días

---

## 🎯 Principios de la Migración

1. **Incrementalidad:** Cambios pequeños y probables
2. **Backward Compatibility:** El código antiguo sigue funcionando mientras migramos
3. **Testing Continuo:** Probar después de cada fase
4. **Code Reuse:** Mover código común a Base, pero permitir override
5. **Templates:** Componentes Blade reutilizables con opción de personalizar

---

## 📋 FASE 1: Refactorizar Código Reutilizable (sin romper nada)

**Objetivo:** Mover lógica común de Trivia a BaseEngine y BaseGameClient sin cambiar el flujo

**Duración:** 4-5 horas

### 1.1 Backend: Mover Helpers a BaseGameEngine

**Lo que vamos a mover:**

```php
// TriviaEngine tiene esto:
protected function addScore($match, $playerId, $points)
{
    $scoreManager = $this->getScoreManager($match);
    $scoreManager->addScore($playerId, $points);
    $this->saveScoreManager($match, $scoreManager);
}

// ✅ Moverlo a BaseGameEngine como:
protected function addScore(GameMatch $match, int $playerId, int $points, string $reason = 'action'): void
{
    $scoreManager = $this->getScoreManager($match);
    $scoreManager->addScore($playerId, $points);
    $this->saveScoreManager($match, $scoreManager);

    Log::info("[{$this->getGameSlug()}] Score added", [
        'player_id' => $playerId,
        'points' => $points,
        'reason' => $reason
    ]);
}
```

**Otros métodos a mover:**

- `cachePlayersInState()` - Guardar players en _config al inicializar
- `getPlayerFromState()` - Obtener Player desde game_state (SIN query)
- `broadcastToRoom()` - Helper para broadcast optimizado

**Cambios en TriviaEngine:**

```php
// ANTES:
$this->addScore($match, $player->id, $points);

// DESPUÉS (mismo código, pero ahora usa BaseEngine):
$this->addScore($match, $player->id, $points, 'correct_answer');
```

### 1.2 Backend: Cache de Players en _config

**Modificar `TriviaEngine::initialize()`:**

```php
public function initialize(GameMatch $match): void
{
    // ... existing code ...

    // ✅ NUEVO: Cachear players en _config (1 query, 1 sola vez)
    $this->cachePlayersInState($match);
}
```

**Modificar `TriviaEngine::processRoundAction()`:**

```php
// ANTES (con query):
$player = $room->match->players->firstWhere('user_id', $userId);

// DESPUÉS (sin query):
$player = $this->getPlayerFromState($match, $playerId);
```

### 1.3 Frontend: Mover Helpers a BaseGameClient

**Lo que vamos a mover:**

```javascript
// game.blade.php tiene esto:
function displayQuestion(question, categoryName, currentRound, totalRounds) {
    document.getElementById('loading-state').classList.add('hidden');
    document.getElementById('question-state').classList.remove('hidden');
    // ...
}

// ✅ Moverlo a TriviaGameClient (que extiende BaseGameClient):
class TriviaGameClient extends BaseGameClient {
    displayQuestion(question, categoryName) {
        this.hideLoading();
        this.showQuestion();
        // Lógica específica de Trivia
    }

    // Nuevos helpers en BaseGameClient:
    hideElement(id) {
        document.getElementById(id)?.classList.add('hidden');
    }

    showElement(id) {
        document.getElementById(id)?.classList.remove('hidden');
    }
}
```

### 1.4 Testing Fase 1

```bash
# 1. Probar Trivia con los cambios
php artisan serve
npm run dev

# 2. Abrir 4 navegadores
# 3. Jugar una partida completa
# 4. Verificar:
#    - ✅ Las preguntas se cargan
#    - ✅ Las respuestas funcionan
#    - ✅ Los puntos se suman correctamente
#    - ✅ Las rondas avanzan
#    - ✅ El juego termina correctamente

# 5. Verificar logs
tail -f storage/logs/laravel.log | grep "Trivia"

# 6. Verificar que NO hay queries extras
# Debería haber:
# - 1 query inicial: SELECT players (en initialize)
# - 0 queries durante el juego
```

**Criterio de éxito:** El juego funciona exactamente igual que antes

---

## 📋 FASE 2: Plantillas Blade Reutilizables

**Objetivo:** Crear componentes Blade comunes para countdown, loading, messages

**Duración:** 2-3 horas

### 2.1 Crear Componentes Blade Genéricos

**Archivo: `resources/views/components/game/loading-state.blade.php`**

```blade
{{-- Loading state genérico --}}
@props([
    'emoji' => '⏳',
    'message' => 'Cargando...',
    'roomCode' => null
])

<div {{ $attributes->merge(['class' => 'text-center']) }}>
    <div class="mb-6">
        <span class="text-6xl">{{ $emoji }}</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $message }}</h2>
    @if($roomCode)
        <p class="text-gray-600">
            Sala: <strong class="text-gray-900">{{ $roomCode }}</strong>
        </p>
    @endif
</div>
```

**Archivo: `resources/views/components/game/countdown.blade.php`**

```blade
{{-- Countdown genérico --}}
@props([
    'seconds' => 3,
    'message' => 'Comenzando en...',
    'size' => 'large' // 'small', 'medium', 'large'
])

<div {{ $attributes->merge(['class' => 'text-center countdown-container']) }}>
    <p class="
        @if($size === 'small') text-lg
        @elseif($size === 'medium') text-2xl
        @else text-4xl
        @endif
        font-bold text-gray-800 mb-4
    ">
        {{ $message }}
    </p>
    <div class="
        countdown-number
        @if($size === 'small') text-4xl
        @elseif($size === 'medium') text-6xl
        @else text-8xl
        @endif
        font-bold text-blue-600
    ">
        <span class="animate-pulse">{{ $seconds }}</span>
    </div>
</div>
```

**Archivo: `resources/views/components/game/message.blade.php`**

```blade
{{-- Mensaje temporal --}}
@props([
    'type' => 'info', // 'info', 'success', 'error', 'warning'
    'message' => '',
    'icon' => null,
    'dismissible' => false
])

@php
$colors = [
    'info' => 'bg-blue-100 border-blue-400 text-blue-700',
    'success' => 'bg-green-100 border-green-400 text-green-700',
    'error' => 'bg-red-100 border-red-400 text-red-700',
    'warning' => 'bg-yellow-100 border-yellow-400 text-yellow-700',
];

$icons = [
    'info' => 'ℹ️',
    'success' => '✅',
    'error' => '❌',
    'warning' => '⚠️',
];
@endphp

<div {{ $attributes->merge(['class' => "border-l-4 p-4 {$colors[$type]} rounded-r-lg"]) }} role="alert">
    <div class="flex items-center">
        @if($icon || isset($icons[$type]))
            <span class="text-2xl mr-3">{{ $icon ?? $icons[$type] }}</span>
        @endif
        <div class="flex-1">
            <p class="font-medium">{{ $message }}</p>
        </div>
        @if($dismissible)
            <button onclick="this.parentElement.parentElement.remove()" class="ml-3 text-xl font-bold">
                ×
            </button>
        @endif
    </div>
</div>
```

**Archivo: `resources/views/components/game/round-info.blade.php`**

```blade
{{-- Información de ronda --}}
@props([
    'current' => 1,
    'total' => 10,
    'label' => 'Ronda'
])

<div {{ $attributes->merge(['class' => 'text-center mb-8']) }}>
    <p class="text-lg text-gray-600">
        {{ $label }} <strong id="current-round" class="text-blue-600">{{ $current }}</strong>
        de <strong id="total-rounds" class="text-blue-600">{{ $total }}</strong>
    </p>
</div>
```

**Archivo: `resources/views/components/game/player-lock.blade.php`**

```blade
{{-- Indicador de jugador bloqueado --}}
@props([
    'message' => 'Ya has respondido',
    'icon' => '🔒'
])

<div {{ $attributes->merge(['class' => 'bg-gray-100 border-2 border-gray-300 rounded-lg p-8 text-center']) }}>
    <div class="text-6xl mb-4">{{ $icon }}</div>
    <p class="text-xl font-medium text-gray-700">{{ $message }}</p>
    <p class="text-sm text-gray-500 mt-2">Esperando a los demás jugadores...</p>
</div>
```

### 2.2 Refactorizar game.blade.php para usar Componentes

**ANTES (`games/trivia/views/game.blade.php`):**

```blade
<div id="loading-state" class="text-center">
    <div class="mb-6">
        <span class="text-6xl">⏳</span>
    </div>
    <h2 class="text-2xl font-bold text-gray-800 mb-4">Esperando primera pregunta...</h2>
    <p class="text-gray-600">
        Sala: <strong class="text-gray-900">{{ $code }}</strong>
    </p>
</div>
```

**DESPUÉS:**

```blade
<x-game.loading-state
    id="loading-state"
    emoji="⏳"
    message="Esperando primera pregunta..."
    :roomCode="$code"
/>
```

**Ventajas:**
- ✅ Código más limpio
- ✅ Reutilizable en todos los juegos
- ✅ Fácil de personalizar (pasar props)
- ✅ Styling consistente

### 2.3 Testing Fase 2

```bash
# 1. Probar que las plantillas se ven correctamente
# 2. Probar que se pueden personalizar (cambiar emoji, message, etc.)
# 3. Probar que funcionan en Trivia sin romper nada
```

**Criterio de éxito:** Las vistas se ven igual o mejor que antes

---

## 📋 FASE 3: WebSocket Bidireccional (Backend)

**Objetivo:** Permitir que el cliente envíe acciones via WebSocket

**Duración:** 4-5 horas

### 3.1 Crear Evento para Player Actions

**Archivo: `app/Events/Game/PlayerActionEvent.php`**

```php
<?php

namespace App\Events\Game;

use App\Models\GameMatch;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento broadcast cuando un jugador realiza una acción.
 */
class PlayerActionEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GameMatch $match,
        public array $data
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel("room.{$this->match->room->code}");
    }

    public function broadcastAs(): string
    {
        return 'game.action.result';
    }

    public function broadcastWith(): array
    {
        return $this->data;
    }
}
```

### 3.2 Crear Listener para Client Whispers

**Archivo: `app/Listeners/HandleClientGameAction.php`**

```php
<?php

namespace App\Listeners;

use App\Events\Game\PlayerActionEvent;
use App\Models\GameMatch;
use Illuminate\Support\Facades\Log;

/**
 * Escuchar whispers de clientes y procesarlos.
 *
 * Este listener se registra en routes/channels.php para el Presence Channel.
 */
class HandleClientGameAction
{
    /**
     * Manejar acción del cliente recibida via WebSocket.
     *
     * IMPORTANTE: Este método se ejecuta cuando el cliente hace whisper('game.action').
     *
     * @param array $event Datos del whisper
     * @return void
     */
    public function handle(array $event): void
    {
        Log::info('[HandleClientGameAction] Received client action', $event);

        // 1. Extraer datos
        $action = $event['action'] ?? null;
        $data = $event['data'] ?? [];
        $playerId = $event['player_id'] ?? null;

        // 2. Buscar el match (aún desde MySQL, migraremos a Redis en Fase 4)
        $roomCode = $event['room_code'] ?? null; // El cliente debe enviarlo

        if (!$roomCode) {
            Log::error('[HandleClientGameAction] Missing room_code');
            return;
        }

        $room = \App\Models\Room::where('code', $roomCode)->first();

        if (!$room || !$room->match) {
            Log::error('[HandleClientGameAction] Room or match not found', ['code' => $roomCode]);
            return;
        }

        $match = $room->match;

        // 3. Obtener engine
        $engine = $match->getEngine();

        // 4. Procesar acción
        try {
            $result = $engine->processAction(
                $match,
                $engine->getPlayerFromState($match, $playerId), // ← Usa cache (no query)
                $action,
                $data
            );

            // 5. Broadcast resultado
            broadcast(new PlayerActionEvent($match, [
                'player_id' => $playerId,
                'action' => $action,
                'success' => $result['success'] ?? false,
                'data' => $result,
                'timestamp' => now()->toDateTimeString(),
            ]))->toOthers();

        } catch (\Exception $e) {
            Log::error('[HandleClientGameAction] Error processing action', [
                'error' => $e->getMessage(),
                'match_id' => $match->id,
                'action' => $action
            ]);
        }
    }
}
```

### 3.3 Registrar Listener en Presence Channel

**Modificar `routes/channels.php`:**

```php
use App\Listeners\HandleClientGameAction;

Broadcast::channel('room.{code}', function ($user, $code) {
    // ... existing authorization ...

    // ✅ NUEVO: Escuchar client events (whispers)
    return [
        'id' => $user->id,
        'name' => $user->name,
        // Callbacks para whispers
        'whisper' => [
            'game.action' => [HandleClientGameAction::class, 'handle']
        ]
    ];
});
```

### 3.4 Agregar Método en BaseGameEngine

```php
/**
 * Obtener datos del jugador desde game_state (SIN query).
 *
 * @param GameMatch $match
 * @param int $playerId
 * @return Player|null
 */
protected function getPlayerFromState(GameMatch $match, int $playerId): ?Player
{
    $playerData = $match->game_state['_config']['players'][$playerId] ?? null;

    if (!$playerData) {
        return null;
    }

    // Crear Player object desde datos en memoria
    $player = new Player();
    $player->id = $playerData['id'];
    $player->name = $playerData['name'];
    $player->user_id = $playerData['user_id'];
    $player->exists = true;

    return $player;
}
```

### 3.5 Testing Fase 3

**Setup:**
```bash
# 1. Asegurar que Reverb está corriendo
php artisan reverb:start

# 2. En otro terminal
php artisan serve

# 3. En otro terminal
npm run dev
```

**Test con Browser Console:**
```javascript
// En la consola del navegador (dentro del juego):
const channel = Echo.private('room.ABC123');

channel.whisper('game.action', {
    action: 'answer',
    data: { answer_index: 2 },
    player_id: 123,
    room_code: 'ABC123'
});

// Verificar en logs del servidor:
// ✅ [HandleClientGameAction] Received client action
// ✅ [Trivia] Processing action
// ✅ Broadcast: PlayerActionEvent
```

**Criterio de éxito:**
- ✅ El servidor recibe whispers
- ✅ Procesa la acción correctamente
- ✅ Broadcast resultado a todos
- ✅ NO rompe el flujo HTTP existente (backward compatible)

---

## 📋 FASE 4: WebSocket Bidireccional (Frontend)

**Objetivo:** Cliente envía acciones via WebSocket en lugar de HTTP POST

**Duración:** 3-4 horas

### 4.1 Agregar sendGameAction() a BaseGameClient

**Archivo: `resources/js/core/BaseGameClient.js`**

```javascript
/**
 * Enviar acción de juego via WebSocket.
 *
 * @param {string} action - Tipo de acción ('answer', 'draw', etc.)
 * @param {object} data - Datos de la acción
 * @param {boolean} optimistic - Si aplicar update optimista
 * @returns {Promise<object>}
 */
async sendGameAction(action, data, optimistic = false) {
    console.log(`📤 [BaseGameClient] Sending action via WebSocket:`, action, data);

    // Optimistic update (opcional)
    if (optimistic) {
        this.applyOptimisticUpdate(action, data);
    }

    // Enviar via WebSocket
    const channel = window.Echo.private(`room.${this.roomCode}`);

    channel.whisper('game.action', {
        action: action,
        data: data,
        player_id: this.playerId,
        room_code: this.roomCode,
        timestamp: Date.now(),
    });

    // Esperar confirmación (timeout 5s)
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            reject(new Error('Action timeout'));
        }, 5000);

        const confirmHandler = (event) => {
            if (event.player_id === this.playerId && event.action === action) {
                clearTimeout(timeout);
                channel.stopListening('.game.action.result', confirmHandler);
                resolve(event);
            }
        };

        channel.listen('.game.action.result', confirmHandler);
    });
}

/**
 * Aplicar optimistic update (override en juegos específicos).
 */
applyOptimisticUpdate(action, data) {
    // Los juegos específicos implementan esto
}

/**
 * Revertir optimistic update si falló.
 */
revertOptimisticUpdate(action, data) {
    // Los juegos específicos implementan esto
}
```

### 4.2 Crear TriviaGameClient

**Archivo: `resources/js/games/TriviaGameClient.js`**

```javascript
import { BaseGameClient } from '../core/BaseGameClient.js';

export class TriviaGameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        // Referencias DOM
        this.loadingState = document.getElementById('loading-state');
        this.questionState = document.getElementById('question-state');
        this.optionsContainer = document.getElementById('options-container');
    }

    /**
     * Display question (lógica específica de Trivia).
     */
    displayQuestion(question, categoryName, currentRound, totalRounds) {
        console.log('📝 [Trivia] Displaying question:', question);

        // Usar helpers de BaseGameClient
        this.hideElement('loading-state');
        this.showElement('question-state');

        // Actualizar UI
        document.getElementById('current-round').textContent = currentRound;
        document.getElementById('total-rounds').textContent = totalRounds;
        document.getElementById('question-category').textContent = categoryName;
        document.getElementById('question-text').textContent = question.question;

        // Renderizar opciones
        this.renderOptions(question.options);
    }

    /**
     * Renderizar opciones.
     */
    renderOptions(options) {
        this.optionsContainer.innerHTML = '';

        options.forEach((option, index) => {
            const button = document.createElement('button');
            button.className = 'option-button';
            button.textContent = option;
            button.dataset.index = index;

            button.addEventListener('click', () => this.submitAnswer(index, button));

            this.optionsContainer.appendChild(button);
        });
    }

    /**
     * Enviar respuesta via WebSocket.
     */
    async submitAnswer(answerIndex, button) {
        console.log('✅ [Trivia] Submitting answer:', answerIndex);

        try {
            // Enviar via WebSocket (con optimistic update)
            const result = await this.sendGameAction(
                'answer',
                { answer_index: answerIndex },
                true // ← Optimistic
            );

            console.log('📨 [Trivia] Answer result:', result);

            // Manejar resultado
            if (result.success && result.data.correct) {
                button.classList.add('correct');
            } else {
                button.classList.add('incorrect');
            }

        } catch (error) {
            console.error('❌ [Trivia] Error submitting answer:', error);

            // Revertir optimistic update
            this.revertOptimisticUpdate('answer', { answer_index: answerIndex });

            alert('Error al enviar respuesta');
        }
    }

    /**
     * Optimistic update: Deshabilitar botones.
     */
    applyOptimisticUpdate(action, data) {
        if (action === 'answer') {
            // Deshabilitar todos los botones
            document.querySelectorAll('.option-button').forEach(btn => {
                btn.disabled = true;
                btn.classList.add('opacity-50');
            });
        }
    }

    /**
     * Revertir optimistic update.
     */
    revertOptimisticUpdate(action, data) {
        if (action === 'answer') {
            // Re-habilitar botones
            document.querySelectorAll('.option-button').forEach(btn => {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
            });
        }
    }

    /**
     * Override: RoundStarted handler.
     */
    handleRoundStarted(event) {
        super.handleRoundStarted(event);

        const question = event.game_state.current_question;
        const categories = event.game_state._config?.categories || {};
        const categoryName = categories[question.category] || question.category;

        this.displayQuestion(question, categoryName, event.current_round, event.total_rounds);
    }
}

// Exportar globalmente
window.TriviaGameClient = TriviaGameClient;
```

### 4.3 Actualizar game.blade.php

**ANTES:**

```javascript
button.addEventListener('click', async () => {
    const response = await fetch(`/api/trivia/${roomCode}/answer`, {
        method: 'POST',
        body: JSON.stringify({ answer_index: index })
    });
});
```

**DESPUÉS:**

```blade
<script type="module">
import { TriviaGameClient } from '/resources/js/games/TriviaGameClient.js';

const trivia = new TriviaGameClient({
    roomCode: '{{ $code }}',
    playerId: {{ auth()->id() }},
    matchId: {{ $match->id }},
    gameSlug: 'trivia',
    gameState: @json($match->game_state),
});

// Setup WebSocket listeners
trivia.setupEventManager();

// El resto se maneja automáticamente via eventos
</script>
```

### 4.4 Testing Fase 4

```bash
# 1. Probar con 4 navegadores
# 2. Responder preguntas
# 3. Verificar en Network tab:
#    - ❌ NO debe haber POST /api/trivia/*/answer
#    - ✅ Solo WebSocket messages

# 4. Verificar optimistic updates:
#    - ✅ Botones se deshabilitan inmediatamente
#    - ✅ Respuesta se marca como correcta/incorrecta
#    - ✅ Si hay error, se revierten cambios

# 5. Verificar latencia:
#    - HTTP POST: ~100-200ms
#    - WebSocket: ~10-20ms
```

**Criterio de éxito:**
- ✅ No hay HTTP POST durante el juego
- ✅ Todo funciona via WebSocket
- ✅ Optimistic updates funcionan
- ✅ Errores se manejan correctamente

---

## 📋 FASE 5: Redis State Manager

**Objetivo:** Mover game_state de MySQL a Redis durante el juego

**Duración:** 3-4 horas

### 5.1 Agregar Métodos Redis a BaseGameEngine

```php
/**
 * Cargar match desde Redis o MySQL.
 *
 * @param int $matchId
 * @return GameMatch
 */
protected function loadMatch(int $matchId): GameMatch
{
    // 1. Intentar desde Redis
    $redisState = $this->loadStateFromRedis($matchId);

    if ($redisState) {
        // Crear Match object desde Redis (sin query)
        $match = new GameMatch();
        $match->id = $matchId;
        $match->game_state = $redisState;
        $match->exists = true;

        Log::debug("[{$this->getGameSlug()}] Match loaded from Redis", [
            'match_id' => $matchId
        ]);

        return $match;
    }

    // 2. Fallback a MySQL
    $match = GameMatch::findOrFail($matchId);

    // 3. Cachear en Redis para próximos accesos
    $this->saveStateToRedis($matchId, $match->game_state);

    Log::debug("[{$this->getGameSlug()}] Match loaded from MySQL and cached to Redis", [
        'match_id' => $matchId
    ]);

    return $match;
}

/**
 * Guardar match a Redis (y opcionalmente MySQL).
 *
 * @param GameMatch $match
 * @param bool $syncToMySQL Si también guardar en MySQL
 * @return void
 */
protected function saveMatch(GameMatch $match, bool $syncToMySQL = false): void
{
    // 1. Siempre guardar a Redis
    $this->saveStateToRedis($match->id, $match->game_state);

    // 2. Opcionalmente guardar a MySQL (checkpoint)
    if ($syncToMySQL) {
        $match->save();

        Log::debug("[{$this->getGameSlug()}] Match saved to Redis + MySQL", [
            'match_id' => $match->id
        ]);
    } else {
        Log::debug("[{$this->getGameSlug()}] Match saved to Redis only", [
            'match_id' => $match->id
        ]);
    }
}
```

### 5.2 Modificar TriviaEngine para usar Redis

**ANTES:**

```php
public function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    // ...
    $match->save(); // ← Query a MySQL
}
```

**DESPUÉS:**

```php
public function processRoundAction(GameMatch $match, Player $player, array $data): array
{
    // ...
    $this->saveMatch($match); // ← Solo Redis

    // Checkpoint cada 5 rondas
    if ($this->getCurrentRound($match->game_state) % 5 === 0) {
        $this->saveMatch($match, syncToMySQL: true); // ← Redis + MySQL
    }
}
```

### 5.3 Modificar HandleClientGameAction

```php
public function handle(array $event): void
{
    // ...

    // ANTES:
    $room = \App\Models\Room::where('code', $roomCode)->first();
    $match = $room->match;

    // DESPUÉS:
    // 1. Buscar room (solo para obtener match_id)
    $matchId = Redis::get("room:{$roomCode}:match_id");

    if (!$matchId) {
        // Fallback a MySQL
        $room = \App\Models\Room::where('code', $roomCode)->first();
        $matchId = $room->match->id;

        // Cachear para próximos accesos
        Redis::setex("room:{$roomCode}:match_id", 3600, $matchId);
    }

    // 2. Cargar match desde Redis
    $engine = app(\App\Services\GameService::class)->getEngine($gameSlug);
    $match = $engine->loadMatch($matchId);

    // ...
}
```

### 5.4 Testing Fase 5

```bash
# 1. Limpiar Redis
redis-cli FLUSHDB

# 2. Iniciar juego
# 3. Verificar que state se guarda en Redis:
redis-cli KEYS "game:match:*"
# Debe mostrar: game:match:123:state

# 4. Ver contenido:
redis-cli GET "game:match:123:state" | jq

# 5. Jugar partida completa
# 6. Verificar queries a MySQL:
#    - ✅ 1 query inicial (cargar room/match)
#    - ✅ 0 queries durante el juego
#    - ✅ 1 query cada 5 rondas (checkpoint)
#    - ✅ 1 query al final (guardar resultado)
```

**Criterio de éxito:**
- ✅ Game state en Redis
- ✅ 0 queries durante rondas 1-4
- ✅ 1 checkpoint query en ronda 5
- ✅ Juego funciona igual de bien

---

## 📋 Resumen de Mejoras por Fase

| Fase | Mejora | Queries | Latencia | Backward Compatible |
|------|--------|---------|----------|---------------------|
| 0 (Actual) | HTTP POST + MySQL | 3 por acción | 100-200ms | - |
| 1 | Code reuse + player cache | 1 inicial + 0 durante | 100-200ms | ✅ |
| 2 | Blade templates | 1 inicial + 0 durante | 100-200ms | ✅ |
| 3 | WebSocket backend | 1 inicial + 0 durante | 100-200ms | ✅ |
| 4 | WebSocket frontend | 0 durante | 10-20ms | ❌ (ya no HTTP) |
| 5 | Redis state | 0 durante (1 cada 5 rondas) | 10-20ms | ✅ (fallback MySQL) |

---

## ✅ Checklist Final

- [ ] Fase 1: Code reuse (4-5h)
  - [ ] Mover helpers a BaseGameEngine
  - [ ] Implementar player cache
  - [ ] Mover helpers a BaseGameClient
  - [ ] Testing completo

- [ ] Fase 2: Blade templates (2-3h)
  - [ ] Crear componentes genéricos
  - [ ] Refactorizar game.blade.php
  - [ ] Testing visual

- [ ] Fase 3: WebSocket backend (4-5h)
  - [ ] Crear PlayerActionEvent
  - [ ] Crear HandleClientGameAction
  - [ ] Registrar listener
  - [ ] Testing con console

- [ ] Fase 4: WebSocket frontend (3-4h)
  - [ ] Agregar sendGameAction() a BaseGameClient
  - [ ] Crear TriviaGameClient
  - [ ] Refactorizar game.blade.php
  - [ ] Testing completo

- [ ] Fase 5: Redis state (3-4h)
  - [ ] Implementar loadMatch() / saveMatch()
  - [ ] Modificar TriviaEngine
  - [ ] Modificar HandleClientGameAction
  - [ ] Testing con Redis CLI

**Total estimado:** 16-21 horas (~2-3 días)

---

## 🚀 Próximo Paso

Empezar con **Fase 1: Code Reuse**.

¿Listo para comenzar? 🎮
