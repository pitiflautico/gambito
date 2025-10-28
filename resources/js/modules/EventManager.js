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

        if (this.autoConnect) {
            this.connect();
        }
    }

    /**
     * Validar configuración recibida
     */
    validateConfig() {
        if (!this.roomCode) {

            this.status = 'error';
            return false;
        }

        if (!this.gameSlug) {

            this.status = 'error';
            return false;
        }

        if (!this.eventConfig || !this.eventConfig.channel || !this.eventConfig.events) {

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
            return;
        }

        if (!window.Echo) {
            this.status = 'error';
            return;
        }

        this.status = 'connecting';

        // Reemplazar {roomCode} en el nombre del canal
        const channelName = this.eventConfig.channel.replace('{roomCode}', this.roomCode);

        try {
            // Usar Presence Channel para trackear conexiones automáticamente
            // Los canales que empiezan con "room." son Presence Channels
            // Laravel añade automáticamente el prefijo "presence-" internamente
            const isPresenceChannel = channelName.startsWith('presence-') || channelName.startsWith('room.');

            if (isPresenceChannel) {
                this.channel = window.Echo.join(channelName);

                // Trackear quién está conectado
                this.channel
                    .here((users) => {
                        if (this.handlers.onUsersHere) {
                            this.handlers.onUsersHere(users);
                        }
                    })
                    .joining((user) => {
                        if (this.handlers.onUserJoining) {
                            this.handlers.onUserJoining(user);
                        }
                    })
                    .leaving((user) => {
                        if (this.handlers.onUserLeaving) {
                            this.handlers.onUserLeaving(user);
                        }
                    });
            } else {
                // Canal privado normal
                this.channel = window.Echo.private(channelName);
            }

            // Registrar listeners automáticamente desde la configuración
            this.registerListeners();

            this.status = 'connected';

            // Callback de conexión exitosa (si existe)
            if (this.handlers.onConnected) {
                this.handlers.onConnected();
            }

        } catch (error) {
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
            return;
        }

        Object.entries(this.eventConfig.events).forEach(([eventClass, config]) => {
            const { name, handler } = config;

            if (!this.handlers[handler]) {
                return;
            }

            // Registrar listener
            // Laravel Echo requiere un punto inicial para eventos personalizados
            const eventName = name.startsWith('.') ? name : `.${name}`;
            this.channel.listen(eventName, (event) => {
                try {
                    this.handlers[handler](event);
                } catch (error) {

                    if (this.handlers.onError) {
                        this.handlers.onError(error, { eventName: name, event });
                    }
                }
            });

            this.listeners.push({ eventClass, name, handler });
        });
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


    }
}

// Export para ES6 modules
export default EventManager;

// Export para uso sin bundler (window.EventManager)
if (typeof window !== 'undefined') {
    window.EventManager = EventManager;
}
