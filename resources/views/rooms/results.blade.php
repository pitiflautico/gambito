<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Resultados - {{ $room->game->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <!-- Encabezado de Resultados -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-8 text-center">
                    <div class="mb-4">
                        <svg class="w-20 h-20 mx-auto text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                        </svg>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 mb-2">
                        ¡Partida Finalizada!
                    </h3>
                    <p class="text-gray-600">
                        {{ $room->game->name }} - Sala {{ $room->code }}
                    </p>
                </div>
            </div>

            <!-- Clasificación de Jugadores -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h4 class="text-xl font-bold mb-4">🏆 Clasificación Final</h4>

                    @if($room->match && $room->match->players->count() > 0)
                        <div class="space-y-3">
                            @php
                                $sortedPlayers = $room->match->players->sortByDesc('score');
                                $position = 1;
                            @endphp

                            @foreach($sortedPlayers as $player)
                                <div class="flex items-center justify-between p-4 rounded-lg {{ $position === 1 ? 'bg-yellow-50 border-2 border-yellow-300' : ($position === 2 ? 'bg-gray-100 border-2 border-gray-300' : ($position === 3 ? 'bg-orange-50 border-2 border-orange-300' : 'bg-gray-50')) }}">
                                    <div class="flex items-center space-x-4">
                                        <!-- Posición -->
                                        <div class="w-12 h-12 rounded-full {{ $position === 1 ? 'bg-yellow-400' : ($position === 2 ? 'bg-gray-400' : ($position === 3 ? 'bg-orange-400' : 'bg-gray-300')) }} flex items-center justify-center text-white font-bold text-lg">
                                            {{ $position }}
                                        </div>

                                        <!-- Avatar y Nombre -->
                                        <div class="flex items-center space-x-3">
                                            <div class="w-12 h-12 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                                                {{ strtoupper(substr($player->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <p class="font-bold text-lg">{{ $player->name }}</p>
                                                @if($player->role)
                                                    <p class="text-xs text-gray-500">{{ $player->role }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Puntuación -->
                                    <div class="text-right">
                                        <p class="text-3xl font-bold text-gray-900">{{ $player->score }}</p>
                                        <p class="text-xs text-gray-500">puntos</p>
                                    </div>
                                </div>
                                @php $position++; @endphp
                            @endforeach
                        </div>
                    @else
                        <p class="text-center text-gray-500 py-8">No hay jugadores registrados</p>
                    @endif
                </div>
            </div>

            <!-- Estadísticas de la Partida -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h4 class="text-xl font-bold mb-4">📊 Estadísticas</h4>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-1">Jugadores</p>
                            <p class="text-2xl font-bold text-gray-900">{{ $stats['players'] ?? 0 }}</p>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-1">Duración</p>
                            <p class="text-2xl font-bold text-gray-900">
                                @if($room->match && $room->match->duration())
                                    {{ gmdate('i:s', $room->match->duration()) }}
                                @else
                                    -
                                @endif
                            </p>
                            <p class="text-xs text-gray-500">min:seg</p>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-1">Iniciada</p>
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $room->match && $room->match->started_at ? $room->match->started_at->format('H:i') : '-' }}
                            </p>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-600 mb-1">Finalizada</p>
                            <p class="text-sm font-semibold text-gray-900">
                                {{ $room->match && $room->match->finished_at ? $room->match->finished_at->format('H:i') : '-' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Acciones -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row gap-4 justify-center">
                        <a
                            href="{{ route('home') }}"
                            class="inline-flex items-center justify-center px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500"
                        >
                            🏠 Volver al Inicio
                        </a>

                        @auth
                            <a
                                href="{{ route('rooms.create') }}"
                                class="inline-flex items-center justify-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                                🎮 Nueva Partida
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
