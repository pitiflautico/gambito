<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug - Game Events</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @vite(['resources/js/app.js'])
</head>
<body class="bg-gray-900 text-white">
    <div class="container mx-auto p-6">
        <h1 class="text-3xl font-bold mb-6">🐛 Game Events Debug Panel</h1>

        <!-- Room Info -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">📍 Room Info</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-gray-400">Room Code:</p>
                    <p class="text-2xl font-mono" id="room-code">{{ $roomCode }}</p>
                </div>
                <div>
                    <p class="text-gray-400">Match ID:</p>
                    <p class="text-2xl font-mono" id="match-id">{{ $match->id ?? 'N/A' }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-6">
            <!-- Left Column: Game State -->
            <div class="col-span-1">
                <div class="bg-gray-800 rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">🎮 Game State</h2>
                    <div class="space-y-3 text-sm">
                        <div>
                            <span class="text-gray-400">Status:</span>
                            <span class="font-mono ml-2" id="game-status">{{ $match->game_state['phase'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Round:</span>
                            <span class="font-mono ml-2" id="current-round">
                                {{ $match->game_state['round_system']['current_round'] ?? 0 }} /
                                {{ $match->game_state['round_system']['total_rounds'] ?? 0 }}
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-400">Turn:</span>
                            <span class="font-mono ml-2" id="current-turn">{{ $match->game_state['turn_system']['current_turn_index'] ?? 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400">Timer:</span>
                            <span class="font-mono ml-2" id="timer-remaining">N/A</span>
                        </div>
                    </div>
                </div>

                <!-- Players -->
                <div class="bg-gray-800 rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">👥 Players</h2>
                    <div id="players-list" class="space-y-2 text-sm">
                        @if($match && $match->players)
                            @foreach($match->players as $player)
                                <div class="flex justify-between items-center p-2 bg-gray-700 rounded">
                                    <span>{{ $player->name }}</span>
                                    <span class="text-xs text-gray-400">ID: {{ $player->id }}</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            <!-- Middle Column: Events Log -->
            <div class="col-span-1">
                <div class="bg-gray-800 rounded-lg p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">📡 Events Log</h2>
                        <button onclick="clearEventsLog()" class="text-xs bg-red-600 hover:bg-red-700 px-3 py-1 rounded">
                            Clear
                        </button>
                    </div>
                    <div id="events-log" class="space-y-2 text-xs font-mono h-96 overflow-y-auto">
                        <div class="text-gray-500">Waiting for events...</div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Available Events -->
            <div class="col-span-1">
                <div class="bg-gray-800 rounded-lg p-6">
                    <h2 class="text-xl font-semibold mb-4">⚡ Available Events</h2>
                    <div class="space-y-2 text-sm">
                        @foreach($baseEvents as $eventClass => $eventConfig)
                            <div class="bg-gray-700 p-3 rounded">
                                <div class="font-semibold text-green-400">{{ $eventClass }}</div>
                                <div class="text-xs text-gray-400 mt-1">{{ $eventConfig['name'] }}</div>
                                <div class="text-xs text-gray-500">Handler: {{ $eventConfig['handler'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="bg-gray-800 rounded-lg p-6 mt-6">
            <h2 class="text-xl font-semibold mb-4">🎬 Actions</h2>
            <div class="grid grid-cols-4 gap-3">
                <button onclick="startGame()" class="bg-green-600 hover:bg-green-700 px-4 py-2 rounded font-semibold">
                    ▶️ Start Game
                </button>
                <button onclick="nextRound()" class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded font-semibold">
                    ⏭️ Next Round
                </button>
                <button onclick="endGame()" class="bg-red-600 hover:bg-red-700 px-4 py-2 rounded font-semibold">
                    ⏹️ End Game
                </button>
                <button onclick="refreshState()" class="bg-gray-600 hover:bg-gray-700 px-4 py-2 rounded font-semibold">
                    🔄 Refresh State
                </button>
            </div>
        </div>

        <!-- Full Game State (JSON) -->
        <div class="bg-gray-800 rounded-lg p-6 mt-6">
            <h2 class="text-xl font-semibold mb-4">📄 Full Game State (JSON)</h2>
            <pre id="full-state" class="bg-gray-900 p-4 rounded text-xs overflow-x-auto">{{ json_encode($match->game_state ?? [], JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>

    <script>
        const roomCode = '{{ $roomCode }}';
        const matchId = {{ $match->id ?? 'null' }};
        let eventsCount = 0;

        // Connect to WebSocket
        window.Echo.channel(`room.${roomCode}`)
            .listen('.game.started', (e) => logEvent('GameStartedEvent', e))
            .listen('.player.connected', (e) => logEvent('PlayerConnectedEvent', e))
            .listen('.game.round.started', (e) => logEvent('RoundStartedEvent', e))
            .listen('.game.round.ended', (e) => logEvent('RoundEndedEvent', e))
            .listen('.game.player.action', (e) => logEvent('PlayerActionEvent', e))
            .listen('.game.phase.changed', (e) => logEvent('PhaseChangedEvent', e))
            .listen('.game.turn.changed', (e) => logEvent('TurnChangedEvent', e))
            .listen('.game.finished', (e) => logEvent('GameFinishedEvent', e));

        function logEvent(eventName, data) {
            eventsCount++;
            const log = document.getElementById('events-log');
            const timestamp = new Date().toLocaleTimeString();

            const eventDiv = document.createElement('div');
            eventDiv.className = 'border-l-4 border-green-500 pl-3 py-2 bg-gray-700 rounded';
            eventDiv.innerHTML = `
                <div class="flex justify-between items-start">
                    <span class="text-green-400 font-semibold">#${eventsCount} ${eventName}</span>
                    <span class="text-gray-500">${timestamp}</span>
                </div>
                <pre class="text-xs text-gray-300 mt-1 overflow-x-auto">${JSON.stringify(data, null, 2)}</pre>
            `;

            if (log.firstChild && log.firstChild.classList.contains('text-gray-500')) {
                log.innerHTML = '';
            }

            log.insertBefore(eventDiv, log.firstChild);

            // Auto-update game state based on event
            updateGameState(eventName, data);
        }

        function updateGameState(eventName, data) {
            switch(eventName) {
                case 'RoundStartedEvent':
                    document.getElementById('current-round').textContent =
                        `${data.current_round} / ${data.total_rounds}`;
                    document.getElementById('game-status').textContent = data.phase || 'playing';
                    break;
                case 'RoundEndedEvent':
                    // Round info updated on next RoundStarted
                    break;
                case 'GameFinishedEvent':
                    document.getElementById('game-status').textContent = 'finished';
                    break;
            }
        }

        function clearEventsLog() {
            document.getElementById('events-log').innerHTML = '<div class="text-gray-500">Log cleared...</div>';
            eventsCount = 0;
        }

        async function startGame() {
            try {
                const response = await fetch(`/debug/game-events/${roomCode}/start`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                const result = await response.json();
                console.log('Start Game:', result);
            } catch (error) {
                console.error('Error starting game:', error);
            }
        }

        async function nextRound() {
            try {
                const response = await fetch(`/debug/game-events/${roomCode}/next-round`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                const result = await response.json();
                console.log('Next Round:', result);
            } catch (error) {
                console.error('Error advancing round:', error);
            }
        }

        async function endGame() {
            try {
                const response = await fetch(`/debug/game-events/${roomCode}/end`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                });
                const result = await response.json();
                console.log('End Game:', result);
            } catch (error) {
                console.error('Error ending game:', error);
            }
        }

        async function refreshState() {
            try {
                const response = await fetch(`/debug/game-events/${roomCode}/state`);
                const result = await response.json();

                // Update Full State
                document.getElementById('full-state').textContent =
                    JSON.stringify(result.game_state, null, 2);

                // Update UI
                if (result.game_state) {
                    document.getElementById('game-status').textContent =
                        result.game_state.phase || 'N/A';

                    if (result.game_state.round_system) {
                        document.getElementById('current-round').textContent =
                            `${result.game_state.round_system.current_round || 0} / ${result.game_state.round_system.total_rounds || 0}`;
                    }
                }
            } catch (error) {
                console.error('Error refreshing state:', error);
            }
        }

        // Auto-refresh every 5 seconds
        setInterval(refreshState, 5000);
    </script>
</body>
</html>
