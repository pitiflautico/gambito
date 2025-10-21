<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Pictionary - {{ $room->name }}</title>

    <!-- Tailwind CSS CDN (solo para demo) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Canvas CSS -->
    <link rel="stylesheet" href="{{ asset('games/pictionary/css/canvas.css') }}">
</head>
<body>
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
            <button id="btn-refresh" class="btn-refresh" title="Actualizar estado del juego">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                <span>Actualizar</span>
            </button>
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
                <h3>Actividad</h3>
                <div id="answers-list" class="answers-list">
                    {{-- Se mostrarán los eventos del juego aquí --}}
                </div>

                {{-- Botón "YO SÉ" (solo para adivinadores) --}}
                <div id="yo-se-container" class="yo-se-container hidden">
                    <button id="btn-yo-se" class="btn-yo-se">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 11l3 3L22 4"></path>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        <span>¡YO SÉ!</span>
                    </button>
                    <p class="yo-se-hint">Pulsa cuando sepas la respuesta</p>
                </div>

                {{-- Mensaje cuando has pulsado "YO SÉ" --}}
                <div id="waiting-confirmation" class="waiting-confirmation hidden">
                    <div class="waiting-icon">⏳</div>
                    <p class="waiting-text">Di la palabra <strong>EN VOZ ALTA</strong></p>
                    <p class="waiting-subtext">Esperando confirmación del dibujante...</p>
                </div>

                {{-- Botones de confirmación (solo para dibujante) --}}
                <div id="confirmation-container" class="confirmation-container hidden">
                    <div class="confirmation-header">
                        <span class="confirmation-icon">👤</span>
                        <p class="confirmation-title">
                            <strong id="pending-player-name"></strong> cree saber la respuesta
                        </p>
                    </div>
                    <p class="confirmation-instruction">¿Dijo la palabra correcta?</p>
                    <div class="confirmation-buttons">
                        <button id="btn-correct" class="btn-confirm btn-correct">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            ¡SÍ, CORRECTA!
                        </button>
                        <button id="btn-incorrect" class="btn-confirm btn-incorrect">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                            NO, INCORRECTA
                        </button>
                    </div>
                </div>

                {{-- Mensaje de eliminado --}}
                <div id="eliminated-message" class="eliminated-message hidden">
                    <div class="eliminated-icon">❌</div>
                    <p class="eliminated-text">Fallaste esta ronda</p>
                    <p class="eliminated-subtext">Espera a la siguiente ronda</p>
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
        <a href="{{ route('home') }}" class="btn-primary">Volver al inicio</a>
    </div>
</div>

<!-- Scripts -->
<script>
    // Datos iniciales desde el servidor
    window.gameData = {
        matchId: {{ $match->id }},
        playerId: {{ auth()->check() ? auth()->user()->id : 999 }},
        roomCode: '{{ $room->code }}',
        csrfToken: '{{ csrf_token() }}'
    };
</script>
<script src="{{ asset('games/pictionary/js/canvas.js') }}"></script>
</body>
</html>
