/**
 * Trivia Game - Frontend Logic
 *
 * Maneja la lógica del juego de Trivia en el cliente:
 * - Mostrar preguntas y opciones
 * - Enviar respuestas al servidor
 * - Recibir actualizaciones vía WebSocket
 * - Mostrar resultados y ranking
 * - Actualizar UI en tiempo real
 */

class TriviaGame {
    constructor(config) {
        this.roomCode = config.roomCode;
        this.playerId = config.playerId;
        this.matchId = config.matchId;
        this.gameSlug = config.gameSlug;
        this.players = config.players || [];
        this.scores = config.scores || {};

        this.currentQuestion = null;
        this.hasAnswered = false;
        this.selectedOption = null;

        this.initializeElements();
        this.setupWebSocket();
        this.setupEventListeners();

        console.log('[Trivia] Game initialized', {
            roomCode: this.roomCode,
            playerId: this.playerId,
            players: this.players.length
        });
    }

    initializeElements() {
        // Panels
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

    setupWebSocket() {
        if (!window.Echo) {
            console.error('[Trivia] Laravel Echo not initialized');
            return;
        }

        const channel = window.Echo.channel(`room.${this.roomCode}`);

        // Question Started
        channel.listen('trivia.question.started', (event) => {
            console.log('[Trivia] Question started', event);
            this.handleQuestionStarted(event);
        });

        // Player Answered
        channel.listen('trivia.player.answered', (event) => {
            console.log('[Trivia] Player answered', event);
            this.handlePlayerAnswered(event);
        });

        // Question Ended
        channel.listen('trivia.question.ended', (event) => {
            console.log('[Trivia] Question ended', event);
            this.handleQuestionEnded(event);
        });

        console.log('[Trivia] WebSocket listeners configured');
    }

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

    handleQuestionStarted(event) {
        this.currentQuestion = {
            question: event.question,
            options: event.options,
            currentRound: event.current_round,
            totalRounds: event.total_rounds
        };

        this.hasAnswered = false;
        this.selectedOption = null;

        this.showQuestionActive();
        this.renderQuestion();
        this.updateProgress(0);
        this.resetPlayerStatuses();
    }

    handlePlayerAnswered(event) {
        // Actualizar contador de respuestas
        this.updateProgress(event.answered_count);

        // Marcar jugador como respondido
        this.markPlayerAsAnswered(event.player_id);

        // Mostrar mensaje
        if (event.player_id !== this.playerId) {
            this.showMessage(`${event.player_name} ha respondido`, 'info');
        }
    }

    handleQuestionEnded(event) {
        this.scores = event.scores;

        // Mostrar respuesta correcta
        this.showCorrectAnswer(event.correct_answer);

        // Actualizar puntuaciones
        this.updateScores(event.scores);

        // Mostrar resultados después de 2 segundos
        setTimeout(() => {
            this.showResults(event.correct_answer, event.results);
        }, 2000);
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

            const data = await response.json();

            if (data.success) {
                console.log('[Trivia] Answer sent successfully', data);
            } else {
                console.error('[Trivia] Error sending answer', data);
                this.showMessage(data.error || 'Error al enviar respuesta', 'error');
            }
        } catch (error) {
            console.error('[Trivia] Network error', error);
            this.showMessage('Error de conexión', 'error');
        }
    }

    showCorrectAnswer(correctIndex) {
        const buttons = this.optionsGrid.querySelectorAll('.option-btn');

        buttons.forEach((btn, idx) => {
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
        feedbackMessage.textContent = isCorrect
            ? '¡Correcto! +100 puntos'
            : 'Incorrecto';
    }

    showResults(correctIndex, results) {
        this.questionActive.classList.add('hidden');
        this.questionResults.classList.remove('hidden');

        // Correct answer
        const correctAnswerText = document.getElementById('correct-answer-text');
        correctAnswerText.textContent = this.currentQuestion.options[correctIndex];

        // Players results
        const playersResults = document.getElementById('players-results');
        playersResults.innerHTML = '';

        Object.entries(results).forEach(([playerId, result]) => {
            const player = this.players.find(p => p.id == playerId);
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

        // Countdown to next question
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
            }
        }, 1000);
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
}

// Export to window
window.TriviaGame = TriviaGame;
