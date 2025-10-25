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
                <div class="px-6 py-12 text-center">
                    <div class="mb-6">
                        <span class="text-8xl">✅</span>
                    </div>

                    <h2 class="text-3xl font-bold text-gray-800 mb-6">Trivia está funcionando</h2>

                    <p class="text-gray-600 mb-3">
                        Sala: <strong class="text-gray-900">{{ $code }}</strong>
                    </p>

                    <p class="text-gray-600 mb-8">
                        Match ID: <strong class="text-gray-900">{{ $match->id }}</strong>
                    </p>

                    <!-- Status -->
                    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 mx-auto max-w-2xl">
                        <p class="font-bold text-blue-800 mb-2">Flujo híbrido funcionando:</p>
                        <div class="text-left text-sm text-blue-700 space-y-1">
                            <div>✅ FASE 1: Lobby completado</div>
                            <div>✅ FASE 2: Countdown completado</div>
                            <div>✅ FASE 3: Juego iniciado</div>
                        </div>
                    </div>

                    <hr class="my-8 border-gray-300">

                    <h5 class="text-lg font-semibold text-gray-800 mb-4">Próximos pasos:</h5>
                    <ul class="text-left text-gray-700 space-y-2 max-w-md mx-auto">
                        <li>📝 Cargar preguntas desde questions.json</li>
                        <li>🎯 Mostrar primera pregunta</li>
                        <li>⏱️ Timer por pregunta</li>
                        <li>🎮 Procesar respuestas</li>
                        <li>🏆 Sistema de puntuación</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
    const roomCode = '{{ $code }}';
    const matchId = {{ $match->id }};

    console.log('🎮 [Trivia] Game view loaded', {
        roomCode,
        matchId
    });

    // Conectar al canal de la sala
    if (typeof window.Echo !== 'undefined') {
        const channel = window.Echo.channel(`room.${roomCode}`);

        // Escuchar evento de ronda iniciada
        channel.listen('.game.round-started', (data) => {
            console.log('🎮 [Trivia] Round started event received:', data);
        });

        console.log('✅ [Trivia] WebSocket channel connected');
    }
    </script>
</x-app-layout>
