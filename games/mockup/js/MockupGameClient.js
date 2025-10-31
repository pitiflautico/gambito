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
    }

    /**
     * Handler para Good Answer - finaliza la ronda inmediatamente
     */
    async handleGoodAnswer() {
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
        } catch (error) {
            console.error('❌ [Mockup] Error calling Good Answer:', error);
        }
    }

    /**
     * Handler para Bad Answer - bloquea al jugador
     */
    async handleBadAnswer() {
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

                // Actualizar indicador de rol
                this.updateRoleDisplay();

                // CONVENCIÓN: Usar updateUI() para renderizar todo desde gameState
                this.updateUI();
            },
            handlePhase1Started: (event) => {
                this.renderPhase1();
            },
            handlePhase1Ended: (event) => {
            },
            handlePhase2Started: (event) => {
                this.renderPhase2();
            },
            handlePhaseStarted: (event) => {
                if (event.phase_name === 'phase3') {
                    this.renderPhase3Generic();
                }
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

        // Actualizar display del rol (puede haber rotado)
        this.updateRoleDisplay();

        // Limpiar estado de ronda anterior
        this.hideLockedMessage();
        this.hidePhase3Message();
        this.hideAskerMessage();

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
    }

    /**
     * Ocultar botones de respuesta (fase 1)
     */
    hideAnswerButtons() {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'none';
        }
    }

    /**
     * Mostrar mensaje de asker con título personalizado por fase
     */
    showAskerMessage(phaseName) {
        const askerMessage = document.getElementById('asker-message');
        const askerPhaseTitle = document.getElementById('asker-phase-title');

        if (askerMessage) {
            askerMessage.style.display = 'block';
        }

        if (askerPhaseTitle) {
            const phaseLabels = {
                'phase1': 'You are the Asker - Phase 1',
                'phase2': 'You are the Asker - Phase 2',
                'phase3': 'You are the Asker - Phase 3'
            };
            askerPhaseTitle.textContent = phaseLabels[phaseName] || 'You are the Asker';
        }
    }

    /**
     * Ocultar mensaje de asker
     */
    hideAskerMessage() {
        const askerMessage = document.getElementById('asker-message');
        if (askerMessage) {
            askerMessage.style.display = 'none';
        }
    }

    /**
     * Mostrar mensaje de fase 3 (usando evento genérico)
     */
    showPhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'block';
        }
    }

    /**
     * Ocultar mensaje de fase 3
     */
    hidePhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'none';
        }
    }

    /**
     * Override: Handler de reconexión de jugador
     *
     * CONVENCIÓN: Usar updateUI() para re-renderizar todo desde gameState
     */
    handlePlayerReconnected(event) {
        super.handlePlayerReconnected(event);
        this.updateUI();
    }

    /**
     * Handler cuando un jugador es bloqueado
     */
    onPlayerLocked(event) {
        if (event.player_id !== this.config.playerId) {
            return;
        }

        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'none';
        }

        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'block';
        }
    }

    /**
     * Handler cuando los jugadores son desbloqueados (nueva ronda)
     */
    onPlayerUnlocked(event) {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'block';
        }

        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'none';
        }
    }

    /**
     * Restaurar estado de jugador bloqueado al reconectar
     */
    restorePlayerLockedState() {
        if (!this.gameState || !this.gameState.player_system) {
            return;
        }

        const playerSystem = this.gameState.player_system;
        const lockedPlayers = playerSystem.locked_players || [];
        const isLocked = lockedPlayers.includes(this.config.playerId);

        if (isLocked) {
            this.onPlayerLocked({
                player_id: this.config.playerId,
                player_name: 'Current Player'
            });
        }
    }

    // ========================================================================
    // MÉTODOS DE ROLES - Detección de rol del jugador actual
    // ========================================================================

    /**
     * Obtener el rol del jugador actual
     *
     * @returns {string|null} El rol del jugador actual ('asker', 'guesser', etc.) o null si no tiene rol
     */
    getPlayerRole() {
        if (!this.gameState?.player_system?.persistent_roles) {
            console.log('🎭 [Mockup] No persistent_roles in gameState', this.gameState);
            return null;
        }

        const role = this.gameState.player_system.persistent_roles[this.config.playerId];
        console.log('🎭 [Mockup] Player role:', {
            playerId: this.config.playerId,
            role: role,
            allRoles: this.gameState.player_system.persistent_roles
        });
        return role || null;
    }

    /**
     * Actualizar el indicador visual de rol en la UI
     */
    updateRoleDisplay() {
        const roleEl = document.getElementById('player-role');
        if (roleEl) {
            const role = this.getPlayerRole();
            roleEl.textContent = role ? role.toUpperCase() : 'NO ROLE';
            roleEl.className = role === 'asker' ? 'font-bold text-yellow-400' : 'font-bold text-green-400';
        }
    }

    /**
     * Verificar si el jugador actual tiene un rol específico
     *
     * @param {string} roleName - Nombre del rol a verificar ('asker', 'guesser', etc.)
     * @returns {boolean} true si el jugador tiene ese rol
     */
    hasRole(roleName) {
        const currentRole = this.getPlayerRole();
        return currentRole === roleName;
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
        if (this.presenceMonitor?.hasDisconnectedPlayers() && this.lastDisconnectedPlayerEvent) {
            this.showPlayerDisconnectedPopup(this.lastDisconnectedPlayerEvent);
        }
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
            return;
        }

        // Obtener el método de render desde la configuración
        const renderMethod = phaseConfig.render_method;

        if (!renderMethod) {
            return;
        }

        // Verificar que el método existe en esta clase
        if (typeof this[renderMethod] !== 'function') {
            console.warn(`⚠️ [Mockup] Render method "${renderMethod}" not found in MockupGameClient`);
            return;
        }


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

    }

    /**
     * 2. RENDER POR FASE: Phase1
     *
     * Fase de countdown (3 segundos).
     * - Asker: Muestra mensaje "You are the Asker - Phase 1"
     * - Guesser: Solo oculta botones (no mensajes aún)
     */
    renderPhase1() {
        const phaseUI = this.gameState._ui?.phases?.phase1;

        // Actualizar indicador de rol
        this.updateRoleDisplay();

        // Ocultar botones de respuesta durante countdown
        this.hideAnswerButtons();

        // Verificar rol del jugador actual
        const playerRole = this.getPlayerRole();

        if (playerRole === 'asker') {
            // Mostrar mensaje de asker
            this.showAskerMessage('phase1');
        } else {
            // Guesser: ocultar mensaje de asker (por si estaba visible)
            this.hideAskerMessage();
        }

    }

    /**
     * 2. RENDER POR FASE: Phase2
     *
     * Fase de respuesta.
     * - Asker: Muestra mensaje "You are the Asker - Phase 2", sin botones
     * - Guesser: Muestra botones de respuesta, restaura estado de bloqueado si ya votó
     */
    renderPhase2() {
        const phaseUI = this.gameState._ui?.phases?.phase2;

        // Actualizar indicador de rol
        this.updateRoleDisplay();

        // Verificar rol del jugador actual
        const playerRole = this.getPlayerRole();

        if (playerRole === 'asker') {
            // Asker: Mostrar mensaje y ocultar botones
            this.showAskerMessage('phase2');
            this.hideAnswerButtons();
        } else {
            // Guesser: Ocultar mensaje de asker y mostrar botones
            this.hideAskerMessage();
            this.showAnswerButtons();

            // Si el jugador ya votó, restaurar estado de bloqueado
            this.restorePlayerLockedState();
        }

    }

    /**
     * 2. RENDER GENÉRICO: Phase3 (usando handler genérico)
     *
     * Fase de resultados.
     * - Asker: Muestra mensaje "You are the Asker - Phase 3", sin botones
     * - Guesser: Muestra mensaje de fase 3 (evento genérico)
     */
    renderPhase3Generic() {
        const phaseUI = this.gameState._ui?.phases?.phase3;

        // Actualizar indicador de rol
        this.updateRoleDisplay();

        // Ocultar botones siempre en fase 3
        this.hideAnswerButtons();

        // Verificar rol del jugador actual
        const playerRole = this.getPlayerRole();

        if (playerRole === 'asker') {
            // Asker: Mostrar mensaje de asker
            this.showAskerMessage('phase3');
            this.hidePhase3Message();
        } else {
            // Guesser: Mostrar mensaje de fase 3
            this.hideAskerMessage();
            this.showPhase3Message();
        }

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
