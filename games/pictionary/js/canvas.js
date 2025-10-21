/**
 * Pictionary Canvas - Sistema de dibujo
 *
 * Maneja el canvas HTML5 para dibujar, herramientas de dibujo,
 * y eventos de mouse/touch.
 *
 * TODO Task 7.0: Integrar WebSockets para sincronización en tiempo real
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

        // Inicializar
        this.init();
    }

    init() {
        this.setupCanvas();
        this.bindToolEvents();
        this.bindCanvasEvents();
        this.bindFormEvents();

        console.log('Pictionary Canvas initialized');
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
        this.canvas.addEventListener('mouseout', () => this.stopDrawing());

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
        // Formulario de respuesta (adivinadores)
        const answerForm = document.getElementById('answer-form');
        if (answerForm) {
            answerForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitAnswer();
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

        // TODO Task 7.0: Emitir evento WebSocket con los datos del trazo
        // this.emitDrawEvent({
        //     x0: this.lastX,
        //     y0: this.lastY,
        //     x1: coords.x,
        //     y1: coords.y,
        //     color: this.currentTool === 'eraser' ? '#FFFFFF' : this.currentColor,
        //     size: this.currentTool === 'eraser' ? this.currentSize * 2 : this.currentSize
        // });

        this.lastX = coords.x;
        this.lastY = coords.y;
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

        // TODO Task 7.0: Emitir evento WebSocket de limpieza
        // this.emitClearEvent();

        console.log('Canvas cleared');
    }

    /**
     * Dibujar trazo desde evento remoto (WebSocket)
     * TODO Task 7.0: Implementar cuando se agregue WebSocket
     */
    drawRemoteStroke(data) {
        this.ctx.strokeStyle = data.color;
        this.ctx.lineWidth = data.size;

        this.ctx.beginPath();
        this.ctx.moveTo(data.x0, data.y0);
        this.ctx.lineTo(data.x1, data.y1);
        this.ctx.stroke();
    }

    /**
     * Establecer rol del jugador
     */
    setRole(isDrawer, word = null) {
        this.isDrawer = isDrawer;

        const wordDisplay = document.getElementById('word-display');
        const secretWord = document.getElementById('secret-word');
        const answerInputContainer = document.getElementById('answer-input-container');

        if (isDrawer) {
            // Mostrar palabra secreta
            wordDisplay.classList.remove('hidden');
            secretWord.textContent = word || '-';

            // Ocultar input de respuesta
            answerInputContainer.classList.add('hidden');

            // Habilitar herramientas
            this.canvas.style.cursor = 'crosshair';
        } else {
            // Ocultar palabra secreta
            wordDisplay.classList.add('hidden');

            // Mostrar input de respuesta
            answerInputContainer.classList.remove('hidden');

            // Deshabilitar dibujo
            this.canvas.style.cursor = 'default';
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
     * Confirmar respuesta (dibujante)
     */
    confirmAnswer(isCorrect) {
        console.log('Answer confirmed as:', isCorrect ? 'correct' : 'incorrect');

        // TODO Task 6.0: Enviar confirmación al servidor
        // fetch('/api/pictionary/confirm-answer', {
        //     method: 'POST',
        //     headers: {
        //         'Content-Type': 'application/json',
        //         'X-CSRF-TOKEN': window.gameData.csrfToken
        //     },
        //     body: JSON.stringify({
        //         match_id: window.gameData.matchId,
        //         player_id: pendingPlayerId,
        //         is_correct: isCorrect
        //     })
        // });

        // Ocultar panel de confirmación
        document.getElementById('confirmation-container').classList.add('hidden');
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
    window.pictionaryCanvas = new PictionaryCanvas();

    // TODO Task 7.0: Conectar WebSocket
    // window.pictionaryCanvas.connectWebSocket();
});
