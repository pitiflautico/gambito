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
            @if(isset($players) && isset($playerId))
                @php
                    $currentPlayer = collect($players)->firstWhere('id', $playerId);
                @endphp
                @if($currentPlayer)
                    <p class="player-name-indicator">
                        <span style="font-size: 0.9em; color: #666;">Jugando como:</span>
                        <strong style="color: #4F46E5; font-size: 1.1em;">{{ $currentPlayer['name'] }}</strong>
                    </p>
                @endif
            @endif
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
        <button id="next-round-btn" class="btn-primary">Siguiente ronda</button>
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
    <script>
        // Datos iniciales desde el servidor
        window.gameData = {
            matchId: {{ $match->id }},
            playerId: {{ $playerId ?? auth()->user()->id }},
            roomCode: '{{ $room->code }}',
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

        // El botón "YA LO SÉ" y su funcionalidad están manejados por pictionary-canvas.js
        // que se compila con Vite y se carga automáticamente
    </script>
    {{-- El canvas.js se carga a través de Vite en app.js --}}
@endpush
