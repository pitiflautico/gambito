<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Dashboard
            </h2>
            <a
                href="{{ route('rooms.create') }}"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
            >
                🎮 Crear Nueva Sala
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <!-- Mensajes de Error -->
            @if(session('error'))
                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <p class="text-red-800">{{ session('error') }}</p>
                </div>
            @endif

            <!-- Mensajes de Éxito -->
            @if(session('success'))
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <p class="text-green-800">{{ session('success') }}</p>
                </div>
            @endif

            <!-- Bienvenida -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-2">¡Bienvenido, {{ Auth::user()->name }}!</h3>
                    <p class="text-gray-600">Crea una sala para empezar a jugar con tus amigos.</p>
                </div>
            </div>

            <!-- Acciones Rápidas -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Crear Sala -->
                <a href="{{ route('rooms.create') }}" class="block group">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition">
                        <div class="p-6">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition">
                                        <span class="text-2xl">🎮</span>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Crear Sala</h4>
                                    <p class="text-sm text-gray-600">Inicia una nueva partida</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>

                <!-- Unirse a Sala -->
                <a href="{{ route('rooms.join') }}" class="block group">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition">
                        <div class="p-6">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition">
                                        <span class="text-2xl">🚪</span>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Unirse a Sala</h4>
                                    <p class="text-sm text-gray-600">Ingresa con un código</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>

                <!-- Crear Juego (redirige a crear sala con selección de juego) -->
                <a href="{{ route('rooms.create') }}" class="block group">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition">
                        <div class="p-6">
                            <div class="flex items-center space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition">
                                        <span class="text-2xl">🎲</span>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900">Ver Juegos</h4>
                                    <p class="text-sm text-gray-600">Explora los disponibles</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
