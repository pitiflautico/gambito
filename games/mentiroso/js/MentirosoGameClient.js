// BaseGameClient ya está disponible globalmente a través de resources/js/app.js
const { BaseGameClient } = window;

export class MentirosoGameClient extends BaseGameClient {
    constructor(config) {
        super(config);
        this.currentRole = null;
        this.currentStatement = null;
        this.statementIsTrue = null; // Solo el orador conoce esto
        this.hasVoted = false;
        this.votedValue = null;

        // Inicializar event listeners de botones
        this.initializeEventListeners();
    }

    /**
     * Override: Configurar EventManager con handlers específicos de Mentiroso
     */
    setupEventManager() {
        // Registrar handlers personalizados de Mentiroso automáticamente
        const customHandlers = {
            handleStatementRevealed: (event) => this.handleStatementRevealed(event),
            handleGameStateUpdated: (event) => this.handleGameStateUpdated(event),
            handlePlayersUnlocked: (event) => this.handlePlayersUnlocked(event),
            handlePhaseChanged: (event) => this.handlePhaseChanged(event),
        };

        // Llamar al setupEventManager del padre con los handlers custom
        super.setupEventManager(customHandlers);
    }

    /**
     * Initialize DOM event listeners
     */
    initializeEventListeners() {
        // Vote buttons
        const voteTrue = document.getElementById('vote-true');
        const voteFalse = document.getElementById('vote-false');

        if (voteTrue) {
            voteTrue.addEventListener('click', () => this.submitVote(true));
        }

        if (voteFalse) {
            voteFalse.addEventListener('click', () => this.submitVote(false));
        }

        // Back to lobby button
        const backButton = document.getElementById('back-to-lobby');
        if (backButton) {
            backButton.addEventListener('click', () => {
                window.location.href = `/rooms/${this.roomCode}`;
            });
        }
    }

