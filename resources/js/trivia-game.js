/**
 * Trivia Game - Frontend Logic
 *
 * Extiende BaseGameClient y añade lógica específica de Trivia:
 * - Mostrar preguntas y opciones
 * - Enviar respuestas al servidor
 * - Mostrar resultados y ranking
 * - Actualizar UI en tiempo real
 *
 * DEPENDENCIAS:
 * - BaseGameClient (gestiona eventos y conexión WebSocket)
 */

import { BaseGameClient } from './core/BaseGameClient.js';

class TriviaGame extends BaseGameClient {
    constructor(config) {
        // Llamar al constructor del padre (BaseGameClient)
        super(config);

        console.log('🎮 [TriviaGame] Initialized');

        // Propiedades específicas de Trivia
        this.currentQuestion = null;
        this.hasAnswered = false;
        this.selectedOption = null;
        this.lastRoundNumber = null;

        // Inicializar UI de Trivia
        this.initializeElements();
        this.setupEventListeners();

        // Configurar handlers de eventos específicos de Trivia
        this.setupEventManager({
            handleGameStarted: (event) => this.handleGameStartedTrivia(event),
            handleRoundStarted: (event) => this.handleRoundStartedTrivia(event),
            handleRoundEnded: (event) => this.handleRoundEndedTrivia(event),
            handlePlayerAction: (event) => this.handlePlayerActionTrivia(event),
            handlePhaseChanged: (event) => this.handlePhaseChangedTrivia(event),
            handleTurnChanged: (event) => this.handleTurnChangedTrivia(event),
            handleGameFinished: (event) => this.handleGameFinishedTrivia(event),
        });

        this.syncInitialState(); // Sincronizar con el estado actual
    }

    initializeElements() {
        // Panels
        this.gameStarting = document.getElementById('game-starting');
        this.questionWaiting = document.getElementById('question-waiting');
        this.questionActive = document.getElementById('question-active');
        this.questionResults = document.getElementById('question-results');
        this.finalResults = document.getElementById('final-results');

        // Question elements
        this.questionCategory = document.getElementById('question-category');
        this.questionDifficulty = document.getElementById('question-difficulty');
        this.questionText = document.getElementById('question-text');
        this.optionsGrid = document.getElementById('options-grid');
        this.answerFeedback = document.getElementById('answer-feedback');

        // Header
        this.currentQuestionSpan = document.getElementById('current-question');
        this.totalQuestionsSpan = document.getElementById('total-questions');
        this.timeRemaining = document.getElementById('time-remaining');

        // Players
        this.playersList = document.getElementById('players-list');
        this.answeredCount = document.getElementById('answered-count');
        this.totalPlayersSpan = document.getElementById('total-players');
        this.progressFill = document.getElementById('progress-fill');

        // Messages
        this.gameMessages = document.getElementById('game-messages');
    }

    // setupEventManager() ya no es necesario aquí
    // Se hereda de BaseGameClient y se configura en el constructor

    setupEventListeners() {
        // Option buttons
        this.optionsGrid.addEventListener('click', (e) => {
            const optionBtn = e.target.closest('.option-btn');
            if (optionBtn && !this.hasAnswered) {
                const optionIndex = parseInt(optionBtn.dataset.option);
                this.selectOption(optionIndex);
            }
        });

        // Final results buttons
        const btnPlayAgain = document.getElementById('btn-play-again');
        const btnBackLobby = document.getElementById('btn-back-lobby');

        if (btnPlayAgain) {
            btnPlayAgain.addEventListener('click', () => this.playAgain());
        }

        if (btnBackLobby) {
            btnBackLobby.addEventListener('click', () => this.backToLobby());
        }
    }

