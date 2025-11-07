/**
 * LobbyManager - Gestión del Lobby de espera
 *
 * Responsabilidades:
 * 1. Gestionar Presence Channel (conexiones/desconexiones)
 * 2. Actualizar lista de jugadores dinámicamente
 * 3. Controlar estado del botón "Iniciar Partida" (Master)
 * 4. NO hacer location.reload() - todo es dinámico
 */
export class LobbyManager {
    constructor(roomCode, options = {}) {
        this.roomCode = roomCode;
        this.isMaster = options.isMaster || false;
        this.maxPlayers = options.maxPlayers || 10;
        this.presenceManager = null;
        this.allConnectedLogged = false;

        this.initialize();
    }

    /**
     * Inicializar Presence Channel y WebSocket
     */
    initialize() {
        // Primero inicializar Presence Channel, luego WebSocket listeners
        this.initializePresenceChannel();
    }

    /**
     * Conectar al canal de WebSocket para eventos del juego
     */
    initializeWebSocket() {
        if (typeof window.Echo === 'undefined') {
            setTimeout(() => this.initializeWebSocket(), 100);
            return;
        }

        // IMPORTANTE: GameStartedEvent se emite en PresenceChannel, no en canal público
        // Usar el mismo Presence Channel que ya tenemos para escuchar eventos
        if (!this.presenceManager) {
            // Si aún no tenemos presenceManager, esperar un poco
            setTimeout(() => this.initializeWebSocket(), 100);
            return;
        }

        // Obtener el canal presence del PresenceChannelManager
        const presenceChannel = this.presenceManager.channel;
        
        if (!presenceChannel) {
            console.warn('[LobbyManager] Presence channel not available yet');
            setTimeout(() => this.initializeWebSocket(), 100);
            return;
        }

        // IMPORTANTE: NO escuchamos .player.joined/.player.left
        // El Presence Channel ya maneja esto automáticamente
        // Hacer location.reload() causa desconexiones

        // Evento: Partida iniciada (se emite en PresenceChannel)
        presenceChannel.listen('.game.started', (data) => {
            console.log('🎮 [LobbyManager] Game started event received, redirecting...', data);
            window.location.replace(`/rooms/${this.roomCode}`);
        });

        console.log('[LobbyManager] WebSocket listeners initialized for game.started');
    }

    /**
     * Inicializar Presence Channel Manager
     */
    initializePresenceChannel() {
        if (typeof window.PresenceChannelManager === 'undefined') {
            setTimeout(() => this.initializePresenceChannel(), 100);
            return;
        }

        this.presenceManager = new window.PresenceChannelManager(this.roomCode, {
            onHere: (users) => this.handleHere(users),
            onJoining: (user) => this.handleJoining(user),
            onLeaving: (user) => this.handleLeaving(user),
            onAllConnected: (data) => this.handleAllConnected(data),
            onConnectionChange: (connected, total) => this.handleConnectionChange(connected, total),
        });

        // Una vez que el Presence Channel esté inicializado, configurar listeners de eventos
        // Esperar un poco para asegurar que el canal esté completamente conectado
        setTimeout(() => {
            this.initializeWebSocket();
        }, 500);
    }

    /**
     * Handler: Usuarios actualmente conectados
     */
    handleHere(users) {
        this.updatePlayerConnectionStatus(users);

        // 🔥 FIX: Actualizar contador con datos del Presence Channel
        if (this.presenceManager) {
            const connected = this.presenceManager.connectedUsers.length;
            this.updatePlayerCount(connected, this.maxPlayers);
        }
    }

    /**
     * Handler: Usuario se unió
     */
    handleJoining(user) {
        this.addPlayerToList(user);

        if (this.presenceManager) {
            this.updatePlayerConnectionStatus(this.presenceManager.connectedUsers);

            // 🔥 FIX: Actualizar contador con datos del Presence Channel
            const connected = this.presenceManager.connectedUsers.length;
            this.updatePlayerCount(connected, this.maxPlayers);
        }
    }

    /**
     * Handler: Usuario se fue
     */
    handleLeaving(user) {
        this.removePlayerFromList(user);

        if (this.presenceManager) {
            this.updatePlayerConnectionStatus(this.presenceManager.connectedUsers);

            // 🔥 FIX: Actualizar contador con datos del Presence Channel
            const connected = this.presenceManager.connectedUsers.length;
            this.updatePlayerCount(connected, this.maxPlayers);
        }
    }

    /**
     * Handler: Todos los jugadores mínimos conectados
     */
    handleAllConnected(data) {
        this.allConnectedLogged = true;
        this.updateStartGameButton(data.connected, data.total);
        this.updatePlayerCount(data.connected, this.maxPlayers);
    }

    /**
     * Handler: Cambio en número de conexiones
     */
    handleConnectionChange(connected, total) {
        this.updateStartGameButton(connected, total);
        this.updatePlayerCount(connected, this.maxPlayers);
        if (connected < total) {
            this.allConnectedLogged = false;
        }
    }

