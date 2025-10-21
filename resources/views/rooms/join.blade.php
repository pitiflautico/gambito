<x-guest-layout>
    <div class="mb-4">
        <h2 class="text-2xl font-bold text-gray-900">
            Unirse a una Sala
        </h2>
        <p class="mt-2 text-sm text-gray-600">
            Ingresa el código de 6 caracteres que te compartió el organizador
        </p>
    </div>

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6">
            <h3 class="text-lg font-semibold mb-4">Código de la sala</h3>

                    <form method="POST" action="{{ route('rooms.joinByCode') }}">
                        @csrf

                        <div class="mb-6">
                            <label for="code" class="block text-sm font-medium text-gray-700 mb-2">
                                Código de 6 caracteres
                            </label>
                            <input
                                type="text"
                                name="code"
                                id="code"
                                value="{{ old('code', $code ?? '') }}"
                                maxlength="6"
                                class="w-full px-4 py-3 text-2xl text-center uppercase tracking-widest border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-blue-500 font-mono"
                                placeholder="ABC123"
                                required
                                autofocus
                            >
                            @error('code')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between mt-6">
                            <a
                                href="{{ route('home') }}"
                                class="text-sm text-gray-600 hover:text-gray-900 underline"
                            >
                                Cancelar
                            </a>
                            <button
                                type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                            >
                                Unirse a la Sala
                            </button>
                        </div>
                    </form>

                    <div class="mt-8 pt-6 border-t">
                        <p class="text-sm text-gray-600 text-center">
                            ¿Quieres organizar una partida?
                            <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-medium">
                                Inicia sesión
                            </a>
                        </p>
                    </div>
                </div>
            </div>

    <script>
        // Auto-convertir a mayúsculas mientras escribe
        document.getElementById('code').addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });
    </script>
</x-guest-layout>
