# Sistema de Desconexión/Reconexión de Jugadores

## Descripción

Sistema automático que detecta cuando un jugador se desconecta durante una partida activa, pausa el juego, muestra un popup a los demás jugadores, y reinicia la ronda cuando el jugador se reconecta.

## Características

- ✅ **Detección automática**: Usando Laravel Echo Presence Channels
- ✅ **Pausa automática**: El juego se pausa cuando un jugador se desconecta
- ✅ **Timer pausado**: El timer de ronda se pausa automáticamente
- ✅ **Popup informativo**: Todos los jugadores ven quién se desconectó
- ✅ **Reinicio de ronda**: Cuando el jugador vuelve, se reinicia la ronda actual
- ✅ **Extensible**: Los juegos pueden personalizar el comportamiento mediante hooks
- ✅ **Zero boilerplate**: Funciona automáticamente para todos los juegos

## Arquitectura

### Backend (PHP/Laravel)

#### 1. Eventos WebSocket

**`app/Events/Game/PlayerDisconnectedEvent.php`**
```php
// Se emite cuando un jugador se desconecta DURANTE la partida
// Datos: player_id, player_name, game_phase, current_round
// Canal: presence-room.{roomCode}
// Evento: game.player.disconnected
```

**`app/Events/Game/PlayerReconnectedEvent.php`**
```php
// Se emite cuando un jugador se reconecta
// Datos: player_id, player_name, game_phase, current_round, should_restart_round
// Canal: presence-room.{roomCode}
// Evento: game.player.reconnected
```

#### 2. BaseGameEngine - Métodos Públicos

**`app/Contracts/BaseGameEngine.php`**

```php
/**
 * Manejar desconexión de jugador DURANTE la partida.
 *
 * Comportamiento por defecto:
 * - Pausa el timer si existe
 * - Marca game_state['paused'] = true
 * - Emite PlayerDisconnectedEvent
 */
public function onPlayerDisconnected(GameMatch $match, Player $player): void

/**
 * Manejar reconexión de jugador.
 *
 * Comportamiento por defecto:
 * - Quita el estado de pausa
 * - Reinicia la ronda actual (handleNewRound con advanceRound: false)
 * - Emite PlayerReconnectedEvent
 */
public function onPlayerReconnected(GameMatch $match, Player $player): void
```

#### 3. Hooks Opcionales para Juegos

```php
/**
 * Hook ejecutado ANTES de pausar el juego.
 * Útil para: guardar estado temporal, notificar a servicios externos, etc.
 */
protected function beforePlayerDisconnectedPause(GameMatch $match, Player $player): void

/**
 * Hook ejecutado DESPUÉS de reconectar.
 * Útil para: restaurar estado del jugador, enviar notificaciones, etc.
 */
protected function afterPlayerReconnected(GameMatch $match, Player $player): void
```

#### 4. API Endpoints

**`routes/api.php`**
```php
POST /api/rooms/{code}/player-disconnected
POST /api/rooms/{code}/player-reconnected
```

**`app/Http/Controllers/PlayController.php`**

```php
// Valida que:
// - La sala exista
// - Haya una partida activa
// - El juego esté en fase "playing" (solo para disconnected)
// - El jugador exista
// Luego llama a $engine->onPlayerDisconnected() o onPlayerReconnected()
public function apiPlayerDisconnected(Request $request, string $code)
public function apiPlayerReconnected(Request $request, string $code)
```

### Frontend (JavaScript)

#### 1. PresenceMonitor Module

**`resources/js/modules/PresenceMonitor.js`**

```javascript
/**
 * Módulo que monitorea el Presence Channel y detecta desconexiones.
 *
 * - Se inicializa automáticamente en BaseGameClient
 * - Escucha eventos leaving/joining del presence channel
 * - Notifica al backend cuando detecta una desconexión
 * - El backend decide si debe procesarse (valida fase "playing")
 */
class PresenceMonitor {
    start()                              // Iniciar monitoreo
    setPhase(phase)                      // Actualizar fase del juego
    handlePlayerDisconnected(user)       // Notificar backend
    handlePlayerReconnected(user)        // Notificar backend
    stop()                               // Detener monitoreo
}
```

