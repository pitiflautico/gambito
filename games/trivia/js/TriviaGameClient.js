// BaseGameClient está disponible globalmente desde resources/js/app.js
const { BaseGameClient } = window;

export class TriviaGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.config = config;
        this.customHandlers = null;
        // Alineado con Mockup: registrar handlers al construir
        this.setupEventManager();
    }

    setupEventManager() {
        this.customHandlers = {
            handleDomLoaded: (event) => {
                super.handleDomLoaded(event);
                this.restorePlayerLockedState();
            },
            handleGameStarted: (event) => {
                super.handleGameStarted(event);
                this.updateUI();
            },
            handlePhaseChanged: (event) => {
                super.handlePhaseChanged(event);
                this.updatePhaseDisplay(event);
            },
            handleRoundStarted: (event) => {
                super.handleRoundStarted(event);
                this.updateRoundCounter(
                    this.gameState?.round_system?.current_round || 1,
                    this.gameState?._config?.modules?.round_system?.total_rounds || 5
                );
                this.hideLockedMessage();
                // Asegurar cambio a estado de juego visible
                document.getElementById('loading-state')?.classList.add('hidden');
                document.getElementById('playing-state')?.classList.remove('hidden');
            },
            handleRoundEnded: (event) => {
                super.handleRoundEnded(event);
            },
            // Custom events (aligned with capabilities/config)
            handleQuestionStarted: (event) => {
                console.log('[Trivia] handleQuestionStarted payload:', event);
                // Fase única: pintar pregunta y opciones a la vez
                this.updatePhaseDisplay({ additional_data: { phase_name: 'question' } });

                // Pregunta
                if (event?.question_text) {
                    const qTextEl = document.getElementById('question-text');
                    if (qTextEl) qTextEl.textContent = event.question_text;
                }
                // Fallback: si no viene en payload, leer de gameState._ui
                if (!event?.question_text) {
                    const text = this.gameState?._ui?.phases?.question?.text;
                    const qTextEl = document.getElementById('question-text');
                    if (qTextEl && text) qTextEl.textContent = text;
                }
                if (!event?.question_text && !this.gameState?._ui?.phases?.question?.text) {
                    console.warn('[Trivia] No question text in event or gameState, fetching state...');
                    fetch(`/api/rooms/${this.config.roomCode}/state`).then(r => r.json()).then(data => {
                        this.gameState = data.game_state;
                        const text = this.gameState?._ui?.phases?.question?.text;
                        const qTextEl = document.getElementById('question-text');
                        if (qTextEl && text) qTextEl.textContent = text;
                    }).catch(() => {});
                }
                const questionEl = document.getElementById('question-section');
                questionEl?.classList.remove('hidden');

                // Opciones
                const list = document.getElementById('options-list');
                let options = Array.isArray(event?.options) ? event.options : (this.gameState?._ui?.phases?.answering?.options || []);
                const correctIndex = typeof event?.correct_index === 'number' ? event.correct_index : this.gameState?._ui?.phases?.answering?.correct_option;
                if (list && Array.isArray(options)) {
                    list.innerHTML = '';
                    options.forEach((opt, idx) => {
                        const btn = document.createElement('button');
                        btn.className = 'w-full px-4 py-3 bg-gray-100 hover:bg-gray-200 rounded-lg border';
                        btn.textContent = opt;
                        btn.addEventListener('click', () => {
                            // Enviar respuesta al backend para validación server-side
                            this.sendAnswer(idx);
                            // Optimista: ocultar botones hasta respuesta del servidor
                            this.hideAnswerButtons();
                        });
                        list.appendChild(btn);
                    });
                    document.getElementById('answer-buttons')?.classList.remove('hidden');
                }
                if ((!Array.isArray(options) || options.length === 0) && list) {
                    console.warn('[Trivia] No options in event or gameState, fetching state...');
                    fetch(`/api/rooms/${this.config.roomCode}/state`).then(r => r.json()).then(data => {
                        this.gameState = data.game_state;
                        const opts = this.gameState?._ui?.phases?.answering?.options || [];
                        const correct = this.gameState?._ui?.phases?.answering?.correct_option;
                        list.innerHTML = '';
                        opts.forEach((opt, idx) => {
                            const btn = document.createElement('button');
                            btn.className = 'w-full px-4 py-3 bg-gray-100 hover:bg-gray-200 rounded-lg border';
                            btn.textContent = opt;
                            btn.addEventListener('click', () => {
                                if (typeof correct === 'number' && idx === correct) {
                                    this.sendCorrectAnswer();
                                } else {
                                    this.sendWrongAnswer();
                                }
                            });
                            list.appendChild(btn);
                        });
                        if (opts.length > 0) {
                            document.getElementById('answer-buttons')?.classList.remove('hidden');
                        }
                    }).catch(() => {});
                }

                // Mostrar playing-state
                document.getElementById('loading-state')?.classList.add('hidden');
                document.getElementById('playing-state')?.classList.remove('hidden');
            },
            handleQuestionEnded: (event) => {
            },
            // handleAnsweringStarted ya no se usa en fase única
            // Generic
            handlePhaseStarted: (event) => {
                if (event.phase_name === 'results') {
                    this.renderResultsGeneric();
                }
            },
            handlePlayerLocked: (event) => {
                this.onPlayerLocked(event);
            },
            handlePlayersUnlocked: (event) => {
                this.onPlayersUnlocked(event);
            },
        };

        super.setupEventManager(this.customHandlers);
    }

    // ==============================
    // Acciones del jugador
    // ==============================
    async sendCorrectAnswer() {
        try {
            const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ action: 'correct_answer', data: {} })
            });
            if (!response.ok) {
                console.error('[Trivia] correct_answer failed');
            }
        } catch (e) {
            console.error('[Trivia] correct_answer error', e);
        }
    }

    async sendWrongAnswer() {
        try {
            const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ action: 'wrong_answer', data: {} })
            });
            if (!response.ok) {
                console.error('[Trivia] wrong_answer failed');
            }
        } catch (e) {
            console.error('[Trivia] wrong_answer error', e);
        }
    }

    async sendAnswer(optionIndex) {
        try {
            const response = await fetch(`/api/rooms/${this.config.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ action: 'answer', data: { option_index: optionIndex } })
            });
            if (!response.ok) {
                console.error('[Trivia] answer failed');
            }
        } catch (e) {
            console.error('[Trivia] answer error', e);
        }
    }

    // ==============================
    // Render general/por fase
    // ==============================
    updateUI() {
        if (!this.gameState) return;
        this.renderGeneral();
        this.renderCurrentPhase();
        this.updateRoundCounter(
            this.gameState.round_system?.current_round || 1,
            this.gameState._config?.modules?.round_system?.total_rounds || 5
        );
        this.restorePlayerLockedState();
    }

    renderCurrentPhase() {
        const currentPhase = this.gameState?.phase || this.gameState?.current_phase;
        if (!currentPhase) return;
        const phasesConfig = this.gameState?._config?.modules?.phase_system?.phases;
        if (!phasesConfig || !Array.isArray(phasesConfig)) return;
        const phaseConfig = phasesConfig.find(p => p.name === currentPhase);
        if (!phaseConfig) return;
        const renderMethod = phaseConfig.render_method;
        if (!renderMethod || typeof this[renderMethod] !== 'function') return;
        this[renderMethod]();
    }

    renderGeneral() {
        const header = document.getElementById('game-header');
        if (header) header.style.display = 'block';
        const scores = document.getElementById('scores-container');
        if (scores) scores.style.display = 'block';
    }

    renderQuestion() {
        this.hideAnswerButtons();
        const questionEl = document.getElementById('question-section');
        if (questionEl) questionEl.classList.remove('hidden');

        // Mostrar texto de la pregunta desde gameState._ui
        const text = this.gameState?._ui?.phases?.question?.text;
        const qTextEl = document.getElementById('question-text');
        if (qTextEl && text) {
            qTextEl.textContent = text;
        }
        const resultsEl = document.getElementById('results-section');
        if (resultsEl) resultsEl.classList.add('hidden');
    }

    renderAnswering() {
        this.showAnswerButtons();
        const questionEl = document.getElementById('question-section');
        if (questionEl) questionEl.classList.add('hidden');

        // Renderizar opciones desde gameState._ui
        const options = this.gameState?._ui?.phases?.answering?.options || [];
        const correctIndex = this.gameState?._ui?.phases?.answering?.correct_option;
        const list = document.getElementById('options-list');
        if (list) {
            list.innerHTML = '';
            options.forEach((opt, idx) => {
                const btn = document.createElement('button');
                btn.className = 'w-full px-4 py-3 bg-gray-100 hover:bg-gray-200 rounded-lg border';
                btn.textContent = opt;
                btn.addEventListener('click', () => {
                    if (typeof correctIndex === 'number' && idx === correctIndex) {
                        this.sendCorrectAnswer();
                    } else {
                        this.sendWrongAnswer();
                    }
                });
                list.appendChild(btn);
            });
        }
    }

    renderResultsGeneric() {
        this.hideAnswerButtons();
        const resultsEl = document.getElementById('results-section');
        if (resultsEl) resultsEl.classList.remove('hidden');
    }

    // ==============================
    // UI helpers
    // ==============================
    showAnswerButtons() {
        document.getElementById('answer-buttons')?.classList.remove('hidden');
        document.getElementById('locked-message')?.classList.add('hidden');
    }

    hideAnswerButtons() {
        document.getElementById('answer-buttons')?.classList.add('hidden');
    }

    hideLockedMessage() {
        const lockedMessage = document.getElementById('locked-message');
        if (lockedMessage) {
            lockedMessage.classList.add('hidden');
        }
    }

    onPlayerLocked(event) {
        if (event.player_id !== this.config.playerId) return;
        this.hideAnswerButtons();
        document.getElementById('locked-message')?.classList.remove('hidden');
    }

    onPlayersUnlocked(event) {
        document.getElementById('locked-message')?.classList.add('hidden');
        this.showAnswerButtons();
    }

    restorePlayerLockedState() {
        const lockedPlayers = this.gameState?.player_system?.locked_players || [];
        const isLocked = lockedPlayers.includes(this.config.playerId);
        if (isLocked) {
            this.onPlayerLocked({ player_id: this.config.playerId });
        }
    }

    updateRoundCounter(currentRound, totalRounds) {
        const roundEl = document.getElementById('current-round');
        if (roundEl) roundEl.textContent = currentRound;
        const totalEl = document.getElementById('total-rounds');
        if (totalEl) totalEl.textContent = totalRounds;
    }

    updatePhaseDisplay(event) {
        const name = event?.additional_data?.phase_name || event?.new_phase || 'unknown';
        const el = document.getElementById('current-phase');
        if (el) el.textContent = name;
    }
}

// Exponer globalmente para loader lazy
// IMPORTANTE: Hacer esto DESPUÉS de definir la clase completa
// Esto asegura que todos los métodos estén en el prototipo
window.TriviaGameClient = TriviaGameClient;


