<x-app-layout>
    @vite(['resources/js/app.js', 'games/trivia/js/TriviaGameClient.js'])
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🧠 Trivia - Sala: {{ $code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <!-- Header -->
                <div id="game-header" class="px-6 py-4 text-center bg-indigo-600">
                    <h3 class="text-2xl font-bold text-white">🧠 ¡Responde rápido y suma puntos!</h3>
                </div>

                <!-- Body -->
                <div id="game-container" class="px-6 py-10">
                    <!-- Loading State -->
                    <x-game.loading-state
                        id="loading-state"
                        emoji="🧠"
                        message="Esperando primera ronda..."
                        :roomCode="$code"
                    />

                    <!-- Finished State -->
                    <div id="finished-state" class="hidden">
                        <x-game.results-screen
                            :roomCode="$code"
                            gameTitle="Trivia"
                            containerId="podium"
                            :embedded="true"
                        />
                    </div>

                    <!-- Playing State -->
                    <div id="playing-state" class="hidden">
                        <!-- Round Info -->
                        <x-game.round-info :current="1" :total="5" label="Ronda" />

                        <!-- Phase Info -->
                        <div class="text-center my-4">
                            <p class="text-sm text-gray-600">Fase actual:</p>
                            <p id="current-phase" class="text-xl font-bold text-gray-900">-</p>
                        </div>

                        <!-- Timer -->
                        <div class="text-center my-4">
                            <div class="inline-block bg-indigo-100 px-6 py-3 rounded-lg">
                                <p class="text-sm text-indigo-600 font-semibold mb-1">Tiempo restante</p>
                                <p id="timer" class="text-3xl font-mono font-bold text-indigo-900"></p>
                            </div>
                        </div>

                        <!-- Question Section -->
                        <div id="question-section" class="mb-6">
                            <div class="p-6 bg-indigo-50 border-2 border-indigo-200 rounded-lg text-center">
                                <p id="question-text" class="text-lg font-semibold text-indigo-900">Preparados... la pregunta aparece y luego podrás responder.</p>
                            </div>
                        </div>

                        <!-- Answer Options -->
                        <div id="answer-buttons" class="hidden text-center mb-6">
                            <div id="options-list" class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl mx-auto"></div>
                        </div>

                        <!-- Locked Message -->
                        <div id="locked-message" class="hidden text-center mb-6">
                            <div class="inline-block px-4 py-2 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded">
                                Ya respondiste esta ronda. Espera la siguiente.
                            </div>
                        </div>

                        <!-- Results Section -->
                        <div id="results-section" class="hidden text-center">
                            <div class="mb-4">
                                <h3 class="text-xl font-bold text-gray-800">Resultados</h3>
                                <p id="round-countdown" class="text-gray-600"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('mockup::partials.round_end_popup')
    @include('mockup::partials.game_end_popup')
    @include('mockup::partials.player_disconnected_popup')

    <script type="module">
        // Configuración del juego desde PHP
        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            userId: {{ $userId }},
            gameSlug: 'trivia',
            players: [],
            scores: {},
            gameState: null,
            eventConfig: @json($eventConfig ?? null),
            timing: {}
        };

        // Crear instancia del cliente de Trivia (igual que Mockup)
        const client = new window.TriviaGameClient(config);

        // Cargar estado inicial
        (async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;
                    client.players = data.players || [];
                    client.gameState = gameState;

                    // Cargar scores desde player_system
                    const playerSystem = gameState.player_system?.players || {};
                    client.scores = {};
                    Object.keys(playerSystem).forEach(pid => {
                        client.scores[pid] = playerSystem[pid].score || 0;
                    });

                    // Render inicial
                    client.updateUI();

                    // Si terminó, restaurar resultados
                    if (gameState?.phase === 'finished') {
                        const finishedEvent = {
                            winner: gameState.winner || null,
                            ranking: gameState.ranking || [],
                            scores: client.scores,
                            game_state: gameState
                        };
                        client.handleGameFinished(finishedEvent);
                    }
                }
            } catch (e) {
                console.error('[Trivia] Error loading initial state', e);
            }

            // Wire botones
            document.getElementById('btn-correct')?.addEventListener('click', () => client.sendCorrectAnswer());
            document.getElementById('btn-wrong')?.addEventListener('click', () => client.sendWrongAnswer());
        })();
    </script>
</x-app-layout>


