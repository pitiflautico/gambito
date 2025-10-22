/**
 * Event Manager Module
 *
 * Gestiona la comunicación en tiempo real via WebSockets entre el backend (Laravel/Reverb)
 * y el frontend de los juegos.
 *
 * @see app/Services/Modules/EventManager/EventManager.md
 */

class EventManager {
    /**
     * @param {Object} config
     * @param {string} config.roomCode - Código de la sala
     * @param {string} config.gameSlug - Slug del juego (trivia, pictionary, etc)
     * @param {Object} config.eventConfig - Configuración de eventos desde capabilities.json
     * @param {Object} config.handlers - Mapa de handlers: { handleEventName: function }
     * @param {boolean} config.autoConnect - Conectar automáticamente (default: true)
     */
    constructor(config) {
        this.roomCode = config.roomCode;
        this.gameSlug = config.gameSlug;
        this.eventConfig = config.eventConfig || {};
        this.handlers = config.handlers || {};
        this.autoConnect = config.autoConnect !== false;

        this.channel = null;
        this.status = 'disconnected';
        this.listeners = [];

        this.validateConfig();

        console.log('[EventManager] Initialized', {
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            events: Object.keys(this.eventConfig.events || {}),
            autoConnect: this.autoConnect
        });

        if (this.autoConnect) {
            this.connect();
        }
    }

    /**
     * Validar configuración recibida
     */
    validateConfig() {
        if (!this.roomCode) {
            console.error('[EventManager] roomCode is required');
            this.status = 'error';
            return false;
        }

        if (!this.gameSlug) {
            console.error('[EventManager] gameSlug is required');
            this.status = 'error';
            return false;
        }

        if (!this.eventConfig || !this.eventConfig.channel || !this.eventConfig.events) {
            console.error('[EventManager] Invalid eventConfig. Must have channel and events');
            this.status = 'error';
            return false;
        }

        return true;
    }

    /**
     * Establecer conexión con el canal de WebSocket
     */
    connect() {
        if (this.status === 'error') {
            console.error('[EventManager] Cannot connect due to configuration errors');
            return;
        }

        if (!window.Echo) {
            console.error('[EventManager] Laravel Echo not available. Make sure it is loaded.');
            this.status = 'error';
            return;
        }

        this.status = 'connecting';

        // Reemplazar {roomCode} en el nombre del canal
        const channelName = this.eventConfig.channel.replace('{roomCode}', this.roomCode);

        try {
            this.channel = window.Echo.channel(channelName);

            // Registrar listeners automáticamente desde la configuración
            this.registerListeners();

            this.status = 'connected';
            console.log('[EventManager] Connected to channel:', channelName);

            // Callback de conexión exitosa (si existe)
            if (this.handlers.onConnected) {
                this.handlers.onConnected();
            }

        } catch (error) {
            console.error('[EventManager] Error connecting:', error);
            this.status = 'error';

            // Callback de error (si existe)
            if (this.handlers.onError) {
                this.handlers.onError(error);
            }
        }
    }

    /**
     * Registrar listeners para todos los eventos configurados
     */
    registerListeners() {
        if (!this.channel) {
            console.error('[EventManager] Cannot register listeners: channel not connected');
            return;
        }

        Object.entries(this.eventConfig.events).forEach(([eventClass, config]) => {
            const { name, handler } = config;

            if (!this.handlers[handler]) {
                console.warn(`[EventManager] Handler not found: ${handler} for event ${name}`);
                return;
            }

            // Registrar listener
            this.channel.listen(name, (event) => {
                console.log(`[EventManager] Received: ${name}`, event);

                try {
                    this.handlers[handler](event);
                } catch (error) {
                    console.error(`[EventManager] Error in handler ${handler}:`, error);

                    if (this.handlers.onError) {
                        this.handlers.onError(error, { eventName: name, event });
                    }
                }
            });

            this.listeners.push({ eventClass, name, handler });
            console.log(`[EventManager] Registered listener: ${name} → ${handler}`);
        });

        console.log(`[EventManager] Registered ${this.listeners.length} listeners`);
    }

    /**
     * Desconectar del canal de WebSocket
     */
    disconnect() {
        if (this.channel) {
            const channelName = this.eventConfig.channel.replace('{roomCode}', this.roomCode);
            window.Echo.leave(channelName);
            this.channel = null;
            this.listeners = [];
        }

        this.status = 'disconnected';
        console.log('[EventManager] Disconnected');

        // Callback de desconexión (si existe)
        if (this.handlers.onDisconnected) {
            this.handlers.onDisconnected();
        }
    }

    /**
     * Obtener el estado actual de la conexión
     * @returns {string} 'connected', 'connecting', 'disconnected', 'error'
     */
    getConnectionStatus() {
        return this.status;
    }

    /**
     * Verificar si está conectado
     * @returns {boolean}
     */
    isConnected() {
        return this.status === 'connected';
    }

    /**
     * Obtener información de debug
     * @returns {Object}
     */
    getDebugInfo() {
        return {
            roomCode: this.roomCode,
            gameSlug: this.gameSlug,
            status: this.status,
            channel: this.eventConfig.channel?.replace('{roomCode}', this.roomCode),
            registeredListeners: this.listeners.map(l => ({
                event: l.name,
                handler: l.handler
            })),
            availableHandlers: Object.keys(this.handlers)
        };
    }

    /**
     * Emitir evento (para uso futuro - client-to-client)
     * Nota: Actualmente los juegos usan HTTP API para enviar acciones al servidor
     */
    emit(eventName, payload) {
        console.warn('[EventManager] emit() not implemented. Use HTTP API to send actions to server.');
        console.log('[EventManager] Would emit:', eventName, payload);
    }
}

// Export para ES6 modules
export default EventManager;

// Export para uso sin bundler (window.EventManager)
if (typeof window !== 'undefined') {
    window.EventManager = EventManager;
}
