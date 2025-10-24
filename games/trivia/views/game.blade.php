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
                @elseif(($match->game_state['phase'] ?? '') === 'question')
                    @php
                        $currentQuestion = $match->game_state['current_question'] ?? null;
                        $currentRound = $match->game_state['round_system']['current_round'] ?? 1;
                        $totalRounds = $match->game_state['round_system']['total_rounds'] ?? 10;

                        // Calcular tiempo restante del timer 'turn_timer'
                        $timerSystem = $match->game_state['timer_system'] ?? [];
                        $turnTimer = $timerSystem['timers']['turn_timer'] ?? null;
                        $remaining = null;
                        $totalTime = 15; // default

                        if ($turnTimer) {
                            $startTime = $turnTimer['started_at'];
                            $duration = $turnTimer['duration'];
                            $elapsed = time() - $startTime;
                            $remaining = max(0, $duration - $elapsed);
                            $totalTime = $duration;
                        }
                    @endphp

                    @if($currentQuestion)
                        <h2 class="text-3xl font-bold mb-4">Pregunta {{ $currentRound }}/{{ $totalRounds }}</h2>

                        @if($remaining !== null)
                            <div class="mb-6">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="text-sm font-medium text-gray-600">Tiempo restante</span>
                                    <span id="time-counter" class="text-2xl font-bold {{ $remaining <= 5 ? 'text-red-600 animate-pulse' : 'text-blue-600' }}">{{ $remaining }}s</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                                    <div id="time-progress" class="{{ $remaining <= 5 ? 'bg-red-500' : 'bg-blue-500' }} h-full transition-all duration-1000 ease-linear" style="width: {{ ($remaining / $totalTime) * 100 }}%"></div>
                                </div>
                            </div>
                        @endif

                        <p class="text-2xl mb-8">{{ $currentQuestion['question'] }}</p>
                        <div class="space-y-4">
                            @foreach($currentQuestion['options'] as $index => $option)
                                <button class="w-full py-4 px-6 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-xl font-semibold transition">
                                    {{ $index + 1 }}. {{ $option }}
                                </button>
                            @endforeach
                        </div>

                        <script>
                        // Continuar countdown desde donde se quedó
                        (function() {
                            let remaining = {{ $remaining ?? 0 }};
                            const totalTime = {{ $totalTime }};
                            const warningThreshold = 5;
                            const questionIndex = {{ $match->game_state['current_question_index'] ?? 0 }};

                            if (remaining <= 0) return;

                            const timeCounter = document.getElementById('time-counter');
                            const timeProgress = document.getElementById('time-progress');

                            const interval = setInterval(() => {
                                remaining--;

                                if (remaining <= 0) {
                                    clearInterval(interval);
                                    timeCounter.textContent = '¡Se acabó el tiempo!';
                                    timeCounter.classList.add('text-red-600');
                                    timeProgress.style.width = '0%';

                                    // Notificar al backend que el tiempo expiró (endpoint genérico)
                                    fetch('/api/games/{{ $match->id }}/turn-timeout', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                        }
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            console.log('⏰ Timeout procesado:', data.message);
                                        } else {
                                            console.log('⏸️  Timeout ya procesado por otro cliente');
                                        }
                                    })
                                    .catch(error => {
                                        console.error('❌ Error notificando timeout:', error);
                                    });

                                    return;
                                }

                                timeCounter.textContent = remaining + 's';
                                const percentage = (remaining / totalTime) * 100;
                                timeProgress.style.width = percentage + '%';

                                if (remaining <= warningThreshold) {
                                    timeCounter.classList.remove('text-blue-600');
                                    timeCounter.classList.add('text-red-600', 'animate-pulse');
                                    timeProgress.classList.remove('bg-blue-500');
                                    timeProgress.classList.add('bg-red-500');
                                }
                            }, 1000);
                        })();
                        </script>
                    @else
                        <p class="text-2xl font-semibold">Cargando pregunta...</p>
                    @endif
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
                const timing = event.timing; // { type, delay, remaining, warning_threshold }

                if (!currentQuestion) {
                    console.error('❌ No current_question in game_state');
                    return;
                }

                // Actualizar el contenido para mostrar la pregunta + timer
                const gameStateDiv = document.getElementById('game-state');
                gameStateDiv.innerHTML = `
                    <h2 class="text-3xl font-bold mb-4">Pregunta ${event.current_round}/${event.total_rounds}</h2>

                    ${timing ? `
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-medium text-gray-600">Tiempo restante</span>
                                <span id="time-counter" class="text-2xl font-bold text-blue-600">${timing.remaining || timing.delay}s</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                                <div id="time-progress" class="bg-blue-500 h-full transition-all duration-1000 ease-linear" style="width: 100%"></div>
                            </div>
                        </div>
                    ` : ''}

                    <p class="text-2xl mb-8">${currentQuestion.question}</p>
                    <div class="space-y-4">
                        ${currentQuestion.options.map((option, index) => `
                            <button class="w-full py-4 px-6 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-xl font-semibold transition">
                                ${index + 1}. ${option}
                            </button>
                        `).join('')}
                    </div>
                `;

                // Iniciar countdown visual si hay timing
                if (timing && timing.delay) {
                    let remaining = timing.remaining !== undefined ? timing.remaining : timing.delay;
                    const totalTime = timing.delay;
                    const warningThreshold = timing.warning_threshold || 5;

                    const timeCounter = document.getElementById('time-counter');
                    const timeProgress = document.getElementById('time-progress');

                    // Actualizar cada segundo
                    const interval = setInterval(() => {
                        remaining--;

                        if (remaining <= 0) {
                            clearInterval(interval);
                            timeCounter.textContent = '¡Se acabó el tiempo!';
                            timeCounter.classList.add('text-red-600');
                            timeProgress.style.width = '0%';

                            // Notificar al backend que el tiempo expiró (endpoint genérico)
                            fetch(`/api/games/${window.gameConfig.matchId}/turn-timeout`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                                }
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    console.log('⏰ Timeout procesado:', data.message);
                                } else {
                                    console.log('⏸️  Timeout ya procesado por otro cliente');
                                }
                            })
                            .catch(error => {
                                console.error('❌ Error notificando timeout:', error);
                            });

                            return;
                        }

                        // Actualizar contador
                        timeCounter.textContent = remaining + 's';

                        // Actualizar barra de progreso
                        const percentage = (remaining / totalTime) * 100;
                        timeProgress.style.width = percentage + '%';

                        // Cambiar color cuando queda poco tiempo
                        if (remaining <= warningThreshold) {
                            timeCounter.classList.remove('text-blue-600');
                            timeCounter.classList.add('text-red-600', 'animate-pulse');
                            timeProgress.classList.remove('bg-blue-500');
                            timeProgress.classList.add('bg-red-500');
                        }
                    }, 1000);
                }
            };

            // Handler para PhaseChangedEvent (genérico)
            window.game.handlePhaseChanged = function(event) {
                console.log('🔄 [TriviaGame] PhaseChangedEvent received:', event);
                // En Trivia no necesitamos hacer nada especial con cambios de fase
                // Las transiciones se manejan con RoundStartedEvent y GameFinishedEvent
            };

            // Handler para TurnChangedEvent (genérico)
            window.game.handleTurnChanged = function(event) {
                console.log('↪️ [TriviaGame] TurnChangedEvent received:', event);
                // En Trivia modo simultáneo no hay turnos, este evento no aplica
            };

            // Handler para GameFinishedEvent (genérico)
            window.game.handleGameFinished = function(event) {
                console.log('🏁 [TriviaGame] GameFinishedEvent received:', event);

                const gameStateDiv = document.getElementById('game-state');
                const scores = event.game_state.scoring_system?.scores || {};

                // Crear array de jugadores con sus puntos
                const players = window.gameConfig.players.map(player => ({
                    name: player.name,
                    score: scores[player.id] || 0
                }));

                // Ordenar por puntos (mayor a menor)
                players.sort((a, b) => b.score - a.score);

                // Mostrar pantalla de resultados finales
                gameStateDiv.innerHTML = `
                    <div class="text-center">
                        <h2 class="text-4xl font-bold mb-4 text-green-600">🏆 ¡Juego Terminado!</h2>
                        <div class="mt-8 space-y-4">
                            <h3 class="text-2xl font-semibold mb-4">Resultados Finales:</h3>
                            ${players.map((player, index) => `
                                <div class="flex justify-between items-center p-4 ${index === 0 ? 'bg-yellow-100 border-2 border-yellow-400' : 'bg-gray-100'} rounded-lg">
                                    <span class="text-xl font-semibold">
                                        ${index === 0 ? '👑 ' : `${index + 1}. `}${player.name}
                                    </span>
                                    <span class="text-2xl font-bold text-blue-600">${player.score} pts</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            };

            window.game.setupEventManager();
        }
    });
    </script>
</body>
</html>
