/**
 * BaseGameClient - Clase base para todos los juegos
 *
 * Proporciona funcionalidad común que todos los juegos necesitan:
 * - Gestión de WebSockets (EventManager)
 * - Handlers de eventos genéricos (RoundStarted, RoundEnded, PlayerAction)
 * - Gestión de scores y jugadores
 * - Sistema de mensajes
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
     * Este método se ejecuta cuando el juego comienza (después de que el
     * master presiona "Iniciar Juego" en el lobby).
     *
     * Se recibe el estado inicial completo del juego.
     */
    handleGameStarted(event) {
        console.log('🎮 [BaseGameClient] GameStartedEvent received:', event);

        // Actualizar game state con el estado inicial
        this.gameState = event.game_state;

        // Los juegos específicos pueden sobrescribir este método
        // para hacer transiciones de UI, mostrar mensajes, etc.
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
     * Este método actualiza scores automáticamente para TODOS los juegos.
     */
    handleRoundEnded(event) {
        // Actualizar scores (común para todos los juegos)
        if (event.scores) {
            this.scores = event.scores;
        }

        // Guardar resultados
        this.lastResults = event.results;
        this.lastRoundNumber = event.round_number;

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
}

// Exportar para que esté disponible globalmente
window.BaseGameClient = BaseGameClient;
