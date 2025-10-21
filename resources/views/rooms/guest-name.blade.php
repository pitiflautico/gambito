<x-guest-layout>
    <div class="mb-4">
        <h2 class="text-2xl font-bold text-gray-900">
            Únete como Invitado
        </h2>
        <p class="mt-2 text-sm text-gray-600">
            Ingresa tu nombre para unirte a la sala: <span class="font-mono font-bold text-blue-600">{{ $code }}</span>
        </p>
    </div>

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6">
            <form method="POST" action="{{ route('rooms.storeGuestName', ['code' => $code]) }}">
                @csrf

                <div class="mb-6">
                    <label for="player_name" class="block text-sm font-medium text-gray-700 mb-2">
                        Tu nombre
                    </label>
                    <input
                        type="text"
                        name="player_name"
                        id="player_name"
                        value="{{ old('player_name') }}"
                        maxlength="50"
                        class="w-full px-4 py-3 text-lg border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-blue-500"
                        placeholder="Ej: Juan Pérez"
                        required
                        autofocus
                    >
                    <p class="mt-2 text-xs text-gray-500">
                        Este nombre será visible para otros jugadores en la sala
                    </p>
                    @error('player_name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                @if($errors->has('error'))
                    <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                        <p class="text-red-800">{{ $errors->first('error') }}</p>
                    </div>
                @endif

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
                        Continuar al Lobby
                    </button>
                </div>
            </form>

            <div class="mt-8 pt-6 border-t">
                <p class="text-sm text-gray-600 text-center">
                    ¿Tienes una cuenta?
                    <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-medium">
                        Inicia sesión
                    </a>
                    para guardar tu historial de partidas
                </p>
            </div>
        </div>
    </div>
</x-guest-layout>
