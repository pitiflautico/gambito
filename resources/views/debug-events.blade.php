<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Debug WebSocket Events - {{ $roomCode }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .panel {
            background: #2a2a2a;
            border: 2px solid #00ff00;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        h1, h2 {
            color: #00ff00;
            text-shadow: 0 0 10px #00ff00;
        }
        button {
            background: #00ff00;
            color: #1a1a1a;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin: 5px;
            font-family: 'Courier New', monospace;
        }
        button:hover {
            background: #00cc00;
            box-shadow: 0 0 10px #00ff00;
        }
        .log {
            background: #000;
            border: 1px solid #00ff00;
            border-radius: 4px;
            padding: 10px;
            max-height: 400px;
            overflow-y: auto;
            font-size: 12px;
            margin-top: 10px;
        }
        .log-entry {
            margin: 5px 0;
            padding: 5px;
            border-left: 3px solid #00ff00;
            padding-left: 10px;
        }
        .log-entry.success { border-left-color: #00ff00; color: #00ff00; }
        .log-entry.error { border-left-color: #ff0000; color: #ff0000; }
        .log-entry.info { border-left-color: #00ccff; color: #00ccff; }
        .log-entry.warning { border-left-color: #ffaa00; color: #ffaa00; }
        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 4px;
            margin: 5px;
            font-weight: bold;
        }
        .status.connected { background: #00ff00; color: #000; }
        .status.connecting { background: #ffaa00; color: #000; }
        .status.disconnected { background: #ff0000; color: #fff; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 10px;
        }
        pre {
            background: #000;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 11px;
        }
        .timestamp {
            color: #666;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <h1>🔧 DEBUG: WebSocket Events - Sala {{ $roomCode }}</h1>

    <!-- Status Panel -->
    <div class="panel">
        <h2>📡 Estado de Conexión</h2>
        <div>
            <strong>Canal:</strong> <span id="channel-name">room.{{ $roomCode }}</span>
            <span id="connection-status" class="status disconnected">DESCONECTADO</span>
        </div>
        <div style="margin-top: 10px;">
            <strong>EventManager:</strong> <span id="eventmanager-status">Cargando...</span>
        </div>
        <div style="margin-top: 10px;">
            <strong>Echo:</strong> <span id="echo-status">Cargando...</span>
        </div>
        <div style="margin-top: 10px;">
            <strong>Listeners Registrados:</strong> <span id="listeners-count">0</span>
        </div>
    </div>

    <!-- Controls Panel -->
    <div class="panel">
        <h2>🎮 Disparar Eventos de Prueba</h2>
        <div>
            <button onclick="fireEvent('round-started')">🎯 Disparar RoundStartedEvent</button>
            <button onclick="fireEvent('round-ended')">🏁 Disparar RoundEndedEvent</button>
            <button onclick="fireEvent('player-action')">👤 Disparar PlayerActionEvent</button>
        </div>
        <div style="margin-top: 10px;">
            <button onclick="checkEventManager()">🔍 Verificar EventManager</button>
            <button onclick="clearLogs()">🗑️ Limpiar Logs</button>
            <button onclick="testEcho()">📡 Test Echo</button>
        </div>
    </div>

    <!-- Logs Panel -->
    <div class="panel">
        <h2>📜 Event Logs (Tiempo Real)</h2>
        <div id="event-logs" class="log">
            <div class="log-entry info">Esperando eventos...</div>
        </div>
    </div>

    <!-- Debug Info Panel -->
    <div class="panel">
        <h2>🔍 Debug Info</h2>
        <div class="grid">
            <div>
                <strong>window.Echo:</strong>
                <pre id="debug-echo">Verificando...</pre>
            </div>
            <div>
                <strong>window.EventManager:</strong>
                <pre id="debug-eventmanager">Verificando...</pre>
            </div>
            <div>
                <strong>Configuración de Eventos:</strong>
                <pre id="debug-eventconfig">{{ json_encode($eventConfig, JSON_PRETTY_PRINT) }}</pre>
            </div>
        </div>
    </div>

    <script>
        const roomCode = '{{ $roomCode }}';
        const eventConfig = @json($eventConfig);
        let eventManager = null;
        let logsContainer = document.getElementById('event-logs');

        // ====================================================================
        // LOGGING
        // ====================================================================

        function addLog(message, type = 'info') {
            const entry = document.createElement('div');
            entry.className = `log-entry ${type}`;
            const timestamp = new Date().toLocaleTimeString();
            entry.innerHTML = `<span class="timestamp">[${timestamp}]</span> ${message}`;
            logsContainer.insertBefore(entry, logsContainer.firstChild);

            // Keep only last 50 logs
            while (logsContainer.children.length > 50) {
                logsContainer.removeChild(logsContainer.lastChild);
            }
        }

        function clearLogs() {
            logsContainer.innerHTML = '<div class="log-entry info">Logs limpiados. Esperando eventos...</div>';
        }

        // ====================================================================
        // STATUS UPDATES
        // ====================================================================

        function updateConnectionStatus(status) {
            const statusEl = document.getElementById('connection-status');
            statusEl.textContent = status.toUpperCase();
            statusEl.className = `status ${status}`;
        }

        function updateDebugInfo() {
            document.getElementById('debug-echo').textContent = typeof window.Echo !== 'undefined'
                ? 'Disponible ✅'
                : 'No disponible ❌';

            document.getElementById('debug-eventmanager').textContent = typeof window.EventManager !== 'undefined'
                ? 'Disponible ✅'
                : 'No disponible ❌';

            document.getElementById('eventmanager-status').textContent = eventManager
                ? `Inicializado (${eventManager.getConnectionStatus()})`
                : 'No inicializado';

            document.getElementById('echo-status').textContent = window.Echo
                ? 'Conectado ✅'
                : 'No disponible ❌';

            if (eventManager) {
                const debugInfo = eventManager.getDebugInfo();
                document.getElementById('listeners-count').textContent = debugInfo.registeredListeners.length;
            }
        }

        // ====================================================================
        // EVENT MANAGER SETUP
        // ====================================================================

        function setupEventManager() {
            addLog('🔧 Inicializando EventManager...', 'info');

            if (!window.EventManager) {
                addLog('❌ ERROR: window.EventManager no está disponible', 'error');
                return;
            }

            try {
                eventManager = new window.EventManager({
                    roomCode: roomCode,
                    gameSlug: 'trivia',
                    eventConfig: eventConfig,
                    handlers: {
                        handleRoundStarted: handleRoundStarted,
                        handleRoundEnded: handleRoundEnded,
                        handlePlayerAction: handlePlayerAction,
                        handlePhaseChanged: handlePhaseChanged,
                        handleTurnChanged: handleTurnChanged,
                        handleGameFinished: handleGameFinished,
                        onConnected: () => {
                            addLog('✅ EventManager conectado', 'success');
                            updateConnectionStatus('connected');
                        },
                        onError: (error) => {
                            addLog(`❌ Error: ${error.message}`, 'error');
                            updateConnectionStatus('error');
                        },
                        onDisconnected: () => {
                            addLog('⚠️ EventManager desconectado', 'warning');
                            updateConnectionStatus('disconnected');
                        }
                    }
                });

                addLog('✅ EventManager inicializado correctamente', 'success');
                updateConnectionStatus('connected');
            } catch (error) {
                addLog(`❌ Error al inicializar EventManager: ${error.message}`, 'error');
                console.error(error);
            }

            updateDebugInfo();
        }

        // ====================================================================
        // EVENT HANDLERS
        // ====================================================================

        function handleRoundStarted(event) {
            addLog('═══════════════════════════════════════', 'success');
            addLog('🎯 EVENTO RECIBIDO: RoundStartedEvent', 'success');
            addLog(`   📊 Ronda: ${event.current_round}/${event.total_rounds}`, 'success');
            addLog(`   📍 Fase: ${event.phase}`, 'success');
            addLog(`   🎮 Game State: ${JSON.stringify(event.game_state).substring(0, 100)}...`, 'success');
            addLog('═══════════════════════════════════════', 'success');
            console.log('🎯 RoundStartedEvent:', event);
        }

        function handleRoundEnded(event) {
            addLog('═══════════════════════════════════════', 'success');
            addLog('🏁 EVENTO RECIBIDO: RoundEndedEvent', 'success');
            addLog(`   🔢 Ronda: ${event.round_number}`, 'success');
            addLog(`   🏆 Resultados: ${Object.keys(event.results).length} jugadores`, 'success');
            addLog(`   💯 Scores: ${JSON.stringify(event.scores)}`, 'success');
            addLog('═══════════════════════════════════════', 'success');
            console.log('🏁 RoundEndedEvent:', event);
        }

        function handlePlayerAction(event) {
            addLog('═══════════════════════════════════════', 'info');
            addLog('👤 EVENTO RECIBIDO: PlayerActionEvent', 'info');
            addLog(`   🆔 Player: ${event.player_name} (ID: ${event.player_id})`, 'info');
            addLog(`   ⚡ Acción: ${event.action_type}`, 'info');
            addLog(`   ✅ Exitosa: ${event.success}`, 'info');
            addLog('═══════════════════════════════════════', 'info');
            console.log('👤 PlayerActionEvent:', event);
        }

        function handlePhaseChanged(event) {
            addLog(`🔄 PhaseChangedEvent: ${event.previous_phase} → ${event.new_phase}`, 'info');
            console.log('🔄 PhaseChangedEvent:', event);
        }

        function handleTurnChanged(event) {
            addLog(`🔁 TurnChangedEvent: Jugador ${event.current_player_name}`, 'info');
            console.log('🔁 TurnChangedEvent:', event);
        }

        function handleGameFinished(event) {
            addLog('🎊 EVENTO RECIBIDO: GameFinishedEvent', 'success');
            console.log('🎊 GameFinishedEvent:', event);
        }

        // ====================================================================
        // CONTROLS
        // ====================================================================

        async function fireEvent(eventType) {
            addLog(`🚀 Disparando evento: ${eventType}...`, 'warning');

            try {
                const response = await fetch(`/debug/fire-event/${roomCode}/${eventType}`);
                const data = await response.json();

                if (data.success) {
                    addLog(`✅ Evento ${data.event} disparado correctamente`, 'success');
                    addLog(`   📡 Canal: ${data.channel}`, 'info');
                    addLog(`   🎯 Broadcast: ${data.broadcast_as}`, 'info');
                } else {
                    addLog(`❌ Error: ${data.error}`, 'error');
                }
            } catch (error) {
                addLog(`❌ Error de red: ${error.message}`, 'error');
            }
        }

        function checkEventManager() {
            if (!eventManager) {
                addLog('❌ EventManager no está inicializado', 'error');
                return;
            }

            const debugInfo = eventManager.getDebugInfo();
            addLog('🔍 Debug Info:', 'info');
            addLog(`   Room Code: ${debugInfo.roomCode}`, 'info');
            addLog(`   Game Slug: ${debugInfo.gameSlug}`, 'info');
            addLog(`   Status: ${debugInfo.status}`, 'info');
            addLog(`   Channel: ${debugInfo.channel}`, 'info');
            addLog(`   Listeners: ${debugInfo.registeredListeners.length}`, 'info');

            debugInfo.registeredListeners.forEach(listener => {
                addLog(`      - ${listener.event} → ${listener.handler}`, 'info');
            });

            console.log('EventManager Debug Info:', debugInfo);
        }

        function testEcho() {
            if (!window.Echo) {
                addLog('❌ window.Echo no está disponible', 'error');
                return;
            }

            addLog('📡 Probando conexión Echo...', 'info');
            addLog(`   Canal: room.${roomCode}`, 'info');

            try {
                const channel = window.Echo.channel(`room.${roomCode}`);
                addLog('✅ Canal de Echo creado correctamente', 'success');
                console.log('Echo channel:', channel);
            } catch (error) {
                addLog(`❌ Error al crear canal: ${error.message}`, 'error');
            }
        }

        // ====================================================================
        // INITIALIZATION
        // ====================================================================

        document.addEventListener('DOMContentLoaded', () => {
            addLog('🚀 Iniciando Debug Panel...', 'info');
            addLog(`📡 Sala: ${roomCode}`, 'info');
            addLog(`🎮 Canal: room.${roomCode}`, 'info');

            // Wait for Echo to be ready
            setTimeout(() => {
                setupEventManager();
            }, 1000);

            // Update debug info every 2 seconds
            setInterval(updateDebugInfo, 2000);
        });
    </script>
</body>
</html>
