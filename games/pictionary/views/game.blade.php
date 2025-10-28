<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎨 Pictionary - Sala: {{ $code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <!-- Header -->
                <div class="bg-purple-600 px-6 py-4 text-center">
                    <h3 class="text-2xl font-bold text-white">🎨 ¡A DIBUJAR Y ADIVINAR!</h3>
                </div>

                <!-- Body -->
                <div id="game-container" class="px-6 py-12">
                    <!-- Loading State -->
                    <x-game.loading-state
                        id="loading-state"
                        emoji="🎨"
                        message="Esperando primera ronda..."
                        :roomCode="$code"
                    />

                    <!-- Round Results State -->
                    <div id="round-results-state" class="hidden text-center">
                        <div class="mb-8">
                            <h3 class="text-2xl text-gray-600 mb-4">🎨 La palabra era:</h3>
                            <h2 id="result-word" class="text-6xl font-bold text-purple-600 mb-6"></h2>
                            <p id="result-guessers" class="text-xl text-gray-700"></p>
                        </div>
                        <div class="mt-8">
                            <p id="round-countdown" class="text-lg text-gray-500"></p>
                        </div>
                    </div>

                    <!-- Finished State -->
                    <div id="finished-state" class="hidden">
                        <x-game.results-screen
                            :roomCode="$code"
                            gameTitle="Pictionary"
                            containerId="podium"
                            :embedded="true"
                        />
                    </div>

                    <!-- Playing State -->
                    <div id="playing-state" class="hidden">
                        <!-- Round Info -->
                        <x-game.round-info
                            :current="1"
                            :total="10"
                            label="Ronda"
                        />

                        <!-- Current Drawer Info -->
                        <div id="drawer-info" class="text-center mb-4 p-4 bg-purple-50 rounded-lg">
                            <p class="text-sm text-purple-600 font-semibold">
                                <span id="drawer-name">Jugador</span> está dibujando...
                            </p>
                        </div>

                        <!-- Word Display (only for drawer) -->
                        <div id="word-display" class="hidden text-center mb-6 p-6 bg-yellow-50 border-4 border-yellow-300 rounded-lg">
                            <p class="text-sm text-yellow-600 font-semibold mb-2">Tu palabra es:</p>
                            <h2 id="word-text" class="text-4xl font-bold text-yellow-900"></h2>
                        </div>

                        <!-- Main Game Area -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Canvas Area (2/3) -->
                            <div class="lg:col-span-2">
                                <!-- Canvas Tools (only for drawer) -->
                                <div id="canvas-tools" class="hidden mb-4 p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center justify-between gap-4">
                                        <!-- Color Picker -->
                                        <div class="flex gap-2">
                                            <button class="color-btn w-10 h-10 rounded-full border-2 border-gray-300 bg-black" data-color="#000000"></button>
                                            <button class="color-btn w-10 h-10 rounded-full border-2 border-gray-300 bg-red-500" data-color="#FF0000"></button>
                                            <button class="color-btn w-10 h-10 rounded-full border-2 border-gray-300 bg-green-500" data-color="#00FF00"></button>
                                            <button class="color-btn w-10 h-10 rounded-full border-2 border-gray-300 bg-blue-500" data-color="#0000FF"></button>
                                            <button class="color-btn w-10 h-10 rounded-full border-2 border-gray-300 bg-yellow-400" data-color="#FFFF00"></button>
                                        </div>

                                        <!-- Brush Size -->
                                        <div class="flex gap-2">
                                            <button class="brush-btn px-3 py-2 bg-gray-200 rounded hover:bg-gray-300" data-size="2">Fino</button>
                                            <button class="brush-btn px-3 py-2 bg-gray-200 rounded hover:bg-gray-300" data-size="4">Medio</button>
                                            <button class="brush-btn px-3 py-2 bg-gray-200 rounded hover:bg-gray-300" data-size="8">Grueso</button>
                                        </div>

                                        <!-- Clear Button -->
                                        <button id="clear-canvas-btn" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600">
                                            🗑️ Limpiar
                                        </button>
                                    </div>
                                </div>

                                <!-- Canvas -->
                                <div class="relative bg-white border-4 border-gray-300 rounded-lg overflow-hidden">
                                    <canvas
                                        id="drawing-canvas"
                                        width="800"
                                        height="600"
                                        class="w-full cursor-crosshair"
                                    ></canvas>
                                </div>

                                <!-- Timer -->
                                <div class="text-center mt-4">
                                    <div class="inline-block bg-purple-100 px-6 py-3 rounded-lg">
                                        <p class="text-sm text-purple-600 font-semibold mb-1">Tiempo restante</p>
                                        <p id="timer" class="text-4xl font-mono font-bold text-purple-900"></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Sidebar (1/3) -->
                            <div class="lg:col-span-1">
                                <!-- Players & Scores -->
                                <div class="mb-6 p-4 bg-white rounded-lg border-2 border-gray-200">
                                    <h3 class="text-lg font-bold text-gray-900 mb-3">Jugadores</h3>
                                    <div id="players-scores-list" class="space-y-2">
                                        <!-- Players with scores will be inserted here -->
                                    </div>
                                </div>

                                <!-- Validation Panel (only for drawer) -->
                                <div id="validation-panel" class="hidden mb-6">
                                    <div class="p-4 bg-purple-50 rounded-lg border-2 border-purple-300">
                                        <h3 class="text-lg font-bold text-purple-900 mb-3">Validar respuestas</h3>
                                        <div id="claims-list" class="space-y-3">
                                            <!-- Claims will be inserted here dynamically -->
                                            <p class="text-sm text-gray-500 text-center py-4">
                                                Esperando a que alguien adivine...
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Guess Button (only for guessers) -->
                                <div id="guess-section" class="mb-6">
                                    <!-- Botón "¡Lo sé!" -->
                                    <div id="claim-section" class="p-6 bg-gradient-to-r from-green-50 to-blue-50 rounded-lg border-2 border-green-300">
                                        <button
                                            id="claim-answer-btn"
                                            class="w-full px-6 py-4 bg-green-600 text-white text-xl font-bold rounded-lg hover:bg-green-700 transition shadow-lg transform hover:scale-105"
                                        >
                                            🙋 ¡Lo sé!
                                        </button>
                                        <p class="text-center text-sm text-gray-600 mt-2">
                                            Presiona cuando sepas la respuesta
                                        </p>
                                    </div>

                                    <!-- Estado: Esperando validación del drawer -->
                                    <div id="waiting-validation" class="hidden p-6 bg-yellow-50 border-2 border-yellow-300 rounded-lg">
                                        <div class="text-center">
                                            <div class="text-4xl mb-3">⏳</div>
                                            <h3 class="text-lg font-bold text-yellow-900 mb-2">Esperando validación...</h3>
                                            <p class="text-sm text-yellow-700">El dibujante está validando tu respuesta</p>
                                        </div>
                                    </div>

                                    <!-- Estado: Respuesta correcta -->
                                    <div id="correct-overlay" class="hidden p-6 bg-green-100 border-2 border-green-400 rounded-lg">
                                        <div class="text-center">
                                            <div class="text-5xl mb-3">🎉</div>
                                            <h3 class="text-xl font-bold text-green-900 mb-2">¡Correcto!</h3>
                                            <p id="points-earned" class="text-lg text-green-700 font-semibold"></p>
                                        </div>
                                    </div>

                                    <!-- Estado: Respuesta incorrecta -->
                                    <div id="incorrect-overlay" class="hidden p-6 bg-red-100 border-2 border-red-400 rounded-lg">
                                        <div class="text-center">
                                            <div class="text-5xl mb-3">❌</div>
                                            <h3 class="text-xl font-bold text-red-900 mb-2">Incorrecto</h3>
                                            <p class="text-sm text-red-700">Sigue intentando en la siguiente ronda</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Live Guesses Feed -->
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <h3 class="text-lg font-bold text-gray-900 mb-3">Intentos</h3>
                                    <div id="guesses-feed" class="space-y-2 max-h-96 overflow-y-auto">
                                        <!-- Guesses will be inserted here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Player Disconnected Popup --}}
    <x-game.player-disconnected-popup />

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

        /* Canvas cursor */
        #drawing-canvas.drawing {
            cursor: crosshair;
        }

        #drawing-canvas.disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Active tool buttons */
        .color-btn.active {
            border-width: 4px;
            border-color: #2563EB;
        }

        .brush-btn.active {
            background-color: #3B82F6;
            color: white;
        }
    </style>

    <script type="module">
        // Cargar PictionaryGameClient de forma lazy
        await window.loadPictionaryGameClient();

        // Configuración del juego desde PHP
        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            userId: {{ $userId }}, // Necesario para canal privado
            gameSlug: 'pictionary',
            players: [],  // Se cargará dinámicamente
            scores: {},
            gameState: null,
            eventConfig: @json($eventConfig ?? null),
            timing: {}
        };

        console.log('[Pictionary] Config loaded:', config);

        // Crear instancia del cliente de Pictionary
        const pictionaryClient = new window.PictionaryGameClient(config);

        // 🔥 Cargar estado inicial ANTES de conectar WebSockets
        // Esto previene race conditions donde los eventos llegan antes del estado inicial
        (async () => {
            try {
                const response = await fetch(`/api/rooms/{{ $code }}/state`);
                if (response.ok) {
                    const data = await response.json();
                    const gameState = data.game_state;

                    console.log('[Pictionary] Initial state loaded:', gameState);

                    // Cargar players desde el estado
                    pictionaryClient.players = data.players || [];
                    pictionaryClient.gameState = gameState;

                    // Cargar scores desde player_system
                    const playerSystem = gameState.player_system?.players || {};
                    pictionaryClient.scores = {};
                    Object.keys(playerSystem).forEach(playerId => {
                        pictionaryClient.scores[playerId] = playerSystem[playerId].score || 0;
                    });
                    console.log('[Pictionary] Initial scores loaded:', pictionaryClient.scores);

                    // Renderizar lista de jugadores con scores actualizados
                    pictionaryClient.renderPlayersList();

                    // Si el juego ya empezó, restaurar estado según la fase
                    if (gameState?.phase === 'playing') {
                        const eventData = {
                            current_round: gameState.round_system?.current_round || 1,
                            total_rounds: gameState.round_system?.total_rounds || 10,
                            drawer_id: gameState.drawer_rotation?.[gameState.current_drawer_index || 0],
                            word: null, // Solo el drawer lo verá
                            game_state: gameState
                        };

                        // Si soy el drawer, obtener la palabra actual
                        const currentDrawerId = gameState.drawer_rotation?.[gameState.current_drawer_index || 0];
                        if (currentDrawerId === config.playerId) {
                            // La palabra actual está en current_word (si no fue filtrada)
                            const currentWord = gameState.current_word;
                            if (currentWord) {
                                // Simular evento WordRevealedEvent para mostrar la palabra
                                const wordEvent = {
                                    word: currentWord.word,
                                    difficulty: currentWord.difficulty || 'medium',
                                    round_number: gameState.round_system?.current_round || 1
                                };
                                pictionaryClient.handleWordRevealed(wordEvent);
                                console.log('[Pictionary] Word restored from state on reconnect:', currentWord.word);
                            }
                        }

                        // Mostrar la pregunta primero (sin timing)
                        pictionaryClient.handleRoundStarted(eventData);

                        // ✅ SISTEMA UNIFICADO DE FASES: Reconstruir y reiniciar timer con PhaseChangedEvent
                        const timerData = gameState.timer_system?.timers?.round;
                        if (timerData) {
                            const startedAt = new Date(timerData.started_at).getTime() / 1000; // a segundos UNIX
                            const duration = timerData.duration;

                            // Simular PhaseChangedEvent para que BaseGameClient reinicie el timer
                            const phaseEvent = {
                                new_phase: 'main',
                                previous_phase: '',
                                additional_data: {
                                    server_time: startedAt,
                                    duration: duration
                                }
                            };

                            // Después de mostrar la ronda, emitir el evento de fase
                            // para que BaseGameClient.handlePhaseChanged() reinicie el timer
                            setTimeout(() => {
                                pictionaryClient.handlePhaseChanged(phaseEvent);
                            }, 100);
                        }

                        // Si ya dibujamos o adivinamos, mostrar overlay de bloqueado
                        const locks = gameState.player_state_system?.locks || {};
                        if (locks[config.playerId] === true) {
                            pictionaryClient.isLocked = true;
                            document.getElementById('locked-overlay')?.classList.remove('hidden');
                        }

                        // Renderizar strokes existentes en el canvas
                        const canvasData = gameState.canvas_data || [];
                        canvasData.forEach(stroke => {
                            pictionaryClient.renderStroke(stroke);
                        });
                    } else if (gameState?.phase === 'finished') {
                        // Juego terminado - Restaurar pantalla de resultados finales
                        console.log('[Pictionary] Restoring finished state on reconnect');

                        // Simular evento GameFinishedEvent
                        const finishedEvent = {
                            winner: gameState.winner || null,
                            ranking: gameState.ranking || [],
                            scores: pictionaryClient.scores,
                            game_state: gameState
                        };

                        pictionaryClient.handleGameFinished(finishedEvent);
                        console.log('[Pictionary] Finished state restored');
                    }
                } else {
                    console.warn('⚠️ [Pictionary] Could not load initial state');
                }
            } catch (error) {
                console.error('❌ [Pictionary] Error loading initial state:', error);
            }

            // Configurar Event Manager DESPUÉS de cargar el estado inicial
            // PictionaryGameClient.setupEventManager() registra automáticamente los handlers desde capabilities.json
            pictionaryClient.setupEventManager();
        })();
    </script>
</x-app-layout>
