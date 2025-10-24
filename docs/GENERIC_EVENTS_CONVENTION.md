# Convención de Eventos Genéricos del BaseGameEngine

## 📋 Resumen Ejecutivo

**REGLA DE ORO**: Todos los juegos deben usar los **eventos genéricos** del BaseGameEngine en lugar de crear eventos propios para funcionalidades estándar.

## 🎯 Eventos Genéricos Disponibles

Ubicación: `app/Events/Game/`

### 1. `PlayerConnectedToGameEvent`
**Cuándo emitir:** Cuando un jugador se conecta a la sala (fase starting)
**Datos incluidos:**
- `connected_count`: Número de jugadores conectados
- `total_players`: Total de jugadores esperados

**Ejemplo de uso en el juego:**
```javascript
handlePlayerConnected(event) {
    // Actualizar contador en tiempo real
    document.getElementById('connection-status').textContent =
        `(${event.connected_count}/${event.total_players})`;
}
```

### 2. `GameStartedEvent`
**Cuándo emitir:** Cuando todos los jugadores están conectados y el juego va a empezar
**Datos incluidos:**
- `game_name`: Nombre del juego
- `total_players`: Total de jugadores
- `timing`: Metadata de countdown (opcional)

**Ejemplo de uso en el juego:**
```javascript
handleGameStarted(event) {
    // BaseGameClient lo maneja automáticamente
    // Muestra countdown 3-2-1 y llama notifyGameReady()
}
```

### 3. `RoundStartedEvent`
**Cuándo emitir:** Cuando empieza una nueva ronda
**Datos incluidos:**
- `current_round`: Número de ronda actual
- `total_rounds`: Total de rondas
- `phase`: Fase del juego (ej: 'playing', 'question')
- `game_state`: Estado completo del juego

**Ejemplo de uso en el juego:**
```javascript
handleRoundStarted(event) {
    // Trivia: Mostrar nueva pregunta
    // Pictionary: Cambiar dibujante
    // Cada juego implementa su lógica
    this.showNewQuestion(event.game_state.current_question);
}
```

### 4. `RoundEndedEvent`
**Cuándo emitir:** Cuando termina una ronda
**Datos incluidos:**
- `round_number`: Número de ronda que terminó
- `results`: Resultados de la ronda (quién ganó, quién perdió, etc.)
- `scores`: Puntuaciones actualizadas

### 5. `TurnChangedEvent`
**Cuándo emitir:** Cuando cambia el turno del jugador actual
**Datos incluidos:**
- `current_player_id`: ID del jugador con el turno
- `current_player_name`: Nombre del jugador
- `current_round`: Ronda actual
- `turn_index`: Índice del turno
- `cycle_completed`: Si se completó un ciclo completo
- `player_roles`: Roles actuales de los jugadores (drawer, guesser, etc.)

### 6. `PhaseChangedEvent`
**Cuándo emitir:** Cuando cambia la fase del juego
**Datos incluidos:**
- `new_phase`: Nueva fase
- `previous_phase`: Fase anterior
- `additional_data`: Datos adicionales específicos del juego

**Fases comunes:**
- `waiting`: Esperando inicio
- `playing`: Jugando (respondiendo, dibujando, etc.)
- `scoring`: Calculando puntos
- `results`: Mostrando resultados
- `finished`: Juego terminado

### 7. `GameStateUpdatedEvent`
**Cuándo emitir:** Actualización completa del estado (sincronización)
**Datos incluidos:**
- `game_state`: Estado completo del juego
- `update_type`: Tipo de actualización ('full', 'partial', 'sync')

### 8. `PlayerActionEvent`
**Cuándo emitir:** Cuando un jugador realiza una acción
**Datos incluidos:**
- `player_id`: ID del jugador
- `player_name`: Nombre del jugador
- `action_type`: Tipo de acción ('answer', 'draw', 'guess', etc.)
- `action_data`: Datos de la acción
- `success`: Si la acción fue exitosa

## ✅ Cómo Usar los Eventos Genéricos

### Paso 1: Emitir desde el Backend

**En lugar de crear eventos propios:**
```php
// ❌ INCORRECTO - Evento específico del juego
event(new QuestionStartedEvent($match, $question, $options, $round, $total));
```

**Usar eventos genéricos:**
```php
// ✅ CORRECTO - Evento genérico
use App\Events\Game\RoundStartedEvent;

event(new RoundStartedEvent(
    match: $match,
    currentRound: $round,
    totalRounds: $total,
    phase: 'question'
));
```

### Paso 2: Configurar en capabilities.json

