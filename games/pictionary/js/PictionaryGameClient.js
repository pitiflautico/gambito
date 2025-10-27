// BaseGameClient ya está disponible globalmente a través de resources/js/app.js
const { BaseGameClient } = window;

/**
 * Pictionary Game Client
 *
 * Maneja la lógica específica del cliente para Pictionary:
 * - Dibujo en canvas (para drawer)
 * - Adivinanzas (para guessers)
 * - Sincronización de strokes en tiempo real
 * - Roles (drawer/guesser)
 */
export class PictionaryGameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        // Canvas state
        this.canvas = null;
        this.ctx = null;
        this.isDrawing = false;
        this.currentColor = '#000000';
        this.currentBrushSize = 4;
        this.isDrawer = false;
        this.currentStroke = []; // Puntos del stroke actual
        this.lastSentIndex = 0; // Índice del último punto enviado
        this.streamThrottle = null; // Throttle para streaming de strokes
        this.lastReceivedPoint = null; // Último punto recibido para continuar strokes

        // Player state
        this.currentWord = null;
        this.isLocked = false;

        // TODO: Inicializar canvas
        this.initializeCanvas();
        this.initializeControls();

        // NOTA: No renderizamos la lista aquí porque this.players está vacío
        // Se renderiza después de cargar el estado inicial en game.blade.php
    }

    /**
     * Override: Configurar EventManager con handlers específicos de Pictionary
     */
    setupEventManager() {
        // Registrar handlers personalizados de Pictionary automáticamente
        const customHandlers = {
            handleDrawStroke: (event) => this.handleDrawStroke(event),
            handleWordRevealed: (event) => this.handleWordRevealed(event),
            handleCanvasCleared: (event) => this.handleCanvasCleared(event),
            handleAnswerClaimed: (event) => this.handleAnswerClaimed(event),
            handleAnswerValidated: (event) => this.handleAnswerValidated(event),
            handlePlayersUnlocked: (event) => this.handlePlayersUnlocked(event),
            handleRoundEnded: (event) => this.handleRoundEnded(event),
            handleGameFinished: (event) => this.handleGameFinished(event)
        };

        // Llamar al método padre con los handlers personalizados
        super.setupEventManager(customHandlers);

        // IMPORTANTE: WordRevealedEvent se envía por un canal privado del usuario
        // Necesitamos escuchar también el canal privado además del canal de la sala
        this.setupPrivateUserChannel();
    }

    /**
     * Configurar listener para el canal privado del usuario
     * Este canal recibe eventos como WordRevealedEvent que solo deben verlos ciertos jugadores
     */
    setupPrivateUserChannel() {
        if (!window.Echo) {
            console.error('[Pictionary] window.Echo is not available');
            return;
        }

        // Escuchar canal privado del usuario actual
        const userId = this.userId;
        if (!userId) {
            console.warn('[Pictionary] User ID not available, cannot listen to private channel');
            console.warn('[Pictionary] Config:', { userId: this.userId, playerId: this.playerId });
            return;
        }

        console.log(`[Pictionary] Setting up private channel: user.${userId}`);

        const channel = window.Echo.private(`user.${userId}`);

        channel.listen('.pictionary.word-revealed', (event) => {
            console.log('[Pictionary] ✅ WordRevealed received on private channel:', event);
            this.handleWordRevealed(event);
        });

        console.log(`[Pictionary] ✅ Subscribed to private channel: user.${userId}`);
    }

    /**
     * Inicializar canvas para dibujo
     */
    initializeCanvas() {
        this.canvas = document.getElementById('drawing-canvas');
        if (!this.canvas) {
            console.warn('[Pictionary] Canvas not found');
            return;
        }

        this.ctx = this.canvas.getContext('2d');

        // Canvas setup
        this.ctx.fillStyle = 'white';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';

        // Event listeners para dibujo (mouse)
        this.canvas.addEventListener('mousedown', this.startDrawing.bind(this));
        this.canvas.addEventListener('mousemove', this.draw.bind(this));
        this.canvas.addEventListener('mouseup', this.stopDrawing.bind(this));
        this.canvas.addEventListener('mouseleave', this.stopDrawing.bind(this));

        // Event listeners para dibujo (touch - móvil)
        this.canvas.addEventListener('touchstart', this.startDrawing.bind(this));
        this.canvas.addEventListener('touchmove', this.draw.bind(this));
        this.canvas.addEventListener('touchend', this.stopDrawing.bind(this));
        this.canvas.addEventListener('touchcancel', this.stopDrawing.bind(this));

        console.log('[Pictionary] Canvas initialized');
    }

    /**
     * Inicializar controles (color picker, brush size, clear, guess)
     */
    initializeControls() {
        // Color buttons
        document.querySelectorAll('.color-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.currentColor = btn.dataset.color;
                this.updateActiveColorButton(btn);
            });
        });

        // Brush size buttons
        document.querySelectorAll('.brush-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.currentBrushSize = parseInt(btn.dataset.size);
                this.updateActiveBrushButton(btn);
            });
        });

        // Clear canvas button
        const clearBtn = document.getElementById('clear-canvas-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clearCanvas();
            });
        }

        // Botón "¡Lo sé!"
        const claimBtn = document.getElementById('claim-answer-btn');
        if (claimBtn) {
            claimBtn.addEventListener('click', () => {
                console.log('[Pictionary] Claim button clicked!');
                this.claimAnswer();
            });
            console.log('[Pictionary] Claim button event listener attached');
        } else {
            console.warn('[Pictionary] Claim button not found in DOM');
        }

        console.log('[Pictionary] Controls initialized');
    }

    /**
     * Handler: Ronda iniciada
     */
    handleRoundStarted(event) {
        console.log('[Pictionary] Round started:', event);

        // IMPORTANTE: Llamar al handler base primero para procesar timing
        super.handleRoundStarted(event);

        const { current_round, total_rounds, game_state } = event;

        // Obtener drawer_id y word desde game_state
        const drawerRotation = game_state?.drawer_rotation || [];
        const drawerIndex = game_state?.current_drawer_index || 0;
        const drawer_id = drawerRotation[drawerIndex];
        const currentWord = game_state?.current_word;
        const word = currentWord?.word;

        console.log('[Pictionary] Round data:', { current_round, drawer_id, word, isMe: drawer_id === this.playerId });

        // Determinar si soy el drawer
        this.isDrawer = (drawer_id === this.playerId);

        // Mostrar estado de juego, ocultar loading y resultados
        this.hideElement('loading-state');
        this.hideElement('round-results-state');
        this.showElement('playing-state');

        // Actualizar info de ronda
        this.updateRoundInfo(current_round, total_rounds);

        // Limpiar canvas
        this.clearCanvas();

        // Resetear locks
        this.isLocked = false;
        this.hideElement('locked-overlay');

        if (this.isDrawer) {
            // SOY EL DRAWER
            // NOTA: La palabra NO viene en este evento (RoundStartedEvent).
            // La palabra llegará por WordRevealedEvent en el canal privado del usuario.
            // Aquí solo configuramos la UI para el drawer.

            // Mostrar herramientas de canvas
            this.showElement('canvas-tools');

            // Mostrar panel de validación
            this.showElement('validation-panel');

            // Ocultar sección de guess
            this.hideElement('guess-section');

            console.log('[Pictionary] You are the DRAWER! Waiting for word via WordRevealedEvent...');
        } else {
            // SOY GUESSER

            // Ocultar palabra
            this.hideElement('word-display');

            // Ocultar herramientas de canvas
            this.hideElement('canvas-tools');

            // Ocultar panel de validación
            this.hideElement('validation-panel');

            // Mostrar sección de guess
            this.showElement('guess-section');

            // Resetear estados de la UI del guesser
            this.showElement('claim-section');
            this.hideElement('waiting-validation');
            this.hideElement('correct-overlay');
            this.hideElement('incorrect-overlay');

            console.log('[Pictionary] You are a GUESSER!');
        }

        // Actualizar nombre del drawer
        const drawerName = this.getPlayerName(drawer_id);
        const drawerNameEl = document.getElementById('drawer-name');
        if (drawerNameEl) {
            drawerNameEl.textContent = drawerName;
        }
    }

    /**
     * Helper: Actualizar info de ronda
     */
    updateRoundInfo(current, total) {
        // Buscar elemento de round info
        const roundCurrent = document.querySelector('[data-round-current]');
        const roundTotal = document.querySelector('[data-round-total]');

        if (roundCurrent) roundCurrent.textContent = current;
        if (roundTotal) roundTotal.textContent = total;
    }

    /**
     * Helper: Obtener nombre de jugador por ID
     */
    getPlayerName(playerId) {
        const player = this.players.find(p => p.id === playerId);
        return player ? player.name : `Jugador ${playerId}`;
    }

    /**
     * Handler: Stroke dibujado por otro jugador
     */
    handleDrawStroke(event) {
        console.log('[Pictionary] ✅ Draw stroke received from WebSocket:', event);

        const { stroke, player_id } = event;

        console.log('[Pictionary] Stroke details:', {
            from_player: player_id,
            points: stroke?.points?.length,
            color: stroke?.color,
            size: stroke?.size
        });

        // IMPORTANTE: Ignorar eventos de nuestros propios strokes
        // El drawer ya dibuja localmente, no necesita renderizar sus propios eventos
        if (player_id === this.playerId) {
            console.log('[Pictionary] Ignoring own stroke event (already drawn locally)');
            return;
        }

        // Renderizar stroke en el canvas (solo de otros jugadores)
        if (stroke) {
            this.renderStroke(stroke);
            console.log('[Pictionary] Stroke rendered on canvas');
        }
    }

    /**
     * Handler: Canvas limpiado por el drawer
     */
    handleCanvasCleared(event) {
        console.log('[Pictionary] Canvas cleared by drawer');

        // Limpiar canvas localmente
        if (this.ctx) {
            this.ctx.fillStyle = 'white';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        }
    }

    /**
     * Handler: Jugadores desbloqueados (nueva ronda)
     */
    handlePlayersUnlocked(event) {
        console.log('[Pictionary] Players unlocked - new round starting');

        // Desbloquear al jugador localmente
        this.isLocked = false;

        // Resetear UI si soy guesser
        if (!this.isDrawer) {
            this.hideElement('waiting-validation');
            this.hideElement('correct-overlay');
            this.hideElement('incorrect-overlay');
            this.showElement('claim-section');
        }
    }

    /**
     * Handler: Palabra revelada al drawer
     *
     * Este evento solo lo recibe el drawer actual con la palabra que debe dibujar.
     */
    handleWordRevealed(event) {
        console.log('[Pictionary] Word revealed to drawer:', event);

        const { word, difficulty, round_number } = event;

        // Guardar palabra actual
        this.currentWord = word;

        // Mostrar palabra en la UI
        const wordDisplay = document.getElementById('word-display');
        const wordText = document.getElementById('word-text');

        if (wordDisplay && wordText) {
            wordText.textContent = word;
            wordDisplay.classList.remove('hidden');
        }

        // Mostrar herramientas de canvas (solo drawer)
        const canvasTools = document.getElementById('canvas-tools');
        if (canvasTools) {
            canvasTools.classList.remove('hidden');
        }

        // Ocultar sección de guess (drawer no adivina)
        const guessSection = document.getElementById('guess-section');
        if (guessSection) {
            guessSection.classList.add('hidden');
        }
    }

    /**
     * Handler: Jugador reclama que sabe la respuesta (AnswerClaimedEvent)
     */
    handleAnswerClaimed(event) {
        console.log('[Pictionary] Answer claimed:', event);

        const { player_id, player_name } = event;

        // Si soy el drawer, mostrar notificación con botones de validación
        if (this.isDrawer) {
            this.showClaimNotification(player_id, player_name);
        }

        // Si soy yo quien reclamó, mostrar estado de espera
        if (player_id === this.playerId) {
            this.hideElement('claim-section');
            this.showElement('waiting-validation');
        }
    }

    /**
     * Handler: Drawer validó una respuesta (AnswerValidatedEvent)
     */
    handleAnswerValidated(event) {
        console.log('[Pictionary] Answer validated:', event);

        const { player_id, is_correct, points } = event;

        // Si soy el jugador validado
        if (player_id === this.playerId) {
            this.hideElement('waiting-validation');

            if (is_correct) {
                // Correcto
                this.showElement('correct-overlay');
                const pointsEl = document.getElementById('points-earned');
                if (pointsEl) {
                    pointsEl.textContent = `+${points} puntos`;
                }
                this.isLocked = true;
            } else {
                // Incorrecto
                this.showElement('incorrect-overlay');
            }
        }

        // Si soy el drawer, remover la notificación
        if (this.isDrawer) {
            this.removeClaimNotification(player_id);
        }
    }

    /**
     * Reclamar que sé la respuesta
     */
    async claimAnswer() {
        console.log('[Pictionary] claimAnswer() called', {
            isLocked: this.isLocked,
            playerId: this.playerId,
            isDrawer: this.isDrawer
        });

        if (this.isLocked) {
            console.warn('[Pictionary] Cannot claim - player is locked');
            return;
        }

        try {
            console.log('[Pictionary] Sending claim_answer action to server...');
            const response = await this.sendGameAction('claim_answer', {});

            console.log('[Pictionary] Claim response:', response);

            if (response.success) {
                console.log('[Pictionary] Claim sent successfully');
                // La UI se actualiza cuando llega el evento AnswerClaimedEvent
            } else {
                console.error('[Pictionary] Claim failed:', response.message);
            }
        } catch (error) {
            console.error('[Pictionary] Error claiming answer:', error);
        }
    }

    /**
     * Validar claim de un jugador (solo drawer)
     */
    async validateClaim(playerId, isCorrect) {
        try {
            const response = await this.sendGameAction('validate_claim', {
                player_id: playerId,
                is_correct: isCorrect
            });

            if (response.success) {
                console.log('[Pictionary] Validation sent successfully');
                // La UI se actualiza cuando llega el evento AnswerValidatedEvent
            } else {
                console.error('[Pictionary] Validation failed:', response.message);
            }
        } catch (error) {
            console.error('[Pictionary] Error validating claim:', error);
        }
    }

    /**
     * Mostrar notificación de claim en el panel del drawer
     */
    showClaimNotification(playerId, playerName) {
        const claimsList = document.getElementById('claims-list');
        if (!claimsList) return;

        // Remover mensaje de "Esperando..."
        const emptyMsg = claimsList.querySelector('p.text-gray-500');
        if (emptyMsg) emptyMsg.remove();

        // Crear notificación
        const claimDiv = document.createElement('div');
        claimDiv.id = `claim-${playerId}`;
        claimDiv.className = 'p-4 bg-white border-2 border-yellow-400 rounded-lg';
        claimDiv.innerHTML = `
            <p class="font-bold text-gray-900 mb-3">
                🙋 <span class="text-yellow-600">${playerName}</span> dice que lo sabe
            </p>
            <div class="flex gap-2">
                <button
                    class="validate-yes flex-1 px-4 py-2 bg-green-600 text-white font-bold rounded hover:bg-green-700"
                    data-player-id="${playerId}"
                >
                    ✅ Correcto
                </button>
                <button
                    class="validate-no flex-1 px-4 py-2 bg-red-600 text-white font-bold rounded hover:bg-red-700"
                    data-player-id="${playerId}"
                >
                    ❌ Incorrecto
                </button>
            </div>
        `;

        // Agregar event listeners a los botones
        const yesBtn = claimDiv.querySelector('.validate-yes');
        const noBtn = claimDiv.querySelector('.validate-no');

        yesBtn.addEventListener('click', () => {
            this.validateClaim(playerId, true);
        });

        noBtn.addEventListener('click', () => {
            this.validateClaim(playerId, false);
        });

        claimsList.appendChild(claimDiv);
    }

    /**
     * Remover notificación de claim del panel del drawer
     */
    removeClaimNotification(playerId) {
        const claimDiv = document.getElementById(`claim-${playerId}`);
        if (claimDiv) {
            claimDiv.remove();
        }

        // Si no quedan claims, mostrar mensaje de "Esperando..."
        const claimsList = document.getElementById('claims-list');
        if (claimsList && claimsList.children.length === 0) {
            claimsList.innerHTML = `
                <p class="text-sm text-gray-500 text-center py-4">
                    Esperando a que alguien adivine...
                </p>
            `;
        }
    }

    /**
     * Empezar a dibujar
     */
    startDrawing(e) {
        if (!this.isDrawer || this.isLocked) return;

        // Prevenir scroll en móvil
        e.preventDefault();

        this.isDrawing = true;

        const coords = this.getMousePos(e);
        this.ctx.beginPath();
        this.ctx.moveTo(coords.x, coords.y);

        // Iniciar nuevo stroke
        this.currentStroke = [coords];
    }

    /**
     * Dibujar mientras se mueve el mouse
     */
    draw(e) {
        if (!this.isDrawing || !this.isDrawer) return;

        // Prevenir scroll en móvil
        e.preventDefault();

        const coords = this.getMousePos(e);

        this.ctx.strokeStyle = this.currentColor;
        this.ctx.lineWidth = this.currentBrushSize;

        this.ctx.lineTo(coords.x, coords.y);
        this.ctx.stroke();

        // Acumular punto en el stroke actual
        this.currentStroke.push(coords);

        // Streaming en tiempo real: enviar stroke parcial cada 50ms mientras se dibuja
        if (!this.streamThrottle) {
            this.streamThrottle = setTimeout(() => {
                this.sendPartialStroke();
                this.streamThrottle = null;
            }, 50); // 50ms = 20 actualizaciones por segundo
        }
    }

    /**
     * Dejar de dibujar
     */
    stopDrawing() {
        if (!this.isDrawing) return;

        this.isDrawing = false;
        this.ctx.closePath();

        // Cancelar throttle pendiente
        if (this.streamThrottle) {
            clearTimeout(this.streamThrottle);
            this.streamThrottle = null;
        }

        // Enviar stroke final con cualquier punto restante
        if (this.currentStroke.length > this.lastSentIndex) {
            this.sendPartialStroke(true);
        }

        // Limpiar stroke actual
        this.currentStroke = [];
        this.lastSentIndex = 0;
    }

    /**
     * Obtener posición del mouse/touch en el canvas
     */
    getMousePos(e) {
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;

        const clientX = e.clientX || e.touches?.[0]?.clientX || 0;
        const clientY = e.clientY || e.touches?.[0]?.clientY || 0;

        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    /**
     * Enviar stroke parcial al servidor (solo puntos nuevos)
     *
     * @param {boolean} isFinal - Si es el stroke final (cuando se suelta el mouse)
     */
    async sendPartialStroke(isFinal = false) {
        if (!this.isDrawer || this.currentStroke.length === 0) return;

        // Solo enviar si hay puntos nuevos
        const newPoints = this.currentStroke.slice(this.lastSentIndex);
        if (newPoints.length === 0) return;

        const strokeData = {
            points: newPoints,
            color: this.currentColor,
            size: this.currentBrushSize,
            is_continuation: this.lastSentIndex > 0, // Indica si es continuación de un stroke previo
            is_final: isFinal // Indica si es el último paquete del stroke
        };

        console.log('[Pictionary] Sending partial stroke:', {
            newPoints: newPoints.length,
            totalPoints: this.currentStroke.length,
            isContinuation: strokeData.is_continuation,
            isFinal: strokeData.is_final
        });

        // Actualizar índice antes de enviar (para evitar duplicados si hay errores)
        this.lastSentIndex = this.currentStroke.length;

        try {
            await this.sendGameAction('draw_stroke', { stroke: strokeData });
        } catch (error) {
            console.error('[Pictionary] Error sending partial stroke:', error);
        }
    }

    /**
     * Limpiar canvas
     */
    clearCanvas() {
        if (!this.ctx) return;

        this.ctx.fillStyle = 'white';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        // Si soy drawer, enviar clear al servidor
        if (this.isDrawer) {
            this.sendGameAction('clear_canvas', {}).catch(error => {
                console.error('[Pictionary] Error clearing canvas:', error);
            });
        }
    }

    /**
     * Renderizar stroke recibido del servidor
     */
    renderStroke(stroke) {
        if (!this.ctx || !stroke) return;

        const { points, color, size, is_continuation, is_final } = stroke;

        if (!points || points.length === 0) return;

        this.ctx.strokeStyle = color;
        this.ctx.lineWidth = size;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';

        // Si es una continuación y tenemos el último punto, continuar desde ahí
        if (is_continuation && this.lastReceivedPoint) {
            this.ctx.beginPath();
            this.ctx.moveTo(this.lastReceivedPoint.x, this.lastReceivedPoint.y);

            points.forEach((point) => {
                this.ctx.lineTo(point.x, point.y);
            });

            this.ctx.stroke();
            this.ctx.closePath();
        } else {
            // Stroke nuevo - empezar desde el principio
            this.ctx.beginPath();

            points.forEach((point, index) => {
                if (index === 0) {
                    this.ctx.moveTo(point.x, point.y);
                } else {
                    this.ctx.lineTo(point.x, point.y);
                }
            });

            this.ctx.stroke();
            this.ctx.closePath();
        }

        // Guardar último punto para continuaciones
        this.lastReceivedPoint = points[points.length - 1];

        // Si es el stroke final, limpiar el punto guardado
        if (is_final) {
            this.lastReceivedPoint = null;
        }
    }

    /**
     * Actualizar botón de color activo
     */
    updateActiveColorButton(activeBtn) {
        document.querySelectorAll('.color-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        activeBtn.classList.add('active');
    }

    /**
     * Actualizar botón de brush activo
     */
    updateActiveBrushButton(activeBtn) {
        document.querySelectorAll('.brush-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        activeBtn.classList.add('active');
    }

    /**
     * Agregar guess al feed
     */
    addToGuessesFeed(playerId, guess, isCorrect, points = 0) {
        // TODO: Implementar
        // const feed = document.getElementById('guesses-feed');
        // const playerName = this.getPlayerName(playerId);
        //
        // const guessElement = document.createElement('div');
        // guessElement.className = isCorrect
        //     ? 'p-2 bg-green-100 border border-green-300 rounded text-sm'
        //     : 'p-2 bg-gray-100 border border-gray-300 rounded text-sm';
        //
        // guessElement.innerHTML = `
        //     <strong>${playerName}</strong>: ${guess}
        //     ${isCorrect ? `<span class="text-green-600 font-bold">✅ +${points}pts</span>` : ''}
        // `;
        //
        // feed.prepend(guessElement);
    }

    /**
     * Handler: Ronda terminada - Mostrar palabra y resultados
     */
    handleRoundEnded(event) {
        // Llamar primero al handler base que actualiza scores y maneja timing
        super.handleRoundEnded(event);

        console.log('[Pictionary] Round ended:', event);

        const { round_number, results, scores, timing } = event;

        // Extraer palabra (puede venir como objeto {word, difficulty} o como string)
        const word = results?.word?.word || results?.word;

        // Obtener nombres de jugadores que adivinaron
        let guessersText = '❌ Nadie adivinó';
        if (results?.guessers && results.guessers.length > 0) {
            const names = results.guessers.map(g => this.getPlayerName(g.player_id));
            guessersText = `✅ ${names.join(', ')}`;
        }

        // Actualizar elementos de resultados
        const resultWord = document.getElementById('result-word');
        const resultGuessers = document.getElementById('result-guessers');

        if (resultWord && word) {
            resultWord.textContent = `"${word}"`;
        }
        if (resultGuessers) {
            resultGuessers.textContent = guessersText;
        }

        // Ocultar estado de juego, mostrar resultados
        this.hideElement('playing-state');
        this.hideElement('loading-state');
        this.showElement('round-results-state');

        // El TimingModule del BaseGameClient mostrará el countdown automáticamente
        // en el elemento que retorne getCountdownElement()
    }

    /**
     * Implementar getTimerElement para que el TimingModule sepa dónde mostrar el timer de ronda
     */
    getTimerElement() {
        // El timer de ronda se muestra durante el juego (playing-state)
        return document.getElementById('timer');
    }

    /**
     * Implementar getCountdownElement para que el TimingModule sepa dónde mostrar el countdown
     */
    getCountdownElement() {
        // El countdown se mostrará en el div de round-results-state
        return document.getElementById('round-countdown');
    }

    /**
     * Handler: Juego terminado - Mostrar pantalla de resultados finales
     */
    handleGameFinished(event) {
        console.log('[Pictionary] Game finished:', event);

        const { winner, ranking, scores } = event;

        // Ocultar estado de juego
        this.hideElement('playing-state');
        this.hideElement('loading-state');

        // Actualizar puntuaciones finales
        this.scores = scores;

        // Mostrar pantalla de resultados
        this.showElement('finished-state');

        // El componente <x-game.results-screen> se encargará de renderizar el podio
        // usando los datos que ya tiene en this.players y this.scores
        console.log('[Pictionary] Final ranking:', ranking);
        console.log('[Pictionary] Winner:', winner);
    }

    /**
     * Renderizar lista de jugadores con sus scores.
     */
    renderPlayersList() {
        const container = document.getElementById('players-scores-list');
        if (!container) return;

        container.innerHTML = '';

        this.players.forEach(player => {
            const score = this.scores[player.id] || 0;
            const playerDiv = document.createElement('div');
            playerDiv.className = 'flex justify-between items-center p-2 bg-gray-50 rounded';

            playerDiv.innerHTML = `
                <span class="text-sm font-medium text-gray-700">${player.name}</span>
                <span id="player-score-${player.id}" class="text-lg font-bold text-blue-600 transition-all duration-300">
                    ${score} pts
                </span>
            `;

            container.appendChild(playerDiv);
        });
    }

    /**
     * Override: Actualizar score de jugador y refrescar UI.
     */
    handlePlayerScoreUpdated(event) {
        // Llamar al handler base primero
        super.handlePlayerScoreUpdated(event);

        // Actualizar el texto del score (BaseGameClient ya agregó la animación)
        const scoreElement = document.getElementById(`player-score-${event.player_id}`);
        if (scoreElement) {
            scoreElement.textContent = `${event.new_score} pts`;
        }
    }
}

// Exportar para uso global
window.PictionaryGameClient = PictionaryGameClient;
