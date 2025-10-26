<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎮 Trivia - Sala: {{ $code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <!-- Header -->
                <div class="bg-green-600 px-6 py-4 text-center">
                    <h3 class="text-2xl font-bold text-white">🎮 ¡EL JUEGO HA EMPEZADO!</h3>
                </div>

                <!-- Body -->
                <div id="game-container" class="px-6 py-12">
                    <!-- Loading State (usando componente Blade) -->
                    <x-game.loading-state
                        id="loading-state"
                        emoji="⏳"
                        message="Esperando primera pregunta..."
                        :roomCode="$code"
                    />

                    <!-- Finished State (pantalla de resultados finales) -->
                    <div id="finished-state" class="hidden text-center">
                        <div class="mb-8">
                            <h2 class="text-4xl font-bold text-gray-900 mb-2">🏆 ¡Partida terminada!</h2>
                            <p class="text-gray-600">Resultados finales</p>
                        </div>

                        <!-- Podio -->
                        <div id="podium" class="max-w-2xl mx-auto mb-8">
                            <!-- Se llenará dinámicamente vía JavaScript -->
                        </div>

                        <!-- Botones de acción -->
                        <div class="flex gap-4 justify-center">
                            <a href="/rooms/{{ $code }}/lobby"
                               class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                🏠 Volver al Lobby
                            </a>
                            <a href="/"
                               class="px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                                🎮 Crear Nueva Partida
                            </a>
                        </div>
                    </div>

                    <!-- Question State (hidden initially) -->
                    <div id="question-state" class="hidden relative">
                        <!-- Locked Overlay (usando componente Blade) -->
                        <div id="locked-overlay" class="hidden absolute inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm z-10 flex items-center justify-center rounded-lg">
                            <div class="bg-white px-8 py-6 rounded-lg shadow-xl">
                                <x-game.player-lock
                                    message="Ya respondiste"
                                    icon="🔒"
                                />
                            </div>
                        </div>

                        <!-- Round Info (usando componente Blade) -->
                        <x-game.round-info
                            :current="1"
                            :total="10"
                            label="Ronda"
                        />
                        <p id="players-answered" class="text-sm text-gray-500 mt-2 text-center">
                            <!-- Se actualizará vía JavaScript -->
                        </p>

                        <!-- Category and Difficulty -->
                        <div class="text-center mb-6 space-x-3">
                            <span id="category-text" class="inline-block bg-purple-100 text-purple-800 text-sm font-semibold px-4 py-2 rounded-full"></span>
                            <span id="difficulty-badge" class="inline-block px-2 py-1 text-xs font-semibold rounded"></span>
                        </div>

                        <!-- Question -->
                        <div class="text-center mb-8">
                            <h2 id="question-text" class="text-3xl font-bold text-gray-900 mb-2"></h2>
                        </div>

                        <!-- Options -->
                        <div id="options-container" class="space-y-3 max-w-2xl mx-auto">
                            <!-- Options will be inserted here -->
                        </div>

                        <!-- Timer (if present) -->
                        <div class="text-center mt-8">
                            <p id="timer" class="text-xl font-mono text-gray-600"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        // ========================================================================
        // Trivia Game Client - Fase 4: WebSocket Bidirectional Communication
        // ========================================================================
        
        import EventManager from '/resources/js/modules/EventManager.js';
        import TimingModule from '/resources/js/modules/TimingModule.js';
        import { BaseGameClient } from '/resources/js/core/BaseGameClient.js';
        import { TriviaGameClient } from '/games/games/trivia/js/TriviaGameClient.js';

        // Hacer disponibles globalmente (requerido por BaseGameClient)
        window.EventManager = EventManager;
        window.TimingModule = TimingModule;

        // Configuración del juego desde PHP
        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            gameSlug: 'trivia',
            players: [],  // Se cargará dinámicamente
            scores: {},
            gameState: null,
            eventConfig: @json($eventConfig ?? null),
            timing: {}
        };

        console.log('🎮 [Trivia] Initializing TriviaGameClient with config:', config);

        // Crear instancia del cliente de Trivia
        const triviaClient = new TriviaGameClient(config);

        // Configurar Event Manager (conecta WebSockets y registra handlers)
        triviaClient.setupEventManager();

        console.log('✅ [Trivia] Game client initialized and ready');

        // Cargar estado inicial (por si ya hay una pregunta activa)
        window.addEventListener('load', async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;

                    console.log('📦 [Trivia] Initial state loaded:', gameState);

                    // Cargar players desde el estado
                    if (gameState?._config?.players) {
                        triviaClient.players = Object.values(gameState._config.players);
                        console.log('👥 [Trivia] Players loaded:', triviaClient.players);
                    }

                    // Cargar scores desde el estado
                    if (gameState?.scores) {
                        triviaClient.scores = gameState.scores;
                    }

                    // Si hay una pregunta activa, mostrarla
                    if (gameState?.current_question) {
                        // Crear evento simulado con la misma estructura que RoundStartedEvent
                        const eventData = {
                            current_round: gameState.round_system?.current_round || 1,
                            total_rounds: gameState.round_system?.total_rounds || 10,
                            game_state: gameState
                        };

                        console.log('🔄 [Trivia] Displaying current question from initial state');
                        triviaClient.handleRoundStarted(eventData);

                        // Si ya respondimos, mostrar overlay de bloqueado
                        // Los locks se guardan en player_state_system.locks como objeto {playerId: true/false}
                        const locks = gameState.player_state_system?.locks || {};
                        if (locks[config.playerId] === true) {
                            console.log('🔒 [Trivia] Player already answered, showing locked overlay');
                            triviaClient.hasAnswered = true;
                            triviaClient.showElement('locked-overlay');
                        }
                    }
                } else {
                    console.warn('⚠️ [Trivia] Could not load initial state');
                }
            } catch (error) {
                console.error('❌ [Trivia] Error loading initial state:', error);
            }
        });
    </script>
</x-app-layout>
