# Convención: Render Frontend

## Concepto

El render del frontend se organiza en **tres niveles** según su alcance:

1. **Render General**: Elementos comunes que siempre están presentes
2. **Render por Fase**: Contenido específico de cada fase del juego
3. **Actualización Reactiva**: Cambios puntuales en elementos existentes

## Estructura de Métodos de Render

```javascript
class GameClient extends BaseGameClient {

    // 1. RENDER GENERAL (se ejecuta una vez al inicio)
    renderGeneral() {
        // Renderizar elementos comunes:
        // - Header del juego
        // - Contador de rondas
        // - Scoreboard
        // - Timer global
    }

    // 2. RENDER POR FASE (se ejecuta al cambiar de fase)
    renderPhase1() {
        // Renderizar contenido específico de Phase1
        // Ejemplo: Ocultar botones, mostrar instrucciones
    }

    renderPhase2() {
        // Renderizar contenido específico de Phase2
        // Ejemplo: Mostrar botones de respuesta
    }

    // 3. ACTUALIZACIÓN REACTIVA (se ejecuta al cambiar datos)
    updateScores(scores) {
        // Actualizar solo el scoreboard sin re-renderizar todo
    }

    updateTimer(timeRemaining) {
        // Actualizar solo el timer sin re-renderizar todo
    }
}
```

## Flujo de Render

### 1. Render General (una vez al inicio)

**Cuándo:** En `handleGameStarted()` después de llamar a `super.handleGameStarted()`

**Qué renderiza:**
- Header del juego con nombre y logo
- Contador de rondas (Round 1/10)
- Scoreboard inicial (todos con 0 puntos)
- Timer global (si el juego lo usa)
- Elementos de UI comunes

**Ejemplo:**

```javascript
handleGameStarted: (event) => {
    // Llamar al padre para actualizar this.gameState
    super.handleGameStarted(event);

    // Renderizar elementos generales usando _ui
    this.renderGeneral();
},

renderGeneral() {
    // Usar this.gameState._ui para controlar visibilidad
    if (this.gameState._ui?.general?.show_header) {
        this.showHeader();
    }

    if (this.gameState._ui?.general?.show_scores) {
        this.renderScoreboard();
    }

    if (this.gameState._ui?.general?.show_round_counter) {
        this.renderRoundCounter();
    }
}
```

### 2. Render por Fase (cada vez que cambia la fase)

**Opción A: Eventos Custom (RECOMENDADO para fases complejas)**

Usar eventos custom como `Phase1StartedEvent`, `Phase2StartedEvent` que disparan handlers específicos.

```javascript
handlePhase1Started: (event) => {
    // Renderizar contenido específico de Phase1
    this.renderPhase1();
},

renderPhase1() {
    // Leer configuración de _ui
    const phaseUI = this.gameState._ui?.phases?.phase1;

    // Ocultar botones de respuesta
    if (phaseUI?.show_buttons === false) {
        this.hideAnswerButtons();
    }

    // Mostrar instrucciones
    if (phaseUI?.show_instructions) {
        this.showInstructions(phaseUI.instructions_text);
    }
}
```

**Opción B: Handler Genérico (para fases simples)**

Usar `handlePhaseStarted()` genérico con lógica condicional para fases sencillas.

```javascript
handlePhaseStarted: (event) => {
    const phaseName = event.phase_name;
    const phaseUI = this.gameState._ui?.phases?.[phaseName];

    // Renderizar según configuración de _ui
    if (phaseUI) {
        this.renderPhaseGeneric(phaseName, phaseUI);
    }
},

renderPhaseGeneric(phaseName, phaseUI) {
    // Render genérico basado en flags de _ui
    if (phaseUI.show_input) this.showInput();
    if (phaseUI.show_buttons) this.showButtons();
    if (phaseUI.show_message) this.showMessage(phaseUI.message_text);
}
```

### 3. Actualización Reactiva (cuando cambian datos)

**Cuándo:** En handlers de eventos específicos (ScoreUpdated, TimerTick, PlayerAction)

**Qué actualiza:**
- Solo el elemento específico que cambió
- Sin re-renderizar toda la vista

**Ejemplo:**

```javascript
handlePlayerScoreUpdated: (event) => {
    // Llamar al padre para actualizar this.scores
    super.handlePlayerScoreUpdated(event);

    // Actualizar SOLO el score del jugador que cambió
    this.updatePlayerScore(event.player_id, event.new_score);
},

updatePlayerScore(playerId, newScore) {
    const scoreElement = document.getElementById(`player-score-${playerId}`);
    if (scoreElement) {
        scoreElement.textContent = newScore;

        // Animar si ganó puntos (desde _ui)
        if (this.gameState._ui?.general?.animations?.score_increase) {
            scoreElement.classList.add('score-increase');
            setTimeout(() => scoreElement.classList.remove('score-increase'), 500);
        }
    }
}
```

## Convención de Nombres de Métodos

### Render (crear elementos desde cero)
- `renderGeneral()`: Elementos comunes del juego
- `renderPhase1()`, `renderPhase2()`: Contenido específico de cada fase
- `renderPhaseGeneric(name, ui)`: Render genérico para fases simples
- `renderScoreboard()`: Scoreboard completo
- `renderRoundCounter()`: Contador de rondas

### Update (actualizar elementos existentes)
- `updateScores(scores)`: Actualizar todos los scores
- `updatePlayerScore(playerId, score)`: Actualizar score de un jugador
- `updateTimer(time)`: Actualizar timer
- `updateRoundCounter(round, total)`: Actualizar contador de ronda

