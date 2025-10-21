<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Gambito') }} - @yield('title', 'Home')</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="antialiased bg-gray-50">
    <!-- Navbar -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <!-- Logo -->
                    <div class="flex-shrink-0 flex items-center">
                        <a href="{{ route('home') }}" class="text-xl font-bold text-gray-900">
                            {{ config('app.name', 'Gambito') }}
                        </a>
                    </div>

                    <!-- Navigation Links -->
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="{{ route('home') }}" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Inicio
                        </a>
                    </div>
                </div>

                <!-- Right Side Of Navbar -->
                <div class="flex items-center">
                    @auth
                        <div class="hidden sm:flex sm:items-center sm:ml-6 space-x-4">
                            <span class="text-sm text-gray-700">{{ Auth::user()->name }}</span>
                            @if(Auth::user()->isAdmin())
                                <a href="/admin" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Panel Admin</a>
                            @endif
                            <a href="{{ route('dashboard') }}" class="text-sm text-gray-700 hover:text-gray-900">Dashboard</a>
                            <form method="POST" action="{{ route('logout') }}" class="inline">
                                @csrf
                                <button type="submit" class="text-sm text-gray-700 hover:text-gray-900">
                                    Cerrar Sesión
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="hidden sm:flex sm:items-center sm:ml-6 space-x-4">
                            <a href="{{ route('login') }}" class="text-sm text-gray-700 hover:text-gray-900">Iniciar Sesión</a>
                            <a href="{{ route('register') }}" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-blue-700 transition">
                                Registrarse
                            </a>
                        </div>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <p class="text-center text-gray-500 text-sm">
                &copy; {{ date('Y') }} {{ config('app.name', 'Gambito') }}. Todos los derechos reservados.
            </p>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
