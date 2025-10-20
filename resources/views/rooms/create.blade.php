<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Crear Nueva Sala
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('rooms.store') }}">
                        @csrf

                        <!-- Selección de Juego -->
                        <div class="mb-6">
                            <label for="game_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Selecciona un juego
                            </label>

                            @if($games->isEmpty())
                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                    <p class="text-yellow-800">
                                        No hay juegos disponibles actualmente.
                                        <a href="{{ route('dashboard') }}" class="underline">Volver al inicio</a>
                                    </p>
                                </div>
                            @else
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    @foreach($games as $game)
                                        <label class="relative cursor-pointer">
                                            <input
                                                type="radio"
                                                name="game_id"
                                                value="{{ $game->id }}"
                                                class="peer sr-only"
                                                required
                                            >
                                            <div class="border-2 border-gray-200 rounded-lg p-4 hover:border-blue-500 peer-checked:border-blue-600 peer-checked:bg-blue-50 transition">
                                                <h3 class="font-bold text-lg mb-2">{{ $game->name }}</h3>
                                                <p class="text-sm text-gray-600 mb-3">{{ $game->description }}</p>
                                                <div class="text-xs text-gray-500 space-y-1">
                                                    <p>👥 {{ $game->min_players }}-{{ $game->max_players }} jugadores</p>
                                                    <p>⏱️ {{ $game->estimated_duration }}</p>
                                                </div>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            @endif

                            @error('game_id')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Configuración Opcional -->
                        <div class="mb-6 border-t pt-6">
                            <h3 class="text-lg font-medium mb-4">Configuración de la Sala (Opcional)</h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Máximo de Jugadores -->
                                <div>
                                    <label for="max_players" class="block text-sm font-medium text-gray-700 mb-2">
                                        Máximo de jugadores
                                    </label>
                                    <input
                                        type="number"
                                        name="max_players"
                                        id="max_players"
                                        min="1"
                                        max="100"
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Por defecto del juego"
                                    >
                                    <p class="mt-1 text-xs text-gray-500">Deja vacío para usar el máximo del juego</p>
                                    @error('max_players')
                                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>

                                <!-- Sala Privada -->
                                <div>
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="private"
                                            value="1"
                                            class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        >
                                        <span class="text-sm font-medium text-gray-700">Sala privada</span>
                                    </label>
                                    <p class="mt-1 text-xs text-gray-500 ml-6">Solo se puede unir con el código</p>
                                </div>
                            </div>
                        </div>

                        <!-- Errores Generales -->
                        @if($errors->has('error'))
                            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                                <p class="text-red-800">{{ $errors->first('error') }}</p>
                            </div>
                        @endif

                        <!-- Botones -->
                        <div class="flex items-center justify-end space-x-4">
                            <a
                                href="{{ route('dashboard') }}"
                                class="px-4 py-2 text-gray-700 hover:text-gray-900"
                            >
                                Cancelar
                            </a>
                            <button
                                type="submit"
                                class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50"
                                {{ $games->isEmpty() ? 'disabled' : '' }}
                            >
                                Crear Sala
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
