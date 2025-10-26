# Ejemplos Prácticos: WebSocket Client → Server

**Complemento de:** [`CLIENT_TO_SERVER_WEBSOCKET.md`](./CLIENT_TO_SERVER_WEBSOCKET.md)

Este documento contiene ejemplos completos y probados para implementar comunicación cliente → servidor via WebSocket en tu aplicación.

---

## 📋 Índice

1. [Setup Básico](#1-setup-básico)
2. [Ejemplo 1: Trivia Game - Submit Answer](#2-ejemplo-1-trivia-game---submit-answer)
3. [Ejemplo 2: Pictionary - Drawing Events](#3-ejemplo-2-pictionary---drawing-events)
4. [Ejemplo 3: Manejo de Errores](#4-ejemplo-3-manejo-de-errores)
5. [Ejemplo 4: Testing](#5-ejemplo-4-testing)

---

## 1. Setup Básico

### 1.1 Instalar Dependencias

```bash
# Redis para cache de game_state
composer require predis/predis

# Asegurar que Laravel Echo y Pusher JS están instalados
npm install --save laravel-echo pusher-js
```

### 1.2 Configurar Redis

```env
# .env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

CACHE_DRIVER=redis
SESSION_DRIVER=redis
```

### 1.3 Helper JavaScript para WebSocket

```javascript
// resources/js/utils/WebSocketHelper.js

/**
 * Helper para enviar acciones via WebSocket
 */
export class WebSocketHelper {
    constructor(roomCode) {
        this.roomCode = roomCode;
        this.channel = `presence-room.${roomCode}`;
    }

    /**
     * Enviar acción del jugador
     *
     * @param {string} action - Nombre de la acción (submit-answer, draw, guess)
     * @param {object} data - Datos de la acción
     * @param {number} playerId - ID del jugador
     */
    sendAction(action, data, playerId) {
        if (!window.Echo) {
            console.error('[WS] Echo not available');
            return false;
        }

        const payload = {
            player_id: playerId,
            action: action,
            data: data,
            timestamp: Date.now()
        };

        console.log('[WS] Sending action:', action, data);

        try {
            window.Echo.connector.pusher.send_event(
                'player-action',
                JSON.stringify(payload),
                this.channel
            );
            return true;
        } catch (error) {
            console.error('[WS] Error sending action:', error);
            return false;
        }
    }

    /**
     * Enviar acción con retry automático
     */
    async sendActionWithRetry(action, data, playerId, maxRetries = 3) {
        let attempts = 0;

        while (attempts < maxRetries) {
            const success = this.sendAction(action, data, playerId);

            if (success) {
                return true;
            }

            attempts++;
            console.warn(`[WS] Retry ${attempts}/${maxRetries}`);
            await this.sleep(500 * attempts); // Exponential backoff
        }

        console.error('[WS] Failed after', maxRetries, 'attempts');
        return false;
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Export
export default WebSocketHelper;
```

---

## 2. Ejemplo 1: Trivia Game - Submit Answer

### 2.1 Frontend (JavaScript)

```javascript
// resources/js/trivia-game.js
import WebSocketHelper from './utils/WebSocketHelper.js';

class TriviaGame extends BaseGameClient {
    constructor(config) {
        super(config);

        // Inicializar WebSocket helper
        this.ws = new WebSocketHelper(this.roomCode);

        // ... resto del constructor
    }

    /**
     * Enviar respuesta via WebSocket
     */
    selectOption(optionIndex) {
        if (this.hasAnswered) return;

        // Marcar como respondido (UI instantánea)
        this.hasAnswered = true;
        this.selectedOption = optionIndex;

        // Deshabilitar botones
        const buttons = this.optionsGrid.querySelectorAll('.option-btn');
        buttons.forEach((btn, idx) => {
            if (idx === optionIndex) {
                btn.classList.add('selected');
            }
            btn.disabled = true;
        });

        // Enviar via WebSocket
        const success = this.ws.sendAction('submit-answer', {
            answer: optionIndex,
            timestamp: Date.now()
        }, this.playerId);

        if (!success) {
            // Fallback: Si WebSocket falla, mostrar error
            this.showMessage('Error de conexión. Intentando de nuevo...', 'error');

            // Reactivar botones para permitir reintentar
            this.hasAnswered = false;
            buttons.forEach(btn => btn.disabled = false);
        }
    }

    /**
     * Handler: Recibir confirmación de respuesta
     */
    handlePlayerActionTrivia(event) {
        console.log('[Trivia] Player action received:', event);

        const { player_id, action, is_correct, scores } = event;

        // Si es nuestra propia acción
        if (player_id === this.playerId) {
            if (is_correct) {
                this.showMessage('¡Correcto! +100 puntos', 'success');
            } else {
                this.showMessage('Respuesta incorrecta', 'error');
            }
        }

        // Actualizar scores de todos
        this.updateScores(scores);

        // Actualizar contador de respuestas
        const answeredCount = event.answered_count || 0;
        const totalPlayers = event.total_players || 0;

        this.answeredCount.textContent = answeredCount;
        this.totalPlayersSpan.textContent = totalPlayers;

        // Actualizar progress bar
        const progress = (answeredCount / totalPlayers) * 100;
        this.progressFill.style.width = `${progress}%`;
    }
}

export default TriviaGame;
```

### 2.2 Backend (Listener)

```php
<?php
// app/Listeners/HandlePlayerAction.php

namespace App\Listeners;

use Laravel\Reverb\Events\MessageReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Events\Game\PlayerActionEvent;
use App\Events\Game\RoundEndedEvent;

class HandlePlayerAction
{
    /**
     * Handle the MessageReceived event
     */
    public function handle(MessageReceived $event): void
    {
        $message = json_decode($event->message);

        // Solo procesar player-action
        if ($message->event !== 'player-action') {
            return;
        }

        $data = json_decode($message->data);
        $channel = $message->channel;
        $roomCode = str_replace('presence-room.', '', $channel);

        Log::info('[WS→Server] Player action received', [
            'room_code' => $roomCode,
            'player_id' => $data->player_id,
            'action' => $data->action,
            'socket_id' => $event->connection->id(),
        ]);

        // Procesar con lock para evitar race conditions
        $this->processWithLock($roomCode, function () use ($roomCode, $data) {
            match ($data->action) {
                'submit-answer' => $this->handleSubmitAnswer($roomCode, $data),
                'draw' => $this->handleDraw($roomCode, $data),
                'guess' => $this->handleGuess($roomCode, $data),
                default => Log::warning('[WS] Unknown action', ['action' => $data->action])
            };
        });
    }

    /**
     * Handle submit-answer action
     */
    private function handleSubmitAnswer(string $roomCode, object $data): void
    {
        $playerId = $data->player_id;
        $answer = $data->data->answer;

        // 1. Obtener game_state de Redis
        $gameStateKey = "game_state:{$roomCode}";
        $gameState = Cache::get($gameStateKey);

        if (!$gameState) {
            Log::error('[WS] Game state not found', ['room_code' => $roomCode]);
            return;
        }

        // 2. Validaciones
        if (isset($gameState['answers'][$playerId])) {
            Log::info('[WS] Player already answered', ['player_id' => $playerId]);
            return;
        }

        if ($gameState['phase'] !== 'question') {
            Log::warning('[WS] Invalid phase for answer', [
                'phase' => $gameState['phase'],
                'player_id' => $playerId,
            ]);
            return;
        }

        // 3. Validar respuesta
        $currentQuestion = $gameState['current_question'];
        $isCorrect = ($answer === $currentQuestion['correct_answer']);

        // 4. Actualizar game_state
        $gameState['answers'][$playerId] = $answer;
        $gameState['answer_times'][$playerId] = microtime(true);

        if ($isCorrect) {
            // Calcular puntos (más rápido = más puntos)
            $timeElapsed = microtime(true) - $gameState['question_start_time'];
            $maxPoints = 100;
            $timeBonus = max(0, 50 - ($timeElapsed * 2));
            $points = (int)($maxPoints + $timeBonus);

            $gameState['scores'][$playerId] = ($gameState['scores'][$playerId] ?? 0) + $points;
        }

        $gameState['actions_count'] = ($gameState['actions_count'] ?? 0) + 1;

        // 5. Guardar en Redis
        Cache::put($gameStateKey, $gameState, now()->addHours(2));

        // 6. Broadcast resultado
        $answeredCount = count($gameState['answers']);
        $totalPlayers = count($gameState['players']);

        broadcast(new PlayerActionEvent([
            'player_id' => $playerId,
            'action' => 'submit-answer',
            'is_correct' => $isCorrect,
            'points' => $isCorrect ? $points : 0,
            'scores' => $gameState['scores'],
            'answered_count' => $answeredCount,
            'total_players' => $totalPlayers,
        ]));

        Log::info('[WS] Answer processed', [
            'player_id' => $playerId,
            'is_correct' => $isCorrect,
            'answered' => "{$answeredCount}/{$totalPlayers}",
        ]);

        // 7. Verificar si todos respondieron
        if ($answeredCount === $totalPlayers) {
            $this->endRound($roomCode, $gameState);
        }

        // 8. Checkpoint cada 10 acciones
        if ($gameState['actions_count'] % 10 === 0) {
            $this->saveCheckpoint($roomCode, $gameState);
        }
    }

    /**
     * End round when all players answered
     */
    private function endRound(string $roomCode, array $gameState): void
    {
        // Calcular estadísticas de la ronda
        $correctCount = 0;
        foreach ($gameState['answers'] as $playerId => $answer) {
            if ($answer === $gameState['current_question']['correct_answer']) {
                $correctCount++;
            }
        }

        $results = [
            'question' => $gameState['current_question']['text'],
            'correct_answer' => $gameState['current_question']['correct_answer'],
            'answers' => $gameState['answers'],
            'scores' => $gameState['scores'],
            'statistics' => [
                'correct_count' => $correctCount,
                'total_players' => count($gameState['players']),
                'correct_percentage' => ($correctCount / count($gameState['players'])) * 100,
            ],
        ];

        // Actualizar game_state
        $gameState['phase'] = 'results';
        $gameState['question_results'] = $results;
        $gameStateKey = "game_state:{$roomCode}";
        Cache::put($gameStateKey, $gameState, now()->addHours(2));

        // Broadcast RoundEnded
        broadcast(new RoundEndedEvent([
            'room_code' => $roomCode,
            'game_state' => $gameState,
            'results' => $results,
        ]));

        Log::info('[WS] Round ended', [
            'room_code' => $roomCode,
            'correct_count' => "{$correctCount}/" . count($gameState['players']),
        ]);
    }

    /**
     * Execute callback with Redis lock
     */
    private function processWithLock(string $roomCode, callable $callback): void
    {
        $lockKey = "game_lock:{$roomCode}";
        $lock = Redis::lock($lockKey, 5); // 5 seconds timeout

        if ($lock->get()) {
            try {
                $callback();
            } finally {
                $lock->release();
            }
        } else {
            Log::warning('[WS] Failed to acquire lock', [
                'room_code' => $roomCode,
            ]);
        }
    }

    /**
     * Save checkpoint to database
     */
    private function saveCheckpoint(string $roomCode, array $gameState): void
    {
        try {
            $match = \App\Models\GameMatch::whereHas('room', function ($query) use ($roomCode) {
                $query->where('code', $roomCode);
            })->first();

            if ($match) {
                $match->update(['game_state' => $gameState]);
                Log::info('[WS] Checkpoint saved', [
                    'room_code' => $roomCode,
                    'actions_count' => $gameState['actions_count'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[WS] Error saving checkpoint', [
                'room_code' => $roomCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### 2.3 Registrar Listener

```php
<?php
// app/Providers/EventServiceProvider.php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Laravel\Reverb\Events\MessageReceived;
use App\Listeners\HandlePlayerAction;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected $listen = [
        // ... otros listeners
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        // Listener para mensajes WebSocket
        Event::listen(
            MessageReceived::class,
            HandlePlayerAction::class
        );
    }
}
```

---

## 3. Ejemplo 2: Pictionary - Drawing Events

### 3.1 Frontend (JavaScript)

```javascript
// resources/js/pictionary-game.js
import WebSocketHelper from './utils/WebSocketHelper.js';

class PictionaryGame extends BaseGameClient {
    constructor(config) {
        super(config);

        this.ws = new WebSocketHelper(this.roomCode);
        this.canvas = document.getElementById('drawing-canvas');
        this.ctx = this.canvas.getContext('2d');

        // Buffer para batch de puntos
        this.drawingBuffer = [];
        this.batchInterval = 50; // Enviar cada 50ms

        // Iniciar envío de batches
        this.startBatchSending();

        this.setupDrawingListeners();
    }

    /**
     * Setup canvas drawing
     */
    setupDrawingListeners() {
        let isDrawing = false;
        let lastX = 0;
        let lastY = 0;

        this.canvas.addEventListener('mousedown', (e) => {
            isDrawing = true;
            [lastX, lastY] = [e.offsetX, e.offsetY];
        });

        this.canvas.addEventListener('mousemove', (e) => {
            if (!isDrawing) return;

            const x = e.offsetX;
            const y = e.offsetY;

            // Dibujar localmente (instant UI)
            this.drawLine(lastX, lastY, x, y);

            // Añadir al buffer
            this.drawingBuffer.push({
                type: 'line',
                from: { x: lastX, y: lastY },
                to: { x, y },
                color: this.currentColor,
                width: this.brushWidth,
            });

            [lastX, lastY] = [x, y];
        });

        this.canvas.addEventListener('mouseup', () => {
            isDrawing = false;
        });
    }

    /**
     * Enviar batches de dibujo cada 50ms
     */
    startBatchSending() {
        setInterval(() => {
            if (this.drawingBuffer.length === 0) return;

            // Enviar batch via WebSocket
            this.ws.sendAction('draw-batch', {
                strokes: [...this.drawingBuffer],
            }, this.playerId);

            // Limpiar buffer
            this.drawingBuffer = [];
        }, this.batchInterval);
    }

    /**
     * Dibujar línea en canvas local
     */
    drawLine(x1, y1, x2, y2, color = '#000', width = 2) {
        this.ctx.strokeStyle = color;
        this.ctx.lineWidth = width;
        this.ctx.beginPath();
        this.ctx.moveTo(x1, y1);
        this.ctx.lineTo(x2, y2);
        this.ctx.stroke();
    }

    /**
     * Handler: Recibir dibujos de otros jugadores
     */
    handlePlayerActionPictionary(event) {
        if (event.action !== 'draw-batch') return;
        if (event.player_id === this.playerId) return; // Skip propio

        const { strokes } = event.data;

        // Dibujar cada stroke
        strokes.forEach(stroke => {
            if (stroke.type === 'line') {
                this.drawLine(
                    stroke.from.x,
                    stroke.from.y,
                    stroke.to.x,
                    stroke.to.y,
                    stroke.color,
                    stroke.width
                );
            }
        });
    }
}

export default PictionaryGame;
```

### 3.2 Backend (Listener)

```php
<?php
// app/Listeners/HandlePlayerAction.php (añadir método)

private function handleDraw(string $roomCode, object $data): void
{
    $playerId = $data->player_id;
    $strokes = $data->data->strokes;

    // Obtener game_state
    $gameStateKey = "game_state:{$roomCode}";
    $gameState = Cache::get($gameStateKey);

    if (!$gameState) {
        return;
    }

    // Validar que es el drawer actual
    if ($gameState['current_drawer_id'] !== $playerId) {
        Log::warning('[WS] Player is not the drawer', [
            'player_id' => $playerId,
            'current_drawer' => $gameState['current_drawer_id'],
        ]);
        return;
    }

    // Validar phase
    if ($gameState['phase'] !== 'drawing') {
        return;
    }

    // Guardar strokes en historial (opcional, para replay)
    if (!isset($gameState['drawing_history'])) {
        $gameState['drawing_history'] = [];
    }

    $gameState['drawing_history'] = array_merge(
        $gameState['drawing_history'],
        $strokes
    );

    Cache::put($gameStateKey, $gameState, now()->addHours(2));

    // Broadcast a otros jugadores
    broadcast(new PlayerActionEvent([
        'player_id' => $playerId,
        'action' => 'draw-batch',
        'data' => [
            'strokes' => $strokes,
        ],
    ]))->toOthers();

    // Log cada 100 strokes
    if (count($gameState['drawing_history']) % 100 === 0) {
        Log::info('[WS] Drawing progress', [
            'room_code' => $roomCode,
            'total_strokes' => count($gameState['drawing_history']),
        ]);
    }
}
```

---

## 4. Ejemplo 3: Manejo de Errores

### 4.1 Frontend con Retry

```javascript
// resources/js/utils/WebSocketHelper.js (añadir método)

/**
 * Enviar acción crítica con confirmación
 */
async sendCriticalAction(action, data, playerId) {
    const messageId = this.generateMessageId();
    const payload = {
        player_id: playerId,
        action: action,
        data: data,
        message_id: messageId,
        timestamp: Date.now()
    };

    // Guardar en pending
    this.pendingMessages.set(messageId, payload);

    // Enviar
    const success = this.sendAction(action, data, playerId);

    if (!success) {
        throw new Error('Failed to send action');
    }

    // Esperar confirmación (timeout 5s)
    return this.waitForConfirmation(messageId, 5000);
}

async waitForConfirmation(messageId, timeout) {
    return new Promise((resolve, reject) => {
        const timeoutId = setTimeout(() => {
            this.pendingMessages.delete(messageId);
            reject(new Error('Confirmation timeout'));
        }, timeout);

        // Listener de confirmación
        const confirmationHandler = (event) => {
            if (event.message_id === messageId) {
                clearTimeout(timeoutId);
                this.pendingMessages.delete(messageId);
                window.removeEventListener('action-confirmed', confirmationHandler);
                resolve(event);
            }
        };

        window.addEventListener('action-confirmed', confirmationHandler);
    });
}

generateMessageId() {
    return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}
```

### 4.2 Backend con Confirmación

```php
<?php
// app/Listeners/HandlePlayerAction.php (modificar)

private function handleSubmitAnswer(string $roomCode, object $data): void
{
    $messageId = $data->message_id ?? null;

    try {
        // ... procesamiento normal ...

        // Enviar confirmación
        if ($messageId) {
            broadcast(new \App\Events\ActionConfirmedEvent([
                'message_id' => $messageId,
                'success' => true,
                'player_id' => $playerId,
            ]));
        }

    } catch (\Exception $e) {
        Log::error('[WS] Error processing answer', [
            'error' => $e->getMessage(),
            'message_id' => $messageId,
        ]);

        // Enviar error al cliente
        if ($messageId) {
            broadcast(new \App\Events\ActionErrorEvent([
                'message_id' => $messageId,
                'error' => 'Failed to process answer',
                'code' => 'PROCESSING_ERROR',
            ]));
        }
    }
}
```

---

## 5. Ejemplo 4: Testing

### 5.1 Test de Integración

```php
<?php
// tests/Feature/WebSocketActionTest.php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Room;
use App\Models\GameMatch;
use App\Models\Player;
use App\Listeners\HandlePlayerAction;
use Laravel\Reverb\Events\MessageReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class WebSocketActionTest extends TestCase
{
    public function test_player_can_submit_answer_via_websocket()
    {
        // Arrange
        $room = Room::factory()->create(['status' => Room::STATUS_PLAYING]);
        $match = GameMatch::factory()->create(['room_id' => $room->id]);
        $player = Player::factory()->create(['match_id' => $match->id]);

        // Setup game_state en Redis
        $gameState = [
            'phase' => 'question',
            'players' => [$player->id],
            'scores' => [$player->id => 0],
            'answers' => [],
            'current_question' => [
                'text' => '¿Cuál es la capital de España?',
                'correct_answer' => 2,
                'options' => ['Barcelona', 'Valencia', 'Madrid', 'Sevilla'],
            ],
            'question_start_time' => microtime(true),
        ];

        Cache::put("game_state:{$room->code}", $gameState, now()->addHours(2));

        // Simular mensaje WebSocket
        $message = json_encode([
            'event' => 'player-action',
            'channel' => "presence-room.{$room->code}",
            'data' => json_encode([
                'player_id' => $player->id,
                'action' => 'submit-answer',
                'data' => [
                    'answer' => 2, // Correcto
                ],
            ]),
        ]);

        $mockConnection = \Mockery::mock(\Laravel\Reverb\Contracts\Connection::class);
        $mockConnection->shouldReceive('id')->andReturn('test-socket-id');

        $event = new MessageReceived($mockConnection, $message);

        // Act
        $listener = new HandlePlayerAction();
        $listener->handle($event);

        // Assert
        $updatedState = Cache::get("game_state:{$room->code}");

        $this->assertArrayHasKey($player->id, $updatedState['answers']);
        $this->assertEquals(2, $updatedState['answers'][$player->id]);
        $this->assertGreaterThan(0, $updatedState['scores'][$player->id]);
    }

    public function test_player_cannot_answer_twice()
    {
        // Arrange
        $room = Room::factory()->create(['status' => Room::STATUS_PLAYING]);
        $match = GameMatch::factory()->create(['room_id' => $room->id]);
        $player = Player::factory()->create(['match_id' => $match->id]);

        $gameState = [
            'phase' => 'question',
            'players' => [$player->id],
            'scores' => [$player->id => 100],
            'answers' => [$player->id => 1], // Ya respondió
            'current_question' => [
                'correct_answer' => 2,
            ],
            'question_start_time' => microtime(true),
        ];

        Cache::put("game_state:{$room->code}", $gameState, now()->addHours(2));

        // Act
        $message = json_encode([
            'event' => 'player-action',
            'channel' => "presence-room.{$room->code}",
            'data' => json_encode([
                'player_id' => $player->id,
                'action' => 'submit-answer',
                'data' => ['answer' => 2],
            ]),
        ]);

        $mockConnection = \Mockery::mock(\Laravel\Reverb\Contracts\Connection::class);
        $mockConnection->shouldReceive('id')->andReturn('test-socket-id');

        $event = new MessageReceived($mockConnection, $message);
        $listener = new HandlePlayerAction();
        $listener->handle($event);

        // Assert
        $updatedState = Cache::get("game_state:{$room->code}");

        // Score no debe cambiar
        $this->assertEquals(100, $updatedState['scores'][$player->id]);
        // Answer debe seguir siendo 1
        $this->assertEquals(1, $updatedState['answers'][$player->id]);
    }
}
```

### 5.2 Test de Performance

```php
<?php
// tests/Performance/WebSocketLoadTest.php

namespace Tests\Performance;

use Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class WebSocketLoadTest extends TestCase
{
    public function test_can_handle_10_concurrent_answers()
    {
        $room = Room::factory()->create();
        $match = GameMatch::factory()->create(['room_id' => $room->id]);
        $players = Player::factory()->count(10)->create(['match_id' => $match->id]);

        // Setup game_state
        $gameState = [
            'phase' => 'question',
            'players' => $players->pluck('id')->toArray(),
            'scores' => $players->mapWithKeys(fn($p) => [$p->id => 0])->toArray(),
            'answers' => [],
            'current_question' => ['correct_answer' => 2],
            'question_start_time' => microtime(true),
        ];

        Cache::put("game_state:{$room->code}", $gameState, now()->addHours(2));

        // Simular 10 respuestas simultáneas
        $startTime = microtime(true);

        $promises = [];
        foreach ($players as $player) {
            $promises[] = $this->sendAnswer($room->code, $player->id, rand(0, 3));
        }

        // Esperar a que todas terminen
        // En un test real usarías Promise::all() o similar

        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // ms

        // Assert
        $this->assertLessThan(500, $duration, 'Should process 10 answers in < 500ms');

        $updatedState = Cache::get("game_state:{$room->code}");
        $this->assertCount(10, $updatedState['answers']);
    }

    private function sendAnswer($roomCode, $playerId, $answer)
    {
        // Simular envío de respuesta
        // ...
    }
}
```

---

## 📚 Comandos Útiles

### Limpiar Redis Cache
```bash
php artisan cache:clear
redis-cli FLUSHDB
```

### Monitorear Reverb
```bash
php artisan reverb:start --debug
```

### Monitorear Redis
```bash
redis-cli MONITOR
```

### Ver Logs en Tiempo Real
```bash
tail -f storage/logs/laravel.log | grep WS
```

---

## 🎯 Checklist de Implementación

### Frontend
- [ ] Crear `WebSocketHelper.js`
- [ ] Modificar juego para usar `ws.sendAction()`
- [ ] Implementar retry logic
- [ ] Añadir confirmación de mensajes
- [ ] Actualizar handlers de eventos
- [ ] Tests E2E con Cypress/Playwright

### Backend
- [ ] Crear `HandlePlayerAction` listener
- [ ] Registrar listener en `EventServiceProvider`
- [ ] Implementar locks de Redis
- [ ] Añadir validaciones
- [ ] Implementar checkpoints
- [ ] Tests unitarios e integración

### DevOps
- [ ] Configurar Redis
- [ ] Ajustar timeouts de Reverb
- [ ] Añadir monitoring (Pulse/Telescope)
- [ ] Configurar logs estructurados
- [ ] Load testing con k6

---

**Última actualización:** 2025-10-26
