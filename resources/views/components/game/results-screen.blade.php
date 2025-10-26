{{--
    Componente: Pantalla de Resultados Finales del Juego

    Este componente es genérico y reutilizable para todos los juegos.
    Muestra el podio de resultados con medallas, colores y puntuaciones.

    Props:
    - $roomCode: Código de la sala (para volver al lobby)
    - $gameTitle: Título del juego (ej: "Trivia")
    - $containerId: ID del contenedor del podio (default: 'podium')
--}}

@props([
    'roomCode',
    'gameTitle' => 'Juego',
    'containerId' => 'podium',
    'embedded' => false  // Si true, no usa min-h-screen ni fondo degradado
])

<div class="{{ $embedded ? '' : 'min-h-screen bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center p-4' }}">
    <div class="max-w-2xl w-full {{ $embedded ? 'mx-auto' : '' }}">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold {{ $embedded ? 'text-gray-900' : 'text-white' }} mb-2">
                🏆 ¡Partida Finalizada!
            </h1>
            <p class="{{ $embedded ? 'text-gray-600' : 'text-white opacity-90' }} text-lg">
                {{ $gameTitle }}
            </p>
        </div>

        <!-- Podio Container -->
        <div class="bg-white rounded-2xl shadow-2xl p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">
                Resultados Finales
            </h2>

            {{-- El podio se renderiza dinámicamente por JavaScript --}}
            <div id="{{ $containerId }}" class="space-y-3">
                <!-- Los resultados se cargan aquí vía BaseGameClient.renderPodium() -->
                <div class="text-center py-8 text-gray-400">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-purple-500 mx-auto mb-3"></div>
                    <p>Cargando resultados...</p>
                </div>
            </div>
        </div>

        <!-- Acciones -->
        <div class="bg-white rounded-2xl shadow-2xl p-6">
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Volver al Lobby -->
                <a href="/rooms/{{ $roomCode }}/lobby"
                   class="flex-1 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 font-semibold text-center transition-colors">
                    🏠 Volver al Lobby
                </a>

                <!-- Jugar otra vez -->
                <button
                    onclick="window.location.href='/rooms/{{ $roomCode }}/lobby'"
                    class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 font-semibold transition-colors">
                    🔄 Jugar de Nuevo
                </button>
            </div>
        </div>

        <!-- Footer -->
        @if(!$embedded)
        <div class="text-center mt-6">
            <p class="text-white text-sm opacity-75">
                Código de sala: <span class="font-mono font-bold">{{ $roomCode }}</span>
            </p>
        </div>
        @endif
    </div>
</div>
