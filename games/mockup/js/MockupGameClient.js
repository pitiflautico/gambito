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

        console.log('🧪 [Mockup] Test controls setup complete');
    }

    /**
     * Handler para Good Answer - finaliza la ronda inmediatamente
     */
    async handleGoodAnswer() {
        console.log('✅ [Mockup] Good Answer clicked - ending round immediately');

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

            console.log('✅ [Mockup] Good Answer successful:', data);
        } catch (error) {
            console.error('❌ [Mockup] Error calling Good Answer:', error);
        }
    }

    /**
     * Handler para Bad Answer - bloquea al jugador
     */
    async handleBadAnswer() {
        console.log('❌ [Mockup] Bad Answer clicked - blocking player');

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

            console.log('❌ [Mockup] Bad Answer successful:', data);
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
                console.log('🎮 [Mockup] JUEGO INICIADO', event);
            },
            handlePhase1Started: (event) => {
                console.log('🎯 [Mockup] FASE 1 INICIADA - Timer de 3 segundos comenzando', event);
                // Ocultar botones en fase 1
                this.hideAnswerButtons();
            },
            handlePhase1Ended: (event) => {
                console.log('🏁 [Mockup] FASE 1 FINALIZADA - Timer expirado correctamente', event);
            },
            handlePhase2Started: (event) => {
                console.log('🎯 [Mockup] FASE 2 INICIADA - Mostrando botones de respuesta', event);
                // Mostrar botones en fase 2
                this.showAnswerButtons();

                // Si el jugador ya votó, restaurar estado de bloqueado
                this.restorePlayerLockedState();
            },
            handlePhaseStarted: (event) => {
                console.log('🎬 [Mockup] FASE INICIADA (GENERIC HANDLER)', event);

                // OPCIÓN A: Handler genérico con lógica condicional
                // Esta opción es simple y funciona bien para lógica ligera

                // Fase 3 usa este handler genérico (NO tiene evento custom)
                if (event.phase_name === 'phase3') {
                    console.log('🎯 [Mockup] FASE 3 DETECTADA (usando evento genérico) - Ocultando botones y mostrando mensaje');
                    this.hideAnswerButtons();
                    this.showPhase3Message();
                }

                // NOTA: Phase2 ahora usa evento custom (Phase2StartedEvent)
                // por lo que NO pasará por aquí, irá directo a handlePhase2Started()

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

        console.log('📋 [Mockup] Fase actualizada:', phaseName, event);
    }

    /**
     * Override: Handler de ronda iniciada (para actualizar UI específica)
     */
    handleRoundStarted(event) {
        super.handleRoundStarted(event);

        // Actualizar display de ronda en el DOM
        const roundEl = document.getElementById('current-round');
        if (roundEl) {
            roundEl.textContent = event.round || 1;
        }

        // Ocultar mensaje de bloqueado al iniciar nueva ronda
        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.style.display = 'none';
        }

        // Ocultar mensaje de fase 3 al iniciar nueva ronda
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
        console.log('👀 [Mockup] Botones de respuesta mostrados');
    }

    /**
     * Ocultar botones de respuesta (fase 1)
     */
    hideAnswerButtons() {
        const answerButtons = document.getElementById('answer-buttons');
        if (answerButtons) {
            answerButtons.style.display = 'none';
        }
        console.log('🙈 [Mockup] Botones de respuesta ocultados');
    }

    /**
     * Mostrar mensaje de fase 3 (usando evento genérico)
     */
    showPhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'block';
        }
        console.log('📝 [Mockup] Mensaje de fase 3 mostrado (usando evento genérico)');
    }

    /**
     * Ocultar mensaje de fase 3
     */
    hidePhase3Message() {
        const phase3Message = document.getElementById('phase3-message');
        if (phase3Message) {
            phase3Message.style.display = 'none';
        }
        console.log('🙈 [Mockup] Mensaje de fase 3 ocultado');
    }

    /**
     * Override: Handler de reconexión de jugador
     */
    handlePlayerReconnected(event) {
        console.log('🔌 [Mockup] Player reconnected event:', event);

        // Llamar al handler del padre
        super.handlePlayerReconnected(event);

        // Restaurar estado de jugador bloqueado
        this.restorePlayerLockedState();
    }

    /**
     * Handler cuando un jugador es bloqueado
     */
    onPlayerLocked(event) {
        console.log('🔒 [Mockup] Player locked event:', event);

        // Solo procesar si es el jugador actual
        if (event.player_id !== this.config.playerId) {
            console.log('🔒 [Mockup] Not current player, ignoring');
            return;
        }

        console.log('🔒 [Mockup] Current player locked - hiding buttons');

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
        console.log('🔓 [Mockup] Players unlocked event:', event);

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

        console.log('🔓 [Mockup] Players unlocked - buttons restored');
    }

    /**
     * Restaurar estado de jugador bloqueado al reconectar
     */
    restorePlayerLockedState() {
        console.log('🔄 [Mockup] Checking if need to restore locked state...', {
            hasGameState: !!this.gameState,
            gameState: this.gameState,
            playerId: this.config.playerId
        });

        // Verificar si tenemos gameState
        if (!this.gameState || !this.gameState.player_system) {
            console.log('⚠️ [Mockup] No player_system in gameState, skipping restore');
            return;
        }

        const playerSystem = this.gameState.player_system;
        const lockedPlayers = playerSystem.locked_players || [];

        console.log('🔍 [Mockup] Locked players:', lockedPlayers);

        // Verificar si el jugador actual está bloqueado
        const isLocked = lockedPlayers.includes(this.config.playerId);

        if (isLocked) {
            console.log('🔄 [Mockup] Restoring locked state for player', this.config.playerId);

            // Simular evento PlayerLockedEvent para restaurar UI
            this.onPlayerLocked({
                player_id: this.config.playerId,
                player_name: 'Current Player'
            });
        } else {
            console.log('✅ [Mockup] Player is not locked, no need to restore');
        }
    }
}

// Hacer disponible globalmente
window.MockupGameClient = MockupGameClient;
