/**
 * PresenceChannelManager - Gestión de conexiones en tiempo real
 *
 * Sistema de producción que:
 * 1. Se conecta al Presence Channel de la sala
 * 2. Notifica al backend cuando hay cambios en las conexiones
 * 3. Escucha eventos del backend sobre el estado de las conexiones
 * 4. Dispara callbacks personalizados para que cada vista reaccione
 */
export class PresenceChannelManager {
    constructor(roomCode, options = {}) {
        this.roomCode = roomCode;
        this.connectedUsers = [];
        this.totalPlayers = 0;
        this.channel = null;

        // Callbacks personalizables
        this.onHere = options.onHere || null;
        this.onJoining = options.onJoining || null;
        this.onLeaving = options.onLeaving || null;
        this.onAllConnected = options.onAllConnected || null;
        this.onConnectionChange = options.onConnectionChange || null;

        this.initialize();
    }

    /**
     * Inicializar conexión al Presence Channel y canal de eventos
     */
    initialize() {
        if (typeof window.Echo === 'undefined') {
            console.log('⏳ [Presence] Waiting for Echo to load...');
            setTimeout(() => this.initialize(), 100);
            return;
        }

        console.log('✅ [Presence] Connecting to room:', this.roomCode);

        // Conectar al Presence Channel (para trackear quién está conectado)
        this.channel = window.Echo.join(`room.${this.roomCode}`)
            .here((users) => this.handleHere(users))
            .joining((user) => this.handleJoining(user))
            .leaving((user) => this.handleLeaving(user));

        // Escuchar eventos broadcast en el canal normal de la sala
        window.Echo.channel(`room.${this.roomCode}`)
            .listen('.players.all-connected', (data) => this.handleAllConnected(data));
    }

    /**
     * Handler: Usuarios actualmente conectados
     */
    async handleHere(users) {
        console.log('👥 [Presence:here] Users connected:', users.length);
        this.connectedUsers = users;

        // Callback personalizado
        if (this.onHere) {
            this.onHere(users);
        }

        // Notificar al backend
        await this.notifyBackend(users.length);

        // Callback de cambio de conexión
        if (this.onConnectionChange) {
            this.onConnectionChange(users.length, this.totalPlayers);
        }
    }

    /**
     * Handler: Un usuario se conectó
     */
    async handleJoining(user) {
        console.log('✅ [Presence:joining]', user.name);
        this.connectedUsers.push(user);

        // Callback personalizado
        if (this.onJoining) {
            this.onJoining(user);
        }

        // Notificar al backend
        await this.notifyBackend(this.connectedUsers.length);

        // Callback de cambio de conexión
        if (this.onConnectionChange) {
            this.onConnectionChange(this.connectedUsers.length, this.totalPlayers);
        }
    }

    /**
     * Handler: Un usuario se desconectó
     */
    async handleLeaving(user) {
        console.log('❌ [Presence:leaving]', user.name);
        this.connectedUsers = this.connectedUsers.filter(u => u.id !== user.id);

        // Callback personalizado
        if (this.onLeaving) {
            this.onLeaving(user);
        }

        // Notificar al backend
        await this.notifyBackend(this.connectedUsers.length);

        // Callback de cambio de conexión
        if (this.onConnectionChange) {
            this.onConnectionChange(this.connectedUsers.length, this.totalPlayers);
        }
    }

    /**
     * Handler: Evento del backend - Todos conectados
     */
    handleAllConnected(data) {
        console.log('🎉 [Presence] All players connected!', data);

        // Callback personalizado
        if (this.onAllConnected) {
            this.onAllConnected(data);
        }
    }

    /**
     * Notificar al backend sobre cambios en las conexiones
     */
    async notifyBackend(connectedCount) {
        try {
            const response = await fetch(`/api/rooms/${this.roomCode}/presence/check`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    connected_count: connectedCount,
                }),
            });

            const data = await response.json();

            if (data.success) {
                this.totalPlayers = data.total;
                console.log(`📊 [Presence] Status: ${data.connected}/${data.total}`);
            }
        } catch (error) {
            console.error('❌ [Presence] Error notifying backend:', error);
        }
    }

    /**
     * Obtener usuarios conectados actualmente
     */
    getConnectedUsers() {
        return this.connectedUsers;
    }

    /**
     * Obtener número de usuarios conectados
     */
    getConnectedCount() {
        return this.connectedUsers.length;
    }

    /**
     * Obtener total de jugadores
     */
    getTotalPlayers() {
        return this.totalPlayers;
    }

    /**
     * Desconectar del canal
     */
    disconnect() {
        if (this.channel) {
            window.Echo.leave(`room.${this.roomCode}`);
            console.log('👋 [Presence] Disconnected from room:', this.roomCode);
        }
    }
}
