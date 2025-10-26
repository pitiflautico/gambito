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
                    {{-- Usando componente Blade genérico reutilizable --}}
                    <div id="finished-state" class="hidden">
                        <x-game.results-screen
                            :roomCode="$code"
                            gameTitle="Trivia"
                            containerId="podium"
                            :embedded="true"
                        />
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
                            <div class="inline-block bg-blue-100 px-6 py-3 rounded-lg">
                                <p class="text-sm text-blue-600 font-semibold mb-1">Tiempo restante</p>
                                <p id="timer" class="text-4xl font-mono font-bold text-blue-900"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Timer warning styles */
        #timer.countdown-warning {
            color: #DC2626 !important;
            animation: pulse 1s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.6;
            }
        }

        #timer.timer-expired {
            color: #EF4444 !important;
        }
    </style>

    <script type="module">
        // ========================================================================
        // Trivia Game Client - Fase 4: WebSocket Bidirectional Communication
        // ========================================================================

        // EventManager, TimingModule y BaseGameClient ya están disponibles globalmente
        // a través de resources/js/app.js cargado en el layout

        // Cargar TriviaGameClient de forma lazy
        await window.loadTriviaGameClient();
        const { TriviaGameClient } = window;

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

        // Crear instancia del cliente de Trivia
        const triviaClient = new TriviaGameClient(config);

        // 🔥 FIX: Cargar estado inicial ANTES de conectar WebSockets
        // Esto previene race conditions donde los eventos llegan antes del estado inicial
        (async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;

                    // Cargar players desde el estado
                    if (gameState?._config?.players) {
                        triviaClient.players = Object.values(gameState._config.players);
                    }

                    // Cargar scores desde el estado
                    if (gameState?.scores) {
                        triviaClient.scores = gameState.scores;
                    }

                    // 🎯 PRIORIDAD 1: Si el juego terminó, mostrar pantalla de resultados
                    if (gameState?.phase === 'finished') {

                        // Ocultar loading y question states
                        triviaClient.hideElement('loading-state');
                        triviaClient.hideElement('question-state');

                        // Mostrar finished state
                        triviaClient.showElement('finished-state');

                        // Renderizar podio con ranking y scores finales
                        if (gameState.ranking && gameState.final_scores) {
                            triviaClient.renderPodium(gameState.ranking, gameState.final_scores, 'podium');
                        }

                        return; // ← No cargar más estados si ya terminó
                    }

                    // 🎯 PRIORIDAD 2: Si hay una pregunta activa, mostrarla
                    if (gameState?.current_question) {
                        // Crear evento simulado con la misma estructura que RoundStartedEvent
                        const eventData = {
                            current_round: gameState.round_system?.current_round || 1,
                            total_rounds: gameState.round_system?.total_rounds || 10,
                            game_state: gameState
                        };

                        // Agregar timing metadata si hay un timer activo
                        const timerData = gameState.timer_system?.timers?.round;
                        if (timerData) {
                            // Reconstruir timing metadata desde el timer del backend
                            const startedAt = new Date(timerData.started_at).getTime() / 1000; // a segundos UNIX
                            const pausedAt = timerData.paused_at ? new Date(timerData.paused_at).getTime() / 1000 : null;
                            const duration = timerData.duration;

                            eventData.timing = {
                                duration: duration,
                                server_time: startedAt,
                                countdown_visible: true,
                                warning_threshold: 3
                            };
                        }

                        triviaClient.handleRoundStarted(eventData);

                        // Si ya respondimos, mostrar overlay de bloqueado
                        // Los locks se guardan en player_state_system.locks como objeto {playerId: true/false}
                        const locks = gameState.player_state_system?.locks || {};
                        if (locks[config.playerId] === true) {
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

            // Configurar Event Manager DESPUÉS de cargar el estado inicial
            triviaClient.setupEventManager();
        })();
    </script>
</x-app-layout>
