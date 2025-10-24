@extends('layouts.guest-public')

@section('title', 'Pictionary - ' . $room->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('games/pictionary/css/canvas.css') }}">
@endpush

@section('content')
<div class="pictionary-container">
    {{-- Header de la partida --}}
    <div class="game-header">
        <div class="room-info">
            <h1>{{ $room->name }}</h1>
            <p class="room-code">Código: <strong>{{ $room->code }}</strong></p>
            <div id="player-info-badge" style="margin-top: 0.5rem; padding: 0.75rem 1.25rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: inline-block; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div>
                        <div style="font-size: 0.75em; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem;">Tu jugador</div>
                        <div style="color: #fff; font-size: 1.3em; font-weight: bold; text-shadow: 0 2px 4px rgba(0,0,0,0.2);" id="current-player-name">Cargando...</div>
                        <div style="font-size: 0.7em; color: rgba(255,255,255,0.6); margin-top: 0.15rem;" id="current-player-id">ID: --</div>
                    </div>
                    <div style="border-left: 2px solid rgba(255,255,255,0.3); padding-left: 1rem;">
                        <div style="font-size: 0.75em; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem;">Rol</div>
                        <div style="color: #fff; font-size: 1.1em; font-weight: bold; text-shadow: 0 2px 4px rgba(0,0,0,0.2);" id="current-player-role">...</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="game-status">
            <div class="round-info">
                Ronda <span id="current-round">1</span> / <span id="total-rounds">5</span>
            </div>
            <div class="timer">
                <svg class="timer-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <span id="time-remaining">90</span>s
            </div>
        </div>
    </div>

    {{-- Mensajes del juego --}}
    <div id="game-messages" class="game-messages"></div>

    {{-- Contenedor principal del juego --}}
    <div class="game-content">
        {{-- Panel izquierdo: Canvas y herramientas --}}
        <div class="canvas-panel">
            {{-- Palabra secreta (solo visible para el dibujante) --}}
            <div id="word-display" class="word-display hidden">
                <p>Tu palabra es:</p>
                <h2 id="secret-word">-</h2>
            </div>

            {{-- Canvas de dibujo --}}
            <div class="canvas-container">
                <canvas id="drawing-canvas" width="800" height="600"></canvas>
            </div>

            {{-- Botón "¡YA LO SÉ!" para guessers --}}
            <div id="yo-se-container" class="yo-se-container hidden">
                <button id="btn-yo-se" class="btn-yo-se">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                    <span>¡YA LO SÉ!</span>
                </button>
                <p class="yo-se-hint">Pulsa cuando sepas la respuesta</p>
            </div>

            {{-- Herramientas de dibujo --}}
            <div class="drawing-tools">
                {{-- Herramienta activa --}}
                <div class="tool-group">
                    <button id="tool-pencil" class="tool-btn active" title="Lápiz">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 19l7-7 3 3-7 7-3-3z"></path>
                            <path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path>
                            <path d="M2 2l7.586 7.586"></path>
                        </svg>
                        <span>Lápiz</span>
                    </button>
                    <button id="tool-eraser" class="tool-btn" title="Borrador">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 20H7L3 16l10-10 4 4"></path>
                            <path d="M8 8l6 6"></path>
                        </svg>
                        <span>Borrador</span>
                    </button>
                </div>

                {{-- Selector de colores --}}
                <div class="tool-group">
                    <div class="color-palette">
                        <button class="color-btn active" data-color="#000000" style="background: #000000;" title="Negro"></button>
                        <button class="color-btn" data-color="#FF0000" style="background: #FF0000;" title="Rojo"></button>
                        <button class="color-btn" data-color="#00FF00" style="background: #00FF00;" title="Verde"></button>
                        <button class="color-btn" data-color="#0000FF" style="background: #0000FF;" title="Azul"></button>
                        <button class="color-btn" data-color="#FFFF00" style="background: #FFFF00;" title="Amarillo"></button>
                        <button class="color-btn" data-color="#FF00FF" style="background: #FF00FF;" title="Magenta"></button>
                        <button class="color-btn" data-color="#00FFFF" style="background: #00FFFF;" title="Cian"></button>
                        <button class="color-btn" data-color="#FFA500" style="background: #FFA500;" title="Naranja"></button>
                        <button class="color-btn" data-color="#800080" style="background: #800080;" title="Púrpura"></button>
                        <button class="color-btn" data-color="#8B4513" style="background: #8B4513;" title="Marrón"></button>
                        <button class="color-btn" data-color="#FFB6C1" style="background: #FFB6C1;" title="Rosa"></button>
                        <button class="color-btn" data-color="#808080" style="background: #808080;" title="Gris"></button>
                    </div>
                </div>

                {{-- Selector de grosor --}}
                <div class="tool-group">
                    <label class="tool-label">Grosor:</label>
                    <div class="brush-sizes">
                        <button class="size-btn" data-size="2" title="Fino">
                            <span class="size-indicator" style="width: 4px; height: 4px;"></span>
                        </button>
                        <button class="size-btn active" data-size="5" title="Medio">
                            <span class="size-indicator" style="width: 8px; height: 8px;"></span>
                        </button>
                        <button class="size-btn" data-size="10" title="Grueso">
                            <span class="size-indicator" style="width: 12px; height: 12px;"></span>
                        </button>
                        <button class="size-btn" data-size="20" title="Muy grueso">
                            <span class="size-indicator" style="width: 16px; height: 16px;"></span>
                        </button>
                    </div>
                </div>

                {{-- Botón limpiar --}}
                <div class="tool-group">
                    <button id="clear-canvas" class="btn-clear" title="Limpiar canvas">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        </svg>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Panel derecho: Jugadores y respuestas --}}
        <div class="sidebar">
            {{-- Lista de jugadores --}}
            <div class="players-panel">
                <h3>Jugadores</h3>
                <div id="players-list" class="players-list">
                    @if(isset($players))
                        @foreach($players as $player)
                        <div class="player-item {{ $player['is_drawer'] ? 'is-drawer' : '' }} {{ $player['is_eliminated'] ? 'is-eliminated' : '' }} {{ $player['id'] === $playerId ? 'is-current-player' : '' }}" data-player-id="{{ $player['id'] }}">
                            <span class="player-name">
                                {{ $player['name'] }}
                                @if($player['id'] === $playerId)
                                <span style="font-size: 0.8em; color: #4F46E5;"> (Tú)</span>
                                @endif
                            </span>
                            <span class="player-score">{{ $player['score'] }} pts</span>
                            @if($player['is_drawer'])
                            <span class="drawer-badge">🎨</span>
                            @endif
                        </div>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Panel de respuestas --}}
            <div class="answers-panel">
                <h3>¿Quién responde?</h3>
                <div id="answers-list" class="answers-list">
                    {{-- Lista de jugadores que presionaron "¡YA LO SÉ!" --}}
                </div>

                {{-- Botones de confirmación (solo para dibujante) --}}
                <div id="confirmation-container" class="confirmation-container hidden">
                    <p class="pending-answer">
                        <strong id="pending-player-name"></strong> quiere responder
                    </p>
                    <p class="hint-text">Escucha su respuesta y confirma si es correcta o incorrecta</p>
                    <div class="confirmation-buttons">
                        <button id="btn-correct" class="btn-confirm btn-correct">✓ Correcta</button>
                        <button id="btn-incorrect" class="btn-confirm btn-incorrect">✗ Incorrecta</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal de resultados de ronda --}}
