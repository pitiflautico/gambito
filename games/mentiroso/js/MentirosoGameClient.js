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
     * Override: Configurar listener para el canal privado del player
     * Este método se llama automáticamente desde BaseGameClient.setupPrivateChannels()
     */
    onPrivatePlayerChannelReady(channel) {
        console.log(`[Mentiroso] Private player channel ready: player.${this.playerId}`);

        channel.listen('.statement.revealed', (event) => {
            console.log('[Mentiroso] ✅ StatementRevealed received on private channel:', event);
            this.handleStatementRevealed(event);
        });
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
        
        // Convert players object to array if needed (playersConfig can be object or array)
        let players = [];
        if (Array.isArray(playersConfig)) {
            players = playersConfig;
        } else if (playersConfig && typeof playersConfig === 'object') {
            // Convert object {playerId: {id, name, ...}} to array
            players = Object.values(playersConfig);
        }
        
        const playerSystem = game_state?.player_system?.players || {};

        // Get my role from PlayerManager data
        this.currentRole = playerSystem[this.playerId]?.round_role || null;

        // DEBUG: Log role assignment
        console.log('[Mentiroso] Role assignment debug', {
            playerId: this.playerId,
            playerSystem: playerSystem,
            myPlayerData: playerSystem[this.playerId],
            currentRole: this.currentRole,
        });

        // Update role indicator in UI
        this.updateRoleIndicator();

        // Get statement text - handle both object and string formats
        // current_statement puede ser: null, string, o {text: string, is_true: bool}
        if (!currentStatement) {
            this.currentStatement = '';
        } else if (typeof currentStatement === 'object' && currentStatement.text) {
            this.currentStatement = currentStatement.text;
        } else if (typeof currentStatement === 'string') {
            this.currentStatement = currentStatement;
        } else {
            this.currentStatement = '';
        }

        // DEBUG: Log statement extraction
        console.log('[Mentiroso] Statement extraction debug', {
            currentStatement: currentStatement,
            currentStatementType: typeof currentStatement,
            extractedText: this.currentStatement,
            currentPhase: currentPhase,
            hasStatement: !!this.currentStatement,
            isOrador: this.currentRole === 'orador',
        });

        // Update round info
        this.updateElement('current-round', current_round);
        this.updateElement('total-rounds', total_rounds);

        // Find and display orador name
        const oradorRotation = game_state?.orador_rotation || [];
        const oradorIndex = game_state?.current_orador_index || 0;
        const oradorId = oradorRotation[oradorIndex];

        console.log('[Mentiroso] Orador debug', {
            oradorRotation,
            oradorIndex,
            oradorId,
            playersLength: players.length,
            players,
            playerSystem,
            // Try to get player name from playerSystem if players array is empty
            oradorPlayerFromSystem: playerSystem[oradorId]
        });

        if (players.length > 0) {
            const oradorPlayer = players.find(p => p.id === oradorId);
            if (oradorPlayer) {
                console.log('[Mentiroso] Found orador in players array:', oradorPlayer);
                this.updateElement('orador-name', oradorPlayer.name);
            } else {
                console.warn('[Mentiroso] Orador ID not found in players array', { oradorId, players });
            }
        } else {
            // Fallback: try to get player name from playerSystem or match players
            console.warn('[Mentiroso] Players array is empty, trying fallback');
            const oradorPlayerData = playerSystem[oradorId];
            if (oradorPlayerData && oradorPlayerData.name) {
                console.log('[Mentiroso] Found orador in playerSystem:', oradorPlayerData);
                this.updateElement('orador-name', oradorPlayerData.name);
            } else {
                console.error('[Mentiroso] Could not find orador name', { oradorId, playerSystem });
            }
        }

        // Show statement (without revealing truth)
        console.log('[Mentiroso] Updating statement displays', {
            currentStatement: this.currentStatement,
            hasStatement: !!this.currentStatement,
            isOrador: this.currentRole === 'orador'
        });
        
        this.updateElement('statement-display', this.currentStatement);
        
        // Si soy el orador y tengo el statement, mostrarlo inmediatamente
        // (el StatementRevealedEvent puede llegar después)
        if (this.currentRole === 'orador') {
            if (this.currentStatement) {
                console.log('[Mentiroso] I am orador, updating orador-statement immediately', this.currentStatement);
                this.updateElement('orador-statement', this.currentStatement);
                // Mostrar la fase de preparación
                const prepPhase = document.getElementById('preparation-phase');
                if (prepPhase) {
                    prepPhase.classList.remove('hidden');
                }
            } else {
                console.warn('[Mentiroso] I am orador but statement is empty, waiting for StatementRevealedEvent');
            }
        }
        
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
        console.log('[Mentiroso] StatementRevealed event received', data);
        
        this.statementIsTrue = data.is_true;
        // Handle both object and string formats
        this.currentStatement = typeof data.statement === 'object' ? data.statement.text : data.statement;

        console.log('[Mentiroso] Updating orador statement display', {
            currentStatement: this.currentStatement,
            isTrue: this.statementIsTrue
        });

        // Update statement display for orador
        this.updateElement('orador-statement', this.currentStatement);

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
        } else {
            console.warn('[Mentiroso] truth-indicator element not found');
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
        let phase = additional_data?.phase || new_phase;
        const game_state = additional_data?.game_state;
        const timing = additional_data?.timing;

        // Si game_state está disponible, actualizar this.gameState y usar current_phase si phase es genérico
        if (game_state) {
            this.gameState = game_state;
            // Si phase es 'main' o 'playing', usar current_phase del game_state
            if (phase === 'main' || phase === 'playing') {
                phase = game_state?.current_phase || phase;
            }
        }

        if (!phase) {
            console.error('[Mentiroso] ❌ No phase in PhaseChangedEvent - cannot proceed');
            return;
        }

        console.log('[Mentiroso] handlePhaseChanged', { 
            new_phase, 
            phase, 
            current_phase_from_state: game_state?.current_phase,
            has_timing: !!timing
        });

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

        // Update role if game_state has player_system data
        if (game_state?.player_system?.players) {
            const playerSystem = game_state.player_system.players;
            this.currentRole = playerSystem[this.playerId]?.round_role || null;
            this.updateRoleIndicator();
        }

        // Update phase UI
        this.updatePhase(phase);

        // NOTA: TimingModule.autoProcessEvent() ya maneja automáticamente el timer
        // cuando el evento tiene timer_id, server_time, duration en el nivel raíz.
        // No necesitamos iniciar el timer manualmente aquí.
        console.log('[Mentiroso] PhaseChanged processed, TimingModule will handle timer automatically');
    }

    /**
     * Update role indicator in the UI
     */
    updateRoleIndicator() {
        const roleIndicator = document.getElementById('role-indicator');
        const currentRoleSpan = document.getElementById('current-role');
        
        if (roleIndicator && currentRoleSpan) {
            if (this.currentRole) {
                const roleNames = {
                    'orador': '🎯 Orador',
                    'votante': '👂 Votante'
                };
                currentRoleSpan.textContent = roleNames[this.currentRole] || this.currentRole;
                roleIndicator.classList.remove('hidden');
            } else {
                roleIndicator.classList.add('hidden');
            }
        }
    }

    /**
     * Handle round ended event
     */
    handleRoundEnded(event) {
        console.log('🏁 [FRONTEND] RoundEndedEvent RECIBIDO - Round:', event.round_number);

        // Usar método genérico de BaseGameClient para mostrar popup
        super.handleRoundEnded(event);

        // Personalizar popup con datos específicos de Mentiroso
        this.customizeRoundEndPopup(event);

        // Ocultar todas las fases de juego
        this.hideElement('waiting-phase');
        this.hideElement('preparation-phase');
        this.hideElement('persuasion-phase');
        this.hideElement('voting-phase');
    }

    /**
     * Personalizar el popup genérico de fin de ronda con datos específicos de Mentiroso
     */
    customizeRoundEndPopup(event) {
        const results = event.results || {};
        const statement = results.statement || {};
        
        // Agregar información de la frase y resultados al popup
        const popup = document.getElementById('round-end-popup');
        if (!popup) return;

        // Crear/seleccionar contenedor para información específica de Mentiroso
        let mentirosoInfo = document.getElementById('mentiroso-round-info');
        if (!mentirosoInfo) {
            mentirosoInfo = document.createElement('div');
            mentirosoInfo.id = 'mentiroso-round-info';
            mentirosoInfo.className = 'mt-4 p-4 bg-gray-700 rounded-lg';
            
            const scoresList = document.getElementById('popup-scores-list');
            if (scoresList && scoresList.parentNode) {
                scoresList.parentNode.insertBefore(mentirosoInfo, scoresList.nextSibling);
            }
        }

        // Mostrar frase y resultado
        const isTrue = statement.is_true ?? false;
        mentirosoInfo.innerHTML = `
            <div class="text-center mb-3">
                <p class="text-sm text-gray-400 mb-2">La frase era:</p>
                <p class="text-lg font-bold text-yellow-400 mb-2">"${statement.text || ''}"</p>
                <p class="text-xl font-bold ${isTrue ? 'text-green-400' : 'text-red-400'}">
                    ${isTrue ? '✓ VERDADERO' : '✗ FALSO'}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4 mt-4">
                <div class="bg-green-600/20 rounded-lg p-3 text-center">
                    <p class="text-xs text-gray-400 mb-1">Votaron VERDADERO</p>
                    <p class="text-2xl font-bold text-green-400">${results.correct_votes || 0}</p>
                </div>
                <div class="bg-red-600/20 rounded-lg p-3 text-center">
                    <p class="text-xs text-gray-400 mb-1">Votaron FALSO</p>
                    <p class="text-2xl font-bold text-red-400">${results.incorrect_votes || 0}</p>
                </div>
            </div>
        `;
    }

    // NOTA: updateResultsDisplay() eliminado - ahora usamos popup genérico con customizeRoundEndPopup()

    /**
     * Update phase UI
     */
    updatePhase(phase) {
        console.log('[Mentiroso] updatePhase called', { phase, currentRole: this.currentRole, gameStatePhase: this.gameState?.current_phase });
        
        // Si phase es 'main' o 'playing', usar current_phase de game_state ANTES de procesar
        if (phase === 'main' || phase === 'playing') {
            const actualPhase = this.gameState?.current_phase || 'preparation';
            console.log('[Mentiroso] Phase is main/playing, using actualPhase:', actualPhase);
            phase = actualPhase;
        }

        // Mostrar el contenedor del timer cuando hay una fase activa
        const timerContainer = document.getElementById('timer-container');
        if (timerContainer && phase && phase !== 'waiting' && phase !== 'finished') {
            timerContainer.classList.remove('hidden');
        }
        
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
                console.log('[Mentiroso] Preparation phase - currentRole:', this.currentRole);
                if (this.currentRole === 'orador') {
                    console.log('[Mentiroso] Showing preparation-phase for orador');
                    this.showElement('preparation-phase');
                } else {
                    console.log('[Mentiroso] Showing waiting-phase for non-orador');
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

    // NOTA: updateResultsDisplay() eliminado
    // Ahora usamos popup genérico con customizeRoundEndPopup() para mostrar resultados

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
    handleGameFinished(event) {
        console.log('🏆 [Mentiroso] Game finished:', event);
        
        // Usar método genérico de BaseGameClient para mostrar popup
        super.handleGameFinished(event);
    }

    /**
     * Override: Get countdown element for round transitions
     *
     * Usado por BaseGameClient para mostrar countdown entre rondas
     * Retorna el elemento SIN proxy para que el countdown se muestre correctamente
     */
    getCountdownElement() {
        // El countdown se mostrará en el popup de fin de ronda (genérico)
        return document.getElementById('popup-timer');
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
        // Store game state
        this.gameState = gameState;
        
        // Extract PlayerManager data to get role
        const playerSystem = gameState.player_system?.players || {};
        this.currentRole = playerSystem[this.playerId]?.round_role || null;
        
        // Update role indicator
        this.updateRoleIndicator();
        
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

            // NOTA: NO restauramos el timer manualmente aquí.
            // El backend's BaseGameEngine::onPlayerReconnected() ya reinicia la ronda
            // y re-emite RoundStartedEvent → onRoundStarted() → PhaseChangedEvent con timer data.
            // TimingModule.autoProcessEvent() manejará automáticamente el timer desde PhaseChangedEvent.
            // Esto es consistente con Pictionary y otros juegos que usan PhaseChangedEvent.

        } else if (gameState.phase === 'finished') {
            // Game is finished
            // Formatear evento para super.handleGameFinished()
            const finalScores = gameState.final_scores || gameState.scores || {};
            const scoresArray = Object.entries(finalScores).map(([playerId, score]) => {
                const player = this.getPlayer(parseInt(playerId));
                return {
                    player_id: parseInt(playerId),
                    name: player ? player.name : `Player ${playerId}`,
                    score: score
                };
            });

            this.handleGameFinished({
                winner: gameState.winner || null,
                ranking: scoresArray.sort((a, b) => b.score - a.score),
                scores: finalScores,
                game_state: gameState
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
