# Diseño BaseGameEngine + BaseGameClient

**Fecha:** 26 Octubre 2025
**Objetivo:** Sistema modular reutilizable para todos los juegos
**Principio:** 0 queries durante el juego, WebSocket bidireccional, Redis state

---

## 🎯 Objetivos

1. **BaseGameEngine** debe tener TODA la lógica común del backend
2. **BaseGameClient** debe tener TODA la lógica común del frontend
3. **Los juegos** solo implementan su lógica específica
4. **0 queries a MySQL** durante el juego (solo Redis + WebSocket)
5. **WebSocket bidireccional** - Cliente envía acciones via WebSocket, no HTTP POST

---

## 📦 Arquitectura del Sistema

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          FRONTEND (Cliente)                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  BaseGameClient (resources/js/core/BaseGameClient.js)                  │
│  ├── WebSocket Manager (enviar/recibir)                                │
│  ├── Event Listeners (round.started, round.ended, etc.)                │
│  ├── State Synchronizer (sincronizar con servidor)                     │
│  ├── Action Sender (enviar acciones via WebSocket)                     │
│  ├── Reconnection Handler (handle disconnect/reconnect)                │
│  └── UI Helpers (scores, players, messages)                            │
│                                                                          │
│  TriviaGameClient extends BaseGameClient                               │
│  ├── displayQuestion()                                                  │
│  ├── sendAnswer()  ← Usa BaseGameClient.sendGameAction()              │
│  └── handleAnswer\Result()                                               │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ▲  ▼
                            WebSocket (Laravel Reverb)
                                    ▲  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                          BACKEND (Servidor)                             │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  BaseGameEngine (app/Contracts/BaseGameEngine.php)                     │
│  ├── WebSocket Action Handler (procesar acciones del cliente)          │
│  ├── Redis State Manager (cargar/guardar state)                        │
│  ├── Event Broadcaster (broadcast a room via WebSocket)                │
│  ├── Reconnection Handler (graceful disconnect/reconnect)              │
│  ├── Module System (Round, Timer, Score, PlayerState)                  │
│  └── Validation (authoritative server, no confiar en cliente)          │
│                                                                          │
│  TriviaEngine extends BaseGameEngine                                   │
│  ├── processRoundAction($action = 'answer')                            │
│  │   ├── validateAnswer()                                               │
│  │   ├── calculatePoints()                                              │
│  │   └── updateState()                                                  │
│  ├── startNewRound()                                                    │
│  │   └── loadNextQuestion()                                             │
│  └── endCurrentRound()                                                  │
│      └── getAllPlayerResults()                                          │
│                                                                          │
└─────────────────────────────────────────────────────────────────────────┘
                                    ▲  ▼
                                Redis (State)
                                MySQL (Persistencia)
```

---

## 🔧 BaseGameEngine - Mejoras Necesarias

### Situación Actual

```php
// ✅ YA TIENE:
- Sistema de módulos completo
- Helpers para RoundManager, ScoreManager, PlayerStateManager
- Strategy Pattern para fin de ronda
- Events (RoundStarted, RoundEnded, GameFinished)
- Hook de disconnect/reconnect (vacíos)

// ❌ LE FALTA:
- WebSocket Action Handler (client → server)
- Redis State Manager (todo usa MySQL game_state)
- Graceful disconnect/reconnect logic
- Broadcast helpers optimizados
- Player data cache en game_state
```

### Nuevos Métodos a Agregar

```php
abstract class BaseGameEngine
{
    // ========================================================================
    // WEBSOCKET ACTION HANDLING (client → server)
    // ========================================================================