#### 2. BaseGameClient - Handlers

**`resources/js/core/BaseGameClient.js`**

```javascript
/**
 * Handler cuando un jugador se desconecta.
 *
 * Acciones:
 * - Pausa el timer frontend (clearTimer)
 * - Dispatch evento custom 'game:player:disconnected'
 * - El popup Blade escucha este evento y se muestra
 */
handlePlayerDisconnected(event)

/**
 * Handler cuando un jugador se reconecta.
 *
 * Acciones:
 * - Dispatch evento custom 'game:player:reconnected'
 * - El popup Blade escucha este evento y se oculta
 * - Espera RoundStartedEvent del backend (reinicio de ronda)
 */
handlePlayerReconnected(event)
```

#### 3. Popup Component

**`resources/views/components/game/player-disconnected-popup.blade.php`**

```blade
{{-- Componente Blade reutilizable --}}
<x-game.player-disconnected-popup />

// Escucha eventos custom del window:
// - 'game:player:disconnected' → muestra popup
// - 'game:player:reconnected' → oculta popup

// Muestra:
// - Nombre del jugador desconectado
// - Mensaje "El juego está pausado"
// - Animación de espera
// - Info de ronda y fase actual
```

### Configuración de Eventos

**`config/game-events.php`**

```php
'PlayerDisconnectedEvent' => [
    'name' => 'game.player.disconnected',
    'handler' => 'handlePlayerDisconnected'
],
'PlayerReconnectedEvent' => [
    'name' => 'game.player.reconnected',
    'handler' => 'handlePlayerReconnected'
],
```

## Flujo Completo

### 1. Desconexión

```
1. Usuario cierra tab/pierde conexión
   ↓
2. Laravel Echo detecta 'leaving' en presence channel
   ↓
3. PresenceMonitor detecta el evento
   → POST /api/rooms/{code}/player-disconnected
   ↓
4. PlayController::apiPlayerDisconnected()
   → Valida: sala existe, partida activa, fase = "playing"
   → $engine->onPlayerDisconnected($match, $player)
   ↓
5. BaseGameEngine::onPlayerDisconnected()
   → beforePlayerDisconnectedPause() hook (opcional)
   → Pausa timer (TimerService)
   → game_state['paused'] = true
   → Guarda en BD
   → event(PlayerDisconnectedEvent)
   ↓
6. PlayerDisconnectedEvent broadcast via WebSocket
   → Todos los clientes reciben el evento
   ↓
7. BaseGameClient::handlePlayerDisconnected()
   → Pausa timer frontend
   → window.dispatchEvent('game:player:disconnected')
   ↓
8. Popup Blade escucha evento
   → Muestra popup con nombre del jugador
   → "El juego está pausado hasta que se reconecte"
```

### 2. Reconexión

```
1. Usuario vuelve a la página
   ↓
2. Laravel Echo detecta 'joining' en presence channel
   ↓
3. PresenceMonitor detecta el evento
   → Si estaba en notifiedDisconnections
   → POST /api/rooms/{code}/player-reconnected
   ↓
4. PlayController::apiPlayerReconnected()
   → Valida: sala existe, partida activa, game_state['paused'] = true
   → $engine->onPlayerReconnected($match, $player)
   ↓
5. BaseGameEngine::onPlayerReconnected()
   → game_state['paused'] = false
   → Guarda en BD
   → afterPlayerReconnected() hook (opcional)
   → handleNewRound($match, advanceRound: false) // Reinicia ronda actual
   → event(PlayerReconnectedEvent)
   ↓
6. PlayerReconnectedEvent broadcast via WebSocket
   → Todos los clientes reciben el evento
   ↓
7. BaseGameClient::handlePlayerReconnected()
   → window.dispatchEvent('game:player:reconnected')
   ↓
8. Popup Blade escucha evento
   → Oculta popup
   ↓
9. RoundStartedEvent llega
   → BaseGameClient::handleRoundStarted()
   → Reinicia timer, muestra nueva pregunta/ronda
```

