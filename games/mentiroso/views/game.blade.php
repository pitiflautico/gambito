<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mentiroso - {{ $code }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <div id="app" class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-3xl font-bold">🎭 Mentiroso</h1>
                <p class="text-gray-400">Sala: <span class="font-mono text-yellow-400">{{ $code }}</span></p>
                <!-- Role indicator -->
                <div id="role-indicator" class="mt-2 hidden">
                    <span class="text-xs text-gray-500">Tu rol: </span>
                    <span id="current-role" class="text-sm font-bold text-yellow-400"></span>
                </div>
            </div>
            <div id="round-info" class="text-right">
                <p class="text-sm text-gray-400">Ronda</p>
                <p class="text-2xl font-bold"><span id="current-round">0</span>/<span id="total-rounds">10</span></p>
            </div>
        </div>

        <!-- Timer -->
        <div id="timer-container" class="mb-6 text-center hidden">
            <div class="inline-block bg-gray-800 rounded-lg px-6 py-4">
                <p id="timer-message" class="text-sm text-gray-400 mb-2">Tiempo restante</p>
                <p id="timer" class="text-4xl font-bold text-yellow-400">00:00</p>
            </div>
        </div>

        <!-- Phase Container -->
        <div id="phase-container" class="mb-6">
            <!-- Waiting Phase -->
            <div id="waiting-phase" class="text-center py-12 hidden">
                <div class="animate-pulse">
                    <p class="text-xl text-gray-400">⏳ Esperando a que empiece la ronda...</p>
                </div>
            </div>

            <!-- Preparation Phase (Solo para el orador) -->
            <div id="preparation-phase" class="hidden">
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <h2 class="text-2xl font-bold mb-4">🎯 Eres el Orador</h2>
                    <div class="bg-gray-700 rounded-lg p-6 mb-4">
                        <p class="text-lg mb-2">Tu frase es:</p>
                        <p id="orador-statement" class="text-2xl font-bold text-yellow-400 mb-4"></p>
                        <div id="truth-indicator" class="text-xl font-bold"></div>
                    </div>
                    <p class="text-gray-400">Prepara tu defensa. Debes convencer a los demás de que tu frase es VERDADERA, sin importar si es verdad o mentira.</p>
                </div>
            </div>

            <!-- Persuasion Phase -->
            <div id="persuasion-phase" class="hidden">
                <div class="bg-gray-800 rounded-lg p-6">
                    <div class="text-center mb-6">
                        <h2 class="text-2xl font-bold mb-2">🎤 Fase de Persuasión</h2>
                        <div id="orador-info" class="text-lg text-gray-400 mb-4">
                            <span id="orador-name" class="text-yellow-400 font-bold"></span> está defendiendo su frase
                        </div>
                        <div class="bg-gray-700 rounded-lg p-4">
                            <p id="statement-display" class="text-xl font-bold text-yellow-400"></p>
                        </div>
                    </div>

                    <!-- Mensaje para el orador -->
                    <div id="orador-waiting" class="text-center text-gray-400 hidden">
                        <p>Defiende tu frase. Convence a los demás de que es VERDADERA.</p>
                    </div>
                </div>
            </div>

            <!-- Voting Phase -->
            <div id="voting-phase" class="hidden">
                <div class="bg-gray-800 rounded-lg p-6">
                    <h2 class="text-2xl font-bold text-center mb-4">🗳️ Vota Ahora</h2>
                    <div class="bg-gray-700 rounded-lg p-4 mb-6 text-center">
                        <p id="voting-statement" class="text-xl font-bold text-yellow-400"></p>
                    </div>

                    <!-- Botones de voto (solo para votantes) -->
                    <div id="vote-buttons" class="flex gap-4 justify-center hidden">
                        <button id="vote-true" class="flex-1 max-w-xs bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg transition-colors">
                            ✓ VERDADERO
                        </button>
                        <button id="vote-false" class="flex-1 max-w-xs bg-red-600 hover:bg-red-700 text-white font-bold py-4 px-6 rounded-lg transition-colors">
                            ✗ FALSO
                        </button>
                    </div>

                    <!-- Mensaje de voto enviado -->
                    <div id="vote-sent" class="text-center text-green-400 hidden">
                        <p class="text-xl">✓ Voto enviado</p>
                    </div>

                    <!-- Mensaje para el orador -->
                    <div id="orador-vote-waiting" class="text-center text-gray-400 hidden">
                        <p>Los demás están votando...</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Scoreboard -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h3 class="text-xl font-bold mb-4">🏆 Puntuaciones</h3>
            <div id="scoreboard" class="space-y-2">
                <!-- Scores will be dynamically inserted here -->
            </div>
        </div>

    </div>

    {{-- Player Disconnected Popup --}}
    <x-game.player-disconnected-popup />

    {{-- Round End Popup (generic) --}}
    @include('mockup::partials.round_end_popup')

    {{-- Game End Popup (generic) --}}
    @include('mockup::partials.game_end_popup')

    @vite(['resources/js/app.js', 'games/mentiroso/js/MentirosoGameClient.js'])

    <script type="module">
        // Configuración del juego desde PHP
        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            userId: {{ $userId }},
            gameSlug: 'mentiroso',
            players: [],
            scores: {},
            eventConfig: @json($eventConfig),
        };

        // Crear instancia del cliente de Mentiroso
        const mentirosoClient = new window.MentirosoGameClient(config);

        // Cargar estado inicial ANTES de conectar WebSockets
        (async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;

                    if (gameState) {
                        console.log('[Mentiroso] Loading initial state:', gameState);
                        mentirosoClient.restoreGameState(gameState);
                    }
                } else {
                    console.warn('⚠️ [Mentiroso] Could not load initial state');
                }
            } catch (error) {
                console.error('❌ [Mentiroso] Error loading initial state:', error);
            }

            // Configurar Event Manager DESPUÉS de cargar el estado inicial
            mentirosoClient.setupEventManager();
        })();
    </script>

    @stack('scripts')
</body>
</html>