    syncInitialState() {
        console.log('🔄 [Trivia] Syncing initial state', this.gameState);

        if (!this.gameState) {
            console.log('⏸️ [Trivia] No gameState, showing waiting screen');
            this.showQuestionWaiting();
            return;
        }

        const phase = this.gameState.phase;
        console.log('📍 [Trivia] Current phase:', phase);

        // Manejar diferentes fases del juego
        switch (phase) {
            case 'starting':
                // Esperando que todos los jugadores se conecten
                this.showGameStarting();
                break;

            case 'question':
                // Hay una pregunta activa
                const currentQuestion = this.gameState.current_question;
                const currentRound = this.gameState.round_system?.current_round || 1;
                const totalRounds = this.gameState.round_system?.total_rounds || 10;

                // Si estamos en la ronda 1, NO renderizar aún
                // Esperar a que llegue GameStartedEvent con el countdown
                if (currentRound === 1) {
                    console.log('⏳ [Trivia] Round 1 detected, waiting for GameStartedEvent with countdown');
                    this.showQuestionWaiting();
                    return;
                }

                // Si estamos en rondas posteriores, renderizar directamente
                console.log('❓ [Trivia] Round', currentRound, '- rendering directly');
                if (currentQuestion) {
                    this.handleRoundStartedTrivia({
                        game_state: this.gameState,
                        current_round: currentRound,
                        total_rounds: totalRounds,
                        phase: phase
                    });
                }
                break;

            case 'results':
                // Mostrando resultados de la pregunta
                const results = this.gameState.question_results || {};
                const scores = this.gameState.scoring_system?.scores || {};
                const roundNumber = this.gameState.round_system?.current_round || 1;

                this.handleRoundEndedTrivia({
                    round_number: roundNumber,
                    results: results,
                    scores: scores
                });
                break;

            case 'final_results':
                // Juego terminado
                // TODO: Mostrar resultados finales
                break;

            default:
                // Fase desconocida o esperando
                this.showQuestionWaiting();
        }
    }

    /**
     * Handler específico de Trivia para GameStarted
     *
     * Se ejecuta cuando el juego inicia (después de que el master presiona "Iniciar Juego").
     * Con TimingModule: Muestra countdown "Empezando en 3s..." automáticamente
     */
    async handleGameStartedTrivia(event) {
        console.log('🎮 [Trivia] GameStartedEvent received:', event);

        // Actualizar estado del juego
        this.gameState = event.game_state;

        // Mostrar la pantalla de espera (donde aparecerá el countdown)
        this.showGameStartingScreen();

        // Llamar al handler base - Procesará el timing y mostrará countdown
        // Cuando termine el countdown, llamará a this.onGameReady()
        await super.handleGameStarted(event);
    }

    /**
     * Mostrar pantalla de inicio del juego
     */
    showGameStartingScreen() {
        console.log('[showGameStartingScreen] Mostrando pantalla de inicio...');

        // Ocultar todas las pantallas
        this.questionActive.classList.add('hidden');
        this.questionResults.classList.add('hidden');
        this.questionWaiting.classList.remove('hidden');

        // Mostrar mensaje de inicio en el <p> que existe
        const waitingText = this.questionWaiting.querySelector('p');
        if (waitingText) {
            waitingText.innerHTML = '<strong style="font-size: 1.3em; display: block; margin-bottom: 0.5em;">🎮 ¡Va a empezar la partida!</strong>Prepárate para responder las preguntas...';
            console.log('[showGameStartingScreen] Texto actualizado:', waitingText.innerHTML);
        } else {
            console.error('[showGameStartingScreen] No se encontró el elemento <p>');
        }

        console.log('✅ [Trivia] Game starting screen displayed');
    }

    /**
     * Sobrescribir: Proporcionar elemento para mostrar countdown
     */
    getCountdownElement() {
        return this.questionWaiting.querySelector('p');
    }

    /**
     * Sobrescribir: Callback cuando termina el countdown de inicio
     */
    onGameReady() {
        console.log('✅ [Trivia] Game is ready - Countdown finished');

        // El game_state ya contiene la primera pregunta cargada
        // Renderizarla inmediatamente
        if (this.gameState && this.gameState.current_question) {
            console.log('📝 [Trivia] Rendering first question from game_state');
            this.showQuestion(
                this.gameState.current_question,
                this.gameState.round_system.current_round,
                this.gameState.round_system.total_rounds
            );
        } else {
            console.warn('⚠️ [Trivia] No current_question in game_state');
            // Fallback: Mostrar mensaje de espera
            const waitingText = this.questionWaiting.querySelector('p');
            if (waitingText) {
                waitingText.innerHTML = '<strong style="font-size: 1.5em; display: block; margin-bottom: 0.5em;">🎉 ¡Ha empezado la partida!</strong>Esperando primera pregunta...';
            }
        }
    }

