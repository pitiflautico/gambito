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
        console.log(`[PresenceMonitor] Phase changed: ${this.currentPhase} → ${phase}`);
        this.currentPhase = phase;
    }

    /**
     * Iniciar el monitoreo de presence channel.
     */
    start() {
        if (typeof window.Echo === 'undefined') {
            console.warn('[PresenceMonitor] Laravel Echo not loaded, retrying...');
            setTimeout(() => this.start(), 500);
            return;
        }

        console.log(`[PresenceMonitor] Starting presence monitor for room ${this.roomCode}`);

        this.presenceChannel = window.Echo.join(`room.${this.roomCode}`);

        // Usuarios actualmente conectados
        this.presenceChannel.here((users) => {
            console.log('[PresenceMonitor] Users here:', users.length);
            this.connectedUsers = users;
        });

        // Usuario se unió
        this.presenceChannel.joining((user) => {
            console.log('[PresenceMonitor] User joining:', user.name);

            // Si el usuario estaba marcado como desconectado y ahora vuelve
            if (this.notifiedDisconnections.has(user.id)) {
                this.handlePlayerReconnected(user);
            }

            this.connectedUsers.push(user);
        });

        // Usuario se fue
        this.presenceChannel.leaving((user) => {
            console.log('[PresenceMonitor] User leaving:', user.name);

            this.connectedUsers = this.connectedUsers.filter(u => u.id !== user.id);

            // Siempre notificar al backend - el backend decidirá si debe procesarse
            // (el backend verifica si game_state.phase === 'playing')
            console.log('[PresenceMonitor] Notifying backend of disconnection...');
            this.handlePlayerDisconnected(user);
        });

        console.log('[PresenceMonitor] Presence monitor started successfully');
    }

    /**
     * Manejar desconexión de jugador DURANTE partida.
     */
    async handlePlayerDisconnected(user) {
        console.warn(`[PresenceMonitor] Player disconnected during game: ${user.name}`);

        // Evitar notificar múltiples veces
        if (this.notifiedDisconnections.has(user.id)) {
            console.log('[PresenceMonitor] Already notified, skipping');
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
                console.log('[PresenceMonitor] Server notified of disconnection');
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
        console.log(`[PresenceMonitor] Player reconnected: ${user.name}`);

        // Solo notificar si habíamos notificado la desconexión
        if (!this.notifiedDisconnections.has(user.id)) {
            console.log('[PresenceMonitor] No previous disconnection recorded, skipping');
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
                console.log('[PresenceMonitor] Server notified of reconnection');
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
            console.log('[PresenceMonitor] Presence monitor stopped');
        }
    }
}

// Exportar para uso global
window.PresenceMonitor = PresenceMonitor;