## Integración en Juegos

### Paso 1: Incluir Popup en Vista (Ya hecho en Trivia)

```blade
{{-- En games/{slug}/views/game.blade.php --}}
<x-game.player-disconnected-popup />
```

### Paso 2: (Opcional) Personalizar Comportamiento

Si un juego necesita comportamiento diferente al default:

**Opción A: Usar Hooks**

```php
// En games/trivia/TriviaEngine.php
protected function beforePlayerDisconnectedPause(GameMatch $match, Player $player): void
{
    // Guardar respuesta parcial del jugador
    $gameState = $match->game_state;
    $gameState['partial_answers'][$player->id] = $gameState['current_answer'] ?? null;
    $match->game_state = $gameState;
    $match->save();
}

protected function afterPlayerReconnected(GameMatch $match, Player $player): void
{
    // Restaurar respuesta parcial
    $gameState = $match->game_state;
    if (isset($gameState['partial_answers'][$player->id])) {
        // Restaurar...
        unset($gameState['partial_answers'][$player->id]);
        $match->game_state = $gameState;
        $match->save();
    }
}
```

**Opción B: Sobrescribir Completamente**

```php
// En games/custom-game/CustomGameEngine.php
public function onPlayerDisconnected(GameMatch $match, Player $player): void
{
    // Comportamiento custom: NO pausar, continuar sin el jugador
    Log::info("Player {$player->name} disconnected, continuing without them");

    // Marcar jugador como inactivo pero no pausar
    $gameState = $match->game_state;
    $gameState['inactive_players'][] = $player->id;
    $match->game_state = $gameState;
    $match->save();

    // Emitir evento custom
    event(new PlayerDisconnectedEvent($match, $player));
}
```

## Casos de Uso

### Caso 1: Juego de Preguntas (Trivia)
- **Desconexión**: Pausa ronda, espera reconexión
- **Reconexión**: Reinicia pregunta actual para que todos respondan de nuevo
- **Razón**: Fairness - todos deben tener el mismo tiempo para responder

### Caso 2: Juego por Turnos (Chess, Ajedrez)
```php
// No pausar el juego, simplemente marcar turno como perdido
public function onPlayerDisconnected(GameMatch $match, Player $player): void
{
    // Si es su turno, pasar al siguiente
    if ($this->isPlayerTurn($match, $player)) {
        $this->skipTurn($match, $player);
    }

    event(new PlayerDisconnectedEvent($match, $player));
}
```

### Caso 3: Juego Cooperativo (Escape Room)
```php
// Usar comportamiento default: pausar hasta que vuelvan todos
// (Ya funciona sin código adicional)
```

### Caso 4: Battle Royale
```php
// Eliminar jugador inmediatamente, NO pausar
public function onPlayerDisconnected(GameMatch $match, Player $player): void
{
    $this->eliminatePlayer($match, $player);
    event(new PlayerDisconnectedEvent($match, $player));
}
```

## Estado del Game State

Durante una desconexión, `game_state` contiene:

```php
[
    'phase' => 'playing',
    'paused' => true,                           // Marcado como pausado
    'paused_reason' => 'player_disconnected',   // Razón de la pausa
    'disconnected_player_id' => 123,            // ID del jugador desconectado
    'paused_at' => '2025-10-26 15:30:00',      // Timestamp de la pausa
    'timer_system' => [
        'round' => [
            'paused' => true,                   // Timer pausado
            'paused_at' => 1698334200,
            'remaining_ms' => 5230,             // Tiempo restante
            // ...
        ]
    ],
    // ... resto del estado
]
```

