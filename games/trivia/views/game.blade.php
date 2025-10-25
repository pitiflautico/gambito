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
                    <div id="question-state" class="hidden relative">
                        <!-- Locked Overlay (shown when player is locked) -->
                        <div id="locked-overlay" class="hidden absolute inset-0 bg-gray-900 bg-opacity-60 backdrop-blur-sm z-10 flex items-center justify-center rounded-lg">
                            <div class="bg-white px-8 py-6 rounded-lg shadow-xl text-center">
                                <div class="text-6xl mb-4">🔒</div>
                                <h3 class="text-2xl font-bold text-gray-800 mb-2">Ya respondiste</h3>
                                <p class="text-gray-600">Esperando a los demás jugadores...</p>
                            </div>
                        </div>

                        <!-- Round Info -->
                        <div class="text-center mb-8">
                            <p class="text-lg text-gray-600">
                                Ronda <strong id="current-round" class="text-blue-600">1</strong> de <strong id="total-rounds" class="text-blue-600">10</strong>
                            </p>
                            <p id="players-answered" class="text-sm text-gray-500 mt-2">
                                <!-- Se actualizará vía JavaScript -->
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
    const currentUserId = {{ Auth::id() }};
    let currentPlayerId = null; // Se obtendrá del backend
    let isPlayerLocked = false;
    let playersAnswered = new Set();
    let totalPlayers = 0;

    console.log('🎮 [Trivia] Game view loaded', {
        roomCode,
        matchId,
        currentUserId
    });

    // Obtener el player_id del game_state usando el mapeo
    async function extractPlayerIdFromGameState(gameState) {
        if (gameState?._config?.user_to_player_map) {
            const userToPlayerMap = gameState._config.user_to_player_map;
            currentPlayerId = userToPlayerMap[currentUserId];
            
            if (currentPlayerId) {
                console.log('✅ [Trivia] Player ID mapped:', {
                    user_id: currentUserId,
                    player_id: currentPlayerId
                });
                return true;
            } else {
                console.warn('⚠️ [Trivia] user_id not found in player map');
            }
        } else {
            console.warn('⚠️ [Trivia] No user_to_player_map found, fetching from API...');
            // Fallback: obtener player_id desde el API
            try {
                const response = await fetch(`/api/rooms/${roomCode}/player-info`);
                if (response.ok) {
                    const data = await response.json();
                    currentPlayerId = data.player_id;
                    console.log('✅ [Trivia] Player ID fetched from API:', currentPlayerId);
                    return true;
                } else {
                    console.error('❌ [Trivia] Failed to fetch player info');
                }
            } catch (error) {
                console.error('❌ [Trivia] Error fetching player info:', error);
            }
        }
        return false;
    }

    // Helper para actualizar contador de jugadores
    function updatePlayersAnsweredCounter() {
        const counter = document.getElementById('players-answered');
        if (counter && totalPlayers > 0) {
            counter.textContent = `${playersAnswered.size} de ${totalPlayers} jugadores han respondido`;
            counter.classList.toggle('text-green-600', playersAnswered.size === totalPlayers);
        }
    }

    // Helper para mostrar el overlay de bloqueado
    function showLockedOverlay() {
        console.log('🔒 [Trivia] Showing locked overlay');
        const overlay = document.getElementById('locked-overlay');
        overlay.classList.remove('hidden');
        isPlayerLocked = true;
        
        // Deshabilitar botones de respuesta
        document.querySelectorAll('#options-container button').forEach(btn => {
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
        });
    }

    // Helper para ocultar el overlay de bloqueado (nueva ronda)
    function hideLockedOverlay() {
        console.log('🔓 [Trivia] Hiding locked overlay');
        const overlay = document.getElementById('locked-overlay');
        overlay.classList.add('hidden');
        isPlayerLocked = false;
        
        // Re-habilitar botones de respuesta
        document.querySelectorAll('#options-container button').forEach(btn => {
            btn.disabled = false;
            btn.classList.remove('opacity-50', 'cursor-not-allowed');
        });
        
        // Resetear contador de jugadores
        playersAnswered.clear();
        updatePlayersAnsweredCounter();
    }

    // Helper para mostrar resultados finales del juego
    function showFinalResults(gameState) {
        console.log('🏆 [Trivia] Showing final results:', gameState);
        
        const ranking = gameState.ranking || [];
        const winner = gameState.winner;
        const scores = gameState.final_scores || {};
        
        // Ocultar loading y pregunta
        document.getElementById('loading-state').classList.add('hidden');
        document.getElementById('question-state').classList.add('hidden');
        
        // Crear pantalla de resultados finales
        let resultsHTML = '<div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 3rem; border-radius: 1rem; box-shadow: 0 20px 50px rgba(0,0,0,0.3); z-index: 1000; max-width: 600px; width: 90%; text-align: center;">';
        
        resultsHTML += '<h1 style="font-size: 2.5rem; font-weight: bold; color: #10b981; margin-bottom: 1rem;">🏆 ¡Juego Terminado!</h1>';
        
        if (winner) {
            resultsHTML += `<p style="font-size: 1.25rem; color: #374151; margin-bottom: 2rem;">🎉 Ganador: Jugador ${winner}</p>`;
        }
        
        resultsHTML += '<div style="background: #f3f4f6; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1.5rem;">';
        resultsHTML += '<h2 style="font-size: 1.5rem; font-weight: 600; color: #374151; margin-bottom: 1rem;">📊 Ranking Final</h2>';
        
        ranking.forEach(entry => {
            const medal = entry.position === 1 ? '🥇' : entry.position === 2 ? '🥈' : entry.position === 3 ? '🥉' : `${entry.position}°`;
            const bgColor = entry.position === 1 ? '#dcfce7' : entry.position === 2 ? '#fef3c7' : entry.position === 3 ? '#fed7aa' : '#f9fafb';
            resultsHTML += `<div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; margin-bottom: 0.5rem; background: ${bgColor}; border-radius: 0.5rem;">`;
            resultsHTML += `<span style="font-size: 1.125rem; font-weight: 600;">${medal} Jugador ${entry.player_id}</span>`;
            resultsHTML += `<span style="font-size: 1.125rem; font-weight: bold; color: #10b981;">${entry.score} pts</span>`;
            resultsHTML += '</div>';
        });
        
        resultsHTML += '</div>';
        
        resultsHTML += '<button onclick="window.location.href=\'/\'" style="background: #3b82f6; color: white; padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; border: none; cursor: pointer; font-size: 1rem;">Volver al inicio</button>';
        
        resultsHTML += '</div>';
        
        // Crear backdrop
        const backdrop = document.createElement('div');
        backdrop.id = 'final-results-backdrop';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.8); z-index: 999;';
        backdrop.innerHTML = resultsHTML;
        
        document.body.appendChild(backdrop);
    }

    // Helper para mostrar resultados de la ronda
    function showRoundResults(results, scores) {
        console.log('📊 [Trivia] Showing round results:', results);

        const question = results.question;
        const players = results.players || [];
        const winnerId = results.winner_id;
        const correctAnswerIndex = question.correct_answer;
        const correctAnswerText = question.options[correctAnswerIndex];

        // Crear overlay de resultados
        let resultHTML = '<div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 1rem; box-shadow: 0 20px 50px rgba(0,0,0,0.3); z-index: 1000; max-width: 500px; width: 90%;">';
        
        // Título
        if (winnerId) {
            const winner = players.find(p => p.player_id === winnerId);
            resultHTML += `<h2 style="font-size: 1.5rem; font-weight: bold; color: #10b981; margin-bottom: 1rem;">✅ ¡Correcto!</h2>`;
            resultHTML += `<p style="font-size: 1rem; color: #374151; margin-bottom: 1rem;">Jugador ${winnerId} acertó primero</p>`;
        } else {
            resultHTML += `<h2 style="font-size: 1.5rem; font-weight: bold; color: #ef4444; margin-bottom: 1rem;">❌ Nadie acertó</h2>`;
        }

        // Mostrar respuesta correcta
        resultHTML += `<div style="background: #f3f4f6; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">`;
        resultHTML += `<p style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Respuesta correcta:</p>`;
        resultHTML += `<p style="font-size: 1.125rem; font-weight: 600; color: #10b981;">${correctAnswerText}</p>`;
        resultHTML += `</div>`;

        // Mostrar quién respondió qué
        if (players.length > 0) {
            resultHTML += `<div style="margin-top: 1rem;">`;
            resultHTML += `<p style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Respuestas:</p>`;
            players.forEach(p => {
                const icon = p.is_correct ? '✅' : '❌';
                const color = p.is_correct ? '#10b981' : '#ef4444';
                resultHTML += `<p style="font-size: 0.875rem; color: ${color};">${icon} Jugador ${p.player_id}: ${question.options[p.answer_index]}</p>`;
            });
            resultHTML += `</div>`;
        }

        // Agregar espacio para countdown
        resultHTML += `<div id="countdown-container" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 2px solid #3b82f6; text-align: center;">`;
        resultHTML += `<p id="countdown-text" style="font-size: 1.25rem; font-weight: 600; color: #3b82f6;">⏱️</p>`;
        resultHTML += `</div>`;
        
        resultHTML += '</div>';

        // Crear backdrop oscuro
        const backdrop = document.createElement('div');
        backdrop.id = 'results-backdrop';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); z-index: 999;';
        backdrop.innerHTML = resultHTML;
        
        document.body.appendChild(backdrop);
        
        // Debug: verificar que el elemento countdown se creó
        console.log('📊 [Trivia] Results backdrop added, countdown element should exist now');
        const testCountdown = document.getElementById('countdown-text');
        console.log('🔍 [Trivia] Countdown element check:', testCountdown ? 'FOUND ✅' : 'NOT FOUND ❌');

        // NO remover automáticamente - el countdown o displayQuestion() lo harán
    }

    // Helper para mostrar la pregunta
    function displayQuestion(question, categoryName, currentRound, totalRounds, fromNewRound = false) {
        // Remover resultados anteriores si existen
        const existingBackdrop = document.getElementById('results-backdrop');
        if (existingBackdrop) {
            existingBackdrop.remove();
        }
        console.log('📝 [Trivia] Displaying question:', question);

        // Solo resetear estado de bloqueo si es una nueva ronda (no al recargar)
        if (fromNewRound) {
            hideLockedOverlay();
        }

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

            button.addEventListener('click', async () => {
                // Evitar responder si ya está bloqueado
                if (isPlayerLocked) {
                    console.log('⚠️ [Trivia] Player is already locked');
                    return;
                }

                console.log('✅ [Trivia] Option selected:', { index, option });

                // Deshabilitar todos los botones para evitar doble envío
                document.querySelectorAll('#options-container button').forEach(btn => {
                    btn.disabled = true;
                    btn.classList.add('opacity-50', 'cursor-not-allowed');
                });

                // Marcar visualmente la opción seleccionada (optimistic UI)
                button.classList.add('!bg-blue-500', '!text-white', '!border-blue-600');

                try {
                    // Enviar respuesta al backend usando ruta genérica
                    const response = await fetch(`/api/rooms/${roomCode}/action`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'answer',
                            data: {
                                answer_index: index
                            }
                        })
                    });

                    const result = await response.json();

                    console.log('📨 [Trivia] Answer response:', result);

                    if (!result.success) {
                        console.error('❌ [Trivia] Error:', result.message);
                        
                        // Si el error es que ya respondió, mostrar el overlay
                        if (result.message.includes('Ya respondiste') || result.message.includes('already')) {
                            console.log('🔒 [Trivia] Backend says player is locked');
                            isPlayerLocked = true;
                            showLockedOverlay();
                        } else {
                            // Para otros errores, mostrar alerta y re-habilitar
                            alert('Error: ' + result.message);
                            
                            // Re-habilitar botones si hubo error
                            document.querySelectorAll('#options-container button').forEach(btn => {
                                btn.disabled = false;
                                btn.classList.remove('opacity-50', 'cursor-not-allowed');
                            });
                            button.classList.remove('!bg-blue-500', '!text-white', '!border-blue-600');
                        }
                    }
                    // Si success=true, el evento game.player.locked se encargará del resto
                } catch (error) {
                    console.error('❌ [Trivia] Network error:', error);
                    alert('Error de red al enviar respuesta');

                    // Re-habilitar botones si hubo error de red
                    document.querySelectorAll('#options-container button').forEach(btn => {
                        btn.disabled = false;
                        btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    });
                    button.classList.remove('!bg-blue-500', '!text-white', '!border-blue-600');
                }
            });

            optionsContainer.appendChild(button);
        });
        
        // Si el jugador está bloqueado, deshabilitar botones inmediatamente
        if (isPlayerLocked) {
            console.log('🔒 [Trivia] Player is locked, disabling buttons');
            document.querySelectorAll('#options-container button').forEach(btn => {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed');
            });
        }
    }

    // Conectar al canal de la sala
    if (typeof window.Echo !== 'undefined') {
        const channel = window.Echo.channel(`room.${roomCode}`);

        // Escuchar cuando termina una ronda
        channel.listen('.game.round.ended', async (data) => {
            console.log('🏁 [Trivia] Round ended event received:', data);

            // Mostrar resultados de la ronda
            showRoundResults(data.results, data.scores);

            // Si hay timing configurado (countdown), manejarlo
            if (data.timing && data.timing.auto_next) {
                const delay = data.timing.delay || 3;
                const message = data.timing.message || 'Siguiente pregunta';

                // Esperar un poquito a que el DOM se actualice
                setTimeout(() => {
                    console.log(`⏰ [Trivia] Starting countdown: ${delay}s`);
                    
                    let remaining = delay;
                    
                    const countdownInterval = setInterval(() => {
                        // Buscar el elemento cada vez (por si fue removido)
                        const countdownEl = document.getElementById('countdown-text');
                        
                        if (countdownEl) {
                            if (remaining > 0) {
                                countdownEl.textContent = `${message} en ${remaining}s...`;
                                console.log(`⏳ [Trivia] ${message} en ${remaining}s`);
                            } else if (remaining === 0) {
                                countdownEl.textContent = `Cargando...`;
                                console.log(`⏳ [Trivia] Countdown finished, loading next round`);
                            }
                        } else {
                            console.log('⚠️ [Trivia] Countdown element disappeared');
                        }

                        if (remaining <= 0) {
                            clearInterval(countdownInterval);
                            
                            // Remover el backdrop de resultados
                            const backdrop = document.getElementById('results-backdrop');
                            if (backdrop) {
                                backdrop.remove();
                                console.log('🗑️ [Trivia] Results backdrop removed by countdown');
                            }
                            
                            // Todos los jugadores llaman al endpoint
                            // El sistema de locks + round ID previenen race conditions
                            const currentRound = data.round_number; // Número de ronda que acaba de terminar
                            console.log('🔄 [Trivia] Calling next-round', { from_round: currentRound });
                            
                            fetch(`/api/rooms/${roomCode}/next-round`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({
                                    from_round: currentRound // Enviar ronda actual para validación
                                })
                            }).then(response => response.json()).then(result => {
                                console.log('✅ [Trivia] Next round response:', result);
                            }).catch(error => {
                                console.error('❌ [Trivia] Error requesting next round:', error);
                            });
                        }
                        
                        remaining--;
                    }, 1000);
                    
                    // Actualizar inmediatamente con el valor inicial
                    const countdownEl = document.getElementById('countdown-text');
                    console.log('🔍 [Trivia] Countdown element found:', !!countdownEl);
                    if (countdownEl) {
                        countdownEl.textContent = `${message} en ${remaining}s...`;
                    }
                }, 100); // Esperar 100ms a que el DOM se actualice
            }
        });

        // Escuchar cuando el juego termina
        channel.listen('.game.ended', (data) => {
            console.log('🏆 [Trivia] Game ended event received:', data);
            
            // Preparar gameState con los datos del evento
            const gameState = {
                phase: 'finished',
                winner: data.winner,
                ranking: data.ranking,
                final_scores: data.scores
            };
            
            showFinalResults(gameState);
        });

        // Escuchar cuando inicia una ronda
        channel.listen('.game.round.started', (data) => {
            console.log('🎮 [Trivia] Round started event received:', data);

            const gameState = data.game_state;
            const currentQuestion = gameState.current_question;
            const categories = gameState._config?.categories || {};
            
            // Extraer player_id si aún no lo tenemos
            if (!currentPlayerId) {
                extractPlayerIdFromGameState(gameState);
            }
            
            // Obtener total de jugadores desde player_state_system
            if (gameState.player_state_system?.player_ids) {
                totalPlayers = gameState.player_state_system.player_ids.length;
                console.log('👥 [Trivia] Total players:', totalPlayers);
            }

            if (currentQuestion) {
                const categoryName = categories[currentQuestion.category] || currentQuestion.category;
                displayQuestion(currentQuestion, categoryName, data.current_round, data.total_rounds, true); // true = nueva ronda
            } else {
                console.error('❌ [Trivia] No current_question in game_state:', gameState);
            }
        });

        // Escuchar cuando todos los jugadores se desbloquean (nueva ronda)
        channel.listen('.game.players.unlocked', (data) => {
            console.log('🔓 [Trivia] Players unlocked event received:', data);
            
            // Ocultar overlay de bloqueado para todos
            hideLockedOverlay();
            
            // Resetear contador de jugadores que respondieron
            playersAnswered.clear();
            updatePlayersAnsweredCounter();
            
            // Re-habilitar botones si existen
            document.querySelectorAll('#options-container button').forEach(btn => {
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            });
        });

        // Escuchar cuando un jugador individual se desbloquea
        channel.listen('.game.player.unlocked', (data) => {
            console.log('🔓 [Trivia] Player unlocked event received:', data);

            // Si es el jugador actual, ocultar overlay
            if (data.player_id === currentPlayerId) {
                console.log('🔓 [Trivia] Current user unlocked');
                hideLockedOverlay();
                
                // Re-habilitar botones si existen
                document.querySelectorAll('#options-container button').forEach(btn => {
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                });
            }

            // Actualizar contador (remover de la lista de respondidos)
            playersAnswered.delete(data.player_id);
            updatePlayersAnsweredCounter();
        });

        // Escuchar cuando un jugador se bloquea
        channel.listen('.game.player.locked', (data) => {
            console.log('🔒 [Trivia] Player locked event received:', data);

            // Solo procesar si es el jugador actual (usar player_id, no user_id)
            if (data.player_id === currentPlayerId) {
                console.log('🔒 [Trivia] Current user locked');
                
                const additionalData = data.additional_data || {};
                const isCorrect = additionalData.is_correct;
                const answerIndex = additionalData.answer_index;
                const correctAnswer = additionalData.correct_answer;
                const points = additionalData.points;

                // Actualizar visualmente la respuesta seleccionada
                const buttons = document.querySelectorAll('#options-container button');
                buttons.forEach((btn) => {
                    const btnIndex = parseInt(btn.dataset.index);
                    
                    if (btnIndex === answerIndex) {
                        // Esta es la respuesta que seleccionó el jugador
                        if (isCorrect) {
                            btn.classList.remove('!bg-blue-500');
                            btn.classList.add('!bg-green-500', '!text-white', '!border-green-600');
                            btn.textContent = `✅ ${btn.textContent.replace('✅ ', '')}`;
                            console.log('✅ [Trivia] Correct answer! +' + points + ' points');
                        } else {
                            btn.classList.remove('!bg-blue-500');
                            btn.classList.add('!bg-red-500', '!text-white', '!border-red-600');
                            btn.textContent = `❌ ${btn.textContent.replace('❌ ', '')}`;
                            console.log('❌ [Trivia] Incorrect answer');
                        }
                    }
                    
                    // Si es incorrecta, también mostrar la correcta
                    if (!isCorrect && btnIndex === correctAnswer) {
                        btn.classList.add('!bg-green-100', '!border-green-500');
                        btn.textContent = `✓ ${btn.textContent}`;
                    }
                });

                // Agregar al contador de jugadores que respondieron
                playersAnswered.add(data.player_id);
                updatePlayersAnsweredCounter();

                // Mostrar overlay de bloqueado después de un pequeño delay para que se vea la respuesta
                setTimeout(() => {
                    showLockedOverlay();
                }, 1000);
            } else {
                console.log('👤 [Trivia] Other player locked:', data.player_name);
                
                // Agregar al contador de jugadores que respondieron
                playersAnswered.add(data.player_id);
                updatePlayersAnsweredCounter();
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
                
                // PRIMERO: Verificar si el juego terminó
                if (gameState?.phase === 'finished') {
                    console.log('🏁 [Trivia] Game finished, showing final results');
                    showFinalResults(gameState);
                    return; // Salir sin procesar pregunta actual
                }
                
                const currentQuestion = gameState?.current_question;
                const roundSystem = gameState?.round_system;
                const categories = gameState?._config?.categories || {};
                const playerStateSystem = gameState?.player_state_system;

                // SEGUNDO: Extraer el player_id del mapeo (esperar porque es async)
                await extractPlayerIdFromGameState(gameState);

                // SEGUNDO: Obtener total de jugadores desde player_state_system
                if (playerStateSystem?.player_ids) {
                    totalPlayers = playerStateSystem.player_ids.length;
                    console.log('👥 [Trivia] Total players loaded:', totalPlayers);
                }

                // TERCERO: Verificar si el jugador actual está bloqueado (ahora que tenemos currentPlayerId)
                if (currentPlayerId && playerStateSystem?.locks) {
                    const isLocked = playerStateSystem.locks[currentPlayerId] === true;
                    console.log('🔍 [Trivia] Checking lock status:', {
                        player_id: currentPlayerId,
                        is_locked: isLocked,
                        all_locks: playerStateSystem.locks
                    });
                    
                    if (isLocked) {
                        console.log('🔒 [Trivia] Player was already locked, will show overlay');
                        isPlayerLocked = true;
                        
                        // Contar jugadores que ya respondieron
                        Object.keys(playerStateSystem.locks).forEach(playerId => {
                            if (playerStateSystem.locks[playerId]) {
                                playersAnswered.add(parseInt(playerId));
                            }
                        });
                        updatePlayersAnsweredCounter();
                    } else {
                        console.log('✅ [Trivia] Player is not locked');
                    }
                } else {
                    console.warn('⚠️ [Trivia] Could not verify lock status:', {
                        has_player_id: !!currentPlayerId,
                        has_locks: !!playerStateSystem?.locks
                    });
                }

                // CUARTO: Mostrar pregunta si existe
                if (currentQuestion && roundSystem) {
                    const categoryName = categories[currentQuestion.category] || currentQuestion.category;
                    displayQuestion(
                        currentQuestion,
                        categoryName,
                        roundSystem.current_round,
                        roundSystem.total_rounds,
                        false // false = recargando página, no nueva ronda
                    );
                    console.log('✅ [Trivia] Loaded current question from API');
                    
                    // QUINTO: Mostrar overlay si estaba bloqueado (después de mostrar pregunta)
                    if (isPlayerLocked) {
                        console.log('🔒 [Trivia] Player was locked, showing overlay');
                        setTimeout(() => {
                            showLockedOverlay();
                        }, 100);
                    }
                }
            }
        } catch (error) {
            console.error('❌ [Trivia] Error loading initial state:', error);
        }
    });
    </script>
</x-app-layout>
