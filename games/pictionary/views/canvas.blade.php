@extends('layouts.app')

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
                    {{-- Se llenará dinámicamente con JavaScript --}}
                </div>
            </div>

            {{-- Panel de respuestas --}}
            <div class="answers-panel">
                <h3>Respuestas</h3>
                <div id="answers-list" class="answers-list">
                    {{-- Se mostrarán los intentos de respuesta aquí --}}
                </div>

                {{-- Input para responder (solo para adivinadores) --}}
                <div id="answer-input-container" class="answer-input-container hidden">
                    <form id="answer-form">
                        <input
                            type="text"
                            id="answer-input"
                            placeholder="Escribe tu respuesta..."
                            maxlength="50"
                            autocomplete="off"
                        >
                        <button type="submit" class="btn-submit">Enviar</button>
                    </form>
                </div>

                {{-- Botones de confirmación (solo para dibujante) --}}
                <div id="confirmation-container" class="confirmation-container hidden">
                    <p class="pending-answer">
                        <strong id="pending-player-name"></strong> responde:
                        <span id="pending-answer-text"></span>
                    </p>
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
        <a href="{{ route('lobby') }}" class="btn-primary">Volver al lobby</a>
    </div>
</div>
@endsection

@push('scripts')
    <script>
        // Datos iniciales desde el servidor
        window.gameData = {
            matchId: {{ $match->id }},
            playerId: {{ auth()->user()->id }},
            roomCode: '{{ $room->code }}',
            csrfToken: '{{ csrf_token() }}'
        };
    </script>
    <script src="{{ asset('games/pictionary/js/canvas.js') }}"></script>
@endpush
