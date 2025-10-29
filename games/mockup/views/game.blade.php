<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mockup Game - {{ $room->code }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <div id="app" class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold">🎮 Mockup Game</h1>
                <p class="text-gray-400">Sala: <span class="font-mono text-yellow-400">{{ $room->code }}</span></p>
                <p class="text-sm text-gray-500">Juego de prueba para validar sistema de eventos y timers</p>
            </div>
            <div id="round-info" class="text-right">
                <p class="text-sm text-gray-400">Ronda</p>
                <p class="text-2xl font-bold">
                    <span id="current-round">{{ $match->game_state['round_system']['current_round'] ?? 1 }}</span>/<span id="total-rounds">{{ $match->game_state['_config']['modules']['round_system']['total_rounds'] ?? 3 }}</span>
                </p>
            </div>
        </div>

        <!-- Phase Info -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <div class="text-center mb-4">
                <p class="text-sm text-gray-400 mb-2">Fase Actual</p>
                <p id="current-phase" class="text-3xl font-bold text-yellow-400">
                    {{ $match->game_state['current_phase'] ?? 'waiting' }}
                </p>
            </div>

            <!-- Timer -->
            <div id="timer-container" class="text-center mb-4">
                <p id="timer-message" class="text-sm text-gray-400 mb-2">Tiempo restante</p>
                <p id="timer" class="text-5xl font-bold text-green-400">00:00</p>
            </div>

            <!-- Phase Description -->
            <div class="text-center text-gray-400">
                <p id="phase-description">Esperando...</p>
            </div>
        </div>

        <!-- Test Controls -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <h2 class="text-xl font-bold mb-4">🧪 Controles de Testing</h2>
            <div class="grid grid-cols-2 gap-4">
                <button id="btn-next-phase" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-lg transition-colors">
                    ⏭️ Next Phase (Manual)
                </button>
                <button id="btn-refresh-state" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-3 px-6 rounded-lg transition-colors">
                    🔄 Refresh State
                </button>
            </div>
            <p class="text-xs text-gray-500 mt-2">* Los timers avanzan automáticamente. El botón "Next Phase" es solo para testing manual.</p>
        </div>

        <!-- Event Log -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-xl font-bold mb-4">📋 Event Log</h2>
            <div id="event-log" class="bg-gray-900 rounded p-4 h-64 overflow-y-auto font-mono text-sm">
                <p class="text-gray-500">Esperando eventos...</p>
            </div>
        </div>
    </div>

    @vite(['resources/js/app.js', 'games/mockup/js/MockupGameClient.js'])

    <script>
        // Pasar datos al frontend
        window.mockupGameData = {
            roomCode: '{{ $room->code }}',
            playerId: {{ session('player_id') ?? 'null' }},
            gameSlug: 'mockup',
            gameState: @json($match->game_state ?? null),
            csrfToken: '{{ csrf_token() }}'
        };

        console.log('[Mockup Game] Loaded with data:', window.mockupGameData);
    </script>

    {{-- Inicializar MockupGameClient --}}
    <script type="module">
        // Configuración del juego desde PHP
        const config = {
            roomCode: '{{ $room->code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId ?? 'null' }},
            userId: {{ $userId ?? 'null' }},
            gameSlug: 'mockup',
            players: [],
            scores: {},
            eventConfig: @json($eventConfig ?? null),
        };

        // Crear instancia del cliente de Mockup
        const mockupClient = new window.MockupGameClient(config);

        // NOTA: Ya NO necesitamos simular GameStartedEvent aquí
        // El flujo correcto es:
        // 1. BaseGameClient constructor → emitDomLoaded()
        // 2. Backend cuenta jugadores con DOM cargado
        // 3. Cuando todos listos → Backend emite GameStartedEvent
        // 4. handleGameStarted() se ejecuta automáticamente

        // Guardar referencia global para debugging
        window.mockupClient = mockupClient;
    </script>

    @stack('scripts')
</body>
</html>
