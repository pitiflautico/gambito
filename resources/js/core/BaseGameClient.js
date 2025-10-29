import TimingModule from '../modules/TimingModule.js';
import PresenceMonitor from '../modules/PresenceMonitor.js';

/**
 * BaseGameClient - Clase base para todos los juegos
 *
 * Proporciona funcionalidad común que todos los juegos necesitan:
 * - Gestión de WebSockets (EventManager)
 * - Handlers de eventos genéricos (RoundStarted, RoundEnded, PlayerAction)
 * - Gestión de scores y jugadores
 * - Sistema de mensajes
 * - Sistema de timing (TimingModule)
 * - Monitoreo de presencia (PresenceMonitor)
 *
 * Cada juego extiende esta clase e implementa solo su lógica específica.
 */
export class BaseGameClient {
    constructor(config) {
        // Configuración básica
        this.roomCode = config.roomCode;
        this.playerId = config.playerId;
        this.userId = config.userId; // Necesario para canales privados
        this.matchId = config.matchId;
        this.gameSlug = config.gameSlug;

        // Datos del juego
        this.players = config.players || [];
        this.scores = config.scores || {};
        this.gameState = config.gameState || null;
        this.eventConfig = config.eventConfig || null;

        // Estado interno
        this.currentRound = 1;
        this.totalRounds = 10;

        // Inicializar TimingModule
        this.timing = new TimingModule();
        this.timing.configure(config.timing || {});

        // Inicializar PresenceMonitor
        this.presenceMonitor = new PresenceMonitor(
            this.roomCode,
            this.gameState?.phase || 'waiting'
        );
        this.presenceMonitor.start();

        // Solo mostrar el estado
    }

    /**
     * Configurar EventManager y registrar handlers
     *
     * Los juegos específicos deben llamar a este método y pueden sobrescribir handlers
     */
    setupEventManager(customHandlers = {}) {
        // Handlers por defecto que todos los juegos usan
        const defaultHandlers = {
            handleGameStarted: (event) => this.handleGameStarted(event),
            handleRoundStarted: (event) => this.handleRoundStarted(event),
            handleRoundEnded: (event) => this.handleRoundEnded(event),
            handlePlayerAction: (event) => this.handlePlayerAction(event),
            handlePhaseChanged: (event) => this.handlePhaseChanged(event),
            handleTurnChanged: (event) => this.handleTurnChanged(event),
            handleGameFinished: (event) => this.handleGameFinished(event),
            handlePlayerDisconnected: (event) => this.handlePlayerDisconnected(event),
            handlePlayerReconnected: (event) => this.handlePlayerReconnected(event),
            handlePlayerScoreUpdated: (event) => this.handlePlayerScoreUpdated(event),
        };

        // Combinar handlers por defecto con handlers custom del juego
        const handlers = { ...defaultHandlers, ...customHandlers };

        this.eventManager = new window.EventManager({
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            eventConfig: this.eventConfig,
            handlers: handlers
        });
    }

    // ========================================================================
    // HANDLERS DE EVENTOS GENÉRICOS
    // ========================================================================

    /**
     * Handler genérico: Juego iniciado
     *
     * Este método se ejecuta cuando el juego comienza.
     * Simplemente actualiza el estado y muestra el countdown si existe.
     * El backend se encargará de iniciar el primer round automáticamente.
     */
    async handleGameStarted(event) {
        // Actualizar game state con el estado inicial
        this.gameState = event.game_state;

        // Mostrar countdown si existe (solo visual, el backend inicia el round)
        if (event.timing) {
            await this.timing.processTimingPoint(
                event.timing,
                () => this.notifyGameReady(),
                this.getCountdownElement()
            );
        }

        // Los juegos específicos pueden sobrescribir este método
        // para hacer transiciones de UI, mostrar mensajes, etc.
    }


