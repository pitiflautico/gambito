import { BaseGameClient } from '/resources/js/core/BaseGameClient.js';

/**
 * TriviaGameClient - Cliente específico para el juego de Trivia
 *
 * Extiende BaseGameClient e implementa la lógica específica de Trivia:
 * - Mostrar preguntas y opciones
 * - Enviar respuestas con optimistic updates
 * - Bloquear jugador después de responder
 * - Mostrar resultados de ronda
 *
 * Fase 4: WebSocket Bidirectional Communication (Frontend)
 */
export class TriviaGameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        // Referencias a elementos DOM
        this.loadingState = document.getElementById('loading-state');
        this.questionState = document.getElementById('question-state');
        this.lockedOverlay = document.getElementById('locked-overlay');
        this.questionText = document.getElementById('question-text');
        this.categoryText = document.getElementById('category-text');
        this.difficultyBadge = document.getElementById('difficulty-badge');
        this.optionsContainer = document.getElementById('options-container');
        this.currentRoundEl = document.getElementById('current-round');
        this.totalRoundsEl = document.getElementById('total-rounds');
        this.playersAnsweredEl = document.getElementById('players-answered');

        // Estado interno del juego
        this.currentQuestion = null;
        this.hasAnswered = false;
        this.answersCount = 0;

        console.log('🎮 [TriviaGameClient] Initialized');
    }

    /**
     * Override: Manejar inicio de ronda (nueva pregunta)
     */
    handleRoundStarted(event) {
        super.handleRoundStarted(event);

        console.log('❓ [TriviaGameClient] Round started:', event);

        // Extraer pregunta del evento
        // El evento genérico envía game_state completo, necesitamos extraer current_question
        const questionData = event.game_state?.current_question || event;

        console.log('🔍 [TriviaGameClient] questionData:', questionData);
        console.log('🔍 [TriviaGameClient] event:', event);

        this.currentQuestion = {
            question: questionData.question || event.question,
            options: questionData.options || event.options,
            category: questionData.category || event.category || 'General',
            difficulty: questionData.difficulty || event.difficulty || 'medium',
            questionNumber: event.current_round,
            totalQuestions: event.total_rounds
        };

        console.log('🔍 [TriviaGameClient] this.currentQuestion:', this.currentQuestion);

        // Resetear estado de respuesta
        this.hasAnswered = false;
        this.answersCount = 0;

        // Mostrar pregunta
        this.displayQuestion();
    }

    /**
     * Mostrar pregunta en la UI
     */
    displayQuestion() {
        if (!this.currentQuestion) {
            console.warn('⚠️ [TriviaGameClient] No current question to display');
            return;
        }

        console.log('📝 [TriviaGameClient] Displaying question:', this.currentQuestion);

        // Ocultar loading, mostrar pregunta
        this.hideElement('loading-state');
        this.hideElement('locked-overlay');
        this.showElement('question-state');

        // Actualizar información de ronda
        if (this.currentRoundEl) {
            this.currentRoundEl.textContent = this.currentQuestion.questionNumber;
        }
        if (this.totalRoundsEl) {
            this.totalRoundsEl.textContent = this.currentQuestion.totalQuestions;
        }

        // Actualizar categoría
        if (this.categoryText) {
            this.categoryText.textContent = this.currentQuestion.category;
        }

        // Actualizar dificultad
        if (this.difficultyBadge) {
            this.difficultyBadge.textContent = this.currentQuestion.difficulty.toUpperCase();
            this.difficultyBadge.className = this.getDifficultyClass(this.currentQuestion.difficulty);
        }

        // Actualizar texto de pregunta
        if (this.questionText) {
            this.questionText.textContent = this.currentQuestion.question;
        }

        // Renderizar opciones
        this.renderOptions();
    }

    /**
     * Renderizar opciones de respuesta como botones
     */
    renderOptions() {
        if (!this.optionsContainer || !this.currentQuestion) {
            console.warn('⚠️ [TriviaGameClient] Cannot render options');
            return;
        }

        // Limpiar opciones anteriores
        this.optionsContainer.innerHTML = '';

        // Crear botones para cada opción
        this.currentQuestion.options.forEach((option, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'w-full bg-white hover:bg-blue-50 border-2 border-gray-300 hover:border-blue-500 rounded-lg px-6 py-4 text-left font-medium text-gray-800 transition-all duration-150 hover:shadow-md';
            button.dataset.answerIndex = index;
            button.textContent = option;

            // Agregar event listener
            button.addEventListener('click', () => this.submitAnswer(index));

            this.optionsContainer.appendChild(button);
        });

        console.log('✅ [TriviaGameClient] Options rendered:', this.currentQuestion.options.length);
    }

    /**
     * Enviar respuesta del jugador (Fase 4 - con optimistic updates)
     */
    async submitAnswer(answerIndex) {
        // Verificar que no haya respondido ya
        if (this.hasAnswered) {
            console.warn('⚠️ [TriviaGameClient] Already answered this question');
            return;
        }

        console.log(`📤 [TriviaGameClient] Submitting answer: ${answerIndex}`);

        // Marcar como respondido (local)
        this.hasAnswered = true;

        try {
            // Enviar acción con optimistic update
            const result = await this.sendGameAction('answer', {
                answer_index: answerIndex
            }, true); // ← optimistic = true

            console.log('✅ [TriviaGameClient] Answer submitted successfully:', result);

        } catch (error) {
            console.error('❌ [TriviaGameClient] Error submitting answer:', error);

            // El error ya revirtió el optimistic update via revertOptimisticUpdate()
            // Solo necesitamos resetear el flag local
            this.hasAnswered = false;
        }
    }

    /**
     * Aplicar actualización optimista (Fase 4)
     *
     * Se ejecuta INMEDIATAMENTE al hacer click en una respuesta,
     * ANTES de enviar al servidor.
     */
    applyOptimisticUpdate(action, data) {
        if (action === 'answer') {
            console.log('🔄 [TriviaGameClient] Applying optimistic update: locking player');

            // Deshabilitar todos los botones de respuesta
            if (this.optionsContainer) {
                const buttons = this.optionsContainer.querySelectorAll('button');
                buttons.forEach(btn => {
                    btn.disabled = true;
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                    btn.classList.remove('hover:bg-blue-50', 'hover:border-blue-500');
                });
            }

            // Mostrar overlay de "bloqueado"
            this.showElement('locked-overlay');

            console.log('🔒 [TriviaGameClient] Player locked (optimistic)');
        }
    }

    /**
     * Revertir actualización optimista (Fase 4)
     *
     * Se ejecuta si la acción falla en el servidor.
     */
    revertOptimisticUpdate(action, data) {
        if (action === 'answer') {
            console.log('↩️  [TriviaGameClient] Reverting optimistic update: unlocking player');

            // Re-habilitar botones de respuesta
            if (this.optionsContainer) {
                const buttons = this.optionsContainer.querySelectorAll('button');
                buttons.forEach(btn => {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btn.classList.add('hover:bg-blue-50', 'hover:border-blue-500');
                });
            }

            // Ocultar overlay de "bloqueado"
            this.hideElement('locked-overlay');

            console.log('🔓 [TriviaGameClient] Player unlocked (reverted)');
        }
    }

    /**
     * Override: Manejar acción de jugador
     *
     * Este evento se emite cuando CUALQUIER jugador realiza una acción.
     * Lo usamos para actualizar el contador de "X de Y jugadores han respondido"
     */
    handlePlayerAction(event) {
        console.log('👤 [TriviaGameClient] Player action:', event);

        // Si es una respuesta, actualizar contador
        if (event.action_type === 'answer' && event.success) {
            this.answersCount++;
            this.updatePlayersAnsweredDisplay();
        }
    }

    /**
     * Actualizar display de "X de Y jugadores han respondido"
     */
    updatePlayersAnsweredDisplay() {
        if (this.playersAnsweredEl) {
            const totalPlayers = this.players.length;
            this.playersAnsweredEl.textContent = `${this.answersCount} de ${totalPlayers} jugadores han respondido`;
        }
    }

    /**
     * Override: Manejar fin de ronda
     */
    handleRoundEnded(event) {
        super.handleRoundEnded(event);

        console.log('🏁 [TriviaGameClient] Round ended:', event);

        // Resetear estado
        this.hasAnswered = false;
        this.answersCount = 0;
        this.currentQuestion = null;

        // Ocultar pregunta, mostrar loading para siguiente ronda
        this.hideElement('question-state');
        this.hideElement('locked-overlay');
        this.showElement('loading-state');

        // El TimingModule del BaseGameClient manejará el countdown automáticamente
        // y llamará a notifyReadyForNextRound() cuando termine
    }

    /**
     * Override: Manejar fin de juego
     */
    handleGameFinished(event) {
        super.handleGameFinished(event);

        console.log('🏆 [TriviaGameClient] Game finished, showing results:', event);

        // Ocultar todos los demás estados
        this.hideElement('loading-state');
        this.hideElement('question-state');
        this.hideElement('locked-overlay');

        // Mostrar estado de finalizado
        this.showElement('finished-state');

        // Renderizar podio usando método genérico de BaseGameClient
        // BaseGameClient.renderPodium() es reutilizable para todos los juegos
        super.renderPodium(event.ranking, event.scores, 'podium');
    }

    /**
     * Obtener clase CSS según dificultad
     */
    getDifficultyClass(difficulty) {
        const classes = {
            'easy': 'px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800',
            'medium': 'px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800',
            'hard': 'px-2 py-1 text-xs font-semibold rounded bg-red-100 text-red-800'
        };

        return classes[difficulty.toLowerCase()] || classes.medium;
    }

    /**
     * Override: Obtener elemento para countdown (timing module)
     */
    getCountdownElement() {
        // Retornar el elemento donde se mostrará el countdown entre rondas
        const loadingMsg = this.loadingState?.querySelector('h2');
        return loadingMsg || null;
    }
}

// Exportar para uso global
window.TriviaGameClient = TriviaGameClient;