    /**
     * Handler específico de Trivia para RoundStarted
     *
     * CONVENCIÓN: Cuando empieza una ronda en Trivia:
     * 1. Resetear estados de respuesta (nadie ha respondido)
     * 2. Mostrar la nueva pregunta
     * 3. Resetear indicadores visuales de jugadores
     * 4. Actualizar contador de rondas
     * 5. Mostrar panel de pregunta activa
     */
    handleRoundStartedTrivia(event) {

        // Llamar al handler base primero (logging, estado base, etc.)
        super.handleRoundStarted(event);

        // Evento genérico: Nueva ronda = Nueva pregunta en Trivia
        const gameState = event.game_state;
        const currentQuestion = gameState.current_question;


        this.currentQuestion = {
            question: currentQuestion.question,
            options: currentQuestion.options,
            currentRound: event.current_round,
            totalRounds: event.total_rounds,
            category: currentQuestion.category,
            difficulty: currentQuestion.difficulty
        };

        // 1. Resetear estados de respuesta (CONVENCIÓN)
        this.hasAnswered = false;
        this.selectedOption = null;

        // 2-5. Renderizar UI
        this.showQuestionActive();
        this.renderQuestion();
        this.updateProgress(0);
        this.resetPlayerStatuses();
        this.updateRoundCounter();

    }

    /**
     * Handler específico de Trivia para PlayerAction
     */
    handlePlayerActionTrivia(event) {

        // Llamar al handler base
        super.handlePlayerAction(event);

        // Evento genérico: Acción de jugador
        if (event.action_type !== 'answer') return;

        // Marcar jugador como respondido
        this.markPlayerAsAnswered(event.player_id);

        // Mostrar mensaje
        if (event.player_id !== this.playerId) {
            this.showMessage(`${event.player_name} ha respondido`, 'info');
        }
    }

    /**
     * Handler específico de Trivia para RoundEnded
     *
     * CONVENCIÓN: Cuando termina una ronda:
     * 1. Actualizar scores de todos los jugadores
     * 2. Mostrar resultados de la ronda
     * 3. Iniciar countdown para próxima pregunta
     */
    handleRoundEndedTrivia(event) {

        // Llamar al handler base
        super.handleRoundEnded(event);

        // Evento genérico: Fin de ronda
        this.scores = event.scores;

        // Guardar el índice de pregunta actual para enviar después del countdown
        this.lastQuestionIndex = this.gameState?.current_question_index ?? 0;

        // Los resultados vienen con player_id como key
        const results = event.results;


        // Actualizar puntuaciones en tiempo real (datos vienen del evento)
        this.updateScores(event.scores);

        // Mostrar quién ganó esta ronda
        this.showRoundResults(results);

    }

    /**
     * Handler específico de Trivia para PhaseChanged
     */
    handlePhaseChangedTrivia(event) {
        // TODO: Implementar cambios de fase si es necesario
    }

    /**
     * Handler específico de Trivia para TurnChanged
     */
    handleTurnChangedTrivia(event) {
        // En Trivia no hay turnos (es simultáneo), pero dejamos el handler
    }

    /**
     * Handler específico de Trivia para GameFinished
     */
    handleGameFinishedTrivia(event) {

        // Llamar al handler base
        super.handleGameFinished(event);

        // Mostrar resultados finales
        this.showFinalResults(event.ranking, event.statistics);
    }

    showQuestionActive() {
        this.questionWaiting.classList.add('hidden');
        this.questionActive.classList.remove('hidden');
        this.questionResults.classList.add('hidden');
        this.finalResults.classList.add('hidden');
    }

    renderQuestion() {
        const q = this.currentQuestion;

        // Category and difficulty
        this.questionCategory.textContent = q.category || 'General';
        this.questionDifficulty.textContent = q.difficulty || 'Media';
        this.questionDifficulty.className = `question-difficulty ${q.difficulty || 'medium'}`;

        // Question text
        this.questionText.textContent = q.question;

        // Options
        this.optionsGrid.innerHTML = '';
        const letters = ['A', 'B', 'C', 'D'];

        q.options.forEach((option, index) => {
            const btn = document.createElement('button');
            btn.className = 'option-btn';
            btn.dataset.option = index;

            btn.innerHTML = `
                <span class="option-letter">${letters[index]}</span>
                <span class="option-text">${option}</span>
            `;

            this.optionsGrid.appendChild(btn);
        });

        // Update header
        this.currentQuestionSpan.textContent = q.currentRound;
        this.totalQuestionsSpan.textContent = q.totalRounds;

        // Hide feedback
        this.answerFeedback.classList.add('hidden');
    }