    /**
     * Override: Manejar expiración de timer con tipo específico.
     *
     * Este método SOLO notifica al backend que el timer expiró.
     * El backend verifica, ejecuta callbacks, y emite PhaseChangedEvent.
     * El frontend solo escucha el evento y actualiza la UI.
     */
    async onTimerExpired(timerType) {
        const timerElement = this.getTimerElement();
        if (timerElement) {
            timerElement.textContent = '¡Tiempo agotado!';
            timerElement.classList.add('timer-expired');
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/check-timer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    timer_type: timerType
                })
            });

            await response.json();
        } catch (error) {
            console.error('[Mentiroso] Error notifying timer expiration:', error);
        }
    }

    /**
     * Handle round started event
     */
    handleRoundStarted(event) {
        console.log('🏁 [FRONTEND] RoundStartedEvent RECIBIDO - Round:', event.current_round);

        // ✅ Llamar a super para actualizar round info (ya NO inicia timer)
        // BaseGameClient.handleRoundStarted() solo actualiza currentRound y totalRounds
        super.handleRoundStarted(event);

        const { current_round, total_rounds, game_state } = event;

        // Store game state for later use (e.g., updateScoreboard)
        this.gameState = game_state;

        // Reset vote state
        this.hasVoted = false;
        this.votedValue = null;

        // Extract data from game_state
        const currentStatement = game_state?.current_statement;
        const currentPhase = game_state?.current_phase || 'preparation';
        const playersConfig = game_state?._config?.players;
        const players = Array.isArray(playersConfig) ? playersConfig : [];
        const playerSystem = game_state?.player_system?.players || {};

        // Get my role from PlayerManager data
        this.currentRole = playerSystem[this.playerId]?.round_role || null;

        // Get statement text - handle both object and string formats
        this.currentStatement = typeof currentStatement === 'object' ? (currentStatement?.text || '') : (currentStatement || '');

        // Update round info
        this.updateElement('current-round', current_round);
        this.updateElement('total-rounds', total_rounds);

        // Find and display orador name
        const oradorRotation = game_state?.orador_rotation || [];
        const oradorIndex = game_state?.current_orador_index || 0;
        const oradorId = oradorRotation[oradorIndex];

        if (players.length > 0) {
            const oradorPlayer = players.find(p => p.id === oradorId);
            if (oradorPlayer) {
                this.updateElement('orador-name', oradorPlayer.name);
            }
        }

        // Show statement (without revealing truth)
        this.updateElement('statement-display', this.currentStatement);
        this.updateElement('orador-statement', this.currentStatement);
        this.updateElement('voting-statement', this.currentStatement);

        // Update phase
        this.updatePhase(currentPhase);

        // NOTA: NO iniciamos timer aquí porque Mentiroso usa PhaseChangedEvent para timers
        // RoundStartedEvent se emite primero (sin timing), luego PhaseChangedEvent con timing
        console.log('[Mentiroso] RoundStarted processed, waiting for PhaseChangedEvent for timer');
    }

    /**
     * Handle private event: statement revealed to orador
     */
    handleStatementRevealed(data) {
        this.statementIsTrue = data.is_true;
        // Handle both object and string formats
        this.currentStatement = typeof data.statement === 'object' ? data.statement.text : data.statement;

        // Show truth indicator (solo el orador lo ve)
        const truthIndicator = document.getElementById('truth-indicator');
        if (truthIndicator) {
            if (data.is_true) {
                truthIndicator.innerHTML = '<span class="text-green-400">✓ Es VERDADERO</span>';
                truthIndicator.className = 'text-xl font-bold bg-green-600/20 rounded-lg p-3';
            } else {
                truthIndicator.innerHTML = '<span class="text-red-400">✗ Es FALSO</span>';
                truthIndicator.className = 'text-xl font-bold bg-red-600/20 rounded-lg p-3';
            }
        }
    }

    /**
     * Handle game state updated
     */
    handleGameStateUpdated(data) {
        if (data.phase) {
            this.updatePhase(data.phase);
        }

        if (data.round_number) {
            this.updateElement('current-round', data.round_number);
        }

        if (data.my_role) {
            this.currentRole = data.my_role;
        }

        // Update scores
        if (data.scores) {
            this.updateScoreboard(data.scores);
        }
    }

    /**
     * Handle phase changed event (for multiple timers in same round)
     * Mentiroso has 3 phases: preparation → persuasion → voting
     */
    async handlePhaseChanged(event) {
        // PhaseChangedEvent broadcasts: { new_phase, previous_phase, additional_data }
        // additional_data contains: { phase, game_state, timing }
        const { new_phase, previous_phase, additional_data } = event;
        const phase = additional_data?.phase || new_phase;
        const game_state = additional_data?.game_state;
        const timing = additional_data?.timing;

        if (!phase) {
            console.error('[Mentiroso] ❌ No phase in PhaseChangedEvent - cannot proceed');
            return;
        }

        // 🛡️ DEFENSIVE CHECK: Si estamos mostrando resultados, IGNORAR PhaseChangedEvent
        // hasta que termine el countdown (BaseGameClient llamará a handleRoundStarted cuando corresponda)
        const resultsPhase = document.getElementById('results-phase');
        if (resultsPhase && !resultsPhase.classList.contains('hidden')) {
            console.log('[Mentiroso] ⏸️ Ignoring PhaseChangedEvent while showing results', {
                new_phase: phase,
                reason: 'waiting_for_round_countdown'
            });
            return;
        }

        // Update phase UI
        this.updatePhase(phase);

        // Start timer if timing metadata provided
        if (timing && timing.server_time && timing.duration) {
            const timerElement = this.getTimerElement();

            if (timerElement) {
                const durationMs = timing.duration * 1000;

                // Usar nuevo sistema: callback con timer_type
                this.timing.startServerSyncedCountdown(
                    timing.server_time,
                    durationMs,
                    timerElement,
                    () => this.onTimerExpired(phase),
                    `${phase}_timer`
                );
            } else {
                console.error('[Mentiroso] ❌ Timer element not found!');
            }
        }
    }

    /**
     * Handle round ended event
     */
    handleRoundEnded(event) {
        console.log('🏁 [FRONTEND] RoundEndedEvent RECIBIDO - Round:', event.round_number);

        // Llamar primero al handler base que actualiza scores y maneja timing
        super.handleRoundEnded(event);

        // Ocultar todas las fases de juego
        this.hideElement('waiting-phase');
        this.hideElement('preparation-phase');
        this.hideElement('persuasion-phase');
        this.hideElement('voting-phase');

        // Mostrar fase de resultados con countdown
        this.showElement('results-phase');
        this.showElement('timer-container');
        this.updateElement('timer-message', 'Siguiente ronda en...');

        // Actualizar datos de resultados en la pantalla
        const results = event.results || {};
        this.updateResultsDisplay(results);
    }

    /**
     * Update phase UI
     */
    updatePhase(phase) {
        // Hide all phases
        this.hideElement('waiting-phase');
        this.hideElement('preparation-phase');
        this.hideElement('persuasion-phase');
        this.hideElement('voting-phase');
        this.hideElement('results-phase');

        // Show current phase
        switch (phase) {
            case 'waiting':
                this.showElement('waiting-phase');
                this.hideElement('timer-container');
                break;

            case 'preparation':
                if (this.currentRole === 'orador') {
                    this.showElement('preparation-phase');
                } else {
                    this.showElement('waiting-phase');
                }
                this.showElement('timer-container');
                this.updateElement('timer-message', 'Preparando defensa...');
                break;

            case 'persuasion':
                this.showElement('persuasion-phase');

                // Show/hide specific elements based on role
                if (this.currentRole === 'orador') {
                    this.showElement('orador-waiting');
                } else {
                    this.hideElement('orador-waiting');
                }

                this.showElement('timer-container');
                this.updateElement('timer-message', 'Tiempo de persuasión');
                break;

            case 'voting':
                this.showElement('voting-phase');

                if (this.currentRole === 'orador') {
                    // Orador no vota
                    this.hideElement('vote-buttons');
                    this.hideElement('vote-sent');
                    this.showElement('orador-vote-waiting');
                } else {
                    // Votante puede votar
                    if (this.hasVoted) {
                        this.hideElement('vote-buttons');
                        this.showElement('vote-sent');
                        this.hideElement('orador-vote-waiting');
                    } else {
                        this.showElement('vote-buttons');
                        this.hideElement('vote-sent');
                        this.hideElement('orador-vote-waiting');
                    }
                }

                this.showElement('timer-container');
                this.updateElement('timer-message', 'Vota: ¿Verdad o mentira?');
                break;

            case 'results':
                this.showElement('results-phase');
                this.showElement('timer-container');  // Mostrar countdown para siguiente ronda
                this.updateElement('timer-message', 'Siguiente ronda en...');
                break;
        }
    }

    /**
     * Submit vote
     */
    async submitVote(isTrue) {
        if (this.hasVoted) {
            return;
        }

        if (this.currentRole === 'orador') {
            return;
        }

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/game/mentiroso/vote`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    player_id: this.playerId,
                    vote: isTrue
                })
            });

            if (!response.ok) {
                throw new Error('Failed to submit vote');
            }

            this.hasVoted = true;
            this.votedValue = isTrue;

            // Update UI
            this.hideElement('vote-buttons');
            this.showElement('vote-sent');

        } catch (error) {
            console.error('[Mentiroso] Error submitting vote:', error);
            alert('Error al enviar el voto');
        }
    }

    /**
     * Update results display with round data
     */
    updateResultsDisplay(data) {
        // Extract statement text (handle both object and string)
        const statement = data.statement || {};
        const statementText = typeof statement === 'object' ? statement.text : statement;
        const isTrue = statement.is_true !== undefined ? statement.is_true : data.is_true;

        // Update statement
        this.updateElement('results-statement', statementText || this.currentStatement);

        // Update truth indicator
        const truthElement = document.getElementById('results-truth');
        if (truthElement) {
            if (isTrue) {
                truthElement.innerHTML = '<span class="text-green-400">✓ Era VERDADERO</span>';
            } else {
                truthElement.innerHTML = '<span class="text-red-400">✗ Era FALSO</span>';
            }
        }

        // Count votes from votes array
        const votes = data.votes || [];
        const votesTrue = votes.filter(v => v.vote === true).length;
        const votesFalse = votes.filter(v => v.vote === false).length;

        this.updateElement('votes-true-count', votesTrue);
        this.updateElement('votes-false-count', votesFalse);

        // Create result message
        const oradorDeceived = data.orador_deceived_majority || false;
        const messageElement = document.getElementById('results-message');
        if (messageElement) {
            if (oradorDeceived) {
                messageElement.textContent = '¡El orador engañó a la mayoría!';
                messageElement.className = 'text-center text-lg mb-4 font-bold text-yellow-400';
            } else {
                messageElement.textContent = 'La mayoría acertó';
                messageElement.className = 'text-center text-lg mb-4 font-bold text-blue-400';
            }
        }

        // Update scores
        if (data.scores) {
            this.updateScoreboard(data.scores);
        }

        // Note: No llamamos updatePhase('results') aquí porque handleRoundEnded()
        // ya manejó mostrar/ocultar las fases correctamente
    }

    /**
     * Update scoreboard
     *
     * @param {Object|Array} scores - Can be either an object {player_id: score} or array of player objects
     */
    updateScoreboard(scores) {
        const scoreboard = document.getElementById('scoreboard');
        if (!scoreboard) return;

        // Convert scores object to array if needed
        let scoresArray;
        if (Array.isArray(scores)) {
            scoresArray = scores;
        } else {
            // Convert {player_id: score} to array of objects
            const players = this.gameState?._config?.players || [];
            scoresArray = Object.entries(scores || {}).map(([playerId, score]) => {
                const player = players.find(p => p.id === parseInt(playerId));
                return {
                    player_id: parseInt(playerId),
                    name: player?.name || `Player ${playerId}`,
                    score: score
                };
            });
        }

        // Sort by score descending
        const sortedScores = scoresArray.sort((a, b) => b.score - a.score);

        scoreboard.innerHTML = sortedScores.map((player, index) => {
            const isMe = player.player_id === this.playerId;
            const medal = index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : '';

            return `
                <div class="flex justify-between items-center bg-gray-700 rounded-lg p-3 ${isMe ? 'ring-2 ring-yellow-400' : ''}">
                    <div class="flex items-center gap-2">
                        <span class="text-xl">${medal}</span>
                        <span class="font-semibold ${isMe ? 'text-yellow-400' : ''}">${player.name}</span>
                        ${isMe ? '<span class="text-xs text-gray-400">(Tú)</span>' : ''}
                    </div>
                    <span class="text-xl font-bold text-yellow-400">${player.score}</span>
                </div>
            `;
        }).join('');
    }

    /**
     * Handle game finished
     */
    handleGameFinished(data) {
        // Show final scores
        const finalScoresContainer = document.getElementById('final-scores');
        if (finalScoresContainer && data.final_scores) {
            const sortedScores = [...data.final_scores].sort((a, b) => b.score - a.score);

            finalScoresContainer.innerHTML = sortedScores.map((player, index) => {
                const isMe = player.player_id === this.playerId;
                const medal = index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : `${index + 1}.`;

                return `
                    <div class="flex justify-between items-center bg-gray-700 rounded-lg p-4 ${isMe ? 'ring-2 ring-yellow-400' : ''}">
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">${medal}</span>
                            <span class="text-xl font-semibold ${isMe ? 'text-yellow-400' : ''}">${player.name}</span>
                            ${isMe ? '<span class="text-sm text-gray-400">(Tú)</span>' : ''}
                        </div>
                        <span class="text-2xl font-bold text-yellow-400">${player.score} pts</span>
                    </div>
                `;
            }).join('');
        }

        // Show game finished modal
        this.showElement('game-finished');
    }

    /**
     * Override: Get countdown element for round transitions
     *
     * Usado por BaseGameClient para mostrar countdown entre rondas
     * Retorna el elemento SIN proxy para que el countdown se muestre correctamente
     */
    getCountdownElement() {
        return document.getElementById('timer');
    }

    /**
     * Override: Get timer element
     *
     * Returns a Proxy that formats seconds as MM:SS instead of just seconds
     */
    getTimerElement() {
        const timerEl = document.getElementById('timer');
        if (!timerEl) return null;

        // Store original element
        const originalElement = timerEl;

        // Create proxy that intercepts textContent writes
        return new Proxy(originalElement, {
            set(target, prop, value) {
                if (prop === 'textContent') {
                    // Format seconds as MM:SS
                    const seconds = parseInt(value) || 0;
                    const minutes = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    target.textContent = `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
                    return true;
                }
                target[prop] = value;
                return true;
            },
            get(target, prop) {
                // Forward classList, classList.add, classList.remove, etc.
                return target[prop];
            }
        });
    }

    /**
     * Restore game state after refresh (F5)
     */
    restoreGameState(gameState) {
        // Extract PlayerManager data to get role
        const playerSystem = gameState.player_system?.players || {};
        this.currentRole = playerSystem[this.playerId]?.round_role || null;
        const currentStatement = gameState.current_statement;
        this.currentStatement = typeof currentStatement === 'object' ? currentStatement?.text : currentStatement;

        // Update round info
        if (gameState.round_number) {
            this.updateElement('current-round', gameState.round_number);
        }
        if (gameState.total_rounds) {
            this.updateElement('total-rounds', gameState.total_rounds);
        }

        // Restore scores
        if (gameState.scores) {
            this.updateScoreboard(gameState.scores);
        }

        // Restore phase-specific state
        if (gameState.phase === 'playing') {
            // Game is in progress
            const subPhase = gameState.current_phase;

            if (subPhase === 'preparation' && this.currentRole === 'orador') {
                // Orador needs to know if statement is true/false
                // This info might be in gameState if backend includes it for refresh
                if (gameState.current_statement) {
                    const stmt = gameState.current_statement;
                    this.currentStatement = typeof stmt === 'object' ? stmt.text : stmt;
                    // Note: is_true is filtered in broadcasts, so orador needs to get it separately
                    // or backend must include it in unfiltered state for refresh
                }
            }

            if (subPhase === 'voting') {
                // Check if already voted by looking at player_system locks
                const myPlayerData = playerSystem[this.playerId];
                if (myPlayerData?.locked === true) {
                    this.hasVoted = true;
                    // Get vote value from action metadata
                    this.votedValue = myPlayerData.action?.vote ?? null;

                    console.log('[Mentiroso] Restored vote state on reconnect:', {
                        hasVoted: this.hasVoted,
                        votedValue: this.votedValue,
                        playerData: myPlayerData
                    });
                }
            }

            // Update orador info
            if (gameState.orador) {
                this.updateElement('orador-name', gameState.orador.name);
            }

            // Update statement displays
            if (this.currentStatement) {
                this.updateElement('statement-display', this.currentStatement);
                this.updateElement('orador-statement', this.currentStatement);
                this.updateElement('voting-statement', this.currentStatement);
            }

            // Update phase UI
            this.updatePhase(subPhase);

            // IMPORTANTE: Iniciar timer si hay PhaseManager activo
            // Esto maneja el caso donde el jugador entra JUSTO cuando empieza la partida
            // y se pierde el PhaseChangedEvent inicial
            if (gameState.phase_manager && subPhase) {
                console.log('🔄 [Mentiroso] Restoring timer for phase:', subPhase);

                // Obtener timing del PhaseManager
                const phaseManager = gameState.phase_manager;
                const phases = phaseManager.phases || [];
                const currentPhaseIndex = phaseManager.current_turn_index || 0;
                const currentPhaseConfig = phases[currentPhaseIndex];

                if (currentPhaseConfig && currentPhaseConfig.duration) {
                    const timerElement = this.getTimerElement();

                    if (timerElement) {
                        // Calcular tiempo restante desde server_time
                        const serverTime = Math.floor(Date.now() / 1000);
                        const durationMs = currentPhaseConfig.duration * 1000;

                        console.log('⏰ [Mentiroso] Starting restored timer:', {
                            phase: currentPhaseConfig.name,
                            duration: currentPhaseConfig.duration,
                            durationMs
                        });

                        this.timing.startServerSyncedCountdown(
                            serverTime,
                            durationMs,
                            timerElement,
                            () => this.onTimerExpired(subPhase),
                            `${subPhase}_timer`
                        );
                    }
                }
            }

        } else if (gameState.phase === 'finished') {
            // Game is finished
            this.handleGameFinished({
                final_scores: gameState.final_scores || gameState.scores
            });
        }
    }

    /**
     * Handle players unlocked event
     */
    handlePlayersUnlocked(data) {
        // Reset vote state for new round
        this.hasVoted = false;
        this.votedValue = null;
        this.statementIsTrue = null;
    }

    /**
     * Utility: Update element text content
     */
    updateElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    /**
     * Utility: Show element
     */
    showElement(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.remove('hidden');
        }
    }

    /**
     * Utility: Hide element
     */
    hideElement(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.add('hidden');
        }
    }
}

// Export para que esté disponible globalmente
window.MentirosoGameClient = MentirosoGameClient;
