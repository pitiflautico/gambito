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
        console.log('🎮 [Lobby] Initializing...', {
            roomCode: this.roomCode,
            isMaster: this.isMaster,
        });

        this.initializeWebSocket();
        this.initializePresenceChannel();
    }

    /**
     * Conectar al canal de WebSocket para eventos del juego
     */
    initializeWebSocket() {
        if (typeof window.Echo === 'undefined') {
            console.log('⏳ [Lobby] Waiting for Echo to load...');
            setTimeout(() => this.initializeWebSocket(), 100);
            return;
        }

        console.log('📡 [Lobby] Connecting to WebSocket channel...');
        const channel = window.Echo.channel(`room.${this.roomCode}`);

        // IMPORTANTE: NO escuchamos .player.joined/.player.left
        // El Presence Channel ya maneja esto automáticamente
        // Hacer location.reload() causa desconexiones

        // Evento: Partida iniciada
        channel.listen('.game.started', (data) => {
            console.log('🎮 [Lobby] Game started, redirecting...');
            window.location.replace(`/rooms/${this.roomCode}`);
        });

        console.log('✅ [Lobby] WebSocket listeners registered');
    }

    /**
     * Inicializar Presence Channel Manager
     */
    initializePresenceChannel() {
        if (typeof window.PresenceChannelManager === 'undefined') {
            console.log('⏳ [Lobby] Waiting for PresenceChannelManager...');
            setTimeout(() => this.initializePresenceChannel(), 100);
            return;
        }

        console.log('✅ [Lobby] Initializing PresenceChannelManager...');

        this.presenceManager = new window.PresenceChannelManager(this.roomCode, {
            onHere: (users) => this.handleHere(users),
            onJoining: (user) => this.handleJoining(user),
            onLeaving: (user) => this.handleLeaving(user),
            onAllConnected: (data) => this.handleAllConnected(data),
            onConnectionChange: (connected, total) => this.handleConnectionChange(connected, total),
        });
    }

    /**
     * Handler: Usuarios actualmente conectados
     */
    handleHere(users) {
        console.log('👥 [Lobby] Users here:', users.length);
        this.updatePlayerConnectionStatus(users);
    }

    /**
     * Handler: Usuario se unió
     */
    handleJoining(user) {
        console.log('✅ [Lobby] User joining:', user.name);
        this.addPlayerToList(user);

        if (this.presenceManager) {
            this.updatePlayerConnectionStatus(this.presenceManager.connectedUsers);
        }
    }

    /**
     * Handler: Usuario se fue
     */
    handleLeaving(user) {
        console.log('❌ [Lobby] User leaving:', user.name);
        this.removePlayerFromList(user);

        if (this.presenceManager) {
            this.updatePlayerConnectionStatus(this.presenceManager.connectedUsers);
        }
    }

    /**
     * Handler: Todos los jugadores mínimos conectados
     */
    handleAllConnected(data) {
        if (!this.allConnectedLogged) {
            console.log('🎉 [Lobby] All players connected!', data);
            this.allConnectedLogged = true;
        }
        this.updateStartGameButton(data.connected, data.total);
    }

    /**
     * Handler: Cambio en número de conexiones
     */
    handleConnectionChange(connected, total) {
        this.updateStartGameButton(connected, total);
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

            if (!this.allConnectedLogged) {
                console.log('🎉 [Master] Puede iniciar la partida', `${connected}/${minPlayers}`);
                this.allConnectedLogged = true;
            }
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
            console.log('ℹ️ Player already in list:', user.id);
            this.updatePlayerConnectionStatus([user]);
            return;
        }

        console.log('➕ Adding player to list:', user.name);

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
        this.updatePlayerCount();
    }

    /**
     * Quitar jugador de la lista dinámicamente (SIN reload)
     */
    removePlayerFromList(user) {
        const playerElement = document.querySelector(`[data-player-id="${user.id}"]`);

        if (playerElement) {
            console.log('➖ Removing player from list:', user.name);
            playerElement.remove();
            this.updatePlayerCount();
        } else {
            console.warn('⚠️ Player not found in list:', user.id);
        }
    }

    /**
     * Actualizar contador de jugadores
     */
    updatePlayerCount() {
        const playersList = document.getElementById('players-list');
        if (!playersList) return;

        const currentCount = playersList.querySelectorAll('[data-player-id]').length;
        const header = document.querySelector('h3.text-lg.font-semibold.mb-4');

        if (header) {
            header.textContent = `Jugadores (${currentCount}/${this.maxPlayers})`;
        }

        console.log(`📊 Updated player count: ${currentCount}/${this.maxPlayers}`);
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
