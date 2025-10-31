// BaseGameClient ya está disponible globalmente a través de resources/js/app.js
const { BaseGameClient } = window;

export class MockupGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.config = config; // Guardar config para acceder en métodos
        this.customHandlers = null; // Guardar referencia a los handlers
        this.setupEventManager();
        // setupTestControls se llamará en handleDomLoaded cuando el DOM esté listo
    }

    /**
     * Configurar controles de testing (Good Answer / Bad Answer)
     */
    setupTestControls() {
        // Good Answer button - finaliza la ronda inmediatamente
        const goodAnswerBtn = document.getElementById('btn-good-answer');
        if (goodAnswerBtn) {
            goodAnswerBtn.addEventListener('click', () => {
                this.handleGoodAnswer();
            });
        }

        // Bad Answer button - bloquea al jugador que lo presiona
        const badAnswerBtn = document.getElementById('btn-bad-answer');
        if (badAnswerBtn) {
            badAnswerBtn.addEventListener('click', () => {
                this.handleBadAnswer();
            });
        }

        // console.log('🧪 [Mockup] Test controls setup complete');
    }

    /**
     * Handler para Good Answer - finaliza la ronda inmediatamente
     */
    async handleGoodAnswer() {
        // console.log('✅ [Mockup] Good Answer clicked - ending round immediately');

        try {
            const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    action: 'good_answer',
                    data: {}
                })
            });

            const data = await response.json();

            if (!response.ok) {
                console.error('❌ [Mockup] Good Answer failed:', data);
                return;
            }

            // console.log('✅ [Mockup] Good Answer successful:', data);
        } catch (error) {
            console.error('❌ [Mockup] Error calling Good Answer:', error);
        }
    }

    /**
     * Handler para Bad Answer - bloquea al jugador
     */
    async handleBadAnswer() {
        // console.log('❌ [Mockup] Bad Answer clicked - blocking player');

        try {
            const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    action: 'bad_answer',
                    data: {}
                })
            });

            const data = await response.json();

            if (!response.ok) {
                console.error('❌ [Mockup] Bad Answer failed:', data);
                return;
            }

            // console.log('❌ [Mockup] Bad Answer successful:', data);
        } catch (error) {
            console.error('❌ [Mockup] Error calling Bad Answer:', error);
        }
    }

    /**
     * Override: Configurar EventManager con handlers específicos de Mockup
     *
     * DOCUMENTACIÓN DEL SISTEMA DE EVENTOS:
     * =====================================
     *
     * 1. EVENTOS GENÉRICOS (BaseGameClient):
     *    - handlePhaseStarted() - Se ejecuta para TODAS las fases
     *    - handleRoundStarted() - Se ejecuta al inicio de cada ronda
     *    - handleRoundEnded() - Se ejecuta al final de cada ronda
     *    - handleGameStarted() - Se ejecuta cuando inicia el juego
     *    - handleGameFinished() - Se ejecuta cuando termina el juego
     *
     * 2. EVENTOS PERSONALIZADOS (Game-specific):
     *    - handlePhase1Started() - Solo para Phase1 (evento custom: Phase1StartedEvent)
     *    - handlePhase1Ended() - Solo para Phase1 (evento custom: Phase1EndedEvent)
     *    - handlePhase2Started() - Solo para Phase2 (si existe Phase2StartedEvent)
     *
     * 3. OPCIONES DE IMPLEMENTACIÓN:
     *
     *    Opción A: Handler genérico con lógica condicional (RECOMENDADO para lógica simple)
     *    ```javascript
     *    handlePhaseStarted: (event) => {
     *        if (event.phase_name === 'phase2') {
     *            this.showAnswerButtons();
     *        }
     *    }
     *    ```
     *
     *    Opción B: Eventos custom específicos (RECOMENDADO para lógica compleja)
     *    - Backend: Crear Phase2StartedEvent.php
     *    - Config: "on_start": "App\\Events\\Mockup\\Phase2StartedEvent"
     *    - Frontend: handlePhase2Started() se ejecutará automáticamente
     *
     * 4. CONFIGURACIÓN EN config.json:
     *    ```json
     *    "event_config": {
     *      "events": {
     *        "Phase1StartedEvent": {
     *          "name": ".mockup.phase1.started",
     *          "handler": "handlePhase1Started"
     *        },
     *        "PhaseStartedEvent": {
     *          "name": ".game.phase.started",
     *          "handler": "handlePhaseStarted"  // Handler genérico
     *        }
     *      }
     *    }
     *    ```
     */
    setupEventManager() {
        // Registrar handlers personalizados de Mockup
        this.customHandlers = {
            handleDomLoaded: (event) => {
                // Llamar al handler del padre primero
                super.handleDomLoaded(event);
                this.setupTestControls(); // Configurar botones de test

                // Restaurar estado de jugador bloqueado al cargar/reconectar
                this.restorePlayerLockedState();
            },
            handlePlayerLocked: (event) => {
                this.onPlayerLocked(event);
            },
            handlePlayersUnlocked: (event) => {
                this.onPlayerUnlocked(event);
            },
            handleGameStarted: (event) => {
                // Llamar al handler del padre PRIMERO para que actualice this.gameState
                super.handleGameStarted(event);

                // CONVENCIÓN: Usar updateUI() para renderizar todo desde gameState
                this.updateUI();
            },
            handlePhase1Started: (event) => {
                // CONVENCIÓN: Render específico de Phase1 (evento custom)
                this.renderPhase1();
            },
            handlePhase1Ended: (event) => {
                // console.log('🏁 [Mockup] FASE 1 FINALIZADA - Timer expirado correctamente', event);
            },
            handlePhase2Started: (event) => {
                // CONVENCIÓN: Render específico de Phase2 (evento custom)
                this.renderPhase2();
            },
            handlePhaseStarted: (event) => {
                // CONVENCIÓN: Handler genérico para fases simples (sin evento custom)

                // Fase 3 usa evento genérico porque es simple (solo ocultar botones + mensaje)
                if (event.phase_name === 'phase3') {
                    this.renderPhase3Generic();
                }

                // TimingModule detectará automáticamente el timer porque el evento tiene:
                // - timer_id
                // - duration
                // - server_time
            }
        };

        // Llamar al setupEventManager del padre con los handlers custom
        super.setupEventManager(this.customHandlers);
    }

    /**
     * Override: Handler de cambio de fase (para actualizar UI específica)
     */
    handlePhaseChanged(event) {
        super.handlePhaseChanged(event);

        // Obtener el nombre de la fase desde additional_data
        const phaseName = event.additional_data?.phase_name || event.new_phase || 'unknown';

        // Actualizar display de fase en el DOM
        const phaseEl = document.getElementById('current-phase');
        if (phaseEl) {
            phaseEl.textContent = phaseName;
        }

        // Actualizar descripción de fase
        const descEl = document.getElementById('phase-description');
        if (descEl) {
            descEl.textContent = `Fase ${phaseName} en progreso...`;
        }

        // console.log('📋 [Mockup] Fase actualizada:', phaseName, event);
    }

    /**
     * Override: Handler de ronda iniciada (para actualizar UI específica)
     *
     * CONVENCIÓN: Leer SIEMPRE de this.gameState (actualizado por super.handleRoundStarted)
     */
    handleRoundStarted(event) {
        super.handleRoundStarted(event);  // Actualiza this.gameState con event.game_state

        // CONVENCIÓN: Leer de this.gameState (source of truth), NO de event.xxx
        const currentRound = this.gameState.round_system?.current_round || 1;
        const totalRounds = this.gameState._config?.modules?.round_system?.total_rounds || 3;

        // Actualizar UI de contador de rondas
        this.updateRoundCounter(currentRound, totalRounds);

        // Limpiar estado de ronda anterior
        this.hideLockedMessage();
        this.hidePhase3Message();

        // Los botones se ocultarán en fase 1 y se mostrarán en fase 2 automáticamente
    }

    /**
     * Mostrar botones de respuesta (fase 2)
     */
    showAnswerButtons() {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'block';
        }
        // console.log('👀 [Mockup] Botones de respuesta mostrados');
    }

    /**
     * Ocultar botones de respuesta (fase 1)
     */
    hideAnswerButtons() {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'none';
        }
        // console.log('🙈 [Mockup] Botones de respuesta ocultados');
    }

    /**
     * Mostrar mensaje de fase 3 (usando evento genérico)
     */
    showPhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'block';
        }
        // console.log('📝 [Mockup] Mensaje de fase 3 mostrado (usando evento genérico)');
    }

    /**
     * Ocultar mensaje de fase 3
     */
    hidePhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'none';
        }
        // console.log('🙈 [Mockup] Mensaje de fase 3 ocultado');
    }

    /**
     * Override: Handler de reconexión de jugador
     *
     * CONVENCIÓN: Usar updateUI() para re-renderizar todo desde gameState
     */
    handlePlayerReconnected(event) {
        // console.log('🔌 [Mockup] Player reconnected event:', event);

        // Llamar al handler del padre (actualiza this.gameState)
        super.handlePlayerReconnected(event);

        // CONVENCIÓN: Re-renderizar TODA la UI desde gameState actualizado
        this.updateUI();
    }

    /**
     * Handler cuando un jugador es bloqueado
     */
    onPlayerLocked(event) {
        // console.log('🔒 [Mockup] Player locked event:', event);

        // Solo procesar si es el jugador actual
        if (event.player_id !== this.config.playerId) {
            // console.log('🔒 [Mockup] Not current player, ignoring');
            return;
        }

        // console.log('🔒 [Mockup] Current player locked - hiding buttons');

        // Ocultar botones
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'none';
        }

        // Mostrar mensaje de bloqueado
        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'block';
        }
    }

    /**
     * Handler cuando los jugadores son desbloqueados (nueva ronda)
     */
    onPlayerUnlocked(event) {
        // console.log('🔓 [Mockup] Players unlocked event:', event);

        // Restaurar botones
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'block';
        }

        // Ocultar mensaje de bloqueado
        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'none';
        }

        // console.log('🔓 [Mockup] Players unlocked - buttons restored');
    }

    /**
     * Restaurar estado de jugador bloqueado al reconectar
     */
    restorePlayerLockedState() {
        // // console.log('🔄 [Mockup] Checking if need to restore locked state...', {
        //     hasGameState: !!this.gameState,
        //     gameState: this.gameState,
        //     playerId: this.config.playerId
        // });

        // Verificar si tenemos gameState
        if (!this.gameState || !this.gameState.player_system) {
            // console.log('⚠️ [Mockup] No player_system in gameState, skipping restore');
            return;
        }

        const playerSystem = this.gameState.player_system;
        const lockedPlayers = playerSystem.locked_players || [];

        // console.log('🔍 [Mockup] Locked players:', lockedPlayers);

        // Verificar si el jugador actual está bloqueado
        const isLocked = lockedPlayers.includes(this.config.playerId);

        if (isLocked) {
            // console.log('🔄 [Mockup] Restoring locked state for player', this.config.playerId);

            // Simular evento PlayerLockedEvent para restaurar UI
            this.onPlayerLocked({
                player_id: this.config.playerId,
                player_name: 'Current Player'
            });
        } else {
            // console.log('✅ [Mockup] Player is not locked, no need to restore');
        }
    }

    // ========================================================================
    // MÉTODOS DE RENDER - Siguiendo CONVENCION_RENDER_FRONTEND.md
    // ========================================================================

    /**
     * MÉTODO MAESTRO: Actualiza TODA la UI desde this.gameState
     *
     * Este es el punto de entrada principal para renderizar la UI completa.
     * Se usa en:
     * - Reconexión de jugadores (restaurar estado completo)
     * - Botón de refresh (volver a renderizar todo)
     * - Cambios de estado complejos
     *
     * Sigue el patrón: Estado → UI (similar a React/Vue)
     */
    updateUI() {
        if (!this.gameState) {
            console.warn('⚠️ [Mockup] Cannot update UI: gameState is null');
            return;
        }

        // console.log('🎨 [Mockup] Updating complete UI from gameState');

        // 1. Render general (elementos comunes)
        this.renderGeneral();

        // 2. Render de fase actual (detecta automáticamente)
        this.renderCurrentPhase();

        // 3. Actualizaciones reactivas (datos dinámicos)
        this.updateRoundCounter(
            this.gameState.round_system?.current_round || 1,
            this.gameState._config?.modules?.round_system?.total_rounds || 3
        );

        // 4. Restaurar estados especiales
        this.restorePlayerLockedState();

        // 5. IMPORTANTE: Restaurar popup de desconexión si hay jugadores desconectados
        // Esto previene que el popup desaparezca al renderizar
        if (this.presenceMonitor?.hasDisconnectedPlayers() && this.lastDisconnectedPlayerEvent) {
            this.showPlayerDisconnectedPopup(this.lastDisconnectedPlayerEvent);
        }

        // console.log('✅ [Mockup] UI update completed');
    }

    /**
     * DETECCIÓN AUTOMÁTICA: Renderiza la fase actual
     *
     * CONVENCIÓN DINÁMICA: Lee del config qué método renderizar para cada fase.
     * Esto hace que el método sea 100% genérico sin hardcodear nombres de fases.
     *
     * Ejemplo en config.json:
     * "phases": [
     *   { "name": "phase1", "render_method": "renderPhase1" },
     *   { "name": "phase2", "render_method": "renderPhase2" }
     * ]
     */
    renderCurrentPhase() {
        const currentPhase = this.gameState.phase || this.gameState.current_phase;

        if (!currentPhase) {
            // console.log('⚠️ [Mockup] No phase detected in gameState');
            return;
        }

        // Obtener configuración de fases desde gameState._config
        const phasesConfig = this.gameState._config?.modules?.phase_system?.phases;

        if (!phasesConfig || !Array.isArray(phasesConfig)) {
            console.warn('⚠️ [Mockup] No phase_system config found in gameState');
            return;
        }

        // Buscar la fase actual en la configuración
        const phaseConfig = phasesConfig.find(p => p.name === currentPhase);

        if (!phaseConfig) {
            // console.log(`⚠️ [Mockup] Phase "${currentPhase}" not found in config, skipping render`);
            return;
        }

        // Obtener el método de render desde la configuración
        const renderMethod = phaseConfig.render_method;

        if (!renderMethod) {
            // console.log(`⚠️ [Mockup] No render_method defined for phase "${currentPhase}"`);
            return;
        }

        // Verificar que el método existe en esta clase
        if (typeof this[renderMethod] !== 'function') {
            console.warn(`⚠️ [Mockup] Render method "${renderMethod}" not found in MockupGameClient`);
            return;
        }

        // console.log(`🎯 [Mockup] Rendering phase "${currentPhase}" using method "${renderMethod}"`);

        // Llamar dinámicamente al método de render
        this[renderMethod]();
    }

    /**
     * 1. RENDER GENERAL: Elementos comunes del juego
     *
     * Se ejecuta una vez al inicio del juego en handleGameStarted().
     * Renderiza elementos que siempre están presentes:
     * - Header del juego
     * - Scoreboard
     * - Contador de rondas
     */
    renderGeneral() {
        const ui = this.gameState._ui?.general;

        // Renderizar header si está configurado
        if (ui?.show_header) {
            const header = document.getElementById('game-header');
            if (header) {
                header.style.display = 'block';
            }
        }

        // Renderizar scores si está configurado
        if (ui?.show_scores) {
            const scoresContainer = document.getElementById('scores-container');
            if (scoresContainer) {
                scoresContainer.style.display = 'block';
            }
        }

        // console.log('🎨 [Mockup] General render completed', ui);
    }

    /**
     * 2. RENDER POR FASE: Phase1
     *
     * Fase de countdown (3 segundos).
     * - Oculta botones de respuesta
     * - Muestra timer de cuenta regresiva
     */
    renderPhase1() {
        const phaseUI = this.gameState._ui?.phases?.phase1;

        // Ocultar botones de respuesta durante countdown
        this.hideAnswerButtons();

        // console.log('🎨 [Mockup] Phase1 render completed', phaseUI);
    }

    /**
     * 2. RENDER POR FASE: Phase2
     *
     * Fase de respuesta.
     * - Muestra botones de respuesta
     * - Restaura estado de jugador bloqueado si ya votó
     */
    renderPhase2() {
        const phaseUI = this.gameState._ui?.phases?.phase2;

        // Mostrar botones de respuesta
        this.showAnswerButtons();

        // Si el jugador ya votó, restaurar estado de bloqueado
        this.restorePlayerLockedState();

        // console.log('🎨 [Mockup] Phase2 render completed', phaseUI);
    }

    /**
     * 2. RENDER GENÉRICO: Phase3 (usando handler genérico)
     *
     * Fase de resultados.
     * - Oculta botones
     * - Muestra mensaje de resultados
     */
    renderPhase3Generic() {
        const phaseUI = this.gameState._ui?.phases?.phase3;

        // Ocultar botones
        this.hideAnswerButtons();

        // Mostrar mensaje de fase 3
        this.showPhase3Message();

        // console.log('🎨 [Mockup] Phase3 render completed (generic)', phaseUI);
    }

    // ========================================================================
    // MÉTODOS DE ACTUALIZACIÓN REACTIVA - Siguiendo CONVENCION_EVENTOS_GAMESTATE.md
    // ========================================================================

    /**
     * 3. ACTUALIZACIÓN REACTIVA: Contador de rondas
     *
     * Actualiza solo el contador de rondas sin re-renderizar toda la vista.
     * Se llama desde handleRoundStarted() que lee de this.gameState.
     */
    updateRoundCounter(currentRound, totalRounds) {
        const roundEl = document.getElementById('current-round');
        if (roundEl) {
            roundEl.textContent = currentRound;
        }

        const totalEl = document.getElementById('total-rounds');
        if (totalEl) {
            totalEl.textContent = totalRounds;
        }

        // console.log('🔄 [Mockup] Round counter updated:', currentRound, '/', totalRounds);
    }

    /**
     * Helper: Ocultar mensaje de jugador bloqueado
     */
    hideLockedMessage() {
        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'none';
        }
    }
}

// Hacer disponible globalmente
window.MockupGameClient = MockupGameClient;