    /**
     * Actualizar badges de conexión de jugadores
     */
    updatePlayerConnectionStatus(connectedUsers) {
        const playerElements = document.querySelectorAll('[data-player-id]');

        playerElements.forEach(playerDiv => {
            const playerId = parseInt(playerDiv.dataset.playerId);
            const isConnected = connectedUsers.some(u => u.id === playerId);

            const connectionDiv = playerDiv.querySelector('.flex.items-center.space-x-2');
            const statusBadge = connectionDiv ? connectionDiv.querySelector('span') : null;

            if (!statusBadge) return;

            if (isConnected) {
                statusBadge.textContent = '● Conectado';
                statusBadge.className = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800';
            } else {
                statusBadge.textContent = '○ Desconectado';
                statusBadge.className = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800';
            }
        });

        // Actualizar botón si es master
        if (this.presenceManager) {
            this.updateStartGameButton(
                this.presenceManager.getConnectedCount(),
                this.presenceManager.getTotalPlayers()
            );
        }
    }

    /**
     * Actualizar estado del botón "Iniciar Partida" (solo Master)
     */
    updateStartGameButton(connected, minPlayers) {
        const button = document.getElementById('start-game-button');
        const status = document.getElementById('start-game-status');

        // Si no es master, no hacer nada
        if (!button || !status) return;

        const canStart = connected >= minPlayers;

        if (canStart) {
            // Activar botón
            button.disabled = false;
            button.className = 'w-full px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 font-semibold';

            // Mensaje positivo
            status.className = 'mb-3 p-3 bg-green-50 rounded-lg text-sm';
            status.innerHTML = `
                <p class="text-green-800 font-medium">✅ ¡Listo para empezar!</p>
                <p class="text-green-600 text-xs mt-1">${connected} jugador${connected > 1 ? 'es' : ''} conectado${connected > 1 ? 's' : ''} (mínimo: ${minPlayers})</p>
            `;

            this.allConnectedLogged = true;
        } else {
            // Desactivar botón
            button.disabled = true;
            button.className = 'w-full px-6 py-3 bg-gray-300 text-gray-500 rounded-lg cursor-not-allowed font-semibold';

            // Mensaje de espera
            const faltantes = minPlayers - connected;
            status.className = 'mb-3 p-3 bg-yellow-50 rounded-lg text-sm';
            status.innerHTML = `
                <p class="text-yellow-800 font-medium">⏳ Esperando jugadores...</p>
                <p class="text-yellow-600 text-xs mt-1">${connected} de ${minPlayers} mínimos conectados${faltantes > 0 ? ' (faltan ' + faltantes + ')' : ''}</p>
            `;

            this.allConnectedLogged = false;
        }
    }

    /**
     * Agregar jugador a la lista dinámicamente (SIN reload)
     */
    addPlayerToList(user) {
        const playersList = document.getElementById('players-list');
        if (!playersList) {
            console.warn('⚠️ players-list not found');
            return;
        }

        // Verificar si ya existe
        const existingPlayer = document.querySelector(`[data-player-id="${user.id}"]`);
        if (existingPlayer) {
            this.updatePlayerConnectionStatus([user]);
            return;
        }

        const playerHtml = `
            <div
                class="flex items-center justify-between p-3 bg-gray-50 rounded-lg ${this.isMaster ? 'cursor-move hover:bg-gray-100' : ''}"
                data-player-id="${user.id}"
                ${this.isMaster ? 'draggable="true" ondragstart="handleDragStart(event)" ondragend="handleDragEnd(event)"' : ''}
            >
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                        ${user.name.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <p class="font-medium">${user.name}</p>
                        ${user.role ? `<p class="text-xs text-gray-500">${user.role}</p>` : ''}
                        <p class="text-xs text-purple-600 font-medium player-team-badge" data-player-id="${user.id}">
                        </p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        ● Conectado
                    </span>
                </div>
            </div>
        `;

        playersList.insertAdjacentHTML('beforeend', playerHtml);
        // ✅ NO actualizar contador aquí - se actualiza desde handleJoining() con datos del Presence
    }

    /**
     * Quitar jugador de la lista dinámicamente (SIN reload)
     */
    removePlayerFromList(user) {
        const playerElement = document.querySelector(`[data-player-id="${user.id}"]`);

        if (playerElement) {
            playerElement.remove();
            // ✅ NO actualizar contador aquí - se actualiza desde handleLeaving() con datos del Presence
        } else {
            console.warn('⚠️ Player not found in list:', user.id);
        }
    }

    /**
     * Actualizar contador de jugadores en el header "Jugadores (X/Y)"
     *
     * @param {number} connected - Número de jugadores conectados (del Presence Channel)
     * @param {number} total - Máximo de jugadores de la sala (NO el mínimo del Presence!)
     */
    updatePlayerCount(connected = null, total = null) {
        const header = document.getElementById('players-header');
        if (!header) {
            console.warn('⚠️ [Lobby] players-header not found');
            return;
        }

        // Usar connected del Presence Channel (número real de jugadores conectados)
        // Usar total = maxPlayers de la sala (capacidad máxima, ej: 10)
        const currentCount = connected !== null ? connected :
            document.getElementById('players-list')?.querySelectorAll('[data-player-id]').length || 0;
        const totalCount = total !== null ? total : this.maxPlayers;

        // Actualizar solo si el número cambió
        const currentText = header.textContent;
        const newText = `Jugadores (${currentCount}/${totalCount})`;

        if (currentText !== newText) {
            header.textContent = newText;
        }
    }

    /**
     * Desconectar del Presence Channel
     */
    disconnect() {
        if (this.presenceManager) {
            this.presenceManager.disconnect();
        }
    }
}
