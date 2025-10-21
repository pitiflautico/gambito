{{--
    Vista de Juego en Curso
    Carga dinámicamente la vista específica del juego desde games/{slug}/views/
--}}

@php
    $gameSlug = $room->game->slug;
    $gamePath = $room->game->path;

    // Construir path de la vista del juego
    // Ejemplo: games/pictionary/views/canvas.blade.php
    $gameViewPath = base_path("{$gamePath}/views/canvas.blade.php");

    // Registrar el namespace de vistas del juego si no está registrado
    $viewNamespace = "game-{$gameSlug}";
    if (!view()->exists("{$viewNamespace}::canvas")) {
        view()->addNamespace($viewNamespace, base_path("{$gamePath}/views"));
    }
@endphp

@if(file_exists($gameViewPath))
    {{-- Cargar la vista del juego con los datos necesarios --}}
    @include("{$viewNamespace}::canvas", [
        'room' => $room,
        'match' => $room->match,
        'playerId' => $playerId ?? null,
        'role' => $role ?? 'guesser',
    ])
@else
    {{-- Fallback si no existe vista específica del juego --}}
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
                                <svg class="w-24 h-24 mx-auto text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>

                            <h3 class="text-2xl font-bold text-gray-900 mb-4">
                                Vista del Juego No Encontrada
                            </h3>

                            <p class="text-gray-600 mb-4">
                                No se encontró la vista para el juego: <strong>{{ $room->game->name }}</strong>
                            </p>

                            <p class="text-sm text-gray-500">
                                Ruta esperada: <code class="bg-gray-100 px-2 py-1 rounded">{{ $gameViewPath }}</code>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-app-layout>
@endif
