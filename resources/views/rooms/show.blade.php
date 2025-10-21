<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $room->game->name }} - Sala: {{ $room->code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="text-center py-12">
                        <div class="mb-6">
                            <svg class="w-24 h-24 mx-auto text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>

                        <h3 class="text-2xl font-bold text-gray-900 mb-4">
                            ¡Partida en Curso!
                        </h3>

                        <p class="text-gray-600 mb-8">
                            El juego "{{ $room->game->name }}" está en progreso.
                        </p>

                        <div class="max-w-2xl mx-auto bg-blue-50 border border-blue-200 rounded-lg p-6">
                            <h4 class="font-semibold text-blue-900 mb-2">
                                🎮 Lógica de Juego Pendiente
                            </h4>
                            <p class="text-sm text-blue-800 mb-4">
                                Esta es una vista placeholder. La lógica específica de cada juego se cargará dinámicamente desde el directorio <code class="bg-blue-100 px-2 py-1 rounded">games/</code>
                            </p>
                            <p class="text-sm text-blue-700">
                                Cada juego tendrá su propia vista y controlador modular que se cargará aquí.
                            </p>
                        </div>

                        <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4 max-w-3xl mx-auto">
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-sm text-gray-600">Jugadores</p>
                                <p class="text-2xl font-bold text-gray-900">{{ $room->match->players()->count() }}</p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-sm text-gray-600">Estado</p>
                                <p class="text-lg font-semibold text-green-600">Jugando</p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-sm text-gray-600">Código</p>
                                <p class="text-2xl font-bold text-gray-900">{{ $room->code }}</p>
                            </div>
                        </div>

                        <!-- Botón temporal para finalizar partida (solo para testing) -->
                        @if(Auth::check() && Auth::id() === $room->master_id)
                            <div class="mt-8">
                                <button
                                    onclick="finishGame()"
                                    class="px-6 py-3 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500"
                                >
                                    🏁 Finalizar Partida (Testing)
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function finishGame() {
            if (!confirm('¿Finalizar la partida?')) return;

            // TODO: Implementar endpoint para finalizar partida
            alert('Funcionalidad pendiente: finalizar partida');
        }
    </script>
    @endpush
</x-app-layout>
