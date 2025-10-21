/**
 * Pictionary Canvas - Sistema de dibujo
 *
 * Maneja el canvas HTML5 para dibujar, herramientas de dibujo,
 * y eventos de mouse/touch.
 *
 * Integra WebSockets con Laravel Echo para sincronización en tiempo real
 */

class PictionaryCanvas {
    constructor() {
        this.canvas = document.getElementById('drawing-canvas');
        this.ctx = this.canvas.getContext('2d');

        // Estado del dibujo
        this.isDrawing = false;
        this.lastX = 0;
        this.lastY = 0;

        // Herramientas
        this.currentTool = 'pencil'; // 'pencil' o 'eraser'
        this.currentColor = '#000000';
        this.currentSize = 5;

        // Estado del juego
        this.isDrawer = false;
        this.gameState = null;

        // WebSocket (usamos el Echo global de bootstrap.js)
        this.roomCode = window.gameData?.roomCode || null;

        // Inicializar
        this.init();
    }

    init() {
        this.setupCanvas();
        this.bindToolEvents();
        this.bindCanvasEvents();
        this.bindFormEvents();
        this.connectWebSocket();

        console.log('🎨 Pictionary Canvas initialized - VERSION: 2024-10-21-WEBSOCKET-INTEGRATED');
        console.log('Room code:', this.roomCode);
        console.log('Echo available:', !!window.Echo);
    }

    /**
     * Conectar WebSocket con Laravel Echo
     */
    connectWebSocket() {
        if (!this.roomCode) {
            console.warn('No room code available, skipping WebSocket connection');
            return;
        }

        if (!window.Echo) {
            console.error('Laravel Echo not initialized. Make sure @vite directive is in your layout.');
            return;
        }

        // Suscribirse al canal público de la sala (demo)
        // TODO: En producción cambiar a .private() con autenticación
        const channel = window.Echo.channel(`room.${this.roomCode}`);

        // Evento: Jugador respondió (presionó "YO SÉ")
        channel.listen('.player.answered', (data) => {
            console.log('Player answered:', data);
            this.handlePlayerAnswered(data);
        });

        // Evento: Jugador eliminado
        channel.listen('.player.eliminated', (data) => {
            console.log('Player eliminated:', data);
            this.handlePlayerEliminated(data);
        });

        // Evento: Estado del juego actualizado
        channel.listen('.game.state.updated', (data) => {
            console.log('Game state updated:', data);
            this.handleGameStateUpdate(data);
        });

        // Evento: Canvas dibujado (sincronización de trazos)
        channel.listen('.canvas.draw', (data) => {
            console.log('Canvas draw received:', data);
            if (data.action === 'clear') {
                this.clearCanvasRemote();
            } else {
                this.drawRemoteStroke(data.stroke);
            }
        });

        console.log(`Connected to WebSocket channel: room.${this.roomCode}`);
    }

    /**
     * Manejar evento de jugador que respondió
     */
    handlePlayerAnswered(data) {
        // Pausar el juego y mostrar panel de confirmación al dibujante
        if (this.isDrawer) {
            const confirmationContainer = document.getElementById('confirmation-container');
            const pendingPlayerName = document.getElementById('pending-player-name');

            confirmationContainer.classList.remove('hidden');
            pendingPlayerName.textContent = data.player_name;

            // Guardar el player_id pendiente para la confirmación
            confirmationContainer.dataset.pendingPlayerId = data.player_id;
        }

        // Mostrar mensaje a todos
        this.showGameMessage(data.message);
    }

    /**
     * Manejar evento de jugador eliminado
     */
    handlePlayerEliminated(data) {
        // Mostrar mensaje
        this.showGameMessage(data.message);

        // Actualizar UI del jugador eliminado
        const playerElement = document.querySelector(`[data-player-id="${data.player_id}"]`);
        if (playerElement) {
            playerElement.classList.add('is-eliminated');
        }

        // Si el juego continúa, ocultar panel de confirmación
        if (data.game_resumes) {
            document.getElementById('confirmation-container').classList.add('hidden');
        }
    }