    /**
     * Handler genérico: Nueva ronda iniciada
     *
     * Este método se ejecuta para TODOS los juegos cuando inicia una ronda.
     * Los juegos específicos pueden sobrescribirlo para añadir lógica custom.
     */
    async handleRoundStarted(event) {
        // Actualizar información de ronda
        this.currentRound = event.current_round;
        this.totalRounds = event.total_rounds;

        // Actualizar fase a "playing" cuando empieza la primera ronda
        if (this.presenceMonitor && this.currentRound === 1) {
            this.presenceMonitor.setPhase('playing');
        }

        // NOTA: El timer ya NO se inicia aquí, se inicia en handlePhaseChanged()
        // cuando llega PhaseChangedEvent después de RoundStartedEvent.
        // Esto es porque ahora SIEMPRE hay fases (mínimo 1), y el timer es de fase, no de ronda.

        // Los juegos específicos sobrescriben este método para renderizar su contenido
    }

    /**
     * Handler genérico: Ronda terminada
     *
     * Este método actualiza scores automáticamente para TODOS los juegos
     * y procesa timing metadata para auto-avanzar a la siguiente ronda.
     */
    async handleRoundEnded(event) {
        // Emitir evento para que los módulos se encarguen (ej: TimingModule cancela sus timers)
        window.dispatchEvent(new CustomEvent('game:round:ended', {
            detail: event
        }));

        // Actualizar scores (común para todos los juegos)
        if (event.scores) {
            this.scores = event.scores;
        }

        // Guardar resultados
        this.lastResults = event.results;
        this.lastRoundNumber = event.round_number;

        // 🔥 RACE CONDITION FIX: Capturar currentRound AHORA
        // Durante el countdown, otro jugador puede avanzar la ronda y actualizar this.currentRound
        // Por eso capturamos el valor aquí y lo usamos en el callback
        const fromRound = this.currentRound;

        // Procesar timing metadata si existe
        if (event.timing) {
            await this.timing.processTimingPoint(
                event.timing,
                () => this.notifyReadyForNextRound(fromRound),
                this.getCountdownElement()
            );
        }

        // Los juegos específicos sobrescriben este método para mostrar resultados
    }

    /**
     * Handler genérico: Acción de jugador
     *
     * Útil para mostrar indicadores de "X está jugando..."
     */
    handlePlayerAction(event) {
        // Los juegos pueden usar esto para mostrar feedback visual
    }

    /**
     * Handler genérico: Cambio de fase
     *
     * Se ejecuta cuando el juego cambia de fase (ej: lobby -> playing -> finished)
     */
    handlePhaseChanged(event) {
        console.log('🎯 [BaseGameClient] handlePhaseChanged called', event);

        // Actualizar fase en PresenceMonitor
        if (this.presenceMonitor && event.new_phase) {
            this.presenceMonitor.setPhase(event.new_phase);
        }

        // Iniciar timer de fase si viene timing metadata en additional_data
        console.log('🔍 [BaseGameClient] Checking timer data:', {
            has_additional_data: !!event.additional_data,
            has_server_time: !!event.additional_data?.server_time,
            has_duration: !!event.additional_data?.duration,
            additional_data: event.additional_data
        });

        if (event.additional_data?.server_time && event.additional_data?.duration) {
            const timerElement = this.getTimerElement();
            console.log('🔍 [BaseGameClient] Timer element found:', !!timerElement, timerElement);

            if (timerElement) {
                // Convertir duración de segundos a milisegundos
                const durationMs = event.additional_data.duration * 1000;

                // Usar nombre de fase para el timer (o 'phase' por defecto)
                const timerName = event.new_phase ? `phase_${event.new_phase}` : 'phase';

                console.log('⏰ [BaseGameClient] Starting timer:', {
                    timerName,
                    server_time: event.additional_data.server_time,
                    durationMs,
                    duration_sec: event.additional_data.duration
                });

                this.timing.startServerSyncedCountdown(
                    event.additional_data.server_time,
                    durationMs,
                    timerElement,
                    () => this.onPhaseTimerExpired(event.new_phase), // Callback cuando expira
                    timerName
                );

                console.log(`⏰ [BaseGameClient] Phase timer started: ${timerName} (${event.additional_data.duration}s)`);
            } else {
                console.warn('⚠️ [BaseGameClient] Timer element not found (id="timer")');
            }
        } else {
            console.warn('⚠️ [BaseGameClient] No timing data in event');
        }

        // Los juegos específicos sobrescriben este método para manejar transiciones de fase
    }