<div id="round-results-modal" class="modal hidden">
    <div class="modal-content">
        <h2>¡Fin de la ronda!</h2>
        <div id="round-results"></div>
        <p class="text-gray-600 text-sm mt-4">
            El siguiente turno comenzará automáticamente en <span id="countdown">3</span> segundos...
        </p>
    </div>
</div>

{{-- Modal de resultados finales --}}
<div id="final-results-modal" class="modal hidden">
    <div class="modal-content">
        <h2>¡Fin del juego!</h2>
        <div id="final-results"></div>
        <div id="final-results-actions">
            {{-- Los botones se agregarán dinámicamente según el tipo de usuario --}}
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/pictionary-canvas.js'])

    <script>
        // Datos iniciales desde el servidor
        window.gameData = {
            matchId: {{ $match->id }},
            playerId: {{ $playerId ?? auth()->user()->id }},
            @php
                $currentPlayer = collect($players ?? [])->firstWhere('id', $playerId ?? 0);
                $playerName = $currentPlayer['name'] ?? null;

                // Fallback: obtener desde el modelo Player si no está en la lista
                if (!$playerName && isset($playerId)) {
                    $playerModel = \App\Models\Player::find($playerId);
                    $playerName = $playerModel ? $playerModel->name : null;
                }

                // Último fallback: usar el nombre del usuario autenticado
                $playerName = $playerName ?? (auth()->check() ? auth()->user()->name : 'Invitado');
            @endphp
            playerName: '{{ $playerName }}',
            roomCode: '{{ $room->code }}',
            gameSlug: 'pictionary',
            eventConfig: @json($eventConfig ?? null),
            csrfToken: '{{ csrf_token() }}',
            isMaster: {{ auth()->check() && $room->master_id === auth()->id() ? 'true' : 'false' }},
            isGuest: {{ auth()->check() ? 'false' : 'true' }},
            @if(isset($role))
            role: '{{ $role }}',
            @endif
            @if(isset($match->game_state['current_word']))
            currentWord: '{{ $match->game_state['current_word'] }}',
            @endif
            @if(isset($match->game_state['phase']))
            phase: '{{ $match->game_state['phase'] }}',
            @endif
            @if(isset($match->game_state['phase']) && $match->game_state['phase'] === 'results')
            gameResults: {
                scores: @json($match->game_state['scores'] ?? []),
                round: {{ $match->game_state['round'] ?? 1 }},
                roundsTotal: {{ $match->game_state['rounds_total'] ?? 5 }}
            }
            @endif
        };

        // Inicializar PictionaryCanvas cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('drawing-canvas');
            if (!canvas) {
                console.log('Pictionary canvas not found, skipping initialization');
                return;
            }

            console.log('🚀 Initializing Pictionary Canvas...');
            window.pictionaryCanvas = new window.PictionaryCanvas(window.gameData);

            // Configurar rol inicial si está disponible en gameData
            if (window.gameData?.role) {
                const isDrawer = window.gameData.role === 'drawer';
                const currentWord = window.gameData?.currentWord || null;
                window.pictionaryCanvas.setRole(isDrawer, isDrawer ? currentWord : null);
            }

            // Actualizar info del jugador (nombre y rol)
            updatePlayerInfo();
        });

        // Función para actualizar la información del jugador
        function updatePlayerInfo() {
            const playerId = window.gameData?.playerId;
            const playerName = window.gameData?.playerName || `Jugador ${playerId}`;
            const role = window.gameData?.role;

            // Actualizar nombre
            document.getElementById('current-player-name').textContent = playerName;

            // Actualizar ID
            document.getElementById('current-player-id').textContent = `ID: ${playerId}`;

            // Actualizar rol
            const roleText = role === 'drawer' ? '🎨 DIBUJANTE' : '🤔 ADIVINADOR';
            document.getElementById('current-player-role').textContent = roleText;
        }

        // Actualizar rol cuando cambie
        if (window.pictionaryCanvas) {
            const originalSetRole = window.pictionaryCanvas.setRole.bind(window.pictionaryCanvas);
            window.pictionaryCanvas.setRole = function(isDrawer, word) {
                originalSetRole(isDrawer, word);
                const roleText = isDrawer ? '🎨 DIBUJANTE' : '🤔 ADIVINADOR';
                document.getElementById('current-player-role').textContent = roleText;
            };
        }
    </script>
@endpush
