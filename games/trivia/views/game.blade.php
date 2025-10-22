@extends('layouts.guest-public')

@section('title', 'Trivia - ' . $room->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('games/trivia/css/game.css') }}">
@endpush

@section('content')
<div class="trivia-container" id="trivia-game">
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
                        <strong style="color: #10B981; font-size: 1.1em;">{{ $currentPlayer['name'] }}</strong>
                    </p>
                @endif
            @endif
        </div>

        <div class="game-status">
            <div class="round-info">
                Pregunta <span id="current-question">1</span> / <span id="total-questions">10</span>
            </div>
            <div class="timer">
                <svg class="timer-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <span id="time-remaining">15</span>s
            </div>
        </div>
    </div>

    {{-- Mensajes del juego --}}
    <div id="game-messages" class="game-messages"></div>

    {{-- Contenedor principal del juego --}}
    <div class="game-content">
        {{-- Panel de la pregunta --}}
        <div class="question-panel" id="question-panel">
            <div class="question-waiting" id="question-waiting">
                <div class="spinner"></div>
                <p>Esperando siguiente pregunta...</p>
            </div>

            <div class="question-active hidden" id="question-active">
                <div class="question-header">
                    <span class="question-category" id="question-category">Categoría</span>
                    <span class="question-difficulty" id="question-difficulty">Dificultad</span>
                </div>

                <div class="question-text" id="question-text">
                    ¿Cuál es la capital de España?
                </div>

                <div class="options-grid" id="options-grid">
                    <button class="option-btn" data-option="0">
                        <span class="option-letter">A</span>
                        <span class="option-text">Madrid</span>
                    </button>
                    <button class="option-btn" data-option="1">
                        <span class="option-letter">B</span>
                        <span class="option-text">Barcelona</span>
                    </button>
                    <button class="option-btn" data-option="2">
                        <span class="option-letter">C</span>
                        <span class="option-text">Valencia</span>
                    </button>
                    <button class="option-btn" data-option="3">
                        <span class="option-letter">D</span>
                        <span class="option-text">Sevilla</span>
                    </button>
                </div>

                <div class="answer-feedback hidden" id="answer-feedback">
                    <div class="feedback-icon"></div>
                    <p class="feedback-message"></p>
                </div>
            </div>

            <div class="question-results hidden" id="question-results">
                <h2 class="results-title">Resultados de la pregunta</h2>
                <div class="correct-answer-display">
                    <p>Respuesta correcta:</p>
                    <h3 id="correct-answer-text">Madrid</h3>
                </div>
                <div class="players-results" id="players-results">
                    <!-- Se llenará dinámicamente con JavaScript -->
                </div>
                <p class="next-question-timer">Siguiente pregunta en <span id="next-question-countdown">5</span>s...</p>
            </div>

            <div class="final-results hidden" id="final-results">
                <h2 class="final-title">¡Juego Terminado!</h2>
                <div class="winner-announcement" id="winner-announcement">
                    <div class="trophy-icon">🏆</div>
                    <h3 class="winner-name">Jugador 1</h3>
                    <p class="winner-score">250 puntos</p>
                </div>
                <div class="final-ranking" id="final-ranking">
                    <!-- Se llenará dinámicamente con JavaScript -->
                </div>
                <div class="final-actions">
                    <button class="btn-primary" id="btn-play-again">Jugar de nuevo</button>
                    <button class="btn-secondary" id="btn-back-lobby">Volver al lobby</button>
                </div>
            </div>
        </div>

        {{-- Panel de jugadores y puntuaciones --}}
        <div class="players-panel">
            <h3 class="panel-title">Jugadores</h3>
            <div class="players-list" id="players-list">
                @if(isset($players))
                    @foreach($players as $player)
                        <div class="player-item" data-player-id="{{ $player['id'] }}">
                            <div class="player-avatar">
                                {{ strtoupper(substr($player['name'], 0, 1)) }}
                            </div>
                            <div class="player-info">
                                <span class="player-name">{{ $player['name'] }}</span>
                                <span class="player-score" data-player-id="{{ $player['id'] }}">0 pts</span>
                            </div>
                            <div class="player-status" data-player-id="{{ $player['id'] }}">
                                <span class="status-indicator"></span>
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            <div class="answer-progress" id="answer-progress">
                <p class="progress-text">
                    <span id="answered-count">0</span> / <span id="total-players">{{ count($players ?? []) }}</span> respondieron
                </p>
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
    @vite(['resources/js/trivia-game.js'])

    <script>
        // Configuración inicial del juego
        window.gameData = {
            roomCode: '{{ $room->code }}',
            playerId: {{ $playerId ?? 'null' }},
            matchId: {{ $match->id ?? 'null' }},
            gameSlug: 'trivia',

            @if(isset($match->game_state))
            phase: '{{ $match->game_state['phase'] ?? 'waiting' }}',
            currentQuestion: {{ $match->game_state['question_index'] ?? 0 }},
            totalQuestions: {{ count($match->game_state['questions'] ?? []) }},
            scores: @json($match->game_state['scores'] ?? []),
            @endif

            players: @json($players ?? []),
        };

        // Inicializar el juego cuando el DOM esté listo
        document.addEventListener('DOMContentLoaded', function() {
            if (window.TriviaGame) {
                window.triviaGame = new window.TriviaGame(window.gameData);
            }
        });
    </script>
@endpush
@endsection