    /**
     * Handler genérico: Cambio de turno
     *
     * Se ejecuta cuando cambia el turno en juegos por turnos
     */
    handleTurnChanged(event) {
        // Los juegos específicos sobrescriben este método para actualizar UI de turnos
    }

    /**
     * Handler genérico: Juego terminado
     *
     * Se ejecuta cuando el juego finaliza
     */
    handleGameFinished(event) {
        // Detener PresenceMonitor (ya no necesitamos monitorear desconexiones)
        if (this.presenceMonitor) {
            this.presenceMonitor.stop();
        }

        // Los juegos específicos sobrescriben este método para mostrar pantalla de resultados finales
    }

    /**
     * Handler genérico: Jugador desconectado
     *
     * Se ejecuta cuando un jugador se desconecta DURANTE la partida.
     * Por defecto, muestra un popup indicando que el juego está pausado.
     */
    handlePlayerDisconnected(event) {

        // Emitir evento para que los módulos se encarguen (ej: TimingModule cancela sus timers)
        // También usado por el Blade component para mostrar el popup de desconexión
        window.dispatchEvent(new CustomEvent('game:player:disconnected', {
            detail: event
        }));

        // Los juegos específicos pueden sobrescribir este método para lógica custom
    }

    /**
     * Handler genérico: Jugador reconectado
     *
     * Se ejecuta cuando un jugador se reconecta.
     * Por defecto, oculta el popup y espera a que el backend reinicie la ronda.
     */
    handlePlayerReconnected(event) {

        // Dispatch custom event para que el Blade component oculte el popup
        window.dispatchEvent(new CustomEvent('game:player:reconnected', {
            detail: event
        }));

        // El backend se encarga de reiniciar la ronda automáticamente
        // handleRoundStarted() será llamado cuando llegue RoundStartedEvent

        // Los juegos específicos pueden sobrescribir este método para lógica custom
    }

    /**
     * Handler genérico: Score de jugador actualizado
     *
     * Se ejecuta cuando un jugador gana o pierde puntos.
     * Actualiza automáticamente this.scores y la UI si existe.
     */
    handlePlayerScoreUpdated(event) {

        const { player_id, new_score, points_earned } = event;

        // Actualizar scores en memoria
        this.scores[player_id] = new_score;

        // Actualizar UI si existe un elemento con id player-score-{playerId}
        const scoreElement = document.getElementById(`player-score-${player_id}`);
        if (scoreElement) {
            scoreElement.textContent = new_score;

            // Agregar animación si ganó puntos
            if (points_earned > 0) {
                scoreElement.classList.add('score-increase');
                setTimeout(() => {
                    scoreElement.classList.remove('score-increase');
                }, 500);
            }
        }

        // Los juegos específicos pueden sobrescribir este método para lógica custom
        // (ej: mostrar notificación, actualizar ranking, etc.)
    }

    // ========================================================================
    // MÉTODOS DE UTILIDAD COMUNES
    // ========================================================================

    /**
     * Obtener jugador por ID
     */
    getPlayer(playerId) {
        return this.players.find(p => String(p.id) === String(playerId));
    }

    /**
     * Obtener jugador actual
     */
    getCurrentPlayer() {
        return this.getPlayer(this.playerId);
    }

    /**
     * Obtener score de un jugador
     */
    getPlayerScore(playerId) {
        return this.scores[playerId] || 0;
    }

    /**
     * Mostrar mensaje en consola (los juegos pueden sobrescribir para mostrar en UI)
     */
    showMessage(message, type = 'info') {
        // Los juegos pueden sobrescribir esto para mostrar mensajes en UI
    }

