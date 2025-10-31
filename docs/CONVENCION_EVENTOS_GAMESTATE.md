# Convención: Eventos y gameState

## Principio: gameState como Single Source of Truth

El frontend debe leer **SIEMPRE** de `this.gameState` para renderizar y tomar decisiones, nunca directamente de los campos del evento.

## Estructura de Eventos

### Backend: Eventos Ligeros

Los eventos envían solo:
1. **Datos del cambio específico** (qué cambió)
2. **game_state completo** (estado actualizado)

```php
// ❌ MAL: Duplicar datos en evento y game_state
class RoundStartedEvent {
    public int $currentRound;        // Duplicado
    public int $totalRounds;         // Duplicado
    public array $gameState;         // Ya contiene round_system.current_round
}

// ✅ BIEN: Solo lo esencial + game_state
class RoundStartedEvent {
    public array $gameState;  // Contiene TODO el estado actualizado
    // Opcionalmente: metadatos del cambio que NO están en gameState
    public ?array $timing;    // Metadatos para TimingModule
}
```

### Frontend: Leer de this.gameState

Los handlers actualizan `this.gameState` y luego leen de ahí:

```javascript
// ❌ MAL: Leer directamente del evento
handleRoundStarted(event) {
    super.handleRoundStarted(event);

    const round = event.current_round;  // ❌ Datos del evento
    this.updateRoundCounter(round);
}

// ✅ BIEN: Leer de this.gameState
handleRoundStarted(event) {
    super.handleRoundStarted(event);  // Actualiza this.gameState con event.game_state

    const round = this.gameState.round_system?.current_round || 1;  // ✅ Source of truth
    this.updateRoundCounter(round);
}
```

## Flujo Completo

### 1. Backend emite evento

```php
// En BaseGameEngine o juego específico
event(new RoundStartedEvent(
    $match,
    $timing  // Solo metadatos adicionales
));
```

### 2. Evento se serializa

```php
class RoundStartedEvent {
    public function broadcastWith(): array {
        return [
            'game_state' => $this->gameState,  // Estado completo
            'timing' => $this->timing,         // Metadatos opcionales
        ];
    }
}
```

### 3. Frontend recibe evento

```javascript
// BaseGameClient actualiza this.gameState automáticamente
handleRoundStarted(event) {
    // 1. Actualizar gameState (BaseGameClient lo hace)
    this.gameState = event.game_state;  // ← Hecho en BaseGameClient

    // 2. Actualizar variables de conveniencia
    this.currentRound = event.game_state.round_system?.current_round || 1;
    this.totalRounds = event.game_state._config?.modules?.round_system?.total_rounds || 10;
}
```

### 4. Juego específico renderiza

```javascript
// MockupGameClient extiende BaseGameClient
handleRoundStarted(event) {
    super.handleRoundStarted(event);  // Actualiza this.gameState

    // ✅ Leer SIEMPRE de this.gameState
    this.updateRoundCounter(
        this.gameState.round_system?.current_round,
        this.gameState._config?.modules?.round_system?.total_rounds
    );
}
```

## Ventajas de esta Convención

1. **Single Source of Truth**: `this.gameState` es la única fuente de verdad
2. **Menos bytes en WebSocket**: Eventos más pequeños
3. **Consistencia garantizada**: No puede haber desincronización entre `event.xxx` y `gameState.xxx`
4. **Acceso a _ui y _data**: Siempre disponibles en `this.gameState`
5. **Más fácil debugging**: Solo un lugar donde buscar datos

## Casos de Uso

### Actualizar UI cuando cambia ronda

```javascript
handleRoundStarted(event) {
    super.handleRoundStarted(event);

    // Leer de gameState
    const currentRound = this.gameState.round_system?.current_round || 1;
    const totalRounds = this.gameState._config?.modules?.round_system?.total_rounds || 3;

    // Actualizar UI
    document.getElementById('current-round').textContent = currentRound;
    document.getElementById('total-rounds').textContent = totalRounds;
}
```

### Mostrar elementos según configuración _ui

```javascript
handlePhase2Started(event) {
    super.handlePhaseStarted(event);

    // Leer configuración de _ui desde gameState
    const showButtons = this.gameState._ui?.phases?.phase2?.show_buttons;

    if (showButtons) {
        this.showAnswerButtons();
    }
}
```

### Validar estado del jugador

```javascript
handlePlayerAction(event) {
    // Verificar si jugador está bloqueado
    const lockedPlayers = this.gameState.player_system?.locked_players || [];
    const isLocked = lockedPlayers.includes(this.config.playerId);

    if (isLocked) {
        console.log('Player is locked, ignoring action');
        return;
    }

    // Procesar acción...
}
```

## Excepciones: Cuándo SÍ leer del evento

Solo leer del evento cuando:

1. **Metadatos que NO están en gameState**
   ```javascript
   handleRoundStarted(event) {
       super.handleRoundStarted(event);

       // ✅ timing no está en gameState, solo en evento
       if (event.timing) {
           this.timing.processTimingPoint(event.timing);
       }
   }
   ```

2. **Eventos de notificación sin estado**
   ```javascript
   handlePlayerDisconnected(event) {
       // ✅ Datos de notificación, no de estado
       const playerName = event.player_name;
       this.showNotification(`${playerName} se desconectó`);
   }
   ```

## Checklist de Implementación

Al crear un nuevo handler de evento:

- [ ] Llamar a `super.handleXXX(event)` PRIMERO (actualiza `this.gameState`)
- [ ] Leer datos de `this.gameState`, NO de `event.xxx`
- [ ] Usar `?.` optional chaining para navegación segura
- [ ] Proporcionar valores por defecto con `||`
- [ ] Solo leer del evento si son metadatos que no están en gameState

## Ejemplo Completo: MockupGameClient

```javascript
export class MockupGameClient extends BaseGameClient {

    handleRoundStarted(event) {
        // 1. Actualizar gameState
        super.handleRoundStarted(event);

        // 2. Leer de gameState (source of truth)
        const round = this.gameState.round_system?.current_round || 1;
        const total = this.gameState._config?.modules?.round_system?.total_rounds || 3;

        // 3. Actualizar UI
        this.updateRoundCounter(round, total);

        // 4. Limpiar estado de ronda anterior
        this.hideLockedMessage();
        this.hidePhase3Message();
    }

    handlePhase2Started(event) {
        super.handlePhaseStarted(event);

        // Leer configuración de UI desde gameState
        const phaseUI = this.gameState._ui?.phases?.phase2;

        if (phaseUI?.show_buttons) {
            this.showAnswerButtons();
        }

        // Restaurar estado de bloqueado desde gameState
        const lockedPlayers = this.gameState.player_system?.locked_players || [];
        if (lockedPlayers.includes(this.config.playerId)) {
            this.onPlayerLocked({ player_id: this.config.playerId });
        }
    }

    updateRoundCounter(current, total) {
        // Método de actualización reactiva
        const roundEl = document.getElementById('current-round');
        if (roundEl) roundEl.textContent = current;

        const totalEl = document.getElementById('total-rounds');
        if (totalEl) totalEl.textContent = total;
    }
}
```