### Show/Hide (visibilidad de elementos)
- `showElement(id)`: Mostrar elemento por ID
- `hideElement(id)`: Ocultar elemento por ID
- `showAnswerButtons()`: Mostrar botones de respuesta
- `hideAnswerButtons()`: Ocultar botones de respuesta

## Decisión: ¿Evento Custom o Handler Genérico?

### Usar Evento Custom cuando:
- La fase tiene lógica compleja de render
- La fase tiene animaciones o transiciones especiales
- La fase necesita inicializar componentes específicos (canvas, forms, etc.)
- Quieres separación clara de responsabilidades

**Ejemplo:** Fase de dibujo en Pictionary
```javascript
handleDrawingPhaseStarted: (event) => {
    this.initCanvas();
    this.renderDrawingTools();
    this.startDrawingTimer();
}
```

### Usar Handler Genérico cuando:
- La fase solo muestra/oculta elementos simples
- La configuración de _ui es suficiente para controlar el render
- No hay lógica compleja de inicialización
- Quieres reducir código boilerplate

**Ejemplo:** Fase de espera simple
```javascript
handlePhaseStarted: (event) => {
    if (event.phase_name === 'waiting') {
        this.showWaitingMessage();
    }
}
```

## Ejemplo Completo: Mockup Game

```javascript
export class MockupGameClient extends BaseGameClient {

    setupEventManager() {
        const customHandlers = {
            // INICIO DEL JUEGO: Render general
            handleGameStarted: (event) => {
                super.handleGameStarted(event);
                this.renderGeneral();
            },

            // FASE 1 (evento custom): Render específico
            handlePhase1Started: (event) => {
                this.renderPhase1();
            },

            // FASE 2 (evento custom): Render específico
            handlePhase2Started: (event) => {
                this.renderPhase2();
            },

            // FASE 3 (evento genérico): Render simple
            handlePhaseStarted: (event) => {
                if (event.phase_name === 'phase3') {
                    this.renderPhase3Generic();
                }
            },

            // ACTUALIZACIÓN REACTIVA: Score
            handlePlayerScoreUpdated: (event) => {
                super.handlePlayerScoreUpdated(event);
                this.updatePlayerScore(event.player_id, event.new_score);
            }
        };

        super.setupEventManager(customHandlers);
    }

    // 1. RENDER GENERAL
    renderGeneral() {
        const ui = this.gameState._ui?.general;

        if (ui?.show_header) {
            document.getElementById('game-header').style.display = 'block';
        }

        if (ui?.show_scores) {
            this.renderScoreboard();
        }
    }

    // 2. RENDER POR FASE (Custom Event)
    renderPhase1() {
        const phaseUI = this.gameState._ui?.phases?.phase1;

        // Ocultar botones
        if (phaseUI?.show_buttons === false) {
            this.hideAnswerButtons();
        }

        // Mostrar timer de cuenta regresiva
        if (phaseUI?.show_countdown) {
            this.showCountdown(3);
        }
    }

    renderPhase2() {
        const phaseUI = this.gameState._ui?.phases?.phase2;

        // Mostrar botones de respuesta
        if (phaseUI?.show_buttons) {
            this.showAnswerButtons();
        }

        // Restaurar estado de jugador bloqueado si ya votó
        this.restorePlayerLockedState();
    }

    // 3. RENDER GENÉRICO (para fases simples)
    renderPhase3Generic() {
        const phaseUI = this.gameState._ui?.phases?.phase3;

        this.hideAnswerButtons();

        if (phaseUI?.show_message) {
            this.showPhase3Message(phaseUI.message_text);
        }
    }

    // 4. ACTUALIZACIÓN REACTIVA
    updatePlayerScore(playerId, newScore) {
        const scoreEl = document.getElementById(`player-score-${playerId}`);
        if (scoreEl) {
            scoreEl.textContent = newScore;
        }
    }
}
```

## Integración con _ui

El render debe leer siempre de `this.gameState._ui` para saber qué mostrar:

```javascript
// ✅ CORRECTO: Leer de _ui
renderPhase1() {
    const phaseUI = this.gameState._ui?.phases?.phase1;

    if (phaseUI?.show_buttons) {
        this.showAnswerButtons();
    }
}

// ❌ INCORRECTO: Hardcodear lógica de visibilidad
renderPhase1() {
    // NO hacer esto - la decisión de qué mostrar debe venir de _ui
    this.showAnswerButtons(); // ¿Cómo sabemos si debemos mostrarlos?
}
```

## Beneficios

1. **Separación Clara**: General vs Fase vs Reactivo
2. **Reutilización**: Métodos de render se pueden llamar desde múltiples lugares
3. **Testing**: Fácil probar cada método de render por separado
4. **Debugging**: Saber exactamente qué método renderiza qué elemento
5. **Mantenibilidad**: Cambios en UI solo afectan métodos específicos

## Checklist al Implementar un Nuevo Juego

- [ ] Crear `renderGeneral()` para elementos comunes
- [ ] Decidir qué fases necesitan eventos custom vs genérico
- [ ] Crear `renderPhaseX()` para fases complejas
- [ ] Crear `updateX()` para actualizaciones reactivas
- [ ] Poblar `_ui` en `initialize()` del Engine
- [ ] Leer siempre de `this.gameState._ui` en métodos de render
