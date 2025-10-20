@extends('layouts.guest-public')

@section('title', 'Inicio')

@section('content')
<div class="min-h-screen">
    <!-- Hero Section -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-700 py-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl md:text-6xl font-bold text-white mb-6">
                Bienvenido a Gambito
            </h1>
            <p class="text-xl text-blue-100 mb-8 max-w-2xl mx-auto">
                La plataforma para gestionar grupos y juegos
            </p>
            @guest
                <div class="space-x-4">
                    <a href="{{ route('register') }}" class="bg-white text-blue-700 px-8 py-3 rounded-lg font-semibold hover:bg-blue-50 transition">
                        Comenzar Ahora
                    </a>
                    <a href="{{ route('login') }}" class="bg-blue-800 text-white px-8 py-3 rounded-lg font-semibold hover:bg-blue-900 transition">
                        Iniciar Sesión
                    </a>
                </div>
            @else
                <div>
                    <p class="text-white text-lg mb-4">Hola, {{ Auth::user()->name }}!</p>
                    @if(Auth::user()->isAdmin())
                        <a href="/admin" class="bg-white text-blue-700 px-8 py-3 rounded-lg font-semibold hover:bg-blue-50 transition inline-block">
                            Ir al Panel de Administración
                        </a>
                    @endif
                </div>
            @endguest
        </div>
    </div>

    <!-- Features Section -->
    <div class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900 mb-4">Características</h2>
                <p class="text-gray-600">Todo lo que necesitas en un solo lugar</p>
            </div>

            <div class="grid md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="bg-gray-50 p-6 rounded-lg">
                    <div class="text-blue-600 text-4xl mb-4">🎮</div>
                    <h3 class="text-xl font-semibold mb-2">Gestión de Juegos</h3>
                    <p class="text-gray-600">Organiza y administra tus juegos favoritos</p>
                </div>

                <!-- Feature 2 -->
                <div class="bg-gray-50 p-6 rounded-lg">
                    <div class="text-blue-600 text-4xl mb-4">👥</div>
                    <h3 class="text-xl font-semibold mb-2">Grupos</h3>
                    <p class="text-gray-600">Crea y gestiona grupos de jugadores</p>
                </div>

                <!-- Feature 3 -->
                <div class="bg-gray-50 p-6 rounded-lg">
                    <div class="text-blue-600 text-4xl mb-4">📊</div>
                    <h3 class="text-xl font-semibold mb-2">Estadísticas</h3>
                    <p class="text-gray-600">Seguimiento detallado de todas las actividades</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