    selectOption(optionIndex) {
        if (this.hasAnswered) return;

        this.selectedOption = optionIndex;
        this.hasAnswered = true;

        // Visually mark as selected
        const buttons = this.optionsGrid.querySelectorAll('.option-btn');
        buttons.forEach((btn, idx) => {
            if (idx === optionIndex) {
                btn.classList.add('selected');
            }
            btn.disabled = true;
        });

        // Send answer to server
        this.sendAnswer(optionIndex);
    }

    async sendAnswer(optionIndex) {
        try {
            const response = await fetch(`/api/trivia/answer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    room_code: this.roomCode,
                    player_id: this.playerId,
                    answer: optionIndex
                })
            });

            if (!response.ok) {
                this.showMessage(`Error del servidor (${response.status})`, 'error');
                return;
            }

            const data = await response.json();

            if (data.success) {
                if (data.is_correct) {
                    this.showMessage('¡Correcto! +100 puntos', 'success');
                } else if (!data.question_ended) {
                    this.showMessage('Respuesta incorrecta. Espera a que el resto responda.', 'info');
                }
            } else {
                const errorMsg = data.error || data.message || data.data?.error || 'Error al enviar respuesta';
                this.showMessage(errorMsg, 'error');
            }
        } catch (error) {
            this.showMessage('Error de conexión', 'error');
        }
    }

    showCorrectAnswer(correctIndex) {
        const buttons = this.optionsGrid.querySelectorAll('.option-btn');

        buttons.forEach((btn, idx) => {
            // Deshabilitar TODOS los botones - la ronda terminó
            btn.disabled = true;

            if (idx === correctIndex) {
                btn.classList.add('correct');
            } else if (idx === this.selectedOption) {
                btn.classList.add('incorrect');
            }
        });

        // Show feedback
        const isCorrect = this.selectedOption === correctIndex;
        this.answerFeedback.classList.remove('hidden');
        this.answerFeedback.classList.toggle('correct', isCorrect);
        this.answerFeedback.classList.toggle('incorrect', !isCorrect);

        const feedbackMessage = this.answerFeedback.querySelector('.feedback-message');

        // Mensaje diferente si no respondió
        if (this.selectedOption === null) {
            feedbackMessage.textContent = 'No respondiste a tiempo';
        } else {
            feedbackMessage.textContent = isCorrect
                ? '¡Correcto! +100 puntos'
                : 'Incorrecto';
        }
    }

    showRoundResults(results) {
        this.questionActive.classList.add('hidden');
        this.questionResults.classList.remove('hidden');

        // Mantener contador de rondas visible
        this.updateRoundCounter();

        // Encontrar al ganador de esta ronda
        const winner = Object.entries(results).find(([_, result]) => result.correct);
        const winnerName = winner ? this.players.find(p => String(p.id) === String(winner[0]))?.name : 'Nadie';

        // Mostrar mensaje del ganador
        const correctAnswerText = document.getElementById('correct-answer-text');
        correctAnswerText.textContent = winner
            ? `${winnerName} respondió correctamente primero!`
            : 'Nadie respondió correctamente';

        // Players results
        const playersResults = document.getElementById('players-results');
        playersResults.innerHTML = '';

        Object.entries(results).forEach(([playerId, result]) => {
            const player = this.players.find(p => String(p.id) === String(playerId));
            if (!player) return;

            const resultDiv = document.createElement('div');
            resultDiv.className = `player-result ${result.correct ? 'correct' : 'incorrect'}`;

            resultDiv.innerHTML = `
                <div class="player-result-info">
                    <div class="player-result-avatar">
                        ${player.name.charAt(0).toUpperCase()}
                    </div>
                    <div class="player-result-details">
                        <span class="player-result-name">${player.name}</span>
                        <span class="player-result-answer">
                            ${result.correct ? '✓ Correcto' : '✗ Incorrecto'}
                        </span>
                    </div>
                </div>
                <div class="player-result-score">
                    ${result.correct ? `+${result.points_earned}` : '0'} pts
                </div>
            `;

            playersResults.appendChild(resultDiv);
        });

        // ✅ Iniciar countdown local de 5 segundos
        // Después del countdown, enviar señal al backend
        this.startNextQuestionCountdown();
    }

    startNextQuestionCountdown() {
        const countdownSpan = document.getElementById('next-question-countdown');
        let seconds = 5;

        const interval = setInterval(() => {
            seconds--;
            countdownSpan.textContent = seconds;

            if (seconds <= 0) {
                clearInterval(interval);
                this.showQuestionWaiting();
                // Enviar señal al backend (el primero que llega inicia la ronda)
                this.notifyCountdownEnded();
            }
        }, 1000);
    }

    async notifyCountdownEnded() {
        try {
            const response = await fetch(`/api/trivia/countdown-ended`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    room_code: this.roomCode,
                    question_index: this.lastQuestionIndex // Enviar índice de pregunta que terminó
                })
            });

            const data = await response.json();

            // El evento RoundStartedEvent llegará por WebSocket
            if (!data.success && !data.game_complete) {
                console.error('❌ [Trivia] Error notifying countdown:', data.error);
            }
        } catch (error) {
            console.error('❌ [Trivia] Network error notifying countdown:', error);
        }
    }

    showQuestionWaiting() {
        this.questionActive.classList.add('hidden');
        this.questionResults.classList.add('hidden');
        this.questionWaiting.classList.remove('hidden');
    }

    updateProgress(answeredCount) {
        const totalPlayers = this.players.length;
        const percentage = (answeredCount / totalPlayers) * 100;

        this.answeredCount.textContent = answeredCount;
        this.progressFill.style.width = `${percentage}%`;
    }

    updateScores(scores) {
        Object.entries(scores).forEach(([playerId, score]) => {
            const scoreSpan = document.querySelector(`.player-score[data-player-id="${playerId}"]`);
            if (scoreSpan) {
                scoreSpan.textContent = `${score} pts`;
            }
        });
    }

    resetPlayerStatuses() {
        const statusIndicators = document.querySelectorAll('.status-indicator');
        statusIndicators.forEach(indicator => {
            indicator.classList.remove('answered');
        });
    }

    markPlayerAsAnswered(playerId) {
        const statusIndicator = document.querySelector(`.player-status[data-player-id="${playerId}"] .status-indicator`);
        if (statusIndicator) {
            statusIndicator.classList.add('answered');
        }
    }

    updateRoundCounter() {
        if (!this.currentQuestion) return;

        this.currentQuestionSpan.textContent = this.currentQuestion.currentRound;
        this.totalQuestionsSpan.textContent = this.currentQuestion.totalRounds;
    }

    showMessage(message, type = 'info') {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${type}`;
        messageDiv.textContent = message;

        this.gameMessages.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.remove();
        }, 5000);
    }