    /**
     * Manejar actualización del estado del juego
     */
    handleGameStateUpdate(data) {
        console.log('Game state update type:', data.update_type);

        // Actualizar ronda
        this.updateRoundInfo(data.round, data.rounds_total);

        // Actualizar puntuaciones
        if (data.scores) {
            this.updateScores(data.scores);
        }

        // Actualizar lista de jugadores con eliminados
        if (data.eliminated_this_round) {
            this.updateEliminatedPlayers(data.eliminated_this_round);
        }

        // Si hay cambio de fase
        if (data.update_type === 'phase_change') {
            this.handlePhaseChange(data.phase);
        }

        // Si hay cambio de dibujante
        if (data.update_type === 'turn_change' && data.current_drawer_id) {
            this.handleDrawerChange(data.current_drawer_id);
        }
    }

    /**
     * Mostrar mensaje del juego
     */
    showGameMessage(message) {
        const messagesContainer = document.getElementById('game-messages');
        if (!messagesContainer) return;

        const messageElement = document.createElement('div');
        messageElement.className = 'game-message';
        messageElement.textContent = message;
        messagesContainer.appendChild(messageElement);

        // Auto-eliminar después de 5 segundos
        setTimeout(() => {
            messageElement.remove();
        }, 5000);

        // Scroll al final
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    /**
     * Actualizar puntuaciones
     */
    updateScores(scores) {
        Object.entries(scores).forEach(([playerId, score]) => {
            const playerElement = document.querySelector(`[data-player-id="${playerId}"] .player-score`);
            if (playerElement) {
                playerElement.textContent = `${score} pts`;
            }
        });
    }

    /**
     * Actualizar jugadores eliminados
     */
    updateEliminatedPlayers(eliminatedIds) {
        // Primero, limpiar todos los jugadores (nueva ronda)
        document.querySelectorAll('.player-item').forEach(el => {
            el.classList.remove('is-eliminated');
        });

        // Marcar solo los eliminados de esta ronda
        eliminatedIds.forEach(playerId => {
            const playerElement = document.querySelector(`[data-player-id="${playerId}"]`);
            if (playerElement) {
                playerElement.classList.add('is-eliminated');
            }
        });
    }

    /**
     * Manejar cambio de fase
     */
    handlePhaseChange(newPhase) {
        console.log('Phase changed to:', newPhase);

        if (newPhase === 'drawing') {
            // Limpiar canvas al iniciar nueva ronda
            this.clearCanvas();
        } else if (newPhase === 'results') {
            // Mostrar resultados
            // TODO: Implementar modal de resultados
        }
    }

    /**
     * Manejar cambio de dibujante
     */
    handleDrawerChange(newDrawerId) {
        const currentPlayerId = window.gameData?.playerId;
        const isNowDrawer = currentPlayerId === newDrawerId;

        // Actualizar rol
        this.setRole(isNowDrawer);

        // Actualizar UI de lista de jugadores
        document.querySelectorAll('.player-item').forEach(el => {
            el.classList.remove('is-drawer');
        });

        const drawerElement = document.querySelector(`[data-player-id="${newDrawerId}"]`);
        if (drawerElement) {
            drawerElement.classList.add('is-drawer');
        }
    }

    /**
     * Configurar canvas inicial
     */
    setupCanvas() {
        // Fondo blanco
        this.ctx.fillStyle = '#FFFFFF';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        // Configuración de dibujo
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
    }

    /**
     * Vincular eventos de las herramientas
     */
    bindToolEvents() {
        // Botones de herramientas (lápiz/borrador)
        document.getElementById('tool-pencil').addEventListener('click', () => {
            this.setTool('pencil');
        });

        document.getElementById('tool-eraser').addEventListener('click', () => {
            this.setTool('eraser');
        });

        // Selector de colores
        document.querySelectorAll('.color-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const color = e.currentTarget.dataset.color;
                this.setColor(color);

                // Actualizar UI
                document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('active'));
                e.currentTarget.classList.add('active');
            });
        });

        // Selector de grosor
        document.querySelectorAll('.size-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const size = parseInt(e.currentTarget.dataset.size);
                this.setSize(size);

                // Actualizar UI
                document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
                e.currentTarget.classList.add('active');
            });
        });

        // Botón limpiar canvas
        document.getElementById('clear-canvas').addEventListener('click', () => {
            if (this.isDrawer) {
                this.clearCanvas();
            }
        });
    }

    /**
     * Vincular eventos del canvas (mouse y touch)
     */
    bindCanvasEvents() {
        // Eventos de mouse
        this.canvas.addEventListener('mousedown', (e) => this.startDrawing(e));
        this.canvas.addEventListener('mousemove', (e) => this.draw(e));
        this.canvas.addEventListener('mouseup', () => this.stopDrawing());

        // Usar mouseup global en lugar de mouseout para evitar problemas al cambiar de pestaña
        document.addEventListener('mouseup', () => {
            if (this.isDrawing) {
                this.stopDrawing();
            }
        });

        // Eventos de touch (móvil/tablet)
        this.canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            const mouseEvent = new MouseEvent('mousedown', {
                clientX: touch.clientX,
                clientY: touch.clientY
            });
            this.canvas.dispatchEvent(mouseEvent);
        });

        this.canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            const mouseEvent = new MouseEvent('mousemove', {
                clientX: touch.clientX,
                clientY: touch.clientY
            });
            this.canvas.dispatchEvent(mouseEvent);
        });

        this.canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            const mouseEvent = new MouseEvent('mouseup', {});
            this.canvas.dispatchEvent(mouseEvent);
        });
    }

    /**
     * Vincular eventos de formularios
     */
    bindFormEvents() {
        // Botón "YO SÉ" (adivinadores)
        const btnYoSe = document.getElementById('btn-yo-se');
        if (btnYoSe) {
            btnYoSe.addEventListener('click', () => {
                console.log('¡YO SÉ! button clicked');
                this.playerAnswered();
            });
        }

        // Botones de confirmación (dibujante)
        const btnCorrect = document.getElementById('btn-correct');
        const btnIncorrect = document.getElementById('btn-incorrect');

        if (btnCorrect) {
            btnCorrect.addEventListener('click', () => this.confirmAnswer(true));
        }

        if (btnIncorrect) {
            btnIncorrect.addEventListener('click', () => this.confirmAnswer(false));
        }
    }

    /**
     * Iniciar dibujo
     */
    startDrawing(e) {
        if (!this.isDrawer) return; // Solo el dibujante puede dibujar

        this.isDrawing = true;
        const coords = this.getCanvasCoordinates(e);
        this.lastX = coords.x;
        this.lastY = coords.y;
    }

    /**
     * Dibujar en el canvas
     */
    draw(e) {
        if (!this.isDrawing) return;
        if (!this.isDrawer) return;

        const coords = this.getCanvasCoordinates(e);

        this.ctx.strokeStyle = this.currentTool === 'eraser' ? '#FFFFFF' : this.currentColor;
        this.ctx.lineWidth = this.currentTool === 'eraser' ? this.currentSize * 2 : this.currentSize;

        this.ctx.beginPath();
        this.ctx.moveTo(this.lastX, this.lastY);
        this.ctx.lineTo(coords.x, coords.y);
        this.ctx.stroke();

        // Emitir evento WebSocket con los datos del trazo
        this.emitDrawEvent({
            x0: this.lastX,
            y0: this.lastY,
            x1: coords.x,
            y1: coords.y,
            color: this.currentTool === 'eraser' ? '#FFFFFF' : this.currentColor,
            size: this.currentTool === 'eraser' ? this.currentSize * 2 : this.currentSize
        });

        this.lastX = coords.x;
        this.lastY = coords.y;
    }

    /**
     * Emitir evento de dibujo vía WebSocket
     */
    emitDrawEvent(strokeData) {
        if (!this.roomCode) {
            console.warn('No room code, skipping draw event');
            return;
        }

        console.log('Emitting draw event:', strokeData);

        // Enviar al backend para broadcasting
        fetch('/api/pictionary/draw', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.gameData.csrfToken
            },
            body: JSON.stringify({
                room_code: this.roomCode,
                match_id: window.gameData.matchId,
                stroke: strokeData
            })
        })
        .then(response => {
            console.log('Draw event response:', response.status, response.statusText);
            return response.json();
        })
        .then(data => {
            console.log('Draw event result:', data);
        })
        .catch(error => {
            console.error('Error emitting draw event:', error);
        });
    }

    /**
     * Detener dibujo
     */
    stopDrawing() {
        this.isDrawing = false;
    }

    /**
     * Obtener coordenadas relativas al canvas
     */
    getCanvasCoordinates(e) {
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;

        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top) * scaleY
        };
    }

    /**
     * Cambiar herramienta
     */
    setTool(tool) {
        this.currentTool = tool;

        // Actualizar UI
        document.querySelectorAll('.tool-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById(`tool-${tool}`).classList.add('active');

        console.log('Tool changed to:', tool);
    }

    /**
     * Cambiar color
     */
    setColor(color) {
        this.currentColor = color;
        console.log('Color changed to:', color);
    }

    /**
     * Cambiar grosor
     */
    setSize(size) {
        this.currentSize = size;
        console.log('Brush size changed to:', size);
    }

    /**
     * Limpiar canvas
     */
    clearCanvas() {
        this.ctx.fillStyle = '#FFFFFF';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        // Emitir evento WebSocket de limpieza
        this.emitClearEvent();

        console.log('Canvas cleared');
    }

    /**
     * Emitir evento de limpiar canvas vía WebSocket
     */
    emitClearEvent() {
        if (!this.roomCode) return;

        // Enviar al backend para broadcasting
        fetch('/api/pictionary/clear', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.gameData.csrfToken
            },
            body: JSON.stringify({
                room_code: this.roomCode,
                match_id: window.gameData.matchId
            })
        }).catch(error => {
            console.error('Error emitting clear event:', error);
        });
    }

    /**
     * Dibujar trazo desde evento remoto (WebSocket)
     */
    drawRemoteStroke(strokeData) {
        this.ctx.strokeStyle = strokeData.color;
        this.ctx.lineWidth = strokeData.size;

        this.ctx.beginPath();
        this.ctx.moveTo(strokeData.x0, strokeData.y0);
        this.ctx.lineTo(strokeData.x1, strokeData.y1);
        this.ctx.stroke();
    }

    /**
     * Limpiar canvas desde evento remoto (WebSocket)
     */
    clearCanvasRemote() {
        this.ctx.fillStyle = '#FFFFFF';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        console.log('Canvas cleared remotely');
    }

    /**
     * Establecer rol del jugador
     */
    setRole(isDrawer, word = null) {
        console.log('🎭 setRole called with isDrawer:', isDrawer, 'word:', word);
        this.isDrawer = isDrawer;

        const wordDisplay = document.getElementById('word-display');
        const secretWord = document.getElementById('secret-word');
        const drawingTools = document.querySelector('.drawing-tools');
        const yoSeContainer = document.getElementById('yo-se-container');

        console.log('Elements found:', {
            wordDisplay: !!wordDisplay,
            secretWord: !!secretWord,
            drawingTools: !!drawingTools,
            yoSeContainer: !!yoSeContainer
        });

        if (isDrawer) {
            // DIBUJANTE
            // Mostrar palabra secreta
            if (wordDisplay) wordDisplay.classList.remove('hidden');
            if (secretWord) secretWord.textContent = word || '-';

            // Mostrar herramientas de dibujo
            if (drawingTools) drawingTools.classList.remove('hidden');

            // Ocultar botón "YO SÉ"
            if (yoSeContainer) yoSeContainer.classList.add('hidden');

            // Habilitar herramientas
            this.canvas.style.cursor = 'crosshair';

            console.log('✅ Demo mode: Drawer (Dibujante)');
        } else {
            // ADIVINADOR
            // Ocultar palabra secreta
            if (wordDisplay) wordDisplay.classList.add('hidden');

            // Ocultar herramientas de dibujo
            if (drawingTools) drawingTools.classList.add('hidden');

            // Mostrar botón "YO SÉ"
            if (yoSeContainer) yoSeContainer.classList.remove('hidden');

            // Deshabilitar dibujo
            this.canvas.style.cursor = 'default';

            console.log('✅ Demo mode: Guesser (Adivinador)');
        }
    }

    /**
     * Enviar respuesta (adivinadores)
     */
    submitAnswer() {
        const input = document.getElementById('answer-input');
        const answer = input.value.trim();

        if (!answer) return;

        console.log('Submitting answer:', answer);

        // TODO Task 6.0: Enviar al servidor vía AJAX
        // fetch('/api/pictionary/answer', {
        //     method: 'POST',
        //     headers: {
        //         'Content-Type': 'application/json',
        //         'X-CSRF-TOKEN': window.gameData.csrfToken
        //     },
        //     body: JSON.stringify({
        //         match_id: window.gameData.matchId,
        //         answer: answer
        //     })
        // });

        // Limpiar input
        input.value = '';

        // Mostrar en UI (temporal)
        this.addAnswerToList(window.gameData.playerId, answer, 'pending');
    }

    /**
     * Jugador pulsa "¡YO SÉ!" (guesser)
     */
    playerAnswered() {
        if (!this.roomCode) return;

        const playerName = `Jugador ${window.gameData.playerId}`;

        console.log('Emitting player answered event:', playerName);

        // Enviar al servidor para broadcast
        fetch('/api/pictionary/player-answered', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.gameData.csrfToken
            },
            body: JSON.stringify({
                room_code: this.roomCode,
                match_id: window.gameData.matchId,
                player_id: window.gameData.playerId,
                player_name: playerName
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Player answered result:', data);

            // Ocultar botón "YO SÉ" y mostrar mensaje de espera
            const yoSeContainer = document.getElementById('yo-se-container');
            const waitingConfirmation = document.getElementById('waiting-confirmation');

            if (yoSeContainer) yoSeContainer.classList.add('hidden');
            if (waitingConfirmation) waitingConfirmation.classList.remove('hidden');
        })
        .catch(error => {
            console.error('Error emitting player answered:', error);
        });
    }

    /**
     * Confirmar respuesta (dibujante)
     */
    confirmAnswer(isCorrect) {
        const confirmationContainer = document.getElementById('confirmation-container');
        const pendingPlayerId = confirmationContainer?.dataset?.pendingPlayerId;
        const pendingPlayerName = document.getElementById('pending-player-name')?.textContent;

        if (!pendingPlayerId || !this.roomCode) return;

        console.log('Confirming answer as:', isCorrect ? 'correct' : 'incorrect', 'for player:', pendingPlayerName);

        // Enviar confirmación al servidor para broadcast
        fetch('/api/pictionary/confirm-answer', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.gameData.csrfToken
            },
            body: JSON.stringify({
                room_code: this.roomCode,
                match_id: window.gameData.matchId,
                player_id: parseInt(pendingPlayerId),
                player_name: pendingPlayerName,
                is_correct: isCorrect
            })
        })
        .then(response => response.json())
        .then(data => {
            console.log('Confirm answer result:', data);

            // Ocultar panel de confirmación
            if (confirmationContainer) confirmationContainer.classList.add('hidden');
        })
        .catch(error => {
            console.error('Error confirming answer:', error);
        });
    }

    /**
     * Añadir respuesta a la lista
     */
    addAnswerToList(playerId, answer, status) {
        const answersList = document.getElementById('answers-list');
        const answerElement = document.createElement('div');
        answerElement.className = `answer-item answer-${status}`;
        answerElement.innerHTML = `
            <span class="player-name">Jugador ${playerId}</span>
            <span class="answer-text">${answer}</span>
            <span class="answer-status">${this.getStatusIcon(status)}</span>
        `;
        answersList.appendChild(answerElement);

        // Scroll al final
        answersList.scrollTop = answersList.scrollHeight;
    }

    /**
     * Obtener icono de estado
     */
    getStatusIcon(status) {
        switch (status) {
            case 'correct':
                return '✓';
            case 'incorrect':
                return '✗';
            case 'pending':
                return '⏳';
            default:
                return '';
        }
    }

    /**
     * Actualizar lista de jugadores
     */
    updatePlayersList(players) {
        const playersList = document.getElementById('players-list');
        playersList.innerHTML = '';

        players.forEach(player => {
            const playerElement = document.createElement('div');
            playerElement.className = 'player-item';
            if (player.isDrawer) {
                playerElement.classList.add('is-drawer');
            }
            if (player.isEliminated) {
                playerElement.classList.add('is-eliminated');
            }

            playerElement.innerHTML = `
                <span class="player-name">${player.name}</span>
                <span class="player-score">${player.score || 0} pts</span>
                ${player.isDrawer ? '<span class="drawer-badge">🎨</span>' : ''}
            `;

            playersList.appendChild(playerElement);
        });
    }

    /**
     * Actualizar temporizador
     */
    updateTimer(seconds) {
        const timerElement = document.getElementById('time-remaining');
        timerElement.textContent = seconds;

        // Cambiar color si quedan menos de 10 segundos
        if (seconds <= 10) {
            timerElement.parentElement.classList.add('timer-warning');
        } else {
            timerElement.parentElement.classList.remove('timer-warning');
        }
    }

    /**
     * Actualizar información de ronda
     */
    updateRoundInfo(currentRound, totalRounds) {
        document.getElementById('current-round').textContent = currentRound;
        document.getElementById('total-rounds').textContent = totalRounds;
    }

    /**
     * Mostrar resultados de ronda
     */
    showRoundResults(results) {
        const modal = document.getElementById('round-results-modal');
        const resultsContainer = document.getElementById('round-results');

        resultsContainer.innerHTML = `
            <p><strong>Palabra:</strong> ${results.word}</p>
            <p><strong>Ganador:</strong> ${results.winner || 'Nadie'}</p>
            <div class="scores">
                <h3>Puntuaciones:</h3>
                <ul>
                    ${results.scores.map(s => `
                        <li>${s.playerName}: ${s.score} pts</li>
                    `).join('')}
                </ul>
            </div>
        `;

        modal.classList.remove('hidden');
    }

    /**
     * Mostrar resultados finales
     */
    showFinalResults(results) {
        const modal = document.getElementById('final-results-modal');
        const resultsContainer = document.getElementById('final-results');

        resultsContainer.innerHTML = `
            <h3>🏆 Ganador: ${results.winner}</h3>
            <div class="final-ranking">
                <h4>Ranking final:</h4>
                <ol>
                    ${results.ranking.map(p => `
                        <li>${p.playerName}: ${p.score} pts</li>
                    `).join('')}
                </ol>
            </div>
        `;

        modal.classList.remove('hidden');
    }
}

// Inicializar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    // Solo inicializar si existe el canvas de Pictionary
    const canvas = document.getElementById('drawing-canvas');
    if (!canvas) {
        console.log('Pictionary canvas not found, skipping initialization');
        return;
    }

    console.log('🚀 Initializing Pictionary Canvas...');
    window.pictionaryCanvas = new PictionaryCanvas();

    // Configurar rol inicial si está disponible en gameData
    if (window.gameData?.role) {
        const isDrawer = window.gameData.role === 'drawer';
        window.pictionaryCanvas.setRole(isDrawer, isDrawer ? 'PALABRA_DEMO' : null);
    }
});
