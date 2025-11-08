<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎴 UNO - Sala: {{ $code }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Header del juego -->
            <div class="bg-gradient-to-r from-red-600 via-blue-600 to-green-600 rounded-lg shadow-lg px-6 py-4 mb-6">
                <div class="flex justify-between items-center text-white">
                    <div>
                        <h3 class="text-2xl font-bold">¡UNO!</h3>
                        <p class="text-sm opacity-90">Sala: {{ $code }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm opacity-90">Tu nombre</p>
                        <p class="text-lg font-semibold">{{ $player->user->name }}</p>
                    </div>
                </div>
            </div>

            <!-- Loading State -->
            <div id="loading-state" class="bg-white rounded-lg shadow-lg p-12 text-center">
                <div class="animate-pulse">
                    <div class="text-6xl mb-4">🎴</div>
                    <h3 class="text-2xl font-bold text-gray-700 mb-2">Cargando juego...</h3>
                    <p class="text-gray-500">Preparando las cartas</p>
                </div>
            </div>

            <!-- Game State -->
            <div id="game-state" class="hidden">
                <!-- Área superior: Otros jugadores -->
                <div id="other-players" class="bg-white rounded-lg shadow-lg p-6 mb-6">
                    <h4 class="text-lg font-semibold mb-4 text-gray-700">Jugadores</h4>
                    <div id="players-list" class="flex flex-wrap gap-4">
                        <!-- Se llenará dinámicamente con JavaScript -->
                    </div>
                </div>

                <!-- Área central: Mesa de juego -->
                <div class="bg-gradient-to-br from-green-700 to-green-900 rounded-lg shadow-2xl p-8 mb-6">
                    <div class="flex justify-center items-center gap-8">
                        <!-- Mazo (para robar) -->
                        <div class="text-center">
                            <button id="draw-btn" class="transform transition-all hover:scale-110 active:scale-95">
                                <div class="w-32 h-48 bg-gray-800 rounded-xl shadow-xl flex items-center justify-center border-4 border-gray-600">
                                    <span class="text-white text-4xl font-bold">🂠</span>
                                </div>
                            </button>
                            <p class="text-white text-sm mt-2 font-semibold">Robar carta</p>
                            <p id="deck-count" class="text-white text-xs opacity-75">0 cartas</p>
                        </div>

                        <!-- Carta actual -->
                        <div class="text-center">
                            <div id="current-card" class="w-32 h-48 bg-white rounded-xl shadow-2xl flex items-center justify-center border-4 border-yellow-400">
                                <span class="text-6xl">?</span>
                            </div>
                            <div id="direction-indicator" class="mt-4 text-white text-2xl font-bold">
                                ↻
                            </div>
                        </div>

                        <!-- Información del turno -->
                        <div class="text-center text-white">
                            <div id="turn-indicator" class="bg-white/20 rounded-lg p-4 backdrop-blur-sm">
                                <p class="text-sm opacity-90">Turno de:</p>
                                <p id="current-player-name" class="text-xl font-bold">-</p>
                                <div id="my-turn-indicator" class="hidden mt-2 bg-yellow-400 text-gray-900 rounded-full px-4 py-1 text-sm font-bold animate-pulse">
                                    ¡ES TU TURNO!
                                </div>
                            </div>

                            <!-- Botón UNO -->
                            <button id="uno-btn" class="hidden mt-4 bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-6 rounded-full shadow-lg transform transition-all hover:scale-110 active:scale-95">
                                ¡UNO!
                            </button>
                        </div>
                    </div>

                    <!-- Pending Draw indicator -->
                    <div id="pending-draw-indicator" class="hidden mt-6 bg-red-600 text-white rounded-lg p-4 text-center">
                        <p class="font-bold">⚠️ Debes robar <span id="pending-draw-count">0</span> carta(s)</p>
                    </div>
                </div>

                <!-- Área inferior: Tu mano -->
                <div class="bg-white rounded-lg shadow-lg p-6">
                    <h4 class="text-lg font-semibold mb-4 text-gray-700">
                        Tu mano (<span id="hand-count">0</span> cartas)
                    </h4>
                    <div id="player-hand" class="flex flex-wrap gap-3 justify-center min-h-[200px]">
                        <!-- Se llenará dinámicamente con JavaScript -->
                    </div>
                </div>
            </div>

            <!-- Finished State -->
            <div id="finished-state" class="hidden bg-white rounded-lg shadow-lg p-12">
                <div class="text-center">
                    <div class="text-6xl mb-4">🏆</div>
                    <h3 class="text-3xl font-bold text-gray-800 mb-4">¡Ronda terminada!</h3>
                    <div id="winner-info" class="mb-6">
                        <!-- Se llenará con información del ganador -->
                    </div>
                    <div id="scores-table" class="max-w-md mx-auto">
                        <!-- Tabla de puntuaciones -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para seleccionar color (Wild cards) -->
    <div id="color-selector-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-2xl p-8 max-w-md">
            <h3 class="text-2xl font-bold text-gray-800 mb-6 text-center">Elige un color</h3>
            <div class="grid grid-cols-2 gap-4">
                <button data-color="red" class="color-btn h-32 bg-red-500 hover:bg-red-600 rounded-lg shadow-lg transform transition-all hover:scale-105 active:scale-95">
                    <span class="text-white text-2xl font-bold">Rojo</span>
                </button>
                <button data-color="blue" class="color-btn h-32 bg-blue-500 hover:bg-blue-600 rounded-lg shadow-lg transform transition-all hover:scale-105 active:scale-95">
                    <span class="text-white text-2xl font-bold">Azul</span>
                </button>
                <button data-color="green" class="color-btn h-32 bg-green-500 hover:bg-green-600 rounded-lg shadow-lg transform transition-all hover:scale-105 active:scale-95">
                    <span class="text-white text-2xl font-bold">Verde</span>
                </button>
                <button data-color="yellow" class="color-btn h-32 bg-yellow-500 hover:bg-yellow-600 rounded-lg shadow-lg transform transition-all hover:scale-105 active:scale-95">
                    <span class="text-white text-2xl font-bold">Amarillo</span>
                </button>
            </div>
        </div>
    </div>

    @push('styles')
    <style>
        .card {
            width: 80px;
            height: 120px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
        }

        .card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 8px 12px rgba(0,0,0,0.2);
        }

        .card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .card.disabled:hover {
            transform: none;
        }

        .card-red { background-color: #EF4444; color: white; }
        .card-blue { background-color: #3B82F6; color: white; }
        .card-green { background-color: #10B981; color: white; }
        .card-yellow { background-color: #F59E0B; color: white; }
        .card-wild { background: linear-gradient(135deg, #EF4444 25%, #3B82F6 25%, #3B82F6 50%, #10B981 50%, #10B981 75%, #F59E0B 75%); color: white; }

        .card-value {
            font-size: 2rem;
        }

        .player-card {
            padding: 1rem;
            border-radius: 8px;
            min-width: 120px;
        }

        .player-card.active {
            border: 3px solid #F59E0B;
            background-color: #FEF3C7;
        }

        .animate-bounce-slow {
            animation: bounce 2s infinite;
        }
    </style>
    @endpush

    @push('scripts')
    <script type="module">
        // Configuración del juego
        window.gameData = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $player->id }},
            gameSlug: 'uno',
            eventConfig: @json($eventConfig ?? null),
            csrfToken: '{{ csrf_token() }}'
        };

        // Cargar el cliente del juego UNO
        import { UnoGameClient } from '/resources/js/uno-client.js';

        document.addEventListener('DOMContentLoaded', async function() {
            // Crear instancia del cliente
            window.unoClient = new UnoGameClient(window.gameData);

            // Inicializar el juego
            await window.unoClient.initialize();
        });
    </script>
    @endpush
</x-app-layout>