    /**
     * Ocultar elemento del DOM.
     *
     * @param {string} id - ID del elemento
     */
    hideElement(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.add('hidden');
        } else {
        }
    }

    /**
     * Mostrar elemento del DOM.
     *
     * @param {string} id - ID del elemento
     */
    showElement(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.remove('hidden');
        } else {
        }
    }

    /**
     * Enviar acción al backend
     *
     * Método genérico para enviar cualquier acción del juego
     */
    async sendAction(endpoint, data) {
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    ...data,
                    room_code: this.roomCode
                })
            });

            const result = await response.json();
            return result;
        } catch (error) {
            throw error;
        }
    }

    // ========================================================================
    // GAME ACTIONS - Fase 4: WebSocket Bidirectional Communication
    // ========================================================================

    /**
     * Enviar acción de juego al backend (Fase 4).
     *
     * Este método encapsula el envío de acciones del jugador al servidor.
     * Soporta actualizaciones optimistas para mejor UX.
     *
     * @param {string} action - Nombre de la acción (ej: 'answer', 'play_card', 'draw')
     * @param {object} data - Datos de la acción
     * @param {boolean} optimistic - Si true, aplica actualización optimista antes de enviar
     * @returns {Promise<object>} Resultado de la acción
     */
    async sendGameAction(action, data = {}, optimistic = false) {
        // Aplicar actualización optimista si está habilitada
        if (optimistic) {
            this.applyOptimisticUpdate(action, data);
        }

        try {
            // Por ahora usa HTTP POST (Fase 3 backend está listo)
            // En el futuro se puede cambiar a WebSocket sin tocar el resto del código
            const response = await fetch(`/api/rooms/${this.roomCode}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    action: action,
                    data: data
                })
            });

            const result = await response.json();

            if (!result.success) {

                // Revertir actualización optimista si falló
                if (optimistic) {
                    this.revertOptimisticUpdate(action, data);
                }
            }

            return result;

        } catch (error) {

            // Revertir actualización optimista si hubo error
            if (optimistic) {
                this.revertOptimisticUpdate(action, data);
            }

            throw error;
        }
    }

    /**
     * Aplicar actualización optimista (Fase 4).
     *
     * Este método se ejecuta ANTES de enviar la acción al servidor,
     * para dar feedback inmediato al usuario.
     *
     * Los juegos específicos deben sobrescribir este método para implementar
     * su lógica de actualización optimista (ej: deshabilitar botones, mostrar loading).
     *
     * @param {string} action - Nombre de la acción
     * @param {object} data - Datos de la acción
     */
    applyOptimisticUpdate(action, data) {
        // Stub method - los juegos específicos sobrescriben esto
    }

    /**
     * Revertir actualización optimista (Fase 4).
     *
     * Este método se ejecuta si la acción falla en el servidor,
     * para revertir los cambios optimistas aplicados.
     *
     * Los juegos específicos deben sobrescribir este método para revertir
     * sus cambios optimistas (ej: re-habilitar botones, ocultar loading).
     *
     * @param {string} action - Nombre de la acción
     * @param {object} data - Datos de la acción
     */
    revertOptimisticUpdate(action, data) {
        // Stub method - los juegos específicos sobrescriben esto
    }

    // ========================================================================
    // TIMING MODULE - Race Condition Protection
    // ========================================================================

    /**
     * Notificar al backend que el countdown de inicio ha terminado y el juego puede comenzar.
     *
     * Race Condition Protection:
     * - Todos los jugadores llaman a este endpoint cuando el countdown de GameStarted termina
     * - El backend usa un lock mechanism para que solo el primer cliente ejecute onGameStart()
     * - Los demás clientes reciben 409 Conflict y esperan el evento del juego (ej: QuestionStartedEvent)
     */
    async notifyGameReady() {
        try {
            const response = await fetch(`/api/games/${this.matchId}/game-ready`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    room_code: this.roomCode
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                if (data.already_processing) {
                    // Otro cliente ya está iniciando el juego (normal, no es error)
                }
            } else {
            }

            // En todos los casos, el cliente se sincronizará con los eventos del juego
        } catch (error) {
        }
    }

    /**
     * Notificar al backend que el frontend está listo para la siguiente ronda.
     *
     * Race Condition Protection:
     * - Todos los jugadores llaman a este endpoint cuando su countdown termina
     * - El backend usa un lock mechanism para que solo el primer cliente avance
     * - Los demás clientes reciben 409 Conflict y se sincronizan con RoundStartedEvent
     * - Esto previene avanzar la ronda múltiples veces
     */
    async notifyReadyForNextRound(fromRound = null) {
        // Si no se especifica fromRound, usar currentRound actual (fallback)
        const roundToSend = fromRound !== null ? fromRound : this.currentRound;

        try {
            const response = await fetch(`/api/games/${this.matchId}/start-next-round`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    room_code: this.roomCode,
                    from_round: roundToSend  // ← RACE CONDITION PROTECTION: usar valor capturado
                })
            });

            const data = await response.json();

            if (response.status === 409) {
                // 409 Conflict: Otro cliente ya está iniciando la ronda
            } else if (!response.ok || !data.success) {
            }

            // En todos los casos, el cliente se sincronizará con RoundStartedEvent
        } catch (error) {
        }
    }

    /**
     * Obtener elemento DOM donde mostrar countdown.
     *
     * Los juegos específicos deben sobrescribir este método para retornar
     * el elemento donde quieren mostrar el countdown de timing.
     *
     * Ejemplo en TriviaGame:
     * getCountdownElement() {
     *     return this.questionWaiting.querySelector('p');
     * }
     *
     * @returns {HTMLElement|null} Elemento DOM o null si no hay
     */
    getCountdownElement() {
        // Por defecto, retornar null (no mostrar countdown)
        // Los juegos específicos sobrescriben esto
        return null;
    }

    /**
     * Callback ejecutado cuando termina el countdown de inicio de juego.
     *
     * Los juegos específicos pueden sobrescribir este método para:
     * - Cambiar el mensaje a "¡Ha empezado la partida!"
     * - Iniciar la primera ronda
     * - Hacer transiciones de UI
     */
    onGameReady() {
        // Los juegos específicos sobrescriben esto
    }

    // ========================================================================
    // UI HELPERS - Pantalla de Resultados Finales
    // ========================================================================

    /**
     * Renderizar podio de resultados finales (genérico para todos los juegos).
     *
     * Este método muestra el ranking final con:
     * - Medallas para top 3 (🥇🥈🥉)
     * - Colores según posición
     * - Nombre y puntuación de cada jugador
     *
     * @param {Array} ranking - Array de {position, player_id, score}
     * @param {Object} scores - Objeto {playerId: score}
     * @param {string} containerId - ID del contenedor DOM (default: 'podium')
     */
    renderPodium(ranking, scores, containerId = 'podium') {
        const podiumContainer = document.getElementById(containerId);
        if (!podiumContainer) {
            return;
        }

        podiumContainer.innerHTML = '';

        ranking.forEach((entry, index) => {
            const player = this.getPlayer(entry.player_id);
            const playerName = player ? player.name : `Jugador ${entry.player_id}`;
            const score = entry.score;
            const position = index + 1;

            // Emojis según posición
            const medals = ['🥇', '🥈', '🥉'];
            const medal = position <= 3 ? medals[position - 1] : `${position}º`;

            // Colores según posición
            const colors = {
                1: 'bg-yellow-100 border-yellow-400 text-yellow-900',
                2: 'bg-gray-100 border-gray-400 text-gray-900',
                3: 'bg-orange-100 border-orange-400 text-orange-900',
            };
            const colorClass = colors[position] || 'bg-blue-50 border-blue-300 text-blue-900';

            const playerCard = document.createElement('div');
            playerCard.className = `border-2 ${colorClass} rounded-lg p-4 mb-3 flex items-center justify-between`;

            playerCard.innerHTML = `
                <div class="flex items-center gap-3">
                    <span class="text-3xl">${medal}</span>
                    <div class="text-left">
                        <p class="font-bold text-lg">${playerName}</p>
                        <p class="text-sm opacity-75">Posición ${position}</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold">${score}</p>
                    <p class="text-sm opacity-75">puntos</p>
                </div>
            `;

            podiumContainer.appendChild(playerCard);
        });
    }

    // ========================================================================
    // TIMER SYSTEM METHODS
    // ========================================================================

    /**
     * Obtener elemento para mostrar el timer.
     *
     * Los juegos específicos pueden sobrescribir este método para especificar
     * dónde se muestra el countdown del timer.
     *
     * @returns {HTMLElement|null} Elemento donde mostrar el timer
     */
    getTimerElement() {
        // Por defecto busca un elemento con id="timer"
        return document.getElementById('timer');
    }

    /**
     * Callback cuando el timer de fase expira.
     *
     * Este método se ejecuta en el frontend cuando el countdown de fase llega a 0.
     * Notifica al backend para que ejecute checkTimerAndAutoAdvance().
     */
    async onPhaseTimerExpired(phaseName) {
        console.log(`⏰ [BaseGameClient] Phase timer expired: ${phaseName}`);

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
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                },
                body: JSON.stringify({
                    phase: phaseName,
                    timestamp: Date.now()
                })
            });

            if (!response.ok) {
                console.error('❌ [BaseGameClient] Error checking timer:', response.statusText);
            }
        } catch (error) {
            console.error('❌ [BaseGameClient] Failed to notify backend about timer expiration:', error);
        }
    }

    /**
     * Callback cuando el timer de ronda expira.
     *
     * Este método se ejecuta en el frontend cuando el countdown llega a 0.
     * Notifica al backend para que ejecute checkTimerAndAutoAdvance(), que
     * llama internamente a completeRound() si el timer realmente expiró.
     *
     * Flujo correcto: check-timer → checkTimerAndAutoAdvance() → completeRound()
     * → RoundEndedEvent → advance → RoundStartedEvent
     *
     * @param {number} roundNumber - Número de ronda que expiró
     */
    async onTimerExpired(roundNumber) {
        // Mostrar mensaje visual
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
                    from_round: roundNumber
                })
            });

            const result = await response.json();

            if (!result.success) {
            }
        } catch (error) {
        }

        // Los juegos específicos pueden sobrescribir este método
        // para agregar efectos visuales o sonoros adicionales
    }

    // ========================================================================
    // STATE RESTORATION
    // ========================================================================

    /**
     * Restaurar estado del juego desde gameState
     *
     * Este método base restaura automáticamente:
     * - Round actual desde round_system
     * - Scores desde player_state_system o scoring_system
     * - Fase actual
     *
     * Los juegos específicos pueden sobrescribir este método para
     * restaurar estado adicional específico del juego.
     *
     * @param {Object} gameState - Estado del juego desde el backend
     */
    restoreGameState(gameState) {
        console.log('[BaseGameClient] Restoring game state:', gameState);

        // Guardar referencia al game state
        this.gameState = gameState;

        // Restaurar round actual desde round_system
        if (gameState.round_system) {
            this.currentRound = gameState.round_system.current_round || 1;
            this.totalRounds = gameState.round_system.total_rounds || 10;

            // Actualizar UI de ronda si existe
            this.updateElement('current-round', this.currentRound);
            this.updateElement('total-rounds', this.totalRounds);
        }

        // Restaurar scores desde player_state_system o scoring_system
        if (gameState.player_state_system?.scores) {
            this.scores = gameState.player_state_system.scores;
        } else if (gameState.scoring_system?.scores) {
            this.scores = gameState.scoring_system.scores;
        }

        // Restaurar fase actual
        if (gameState.phase) {
            this.updateElement('current-phase', gameState.phase);
        }

        // Los juegos específicos deben sobrescribir este método
        // y llamar a super.restoreGameState(gameState) primero
        console.log('[BaseGameClient] State restored - round:', this.currentRound, 'scores:', this.scores);
    }

    /**
     * Helper: Actualizar contenido de un elemento del DOM
     */
    updateElement(id, content) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = content;
        }
    }
}

// Exportar para que esté disponible globalmente
window.BaseGameClient = BaseGameClient;