Cuando se reconecta, estos campos se limpian:

```php
unset($gameState['paused']);
unset($gameState['paused_reason']);
unset($gameState['disconnected_player_id']);
unset($gameState['paused_at']);
```

## Validaciones Backend

El sistema valida automáticamente:

1. **apiPlayerDisconnected()**:
   - ✅ Sala existe
   - ✅ Partida activa existe
   - ✅ `game_state['phase'] === 'playing'` (NO procesa en lobby/finished)
   - ✅ Jugador existe

2. **apiPlayerReconnected()**:
   - ✅ Sala existe
   - ✅ Partida activa existe
   - ✅ `game_state['paused'] === true` (solo si está pausado)
   - ✅ Jugador existe

## Testing

### Test Manual

1. Iniciar partida con 2+ jugadores
2. Durante el juego, cerrar tab de un jugador
3. Verificar:
   - ✅ Popup aparece en clientes restantes
   - ✅ Timer se detiene
   - ✅ Logs muestran "Player disconnected - pausing game"
4. Reabrir tab del jugador desconectado
5. Verificar:
   - ✅ Popup desaparece
   - ✅ Ronda se reinicia
   - ✅ Timer vuelve a empezar
   - ✅ Logs muestran "Player reconnected - resuming game"

### Logs Esperados

**Desconexión:**
```
[TriviaEngine] Player disconnected - pausing game
[TriviaEngine] Game paused due to disconnection
```

**Reconexión:**
```
[TriviaEngine] Player reconnected - resuming game
[TriviaEngine] Restarting current round
[TriviaEngine] Game resumed after reconnection
```

## Archivos del Sistema

### Backend
```
app/
├── Events/Game/
│   ├── PlayerDisconnectedEvent.php      ✅ Evento de desconexión
│   └── PlayerReconnectedEvent.php       ✅ Evento de reconexión
├── Contracts/
│   └── BaseGameEngine.php               ✅ Métodos: onPlayerDisconnected, onPlayerReconnected
└── Http/Controllers/
    └── PlayController.php               ✅ API endpoints

routes/
└── api.php                              ✅ Rutas player-disconnected/reconnected

config/
└── game-events.php                      ✅ Configuración de eventos base
```

### Frontend
```
resources/
├── js/
│   ├── modules/
│   │   └── PresenceMonitor.js          ✅ Monitor de presence channel
│   └── core/
│       └── BaseGameClient.js           ✅ Handlers de eventos
└── views/components/game/
    └── player-disconnected-popup.blade.php  ✅ Popup UI

games/trivia/views/
└── game.blade.php                      ✅ Inclusión del popup (ejemplo)
```

## Limitaciones Conocidas

1. **Múltiples desconexiones simultáneas**: Solo se trackea un jugador desconectado a la vez
   - Solución futura: `game_state['disconnected_players']` como array

2. **Desconexión en lobby**: No se procesa (by design)
   - El sistema solo actúa cuando `phase === 'playing'`

3. **Timeout de reconexión**: No hay límite de tiempo para reconectar
   - Solución futura: Implementar timeout (ej: 2 minutos) y continuar sin el jugador

## Mejoras Futuras

- [ ] Timeout configurable para reconexión
- [ ] Soporte para múltiples jugadores desconectados simultáneamente
- [ ] Opción para votar si continuar sin el jugador
- [ ] Estadísticas de desconexiones por jugador
- [ ] Penalizaciones por desconexiones frecuentes
- [ ] Modo AI replacement (bot temporal)

## Conclusión

Este sistema proporciona una experiencia robusta y profesional cuando los jugadores pierden conexión durante una partida. El comportamiento por defecto (pausar y reiniciar) funciona bien para la mayoría de juegos, y los juegos que necesiten lógica diferente pueden usar los hooks o sobrescribir los métodos completamente.

**Zero boilerplate**: Los nuevos juegos obtienen esta funcionalidad automáticamente sin escribir código adicional.