```json
{
  "slug": "trivia",
  "event_config": {
    "channel": "room.{roomCode}",
    "events": {
      "PlayerConnectedToGameEvent": {
        "name": "player.connected",
        "handler": "handlePlayerConnected"
      },
      "GameStartedEvent": {
        "name": "game.started",
        "handler": "handleGameStarted"
      },
      "RoundStartedEvent": {
        "name": "game.round.started",
        "handler": "handleRoundStarted"
      },
      "RoundEndedEvent": {
        "name": ".game.round.ended",
        "handler": "handleRoundEnded"
      },
      "TurnChangedEvent": {
        "name": ".game.turn.changed",
        "handler": "handleTurnChanged"
      },
      "PhaseChangedEvent": {
        "name": ".game.phase.changed",
        "handler": "handlePhaseChanged"
      }
    }
  }
}
```

### Paso 3: Implementar Handlers en JavaScript

```javascript
class TriviaGame {
    setupEventManager() {
        this.eventManager = new window.EventManager({
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            eventConfig: this.eventConfig,
            handlers: {
                // Handlers genéricos
                handleRoundStarted: (event) => this.onRoundStarted(event),
                handleRoundEnded: (event) => this.onRoundEnded(event),
                handlePhaseChanged: (event) => this.onPhaseChanged(event),
            }
        });
    }

    onRoundStarted(event) {
        // Lógica específica de Trivia
        const question = event.game_state.current_question;
        this.showQuestion(question);
        this.startTimer();
    }

    onRoundEnded(event) {
        // Lógica específica de Trivia
        this.showResults(event.results);
        this.updateScores(event.scores);
    }

    onPhaseChanged(event) {
        // Actualizar UI según la fase
        if (event.new_phase === 'results') {
            this.showResultsPanel();
        } else if (event.new_phase === 'playing') {
            this.showGamePanel();
        }
    }
}
```

## 🔄 Ventajas de Eventos Genéricos

### 1. **Consistencia**
Todos los juegos usan los mismos nombres de eventos
- Fácil de aprender
- Menos confusión
- Documentación centralizada

### 2. **Reutilización**
BaseGameEngine puede emitir estos eventos automáticamente
- Menos código en cada juego
- Comportamiento consistente
- Actualización centralizada

### 3. **Mantenibilidad**
Un solo lugar para cambios
- Agregar campos: se aplica a todos los juegos
- Cambiar estructura: un solo archivo
- Bugfixes: benefician a todos

### 4. **Interoperabilidad**
Herramientas generales funcionan con todos los juegos
- Debugger universal
- Analytics centralizados
- Espectadores genéricos

## 📝 Convenciones Específicas

### Eventos Específicos del Juego

**Solo crear eventos propios cuando:**
1. La funcionalidad es ÚNICA del juego
2. No encaja en ningún evento genérico
3. Contiene datos muy específicos

**Ejemplos válidos de eventos específicos:**
- `CanvasDrawEvent` (Pictionary) - Dibujo en tiempo real
- `CardPlayedEvent` (UNO) - Carta específica jugada
- `WordRevealedEvent` (Ahorcado) - Letra revelada

**Eventos que DEBEN ser genéricos:**
- ❌ `QuestionStartedEvent` → ✅ `RoundStartedEvent`
- ❌ `TurnChangedEvent` (del juego) → ✅ `TurnChangedEvent` (genérico)
- ❌ `GamePhaseChangedEvent` → ✅ `PhaseChangedEvent`

### Nomenclatura

**Eventos genéricos:**
- Namespace: `App\Events\Game\`
- Broadcast name: `.game.{module}.{action}`
- Ejemplos:
  - `.game.round.started`
  - `.game.turn.changed`
  - `.game.phase.changed`

**Eventos específicos:**
- Namespace: `Games\{GameName}\Events\`
- Broadcast name: `.{game}.{feature}.{action}`
- Ejemplos:
  - `.pictionary.canvas.draw`
  - `.uno.card.played`
  - `.trivia.hint.revealed`

## 🚀 Migración de Eventos Existentes

### Trivia
```
❌ QuestionStartedEvent → ✅ RoundStartedEvent
❌ QuestionEndedEvent   → ✅ RoundEndedEvent
✅ PlayerAnsweredEvent  → ✅ PlayerActionEvent (ya es genérico)
❌ GameFinishedEvent    → ✅ GameFinishedEvent (global, no del juego)
```

### Pictionary
```
✅ TurnChangedEvent     → Ya existe genérico (Games\Pictionary → App\Events\Game)
✅ RoundEndedEvent      → Ya existe genérico
✅ CanvasDrawEvent      → Mantener (específico válido)
✅ GameStateUpdatedEvent → Ya existe genérico
```

## 📚 Referencias

- EventManager: `app/Services/Modules/EventManager/EventManager.md`
- Eventos genéricos: `app/Events/Game/`
- Capabilities: `games/{game}/capabilities.json`

---

## 📖 Documentación Relacionada

- [Arquitectura Completa de Eventos y WebSockets](./EVENTS_AND_WEBSOCKETS_ARCHITECTURE.md)
- [Flujo del Motor de Juegos](./GAME_ENGINE_FLOW.md)
- [Cómo Crear un Juego](./HOW_TO_CREATE_A_GAME.md)

---

**Última actualización**: 2025-10-24
**Autores**: Claude Code + Daniel
