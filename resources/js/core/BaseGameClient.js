import TimingModule from '../modules/TimingModule.js';

/**
 * BaseGameClient - Clase base para todos los juegos
 *
 * Proporciona funcionalidad común que todos los juegos necesitan:
 * - Gestión de WebSockets (EventManager)
 * - Handlers de eventos genéricos (RoundStarted, RoundEnded, PlayerAction)
 * - Gestión de scores y jugadores
 * - Sistema de mensajes
 * - Sistema de timing (TimingModule)
 *
 * Cada juego extiende esta clase e implementa solo su lógica específica.
 */
export class BaseGameClient {
    constructor(config) {
        // Configuración básica
        this.roomCode = config.roomCode;
        this.playerId = config.playerId;
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

        // Solo mostrar el estado
        console.log('state:', this.gameState?.phase || 'unknown');

        // Notificar al backend que el jugador está conectado (fire-and-forget, no bloqueante)
        this.notifyPlayerConnected().catch(err => {
            console.warn('⚠️ [BaseGameClient] Failed to notify player connected:', err);
        });
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
            handlePlayerConnected: (event) => this.handlePlayerConnected(event),
            handleRoundStarted: (event) => this.handleRoundStarted(event),
            handleRoundEnded: (event) => this.handleRoundEnded(event),
            handlePlayerAction: (event) => this.handlePlayerAction(event),
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
        console.log('🎮 [BaseGameClient] GameStartedEvent received:', event);

        // Actualizar game state con el estado inicial
        this.gameState = event.game_state;

        // Mostrar countdown si existe (solo visual, el backend inicia el round)
        if (event.timing) {
            console.log('⏰ [BaseGameClient] Showing countdown:', event.timing);

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
     * Handler genérico: Jugador conectado
     *
     * Actualiza el contador de jugadores conectados en tiempo real
     */
    handlePlayerConnected(event) {
        console.log('📱 [BaseGameClient] Player connected:', event);

        // Actualizar el contador en la UI
        const statusElement = document.getElementById('connection-status');
        if (statusElement) {
            statusElement.textContent = `(${event.connected_count}/${event.total_players})`;
        }
    }

    /**
     * Handler genérico: Nueva ronda iniciada
     *
     * Este método se ejecuta para TODOS los juegos cuando inicia una ronda.
     * Los juegos específicos pueden sobrescribirlo para añadir lógica custom.
     */
    handleRoundStarted(event) {
        // Actualizar información de ronda
        this.currentRound = event.current_round;
        this.totalRounds = event.total_rounds;

        // Los juegos específicos sobrescriben este método para renderizar su contenido
    }

    /**
     * Handler genérico: Ronda terminada
     *
     * Este método actualiza scores automáticamente para TODOS los juegos
     * y procesa timing metadata para auto-avanzar a la siguiente ronda.
     */
    async handleRoundEnded(event) {
        // Actualizar scores (común para todos los juegos)
        if (event.scores) {
            this.scores = event.scores;
        }

        // Guardar resultados
        this.lastResults = event.results;
        this.lastRoundNumber = event.round_number;

        // Procesar timing metadata si existe
        if (event.timing) {
            console.log('⏰ [BaseGameClient] Processing timing metadata:', event.timing);

            await this.timing.processTimingPoint(
                event.timing,
                () => this.notifyReadyForNextRound(),
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
            console.error(`❌ [BaseGameClient] Error sending action to ${endpoint}:`, error);
            throw error;
        }
    }

    /**
     * Notificar al backend que el jugador está conectado.
     *
     * Se llama automáticamente cuando el jugador carga la página del juego.
     * El backend usa esto para detectar cuándo todos están conectados y
     * transicionar de la fase "starting" al primer round.
     */
    async notifyPlayerConnected() {
        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/player-connected`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    player_id: this.playerId
                })
            });

            const data = await response.json();

            if (response.ok && data.success) {
                // Silencioso, no mostrar nada
            }
        } catch (error) {
            // Silencioso
        }
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
        console.log('📤 [BaseGameClient] Notifying backend: game ready, starting first round');

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
                    console.log('⏸️  [BaseGameClient] Another client is starting the game, waiting for first event...');
                } else {
                    console.log('✅ [BaseGameClient] Successfully started game');
                }
            } else {
                console.error('❌ [BaseGameClient] Error starting game:', data.error);
            }

            // En todos los casos, el cliente se sincronizará con los eventos del juego
        } catch (error) {
            console.error('❌ [BaseGameClient] Network error notifying game ready:', error);
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
    async notifyReadyForNextRound() {
        console.log('📤 [BaseGameClient] Notifying backend: ready for next round');

        try {
            const response = await fetch(`/api/games/${this.matchId}/start-next-round`, {
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
                console.log('✅ [BaseGameClient] Successfully started next round');
            } else if (response.status === 409) {
                // 409 Conflict: Otro cliente ya está iniciando la ronda
                console.log('⏸️  [BaseGameClient] Another client is starting the round, waiting for RoundStartedEvent...');
            } else {
                console.error('❌ [BaseGameClient] Error starting next round:', data.error);
            }

            // En todos los casos, el cliente se sincronizará con RoundStartedEvent
        } catch (error) {
            console.error('❌ [BaseGameClient] Network error notifying next round:', error);
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
        console.log('✅ [BaseGameClient] Game is ready');

        // Los juegos específicos sobrescriben esto
    }
}

// Exportar para que esté disponible globalmente
window.BaseGameClient = BaseGameClient;
