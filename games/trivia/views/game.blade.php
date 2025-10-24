<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $room->game->name }} - {{ $room->code }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-3xl font-bold mb-2">{{ $room->game->name }}</h1>
            <p class="text-xl text-gray-600 mb-8">Sala: <strong>{{ $room->code }}</strong></p>

            <div id="game-state" class="mt-8 p-8 bg-white rounded-lg shadow-lg min-w-[400px]">
                @if(($match->game_state['phase'] ?? '') === 'starting')
                    <div id="waiting-spinner" class="animate-spin rounded-full h-16 w-16 border-b-4 border-blue-500 mx-auto mb-4"></div>
                    <p id="waiting-text" class="text-2xl font-semibold text-gray-700">Esperando jugadores...</p>
                    <p id="connection-status" class="text-lg text-gray-500 mt-4">(1/{{ $players->count() }})</p>
                    <p id="countdown-timer" class="text-6xl font-bold text-blue-600 mt-6 hidden"></p>
                @else
                    <p class="text-2xl font-semibold">Estado: {{ $match->game_state['phase'] ?? 'unknown' }}</p>
                @endif
            </div>
        </div>
    </div>

    @vite(['resources/js/app.js'])

    <script>
    // Datos del juego desde el servidor
    window.gameConfig = {
        roomCode: '{{ $room->code }}',
        playerId: {{ $playerId }},
        matchId: {{ $match->id }},
        gameSlug: '{{ $room->game->slug }}',
        players: @json($players),
        gameState: @json($match->game_state),
        eventConfig: @json($eventConfig),
    };

    // Esperar a que BaseGameClient esté disponible (cargado por app.js)
    document.addEventListener('DOMContentLoaded', () => {
        if (window.BaseGameClient) {
            console.log('🎮 Initializing BaseGameClient');
            window.game = new window.BaseGameClient(window.gameConfig);

            // Sobrescribir el handler de GameStarted para mostrar countdown en nuestra UI
            const originalHandleGameStarted = window.game.handleGameStarted.bind(window.game);
            window.game.handleGameStarted = async function(event) {
                console.log('🎮 [TriviaGame] GameStarted - Mostrando countdown');

                // Ocultar spinner y actualizar texto
                document.getElementById('waiting-spinner').classList.add('hidden');
                document.getElementById('waiting-text').textContent = '¡Comienza en...!';
                document.getElementById('connection-status').classList.add('hidden');
                document.getElementById('countdown-timer').classList.remove('hidden');

                // Llamar al handler original (que procesa el countdown)
                await originalHandleGameStarted(event);
            };

            // Sobrescribir getCountdownElement para apuntar a nuestro elemento
            window.game.getCountdownElement = function() {
                return document.getElementById('countdown-timer');
            };

            // Handler para RoundStartedEvent (cada pregunta es una ronda)
            window.game.handleRoundStarted = function(event) {
                console.log('🎯 [TriviaGame] RoundStartedEvent received:', event);

                // Extraer datos de la pregunta desde game_state
                const currentQuestion = event.game_state.current_question;

                if (!currentQuestion) {
                    console.error('❌ No current_question in game_state');
                    return;
                }

                // Actualizar el contenido para mostrar la pregunta
                const gameStateDiv = document.getElementById('game-state');
                gameStateDiv.innerHTML = `
                    <h2 class="text-3xl font-bold mb-6">Pregunta ${event.current_round}/${event.total_rounds}</h2>
                    <p class="text-2xl mb-8">${currentQuestion.question}</p>
                    <div class="space-y-4">
                        ${currentQuestion.options.map((option, index) => `
                            <button class="w-full py-4 px-6 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-xl font-semibold transition">
                                ${index + 1}. ${option}
                            </button>
                        `).join('')}
                    </div>
                `;
            };

            window.game.setupEventManager();
        }
    });
    </script>
</body>
</html>
