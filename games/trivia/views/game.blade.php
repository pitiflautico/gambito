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
                    <!-- Loading State -->
                    <div id="loading-state" class="text-center">
                        <div class="mb-6">
                            <span class="text-6xl">⏳</span>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Esperando primera pregunta...</h2>
                        <p class="text-gray-600">
                            Sala: <strong class="text-gray-900">{{ $code }}</strong>
                        </p>
                    </div>

                    <!-- Question State (hidden initially) -->
                    <div id="question-state" class="hidden">
                        <!-- Round Info -->
                        <div class="text-center mb-8">
                            <p class="text-lg text-gray-600">
                                Ronda <strong id="current-round" class="text-blue-600">1</strong> de <strong id="total-rounds" class="text-blue-600">10</strong>
                            </p>
                        </div>

                        <!-- Category -->
                        <div class="text-center mb-6">
                            <span id="question-category" class="inline-block bg-purple-100 text-purple-800 text-sm font-semibold px-4 py-2 rounded-full"></span>
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
    const roomCode = '{{ $code }}';
    const matchId = {{ $match->id }};

    console.log('🎮 [Trivia] Game view loaded', {
        roomCode,
        matchId
    });

    // Helper para mostrar la pregunta
    function displayQuestion(question, categoryName, currentRound, totalRounds) {
        console.log('📝 [Trivia] Displaying question:', question);

        // Ocultar loading, mostrar pregunta
        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('question-state').classList.remove('hidden');

        // Actualizar info de ronda
        document.getElementById('current-round').textContent = currentRound;
        document.getElementById('total-rounds').textContent = totalRounds;

        // Actualizar categoría
        document.getElementById('question-category').textContent = categoryName || question.category || 'General';

        // Actualizar pregunta
        document.getElementById('question-text').textContent = question.question;

        // Renderizar opciones
        const optionsContainer = document.getElementById('options-container');
        optionsContainer.innerHTML = '';

        question.options.forEach((option, index) => {
            const button = document.createElement('button');
            button.className = 'w-full bg-white hover:bg-blue-50 text-left px-6 py-4 rounded-lg border-2 border-gray-300 hover:border-blue-500 transition-all duration-200 text-lg font-medium text-gray-800';
            button.textContent = option;
            button.dataset.index = index;

            button.addEventListener('click', () => {
                console.log('✅ [Trivia] Option selected:', { index, option });
                // TODO: Enviar respuesta al backend
                alert(`Seleccionaste: ${option} (índice: ${index})\n\nTODO: Implementar envío de respuesta al backend`);
            });

            optionsContainer.appendChild(button);
        });
    }

    // Conectar al canal de la sala
    if (typeof window.Echo !== 'undefined') {
        const channel = window.Echo.channel(`room.${roomCode}`);

        // ✅ CORREGIDO: Escuchar evento con el nombre correcto
        channel.listen('.game.round.started', (data) => {
            console.log('🎮 [Trivia] Round started event received:', data);

            const gameState = data.game_state;
            const currentQuestion = gameState.current_question;
            const categories = gameState._config?.categories || {};

            if (currentQuestion) {
                const categoryName = categories[currentQuestion.category] || currentQuestion.category;
                displayQuestion(currentQuestion, categoryName, data.current_round, data.total_rounds);
            } else {
                console.error('❌ [Trivia] No current_question in game_state:', gameState);
            }
        });

        console.log('✅ [Trivia] WebSocket channel connected');
    } else {
        console.error('❌ [Trivia] Echo not available');
    }

    // Cargar pregunta actual si ya existe (refresh)
    window.addEventListener('load', async () => {
        try {
            const response = await fetch(`/api/rooms/${roomCode}/state`);
            if (response.ok) {
                const data = await response.json();
                const gameState = data.game_state;
                const currentQuestion = gameState?.current_question;
                const roundSystem = gameState?.round_system;
                const categories = gameState?._config?.categories || {};

                if (currentQuestion && roundSystem) {
                    const categoryName = categories[currentQuestion.category] || currentQuestion.category;
                    displayQuestion(
                        currentQuestion,
                        categoryName,
                        roundSystem.current_round,
                        roundSystem.total_rounds
                    );
                    console.log('✅ [Trivia] Loaded current question from API');
                }
            }
        } catch (error) {
            console.error('❌ [Trivia] Error loading initial state:', error);
        }
    });
    </script>
</x-app-layout>
