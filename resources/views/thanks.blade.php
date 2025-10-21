@extends('layouts.guest-public')

@section('title', 'Gracias por jugar')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-white rounded-2xl shadow-2xl p-8">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-24 w-24 rounded-full bg-gradient-to-r from-green-400 to-blue-500 mb-4">
                <svg class="h-12 w-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>

            <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                ¡Gracias por jugar!
            </h2>

            <p class="mt-4 text-lg text-gray-600">
                Esperamos que hayas disfrutado de la partida.
            </p>

            <div class="mt-8 bg-indigo-50 rounded-lg p-6">
                <p class="text-sm text-indigo-800 mb-2">
                    ¿Te ha gustado la experiencia?
                </p>
                <p class="text-xs text-indigo-600">
                    Pídele al organizador que cree una nueva partida para seguir jugando.
                </p>
            </div>

            <div class="mt-8 space-y-4">
                <a href="{{ route('home') }}" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                    Ir a la página principal
                </a>

                <a href="{{ route('games.index') }}" class="w-full flex justify-center py-3 px-4 border border-indigo-600 rounded-md shadow-sm text-sm font-medium text-indigo-600 bg-white hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                    Ver todos los juegos
                </a>
            </div>
        </div>

        <div class="mt-6 text-center">
            <p class="text-xs text-gray-500">
                GroupsGames - Diversión en grupo
            </p>
        </div>
    </div>
</div>
@endsection
