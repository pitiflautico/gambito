<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pictionary Game Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración específica del juego Pictionary.
    | Este archivo se carga automáticamente por el GameRegistry.
    |
    */

    // Metadatos básicos
    'name' => 'Pictionary',
    'slug' => 'pictionary',
    'description' => 'Dibuja y adivina palabras antes que los demás',
    'version' => '1.0.0',

    // Configuración de jugadores
    'min_players' => 3,
    'max_players' => 10,
    'supports_guests' => true,

    // Configuración de rutas
    'routes' => [
        // Middlewares aplicados a TODAS las rutas del juego
        'middleware' => [
            // Aquí podríamos agregar middlewares específicos del juego
            // Ejemplo: 'throttle:60,1' para rate limiting
        ],

        // Middlewares solo para rutas API
        'api_middleware' => [
            // Ejemplo: 'auth:sanctum' si requiere autenticación
        ],

        // Middlewares solo para rutas Web
        'web_middleware' => [
            // Ejemplo: 'verified' si requiere email verificado
        ],
    ],

    // Configuración del juego
    'game_config' => [
        'rounds_total' => 5,
        'turn_duration' => 90, // segundos
        'word_difficulties' => ['easy', 'medium', 'hard'],

        // Sistema de puntuación
        'scoring' => [
            'fast_answer' => [
                'time_threshold' => 30, // segundos
                'guesser_points' => 100,
                'drawer_points' => 50,
            ],
            'medium_answer' => [
                'time_threshold' => 60,
                'guesser_points' => 75,
                'drawer_points' => 40,
            ],
            'slow_answer' => [
                'guesser_points' => 50,
                'drawer_points' => 25,
            ],
        ],
    ],

    // Assets del juego
    'assets' => [
        'css' => [
            'public/games/pictionary/css/canvas.css',
        ],
        'js' => [
            // JavaScript se compila con Vite desde resources/js/
            'pictionary-canvas', // Se compila a public/build/assets/
        ],
    ],
];