    playAgain() {
        window.location.href = `/lobby/${this.roomCode}`;
    }

    backToLobby() {
        window.location.href = `/lobby/${this.roomCode}`;
    }

    showFinalResults(ranking, statistics) {
        // Ocultar otros paneles
        this.questionActive.classList.add('hidden');
        this.questionResults.classList.add('hidden');
        this.questionWaiting.classList.add('hidden');
        this.finalResults.classList.remove('hidden');

        // Mostrar ganador
        const winner = ranking[0];
        const winnerAnnouncement = document.getElementById('winner-announcement');
        if (winner && winnerAnnouncement) {
            winnerAnnouncement.querySelector('.winner-name').textContent = winner.player_name;
            winnerAnnouncement.querySelector('.winner-score').textContent = `${winner.score} puntos`;
        }

        // Mostrar ranking completo
        const finalRanking = document.getElementById('final-ranking');
        if (finalRanking) {
            finalRanking.innerHTML = '';
            ranking.forEach((entry) => {
                const rankItem = document.createElement('div');
                rankItem.className = 'rank-item';
                rankItem.innerHTML = `
                    <div class="rank-position">${entry.position}</div>
                    <div class="rank-player">${entry.player_name}</div>
                    <div class="rank-score">${entry.score} pts</div>
                `;
                finalRanking.appendChild(rankItem);
            });
        }
    }
}

// Export to window
window.TriviaGame = TriviaGame;

// Auto-initialize when window.gameConfig is available
if (window.gameConfig) {
    console.log('🎮 [Trivia] Auto-initializing with config:', window.gameConfig);
    window.game = new TriviaGame(window.gameConfig);
} else {
    console.log('⏸️ [Trivia] Waiting for gameConfig...');
    // Esperar a que gameConfig esté disponible
    const checkConfig = setInterval(() => {
        if (window.gameConfig) {
            console.log('🎮 [Trivia] Config found, initializing...');
            clearInterval(checkConfig);
            window.game = new TriviaGame(window.gameConfig);
        }
    }, 100);
}
