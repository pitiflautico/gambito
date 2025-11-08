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
        this.publicChannel = null;
        this.publicChannelSubscribed = false;
        this.gameStartCheckInterval = null;

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
            console.log('[LobbyManager] ⏳ Echo no disponible, reintentando...');
            setTimeout(() => this.initializeWebSocket(), 100);
            return;
        }

        // Verificar que la conexión WebSocket esté establecida
        const pusher = window.Echo.connector.pusher;
        if (!pusher || pusher.connection.state !== 'connected') {
            console.log('[LobbyManager] ⏳ WebSocket no conectado aún, estado:', pusher?.connection?.state);
            // Esperar a que se conecte
            pusher.connection.bind('connected', () => {
                console.log('[LobbyManager] ✅ WebSocket conectado, inicializando listeners...');
                this.setupChannelListeners();
            });
            return;
        }

        // Si ya está conectado, configurar listeners inmediatamente
        this.setupChannelListeners();
    }

    /**
     * Configurar listeners de eventos en los canales
     */
    setupChannelListeners() {
        console.log('[LobbyManager] Configurando listeners de eventos para room:', this.roomCode);

        // IMPORTANTE: Hay DOS eventos diferentes:
        // 1. App\Events\GameStartedEvent - Se emite desde GameMatch::start() en canal público
        // 2. App\Events\Game\GameStartedEvent - Se emite desde PlayController en PresenceChannel
        // 
        // El evento del lobby es el primero, que se emite en canal público
        // Pero también escuchamos en Presence Channel por si acaso

        // Escuchar en canal público (para GameStartedEvent del lobby)
        const channelName = `room.${this.roomCode}`;
        console.log('[LobbyManager] Suscribiendo a canal público:', channelName);
        
        this.publicChannel = window.Echo.channel(channelName);
        
        // Verificar que el canal se haya creado correctamente
        if (!this.publicChannel) {
            console.error('[LobbyManager] ❌ No se pudo crear el canal público');
            return;
        }

        // Función para manejar la redirección cuando se recibe el evento
        const handleGameStarted = (data, source) => {
            console.log(`🎮 [LobbyManager] ✅ Game started event received (${source}), redirecting...`, data);
            // Limpiar intervalo de verificación si existe
            if (this.gameStartCheckInterval) {
                clearInterval(this.gameStartCheckInterval);
                this.gameStartCheckInterval = null;
            }
            window.location.replace(`/rooms/${this.roomCode}`);
        };

        // Registrar listener para el evento game.started
        this.publicChannel.listen('.game.started', (data) => {
            handleGameStarted(data, 'public channel');
        });

        // También escuchar eventos de suscripción del canal para confirmar que está listo
        const pusher = window.Echo.connector.pusher;
        pusher.bind('pusher:subscription_succeeded', (data) => {
            if (data.channel === channelName) {
                console.log('[LobbyManager] ✅ Canal público suscrito correctamente:', channelName);
                this.publicChannelSubscribed = true;
            }
        });

        // También escuchar errores de suscripción
        pusher.bind('pusher:subscription_error', (data) => {
            if (data.channel === channelName) {
                console.error('[LobbyManager] ❌ Error al suscribirse al canal público:', data);
            }
        });

        // El listener del Presence Channel se configura en setupPresenceChannelListener()
        // para evitar duplicación de código

        // Listener global para capturar eventos incluso si el canal no está suscrito aún
        // Esto es especialmente importante en producción donde puede haber problemas de timing
        pusher.bind_global((eventName, data) => {
            // Capturar game.started desde cualquier canal (público o presence)
            if (eventName === '.game.started' || eventName === 'game.started' || 
                (eventName.includes('game.started') && data && data.room_code === this.roomCode)) {
                console.log('[LobbyManager] 🔍 Evento global detectado:', eventName, data);
                
                // Verificar que es para esta sala
                if (data && (data.room_code === this.roomCode || data.roomCode === this.roomCode)) {
                    console.log('🎮 [LobbyManager] ✅ Game started event received (global listener), redirecting...', data);
                    // Limpiar intervalo de verificación si existe
                    if (this.gameStartCheckInterval) {
                        clearInterval(this.gameStartCheckInterval);
                        this.gameStartCheckInterval = null;
                    }
                window.location.replace(`/rooms/${this.roomCode}`);
                }
            }
        });

        console.log('[LobbyManager] ✅ WebSocket listeners initialized for game.started (both channels)');
        
        // Marcar el canal como suscrito después de un breve delay (fallback)
        // En producción, a veces la suscripción puede tardar más
            setTimeout(() => {
            if (!this.publicChannelSubscribed) {
                console.log('[LobbyManager] ⚠️ Canal público no confirmó suscripción, asumiendo suscrito después de timeout');
                this.publicChannelSubscribed = true;
            }
        }, 2000);
    }

    /**
     * Iniciar verificación periódica del estado del juego (fallback para producción)
     * Se usa si el evento WebSocket no llega por algún problema de red
     */
    startGameStartPolling() {
        // Solo iniciar polling si es el master y no hay intervalo activo
        if (!this.isMaster || this.gameStartCheckInterval) {
            return;
        }

        console.log('[LobbyManager] Iniciando polling de verificación de inicio de juego (fallback)');
        let checkCount = 0;
        const maxChecks = 20; // 20 checks = 10 segundos (cada 500ms)

        this.gameStartCheckInterval = setInterval(() => {
            checkCount++;
            
            // Verificar estado del juego desde el servidor
            fetch(`/api/rooms/${this.roomCode}/state`)
                .then(response => response.json())
                .then(data => {
                    // Si el estado es 'active' o 'playing', el juego ha iniciado
                    if (data.status === 'active' || data.status === 'playing') {
                        console.log('[LobbyManager] ✅ Juego iniciado detectado vía polling, redirigiendo...');
                        if (this.gameStartCheckInterval) {
                            clearInterval(this.gameStartCheckInterval);
                            this.gameStartCheckInterval = null;
                        }
                        window.location.replace(`/rooms/${this.roomCode}`);
                    }
                })
                .catch(error => {
                    console.error('[LobbyManager] Error en polling:', error);
                });

            // Detener después de maxChecks
            if (checkCount >= maxChecks) {
                console.log('[LobbyManager] ⏹️ Polling detenido después de', maxChecks, 'intentos');
                if (this.gameStartCheckInterval) {
                    clearInterval(this.gameStartCheckInterval);
                    this.gameStartCheckInterval = null;
                }
            }
        }, 500); // Verificar cada 500ms
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

        // IMPORTANTE: Suscribirse al canal público INMEDIATAMENTE para no perder eventos
        // No esperar a que el Presence Channel esté completamente conectado
        // El evento game.started puede emitirse muy rápido después de hacer clic en "Iniciar Partida"
        this.initializeWebSocket();
        
        // También configurar listener en el Presence Channel como respaldo
        // El Presence Channel ya está conectado, así que podemos escuchar eventos ahí también
        if (this.presenceManager && this.presenceManager.channel) {
            this.setupPresenceChannelListener();
        } else {
            // Si aún no tenemos el channel, esperar un poco y reintentar
        setTimeout(() => {
                if (this.presenceManager && this.presenceManager.channel) {
                    this.setupPresenceChannelListener();
                }
        }, 500);
        }
    }

    /**
     * Configurar listener en el Presence Channel como respaldo
     */
    setupPresenceChannelListener() {
        if (!this.presenceManager || !this.presenceManager.channel) {
            return;
        }

        console.log('[LobbyManager] Configurando listener en Presence Channel (backup)');
        
        // Función para manejar la redirección
        const handleGameStarted = (data, source) => {
            console.log(`🎮 [LobbyManager] ✅ Game started event received (${source}), redirecting...`, data);
            // Limpiar intervalo de verificación si existe
            if (this.gameStartCheckInterval) {
                clearInterval(this.gameStartCheckInterval);
                this.gameStartCheckInterval = null;
            }
            window.location.replace(`/rooms/${this.roomCode}`);
        };

        // Escuchar game.started en el Presence Channel también
        this.presenceManager.channel.listen('.game.started', (data) => {
            handleGameStarted(data, 'presence channel backup');
        });
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