    /**
     * Procesar acción recibida via WebSocket.
     *
     * Este método:
     * 1. Valida que el jugador existe (desde game_state, NO query)
     * 2. Valida que la acción es válida en la fase actual
     * 3. Aplica rate limiting
     * 4. Delega al processAction() existente
     * 5. Broadcast resultado a todos
     *
     * @param GameMatch $match
     * @param int $playerId ID del jugador (desde WebSocket auth)
     * @param string $action Tipo de acción ('answer', 'draw', 'vote', etc.)
     * @param array $data Datos de la acción
     * @return array Resultado
     */
    public function handleWebSocketAction(
        GameMatch $match,
        int $playerId,
        string $action,
        array $data
    ): array {
        Log::info("[{$this->getGameSlug()}] WebSocket action received", [
            'match_id' => $match->id,
            'player_id' => $playerId,
            'action' => $action
        ]);

        // 1. Obtener Player desde game_state (NO query!)
        $player = $this->getPlayerFromState($match, $playerId);

        if (!$player) {
            return [
                'success' => false,
                'error' => 'Player not found',
            ];
        }

        // 2. Validar fase del juego
        if (!$this->canPlayerActInPhase($match, $action)) {
            return [
                'success' => false,
                'error' => 'Invalid action in current phase',
            ];
        }

        // 3. Rate limiting (evitar spam)
        if (!$this->checkRateLimit($playerId, $action)) {
            return [
                'success' => false,
                'error' => 'Rate limit exceeded',
            ];
        }

        // 4. Procesar acción (usa el método existente)
        try {
            $result = $this->processAction($match, $player, $action, $data);

            // 5. Broadcast resultado a todos (optimizado)
            $this->broadcastActionResult($match, $playerId, $action, $result);

            return array_merge(['success' => true], $result);

        } catch (\Exception $e) {
            Log::error("[{$this->getGameSlug()}] Error processing action", [
                'match_id' => $match->id,
                'player_id' => $playerId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener datos del jugador desde game_state (SIN query).
     *
     * Los datos del jugador se guardan en _config al inicializar el juego.
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

        // Crear Player object desde datos en memoria (NO query!)
        $player = new Player();
        $player->id = $playerData['id'];
        $player->name = $playerData['name'];
        $player->user_id = $playerData['user_id'];
        $player->avatar = $playerData['avatar'] ?? null;

        // NO hacer fillable, solo leer
        $player->exists = true;

        return $player;
    }

    /**
     * Validar si el jugador puede actuar en la fase actual.
     *
     * @param GameMatch $match
     * @param string $action
     * @return bool
     */
    protected function canPlayerActInPhase(GameMatch $match, string $action): bool
    {
        $phase = $match->game_state['phase'] ?? 'unknown';

        // Por defecto, solo permitir acciones durante 'playing'
        // Los juegos pueden sobrescribir para lógica custom
        return $phase === 'playing';
    }

    /**
     * Rate limiting para prevenir spam.
     *
     * @param int $playerId
     * @param string $action
     * @return bool
     */
    protected function checkRateLimit(int $playerId, string $action): bool
    {
        $key = "game:action:{$playerId}:{$action}";

        // Max 10 acciones por minuto por defecto
        return RateLimiter::attempt(
            $key,
            $perMinute = 60,
            function() {
                // Allow
            }
        );
    }

    /**
     * Broadcast resultado de acción a todos los jugadores.
     *
     * Usa delta updates en lugar de enviar todo el state.
     *
     * @param GameMatch $match
     * @param int $playerId
     * @param string $action
     * @param array $result
     * @return void
     */
    protected function broadcastActionResult(
        GameMatch $match,
        int $playerId,
        string $action,
        array $result
    ): void {
        // Solo enviar lo que cambió
        $deltaData = [
            'player_id' => $playerId,
            'action' => $action,
            'success' => $result['success'] ?? false,
            'timestamp' => now()->toDateTimeString(),
        ];

        // Agregar solo datos relevantes (NO todo el game_state)
        if (isset($result['points'])) {
            $deltaData['points'] = $result['points'];
        }

        if (isset($result['correct'])) {
            $deltaData['correct'] = $result['correct'];
        }

        broadcast(new \App\Events\Game\PlayerActionEvent(
            match: $match,
            data: $deltaData
        ))->toOthers();
    }

    // ========================================================================
    // REDIS STATE MANAGEMENT
    // ========================================================================

    /**
     * Cargar game_state desde Redis.
     *
     * @param int $matchId
     * @return array|null
     */
    protected function loadStateFromRedis(int $matchId): ?array
    {
        $state = Redis::get("game:match:{$matchId}:state");

        if (!$state) {
            return null;
        }

        return json_decode($state, true);
    }

    /**
     * Guardar game_state a Redis.
     *
     * @param int $matchId
     * @param array $state
     * @param int $ttl Tiempo de vida en segundos (default: 1 hora)
     * @return void
     */
    protected function saveStateToRedis(int $matchId, array $state, int $ttl = 3600): void
    {
        Redis::setex(
            "game:match:{$matchId}:state",
            $ttl,
            json_encode($state)
        );
    }

    /**
     * Sincronizar state: Redis → MySQL.
     *
     * Llamar periódicamente (cada N rondas) como checkpoint.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function syncStateToMySQL(GameMatch $match): void
    {
        $redisState = $this->loadStateFromRedis($match->id);

        if ($redisState) {
            $match->game_state = $redisState;
            $match->save();

            Log::info("[{$this->getGameSlug()}] State synced to MySQL", [
                'match_id' => $match->id
            ]);
        }
    }

    /**
     * Inicializar cache de jugadores en game_state.
     *
     * Esto se llama UNA VEZ en initialize() para guardar los datos de todos
     * los jugadores en _config y evitar queries durante el juego.
     *
     * @param GameMatch $match
     * @return void
     */
    protected function cachePlayersInState(GameMatch $match): void
    {
        $players = $match->players; // ← Query SOLO aquí (1 vez)

        $playersData = [];

        foreach ($players as $player) {
            $playersData[$player->id] = [
                'id' => $player->id,
                'name' => $player->name,
                'user_id' => $player->user_id,
                'avatar' => $player->avatar,
            ];
        }

        $gameState = $match->game_state;
        $gameState['_config']['players'] = $playersData;
        $match->game_state = $gameState;
        $match->save();

        // También guardar en Redis
        $this->saveStateToRedis($match->id, $gameState);

        Log::info("[{$this->getGameSlug()}] Players cached in state", [
            'match_id' => $match->id,
            'player_count' => count($playersData)
        ]);
    }

    // ========================================================================
    // GRACEFUL DISCONNECT/RECONNECT
    // ========================================================================

    /**
     * Manejar desconexión de jugador.
     *
     * Implementación mejorada con grace period.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    public function handlePlayerDisconnect(GameMatch $match, Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player disconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Marcar como disconnected (NO eliminar)
        $playerState = $this->getPlayerStateManager($match);
        $playerState->setPlayerState($player->id, [
            'status' => 'disconnected',
            'disconnected_at' => now()->toDateTimeString(),
        ]);
        $this->savePlayerStateManager($match, $playerState);

        // Broadcast a otros jugadores
        broadcast(new \App\Events\Game\PlayerDisconnectedEvent(
            match: $match,
            playerId: $player->id
        ))->toOthers();

        // Programar removal si no reconecta en 30 segundos
        dispatch(new \App\Jobs\RemoveInactivePlayer($match, $player))
            ->delay(now()->addSeconds(30));
    }

    /**
     * Manejar reconexión de jugador.
     *
     * Implementación mejorada con resync de state.
     *
     * @param GameMatch $match
     * @param Player $player
     * @return void
     */
    public function handlePlayerReconnect(GameMatch $match, Player $player): void
    {
        Log::info("[{$this->getGameSlug()}] Player reconnected", [
            'match_id' => $match->id,
            'player_id' => $player->id
        ]);

        // Marcar como active
        $playerState = $this->getPlayerStateManager($match);
        $playerState->setPlayerState($player->id, [
            'status' => 'active',
            'reconnected_at' => now()->toDateTimeString(),
        ]);
        $this->savePlayerStateManager($match, $playerState);

        // Broadcast a otros jugadores
        broadcast(new \App\Events\Game\PlayerReconnectedEvent(
            match: $match,
            playerId: $player->id
        ))->toOthers();

        // Enviar state actual al jugador reconectado (solo a él)
        broadcast(new \App\Events\Game\StateSyncEvent(
            match: $match,
            gameState: $this->getGameStateForPlayer($match, $player)
        ))->toOthers(); // Laravel filtra por socket_id
    }
}
```

---

## 🎨 BaseGameClient - Mejoras Necesarias

### Situación Actual

```javascript
// ✅ YA TIENE:
- Event listeners genéricos
- TimingModule
- Helpers (getPlayer, getScore, etc.)
- sendAction() con HTTP POST

// ❌ LE FALTA:
- WebSocket action sender
- Reconnection handler
- State synchronizer
- Action queue (offline actions)
- Optimistic updates
```

### Nuevos Métodos a Agregar

```javascript
export class BaseGameClient {
    constructor(config) {
        // ... existing code ...

        // Nuevo: Queue para acciones offline
        this.actionQueue = [];

        // Nuevo: Estado de conexión
        this.isConnected = true;
        this.isReconnecting = false;

        // Setup reconnection listeners
        this.setupReconnectionHandlers();
    }

    // ========================================================================
    // WEBSOCKET ACTION SENDING (client → server)
    // ========================================================================

    /**
     * Enviar acción de juego via WebSocket.
     *
     * REEMPLAZA a sendAction() que usa HTTP POST.
     *
     * @param {string} action - Tipo de acción ('answer', 'draw', 'vote', etc.)
     * @param {object} data - Datos de la acción
     * @param {boolean} optimistic - Si aplicar optimistic update
     * @returns {Promise<object>} Resultado
     */
    async sendGameAction(action, data, optimistic = false) {
        console.log(`📤 [BaseGameClient] Sending action via WebSocket:`, action, data);

        // Si no está conectado, encolar
        if (!this.isConnected) {
            console.warn('⚠️  [BaseGameClient] Not connected, queueing action');
            this.actionQueue.push({ action, data, optimistic });
            return { success: false, queued: true };
        }

        // Optimistic update (opcional)
        if (optimistic) {
            this.applyOptimisticUpdate(action, data);
        }

        // Enviar via WebSocket usando whisper
        const channel = window.Echo.private(`room.${this.roomCode}`);

        channel.whisper('game.action', {
            action: action,
            data: data,
            player_id: this.playerId,
            timestamp: Date.now(),
        });

        // Esperar confirmación del servidor via evento
        return new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                reject(new Error('Action timeout'));
            }, 5000);

            // Listener para la confirmación
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
     * Aplicar optimistic update (cambio visual inmediato).
     *
     * Los juegos específicos sobrescriben esto.
     *
     * @param {string} action
     * @param {object} data
     */
    applyOptimisticUpdate(action, data) {
        // Los juegos específicos implementan esto
        // Ejemplo en Trivia: deshabilitar botones, marcar selección
    }

    /**
     * Revertir optimistic update si falló.
     *
     * @param {string} action
     * @param {object} data
     */
    revertOptimisticUpdate(action, data) {
        // Los juegos específicos implementan esto
        // Ejemplo en Trivia: re-habilitar botones
    }

    // ========================================================================
    // RECONNECTION HANDLING
    // ========================================================================

    /**
     * Setup de handlers de reconexión.
     */
    setupReconnectionHandlers() {
        if (!window.Echo?.connector?.socket) {
            console.warn('⚠️  Echo not available for reconnection handling');
            return;
        }

        const socket = window.Echo.connector.socket;

        // Disconnect
        socket.on('disconnect', () => {
            console.warn('⚠️  [BaseGameClient] WebSocket disconnected');
            this.isConnected = false;
            this.onDisconnect();
        });

        // Reconnecting
        socket.on('reconnecting', (attemptNumber) => {
            console.log(`🔄 [BaseGameClient] Reconnecting (attempt ${attemptNumber})...`);
            this.isReconnecting = true;
            this.onReconnecting(attemptNumber);
        });

        // Reconnect
        socket.on('reconnect', () => {
            console.log('✅ [BaseGameClient] WebSocket reconnected');
            this.isConnected = true;
            this.isReconnecting = false;
            this.onReconnect();
        });

        // Reconnect failed
        socket.on('reconnect_failed', () => {
            console.error('❌ [BaseGameClient] Reconnection failed');
            this.onReconnectFailed();
        });
    }

    /**
     * Callback: Desconectado.
     *
     * Los juegos específicos pueden sobrescribir esto.
     */
    onDisconnect() {
        this.showMessage('Conexión perdida. Reconectando...', 'warning');
    }

    /**
     * Callback: Reconectando.
     *
     * @param {number} attemptNumber
     */
    onReconnecting(attemptNumber) {
        this.showMessage(`Reconectando (intento ${attemptNumber})...`, 'info');
    }

    /**
     * Callback: Reconectado.
     *
     * Sincroniza estado con el servidor y procesa acciones en cola.
     */
    async onReconnect() {
        this.showMessage('Reconectado. Sincronizando...', 'success');

        // 1. Solicitar estado actual del servidor
        await this.syncState();

        // 2. Procesar acciones en cola
        await this.processActionQueue();

        this.showMessage('Sincronización completa', 'success');
    }

    /**
     * Callback: Reconexión fallida.
     */
    onReconnectFailed() {
        this.showMessage('No se pudo reconectar. Recarga la página.', 'error');
    }

    /**
     * Sincronizar estado con el servidor.
     */
    async syncState() {
        console.log('🔄 [BaseGameClient] Syncing state from server...');

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/state`);
            const data = await response.json();

            // Actualizar estado local
            this.gameState = data.game_state;
            this.scores = data.game_state.scoring_system?.scores || {};
            this.currentRound = data.game_state.round_system?.current_round || 1;

            // Los juegos específicos sobrescriben onStateSync() para actualizar UI
            this.onStateSync(data.game_state);

            console.log('✅ [BaseGameClient] State synced');
        } catch (error) {
            console.error('❌ [BaseGameClient] Error syncing state:', error);
        }
    }

    /**
     * Callback: Estado sincronizado.
     *
     * Los juegos específicos sobrescriben esto para actualizar UI.
     *
     * @param {object} gameState
     */
    onStateSync(gameState) {
        // Los juegos específicos implementan esto
        // Ejemplo en Trivia: displayQuestion(gameState.current_question)
    }

    /**
     * Procesar acciones en cola (después de reconectar).
     */
    async processActionQueue() {
        if (this.actionQueue.length === 0) {
            return;
        }

        console.log(`📤 [BaseGameClient] Processing ${this.actionQueue.length} queued actions...`);

        for (const queuedAction of this.actionQueue) {
            try {
                await this.sendGameAction(
                    queuedAction.action,
                    queuedAction.data,
                    queuedAction.optimistic
                );
            } catch (error) {
                console.error('❌ [BaseGameClient] Error processing queued action:', error);
            }
        }

        this.actionQueue = [];
        console.log('✅ [BaseGameClient] Action queue processed');
    }

    // ========================================================================
    // STATE SYNCHRONIZATION
    // ========================================================================

    /**
     * Aplicar delta update al estado local.
     *
     * En lugar de recibir todo el state, solo aplicar cambios incrementales.
     *
     * @param {object} delta - Cambios a aplicar
     */
    applyStateDelta(delta) {
        console.log('🔄 [BaseGameClient] Applying state delta:', delta);

        // Actualizar scores si vienen en el delta
        if (delta.scores) {
            Object.assign(this.scores, delta.scores);
        }

        // Actualizar round si viene en el delta
        if (delta.current_round !== undefined) {
            this.currentRound = delta.current_round;
        }

        // Los juegos específicos sobrescriben onDeltaApplied()
        this.onDeltaApplied(delta);
    }

    /**
     * Callback: Delta aplicado.
     *
     * @param {object} delta
     */
    onDeltaApplied(delta) {
        // Los juegos específicos implementan esto
    }
}
```

---

## 🔄 Flujo Completo con WebSocket Bidireccional

### Ejemplo: Responder pregunta en Trivia

```
┌────────────────────────────────────────────────────────────────────────┐
│ 1. CLIENTE: Jugador hace clic en opción 2                             │
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
        trivia.sendGameAction('answer', { answer_index: 2 }, true)
                                    │
                                    ├─ Optimistic: Deshabilitar botones
                                    ├─ WebSocket whisper: game.action
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ 2. SERVIDOR: Reverb recibe whisper                                    │
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
        WebSocketActionListener::handle($event)
                                    │
                                    ├─ Cargar match desde Redis (1-2ms)
                                    ├─ Llamar engine->handleWebSocketAction()
                                    │
                                    ▼
        TriviaEngine::processRoundAction()
                                    │
                                    ├─ Validar answer (desde game_state, 0 queries)
                                    ├─ Calcular puntos
                                    ├─ Actualizar Redis
                                    │
                                    ▼
        Broadcast: PlayerActionEvent
                                    │
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│ 3. TODOS LOS CLIENTES: Reciben PlayerActionEvent                      │
└────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
        trivia.handlePlayerAction(event)
                                    │
                                    ├─ Si soy yo: Marcar respuesta como correcta/incorrecta
                                    ├─ Si es otro: Mostrar "Juan respondió"
                                    │
                                    ▼
                              UI actualizada

```

**Latencia total:** ~10-20ms (vs ~100-200ms con HTTP POST)

**Queries a MySQL:** 0 (vs 3 con HTTP POST)

---

## 📝 Checklist de Implementación

### Backend (BaseGameEngine)

- [ ] Agregar `handleWebSocketAction()`
- [ ] Agregar `getPlayerFromState()` (sin query)
- [ ] Agregar `canPlayerActInPhase()`
- [ ] Agregar `checkRateLimit()`
- [ ] Agregar `broadcastActionResult()` (delta updates)
- [ ] Agregar `loadStateFromRedis()`
- [ ] Agregar `saveStateToRedis()`
- [ ] Agregar `syncStateToMySQL()` (checkpoints)
- [ ] Agregar `cachePlayersInState()` (llamar en initialize())
- [ ] Mejorar `handlePlayerDisconnect()` (grace period)
- [ ] Mejorar `handlePlayerReconnect()` (resync state)
- [ ] Crear `WebSocketActionListener` (escuchar whispers)
- [ ] Crear eventos: `PlayerActionEvent`, `PlayerDisconnectedEvent`, `PlayerReconnectedEvent`, `StateSyncEvent`
- [ ] Crear job: `RemoveInactivePlayer`

### Frontend (BaseGameClient)

- [ ] Agregar `sendGameAction()` (WebSocket en lugar de HTTP)
- [ ] Agregar `applyOptimisticUpdate()`
- [ ] Agregar `revertOptimisticUpdate()`
- [ ] Agregar `setupReconnectionHandlers()`
- [ ] Agregar `onDisconnect()`
- [ ] Agregar `onReconnecting()`
- [ ] Agregar `onReconnect()`
- [ ] Agregar `onReconnectFailed()`
- [ ] Agregar `syncState()`
- [ ] Agregar `onStateSync()`
- [ ] Agregar `processActionQueue()`
- [ ] Agregar `applyStateDelta()`
- [ ] Agregar `onDeltaApplied()`
- [ ] Agregar listeners para nuevos eventos

### Refactorización Trivia

- [ ] Eliminar `TriviaController::submitAnswer()` (HTTP endpoint)
- [ ] Eliminar ruta `POST /api/trivia/{code}/answer`
- [ ] Modificar `TriviaEngine::processRoundAction()` para usar `getPlayerFromState()`
- [ ] Modificar `game.blade.php` para usar `sendGameAction()` en lugar de fetch
- [ ] Probar flujo completo con 4 jugadores
- [ ] Probar disconnect/reconnect
- [ ] Benchmark latencia y queries

---

## 🚀 Estimación de Tiempo

| Tarea | Tiempo |
|-------|--------|
| Backend: Agregar métodos WebSocket | 3h |
| Backend: Redis State Manager | 2h |
| Backend: Graceful disconnect/reconnect | 2h |
| Backend: Events y listeners | 1h |
| Frontend: Agregar métodos WebSocket | 2h |
| Frontend: Reconnection handlers | 2h |
| Frontend: State sync | 1h |
| Refactorizar Trivia | 3h |
| Testing completo | 4h |
| **TOTAL** | **~20h (2.5 días)** |

---

## 📈 Mejoras Esperadas

| Métrica | Antes | Después | Mejora |
|---------|-------|---------|--------|
| Latencia por acción | 100-200ms | 10-20ms | ~10x |
| Queries por acción | 3 | 0 | ∞ |
| Overhead de red | ~1KB | ~10 bytes | ~100x |
| Reconexión | Manual (reload) | Automática | ✅ |
| Offline actions | Perdidas | Encoladas | ✅ |
| Optimistic UI | No | Sí | ✅ |

---

## ✅ Próximos Pasos

1. Implementar mejoras en `BaseGameEngine`
2. Implementar mejoras en `BaseGameClient`
3. Crear `WebSocketActionListener`
4. Refactorizar Trivia
5. Probar con 4 jugadores
6. Documentar para nuevos juegos
