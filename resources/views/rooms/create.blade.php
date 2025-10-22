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
                                <div class="space-y-3">
                                    @foreach($games as $game)
                                        <label class="flex items-start p-4 border-2 {{ $selectedGame && $selectedGame->id == $game->id ? 'border-blue-500 bg-blue-50' : 'border-gray-200' }} rounded-lg cursor-pointer hover:border-blue-500 hover:bg-blue-50 transition">
                                            <input
                                                type="radio"
                                                name="game_id"
                                                value="{{ $game->id }}"
                                                class="mt-1 mr-3 game-selector"
                                                required
                                                {{ $selectedGame && $selectedGame->id == $game->id ? 'checked' : '' }}
                                                onchange="window.location.href='{{ route('rooms.create') }}?game_id={{ $game->id }}'"
                                            >
                                            <div class="flex-1">
                                                <h3 class="font-bold text-lg mb-1">{{ $game->name }}</h3>
                                                <p class="text-sm text-gray-600 mb-2">{{ $game->description }}</p>
                                                <div class="text-xs text-gray-500 flex gap-4">
                                                    <span>👥 {{ $game->min_players }}-{{ $game->max_players }} jugadores</span>
                                                    <span>⏱️ {{ $game->estimated_duration }}</span>
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

                        @if($selectedGame)
                            <input type="hidden" name="game_id" value="{{ $selectedGame->id }}">
                        @endif

                        <!-- Configuración Opcional -->
                        @if($selectedGame)
                        <div class="mb-6 border-t pt-6">
                            <h3 class="text-lg font-medium mb-4 flex items-center">
                                <span class="mr-2">⚙️</span>
                                Configuración de {{ $selectedGame->name }}
                            </h3>

                            @if(!empty($customizableSettings))
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                                    <p class="text-sm text-blue-800">
                                        ℹ️ Personaliza la experiencia de juego. Si dejas los campos vacíos, se usarán los valores por defecto.
                                    </p>
                                </div>

                                <div class="space-y-6">
                                    @foreach($customizableSettings as $key => $setting)
                                        @if($setting['type'] === 'radio')
                                            <div class="bg-white p-4 rounded-lg border border-gray-200">
                                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                                    {{ $setting['label'] }}
                                                </label>
                                                @if(isset($setting['description']))
                                                    <p class="text-xs text-gray-500 mb-3">{{ $setting['description'] }}</p>
                                                @endif

                                                <div class="space-y-2">
                                                    @foreach($setting['options'] as $option)
                                                        <label class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                                            <input
                                                                type="radio"
                                                                name="{{ $key }}"
                                                                value="{{ $option['value'] }}"
                                                                {{ $option['value'] == $setting['default'] ? 'checked' : '' }}
                                                                class="mt-1 mr-3"
                                                                @if(isset($option['showField']))
                                                                    onchange="document.getElementById('field_{{ $option['showField'] }}').style.display = this.checked ? 'block' : 'none'"
                                                                @endif
                                                            >
                                                            <div>
                                                                <div class="font-medium text-sm">{{ $option['label'] }}</div>
                                                                @if(isset($option['description']))
                                                                    <div class="text-xs text-gray-500">{{ $option['description'] }}</div>
                                                                @endif
                                                            </div>
                                                        </label>
                                                    @endforeach
                                                </div>

                                                @error($key)
                                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif

                                        @if($setting['type'] === 'number')
                                            <div
                                                id="field_{{ $key }}"
                                                class="bg-white p-4 rounded-lg border border-gray-200"
                                                @if(isset($setting['visibleWhen']))
                                                    style="display: none;"
                                                @endif
                                            >
                                                <label for="{{ $key }}" class="block text-sm font-medium text-gray-700 mb-2">
                                                    {{ $setting['label'] }}
                                                </label>
                                                @if(isset($setting['description']))
                                                    <p class="text-xs text-gray-500 mb-2">{{ $setting['description'] }}</p>
                                                @endif
                                                <input
                                                    type="number"
                                                    name="{{ $key }}"
                                                    id="{{ $key }}"
                                                    value="{{ $setting['default'] }}"
                                                    min="{{ $setting['min'] }}"
                                                    max="{{ $setting['max'] }}"
                                                    step="{{ $setting['step'] ?? 1 }}"
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                >
                                                @error($key)
                                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif

                                        @if($setting['type'] === 'select')
                                            <div class="bg-white p-4 rounded-lg border border-gray-200">
                                                <label for="{{ $key }}" class="block text-sm font-medium text-gray-700 mb-2">
                                                    {{ $setting['label'] }}
                                                </label>
                                                @if(isset($setting['description']))
                                                    <p class="text-xs text-gray-500 mb-2">{{ $setting['description'] }}</p>
                                                @endif
                                                <select
                                                    name="{{ $key }}"
                                                    id="{{ $key }}"
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                >
                                                    @foreach($setting['options'] as $option)
                                                        <option
                                                            value="{{ $option['value'] }}"
                                                            {{ $option['value'] == $setting['default'] ? 'selected' : '' }}
                                                        >
                                                            {{ $option['label'] }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @error($key)
                                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif

                                        @if($setting['type'] === 'checkbox')
                                            <div class="bg-white p-4 rounded-lg border border-gray-200">
                                                <label class="flex items-start cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        name="{{ $key }}"
                                                        value="1"
                                                        {{ $setting['default'] ? 'checked' : '' }}
                                                        class="mt-1 mr-3 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    >
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-700">{{ $setting['label'] }}</div>
                                                        @if(isset($setting['description']))
                                                            <div class="text-xs text-gray-500 mt-1">{{ $setting['description'] }}</div>
                                                        @endif
                                                    </div>
                                                </label>
                                                @error($key)
                                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <p class="text-sm text-gray-600">
                                        Este juego no tiene configuraciones adicionales.
                                    </p>
                                </div>
                            @endif
                        </div>
                        @endif

                        <!-- Configuración Básica de Sala -->
                        <div class="mb-6 border-t pt-6">
                            <h3 class="text-lg font-medium mb-4">Configuración Básica (Opcional)</h3>

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

                            <!-- Jugar por Equipos (solo si el juego lo soporta) -->
                            @if($selectedGame && isset($gameConfig['modules']['teams_system']) && $gameConfig['modules']['teams_system']['allow_toggle'])
                            <div class="mt-4 bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-lg p-4">
                                <label class="flex items-start space-x-3 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        name="play_with_teams"
                                        id="play_with_teams"
                                        value="1"
                                        class="mt-1 rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-500 focus:ring-purple-500"
                                    >
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <span class="text-sm font-bold text-purple-900">🏆 Jugar por equipos</span>
                                            <span class="ml-2 px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">Nuevo</span>
                                        </div>
                                        <p class="mt-1 text-xs text-purple-700">
                                            {{ $gameConfig['modules']['teams_system']['description'] ?? 'Los jugadores se organizan en equipos y compiten entre sí' }}
                                        </p>
                                        <p class="mt-1 text-xs text-purple-600">
                                            📊 Podrás configurar los equipos en el lobby antes de iniciar la partida
                                        </p>
                                    </div>
                                </label>
                            </div>
                            @endif
                        </div>

                        <!-- Errores Generales -->
                        @if($errors->has('error'))
                            <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                                <p class="text-red-800">{{ $errors->first('error') }}</p>
                            </div>
                        @endif

                        <!-- Botones -->
                        <div class="flex items-center justify-end space-x-4 mt-6">
                            <a
                                href="{{ route('dashboard') }}"
                                class="text-sm text-gray-600 hover:text-gray-900 underline"
                            >
                                Cancelar
                            </a>
                            <button
                                type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150"
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
