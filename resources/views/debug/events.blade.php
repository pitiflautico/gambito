<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - Presence Channel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @vite(['resources/js/app.js'])
</head>
<body class="bg-gray-900 text-white">
    <div class="container mx-auto p-6">
        <h1 class="text-3xl font-bold mb-6">🐛 Presence Channel Debug Panel</h1>
        <p class="text-gray-400 mb-6">Panel para testear conexiones WebSocket, Presence Channel y estado de jugadores conectados.</p>

        <!-- Quick URLs -->
        <div class="bg-blue-900 border-2 border-blue-600 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">🔗 Quick URLs (click to copy)</h2>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <p class="text-gray-300 text-sm mb-2">Debug Panel</p>
                    <button onclick="copyToClipboard('{{ url('/debug/game-events/' . $roomCode) }}')"
                            class="w-full bg-blue-700 hover:bg-blue-600 px-4 py-2 rounded font-mono text-sm text-left truncate">
                        {{ url('/debug/game-events/' . $roomCode) }}
                    </button>
                </div>
                <div>
                    <p class="text-gray-300 text-sm mb-2">Join Room (code: {{ $roomCode }})</p>
                    <button onclick="copyToClipboard('{{ url('/rooms/join?code=' . $roomCode) }}')"
                            class="w-full bg-blue-700 hover:bg-blue-600 px-4 py-2 rounded font-mono text-sm text-left truncate">
                        {{ url('/rooms/join?code=' . $roomCode) }}
                    </button>
                </div>
                <div>
                    <p class="text-gray-300 text-sm mb-2">Lobby</p>
                    <button onclick="copyToClipboard('{{ url('/rooms/' . $roomCode . '/lobby') }}')"
                            class="w-full bg-blue-700 hover:bg-blue-600 px-4 py-2 rounded font-mono text-sm text-left truncate">
                        {{ url('/rooms/' . $roomCode . '/lobby') }}
                    </button>
                </div>
            </div>
            <p class="text-xs text-blue-300 mt-3">💡 Tip: Open Join Room URL in incognito windows to test multiple players</p>
        </div>

        <!-- Room Info -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">📍 Room Info</h2>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <p class="text-gray-400">Room Code:</p>
                    <p class="text-2xl font-mono" id="room-code">{{ $roomCode }}</p>
                </div>
                <div>
                    <p class="text-gray-400">Match ID:</p>
                    <p class="text-2xl font-mono" id="match-id">{{ $match->id ?? 'N/A' }}</p>
                </div>
                <div>
                    <p class="text-gray-400">My Player:</p>
                    <p class="text-xl font-mono text-blue-400" id="my-player">{{ $player->name ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        <!-- Waiting for Players Indicator -->
        <div id="waiting-indicator" class="bg-yellow-900 border-2 border-yellow-600 rounded-lg p-6 mb-6 hidden">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-yellow-300">⏳ Esperando jugadores...</h2>
                    <p class="text-gray-300 mt-2">Conectados en tiempo real</p>
                </div>
                <div class="text-right">
                    <p class="text-5xl font-bold text-yellow-300" id="connection-counter">0/0</p>
                    <p class="text-sm text-gray-400 mt-1">jugadores</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-6">
            <!-- Left Column: Players -->
                <div class="bg-gray-800 rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">👥 Players</h2>
                    <div id="players-list" class="space-y-2 text-sm">
                        @if($match && $match->players)
                            @foreach($match->players as $player)
                                <div class="flex justify-between items-center p-2 bg-gray-700 rounded">
                                    <span class="player-name">{{ $player->name }}</span>
                                    <span class="connection-status text-xs text-gray-500">○ Desconectado</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            <!-- Right Column: Connection Log -->
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">📡 Connection Log</h2>
                    <button onclick="clearConnectionLog()" class="text-xs bg-red-600 hover:bg-red-700 px-3 py-1 rounded">
                        Clear
                    </button>
                </div>
                <div id="connection-log" class="space-y-2 text-xs font-mono h-96 overflow-y-auto">
                    <div class="text-gray-500">Waiting for connections...</div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="bg-gray-800 rounded-lg p-6 mt-6">
            <h2 class="text-xl font-semibold mb-4">🎬 Actions</h2>
            <div class="flex gap-3">
                <button onclick="resetRoom()" class="bg-orange-600 hover:bg-orange-700 px-6 py-3 rounded font-semibold text-lg">
                    🔄 RESET COMPLETO
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-3">
                ⚠️ Esto eliminará TODOS los jugadores y reseteará completamente el estado de la partida.<br>
                Útil para probar conexiones desde cero las veces que quieras.
            </p>
        </div>
    </div>

    <script>
        const roomCode = '{{ $roomCode }}';
        const matchId = {{ $match->id ?? 'null' }};
        const myPlayerId = {{ $player->id ?? 'null' }};
        let eventsCount = 0;
        let allConnectedLogged = false;
        let presenceManager = null;

        // Inicializar PresenceChannelManager
        function initializePresenceChannel() {
            if (typeof window.PresenceChannelManager === 'undefined') {
                console.log('⏳ Waiting for PresenceChannelManager to load...');
                setTimeout(initializePresenceChannel, 100);
                return;
            }

            console.log('✅ Initializing PresenceChannelManager...');
            console.log('👤 My Player ID:', myPlayerId);

            // Crear instancia del manager con callbacks personalizados
            presenceManager = new window.PresenceChannelManager(roomCode, {
                onHere: (users) => {
                    logConnection('Presence: Here', `${users.length} jugadores conectados: ${users.map(u => u.name).join(', ')}`);
                    updateConnectedPlayers(users);
                },
                onJoining: (user) => {
                    logConnection('User Joined ✅', `${user.name} se ha conectado`);
                },
                onLeaving: (user) => {
                    logConnection('User Left ❌', `${user.name} se ha desconectado`);
                },
                onAllConnected: (data) => {
                    if (!allConnectedLogged) {
                        logConnection('Ready! ✅', `¡Todos los jugadores están conectados! (${data.connected}/${data.total}) - Puedes iniciar la partida`);
                        allConnectedLogged = true;
                    }
                    // Forzar actualización del indicador cuando el backend confirma que todos están conectados
                    updateWaitingIndicator(data.connected, data.total);
                },
                onConnectionChange: (connected, total) => {
                    updateWaitingIndicator(connected, total);
                    if (connected < total) {
                        allConnectedLogged = false; // Reset si vuelve a faltar alguien
                    }
                }
            });
        }

        // Inicializar cuando el DOM esté listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializePresenceChannel);
        } else {
            initializePresenceChannel();
        }

        function logConnection(eventName, message) {
            eventsCount++;
            const log = document.getElementById('connection-log');

            if (!log) {
                console.warn('connection-log element not found');
                return;
            }

            const timestamp = new Date().toLocaleTimeString();

            const eventDiv = document.createElement('div');
            const borderColor = eventName.includes('✅') ? 'border-green-500' :
                               eventName.includes('❌') ? 'border-red-500' : 'border-blue-500';
            const textColor = eventName.includes('✅') ? 'text-green-400' :
                             eventName.includes('❌') ? 'text-red-400' : 'text-blue-400';

            eventDiv.className = `border-l-4 ${borderColor} pl-3 py-2 bg-gray-700 rounded`;
            eventDiv.innerHTML = `
                <div class="flex justify-between items-start">
                    <span class="${textColor} font-semibold">#${eventsCount} ${eventName}</span>
                    <span class="text-gray-500">${timestamp}</span>
                </div>
                <div class="text-xs text-gray-300 mt-1">${message}</div>
            `;

            if (log.firstChild && log.firstChild.classList && log.firstChild.classList.contains('text-gray-500')) {
                log.innerHTML = '';
            }

            log.insertBefore(eventDiv, log.firstChild);
        }

        function clearConnectionLog() {
            document.getElementById('connection-log').innerHTML = '<div class="text-gray-500">Log cleared...</div>';
            eventsCount = 0;
        }

        async function resetRoom() {
            if (!confirm('⚠️ RESET COMPLETO\n\nEsto eliminará TODOS los jugadores y reseteará el estado de la partida.\n\n¿Continuar?')) {
                return;
            }

            try {
                const response = await fetch(`/debug/game-events/${roomCode}/reset`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                const result = await response.json();

                if (result.success) {
                    console.log('✅ Sala reseteada exitosamente');
                    logConnection('Reset ⚠️', 'Sala reseteada completamente - Todos los jugadores eliminados');

                    // Esperar 1 segundo para que se vea el log
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert('❌ Error: ' + (result.error || 'Error desconocido'));
                }
            } catch (error) {
                console.error('Error resetting room:', error);
                alert('❌ Error al resetear: ' + error.message);
            }
        }

        function copyToClipboard(text) {
            // Fallback method que funciona en todos los navegadores
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                const successful = document.execCommand('copy');
                document.body.removeChild(textarea);

                if (successful) {
                    // Visual feedback
                    const button = event.target;
                    const originalText = button.textContent;
                    button.textContent = '✅ Copied!';
                    button.classList.add('bg-green-600');
                    button.classList.remove('bg-blue-700');

                    setTimeout(() => {
                        button.textContent = originalText;
                        button.classList.remove('bg-green-600');
                        button.classList.add('bg-blue-700');
                    }, 1500);
                } else {
                    alert('Error copying to clipboard');
                }
            } catch (err) {
                document.body.removeChild(textarea);
                console.error('Error copying to clipboard:', err);
                alert('Error copying to clipboard');
            }
        }

        // ========================================================================
        // PRESENCE CHANNEL - WAITING FOR PLAYERS
        // ========================================================================

        function updateConnectedPlayers(users) {
            const playersList = document.getElementById('players-list');
            if (!playersList) return;

            // Actualizar lista de players con estado conectado/desconectado
            const allPlayers = Array.from(playersList.children);
            allPlayers.forEach(playerDiv => {
                const playerName = playerDiv.querySelector('.player-name')?.textContent;
                const isConnected = users.some(u => u.name === playerName);

                const statusBadge = playerDiv.querySelector('.connection-status');
                if (statusBadge) {
                    if (isConnected) {
                        statusBadge.textContent = '● CONECTADO';
                        statusBadge.className = 'connection-status text-xs text-green-400';
                    } else {
                        statusBadge.textContent = '○ Desconectado';
                        statusBadge.className = 'connection-status text-xs text-gray-500';
                    }
                }
            });

            // Llamar a updateWaitingIndicator con los datos actuales del manager
            if (presenceManager) {
                updateWaitingIndicator(presenceManager.getConnectedCount(), presenceManager.getTotalPlayers());
            }
        }

        function updateWaitingIndicator(connected, total) {
            const indicator = document.getElementById('waiting-indicator');
            const counter = document.getElementById('connection-counter');

            if (!indicator || !counter) return;

            counter.textContent = `${connected}/${total}`;

            // Mostrar indicador si no todos están conectados
            if (connected < total) {
                indicator.classList.remove('hidden');
                indicator.classList.remove('bg-green-900', 'border-green-600');
                indicator.classList.add('bg-yellow-900', 'border-yellow-600');

                const title = indicator.querySelector('h2');
                const subtitle = indicator.querySelector('p');
                title.textContent = '⏳ Esperando jugadores...';
                title.className = 'text-2xl font-bold text-yellow-300';
                subtitle.textContent = 'Conectados en tiempo real';

                // Reset la bandera si volvemos a estar esperando
                allConnectedLogged = false;
            } else if (total > 0) {
                // Todos conectados - cambiar color a verde
                indicator.classList.remove('hidden');
                indicator.classList.remove('bg-yellow-900', 'border-yellow-600');
                indicator.classList.add('bg-green-900', 'border-green-600');

                const title = indicator.querySelector('h2');
                const subtitle = indicator.querySelector('p');
                title.textContent = '✅ ¡Todos los jugadores conectados!';
                title.className = 'text-2xl font-bold text-green-300';
                subtitle.textContent = '🎮 Listo para empezar la partida';

                // Log especial cuando todos están conectados (solo una vez)
                if (!allConnectedLogged) {
                    logConnection('Ready! ✅', `¡Todos los jugadores están conectados! (${connected}/${total}) - Puedes iniciar la partida`);
                    allConnectedLogged = true;
                }
            }
        }

        function hideWaitingIndicator() {
            const indicator = document.getElementById('waiting-indicator');
            if (indicator) {
                indicator.classList.add('hidden');
            }
        }
    </script>
</body>
</html>
