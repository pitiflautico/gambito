/**
 * PresenceMonitor - Módulo para detectar desconexiones/reconexiones durante partida
 *
 * Este módulo se encarga de:
 * 1. Escuchar eventos de presence channel (leaving/joining)
 * 2. Detectar si el juego está en fase "playing"
 * 3. Notificar al backend cuando hay desconexiones/reconexiones durante el juego
 * 4. El backend emitirá PlayerDisconnectedEvent/PlayerReconnectedEvent
 *
 * Uso:
 * const presenceMonitor = new PresenceMonitor(roomCode, gamePhase);
 * presenceMonitor.start();
 */
export default class PresenceMonitor {
    constructor(roomCode, initialPhase = 'waiting') {
        this.roomCode = roomCode;
        this.currentPhase = initialPhase;
        this.presenceChannel = null;
        this.connectedUsers = [];
        this.notifiedDisconnections = new Set(); // Evitar notificar múltiples veces
    }

    /**
     * Actualizar la fase del juego.
     *
     * Debe ser llamado cuando el juego cambia de fase (waiting → playing → finished)
     */
    setPhase(phase) {
        this.currentPhase = phase;
    }

    /**
     * Iniciar el monitoreo de presence channel.
     *
     * @returns {Promise} Promesa que se resuelve cuando el channel está conectado
     */
    start() {
        return new Promise((resolve) => {
            if (typeof window.Echo === 'undefined') {
                console.warn('[PresenceMonitor] Laravel Echo not loaded, retrying...');
                setTimeout(() => this.start().then(resolve), 500);
                return;
            }

            this.presenceChannel = window.Echo.join(`room.${this.roomCode}`);

            // Usuarios actualmente conectados
            // Este callback se ejecuta cuando el channel está COMPLETAMENTE conectado
            this.presenceChannel.here((users) => {
                this.connectedUsers = users;
                // Silencioso - channel conectado

                // Resolver la promesa - el channel está listo
                resolve();
            });

            // Usuario se unió
            this.presenceChannel.joining((user) => {
                // Si el usuario estaba marcado como desconectado y ahora vuelve
                if (this.notifiedDisconnections.has(user.id)) {
                    this.handlePlayerReconnected(user);
                }

                this.connectedUsers.push(user);
            });

            // Usuario se fue
            this.presenceChannel.leaving((user) => {
                this.connectedUsers = this.connectedUsers.filter(u => u.id !== user.id);

                // Siempre notificar al backend - el backend decidirá si debe procesarse
                // (el backend verifica si game_state.phase === 'playing')
                this.handlePlayerDisconnected(user);
            });
        });
    }

    /**
     * Manejar desconexión de jugador DURANTE partida.
     */
    async handlePlayerDisconnected(user) {
        console.warn(`[PresenceMonitor] Player disconnected during game: ${user.name}`);

        // Evitar notificar múltiples veces
        if (this.notifiedDisconnections.has(user.id)) {
            return;
        }

        this.notifiedDisconnections.add(user.id);

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/player-disconnected`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    player_id: user.id
                })
            });

            const data = await response.json();

            if (data.success) {
                // El servidor emitirá PlayerDisconnectedEvent → BaseGameClient lo manejará
            } else {
                console.error('[PresenceMonitor] Server rejected disconnection notification:', data.message);
                // Remover de notificaciones si falló
                this.notifiedDisconnections.delete(user.id);
            }

        } catch (error) {
            console.error('[PresenceMonitor] Error notifying disconnection:', error);
            // Remover de notificaciones si falló
            this.notifiedDisconnections.delete(user.id);
        }
    }

    /**
     * Manejar reconexión de jugador.
     */
    async handlePlayerReconnected(user) {

        // Solo notificar si habíamos notificado la desconexión
        if (!this.notifiedDisconnections.has(user.id)) {
            return;
        }

        this.notifiedDisconnections.delete(user.id);

        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/player-reconnected`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    player_id: user.id
                })
            });

            const data = await response.json();

            if (data.success) {
                // El servidor emitirá PlayerReconnectedEvent → BaseGameClient lo manejará
            } else {
                console.error('[PresenceMonitor] Server rejected reconnection notification:', data.message);
            }

        } catch (error) {
            console.error('[PresenceMonitor] Error notifying reconnection:', error);
        }
    }

    /**
     * Detener el monitoreo.
     */
    stop() {
        if (this.presenceChannel) {
            window.Echo.leave(`room.${this.roomCode}`);
            this.presenceChannel = null;
        }
    }
}

// Exportar para uso global
window.PresenceMonitor = PresenceMonitor;
