<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Games Directory Path
    |--------------------------------------------------------------------------
    |
    | Ruta donde se encuentran los módulos de juegos.
    | Por defecto: base_path('games')
    |
    */

    'path' => env('GAMES_PATH', base_path('games')),

    /*
    |--------------------------------------------------------------------------
    | Auto-Discovery
    |--------------------------------------------------------------------------
    |
    | Si está habilitado, el sistema escaneará automáticamente la carpeta
    | de juegos en busca de nuevos módulos al iniciar la aplicación.
    | En producción, se recomienda desactivar y usar el comando artisan.
    |
    */

    'auto_discovery' => env('GAMES_AUTO_DISCOVERY', false),

    /*
    |--------------------------------------------------------------------------
    | Required Config Fields
    |--------------------------------------------------------------------------
    |
    | Campos obligatorios que debe tener el archivo config.json de cada juego.
    |
    */

    'required_config_fields' => [
        'id',                  // Identificador único del juego
        'name',                // Nombre del juego
        'slug',                // Slug (debe coincidir con el nombre de la carpeta)
        'description',         // Descripción del juego
        'minPlayers',          // Número mínimo de jugadores
        'maxPlayers',          // Número máximo de jugadores
        'estimatedDuration',   // Duración estimada (ej: "15-20 minutos")
        'version',             // Versión del juego
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Config Fields
    |--------------------------------------------------------------------------
    |
    | Campos opcionales que puede tener el config.json.
    |
    */

    'optional_config_fields' => [
        'type',                // Tipo de juego (ej: "drawing", "trivia", "social")
        'isPremium',           // Si es premium (default: false)
        'author',              // Autor del juego
        'thumbnail',           // Ruta a imagen de miniatura
        'tags',                // Tags descriptivos (array)
    ],

    /*
    |--------------------------------------------------------------------------
    | Available Shared Services (Capabilities)
    |--------------------------------------------------------------------------
    |
    | Servicios compartidos que los juegos pueden declarar en capabilities.json.
    | Cada juego especifica cuáles de estos servicios necesita.
    |
    */

    'available_capabilities' => [
        'websockets',          // Sincronización en tiempo real
        'turns',               // Sistema de turnos
        'phases',              // Sistema de fases
        'roles',               // Asignación de roles
        'timers',              // Temporizadores
        'scoring',             // Sistema de puntuación
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Reglas de validación para los archivos de configuración.
    |
    */

    'validation' => [
        'minPlayers' => [
            'min' => 1,
            'max' => 100,
        ],
        'maxPlayers' => [
            'min' => 1,
            'max' => 100,
        ],
        'version' => [
            'format' => '/^\d+\.\d+(\.\d+)?$/', // Regex para semver (ej: 1.0 o 1.0.1)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configuración del caché de configuraciones de juegos.
    |
    */

    'cache' => [
        'enabled' => env('GAMES_CACHE_ENABLED', true),
        'ttl' => env('GAMES_CACHE_TTL', 3600), // Segundos (1 hora por defecto)
        'key_prefix' => 'game:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Files
    |--------------------------------------------------------------------------
    |
    | Archivos que debe tener cada módulo de juego.
    |
    */

    'required_files' => [
        'config.json',         // Configuración del juego
        'capabilities.json',   // Capacidades y dependencias
        // Engine class se valida dinámicamente por nombre
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional Directories
    |--------------------------------------------------------------------------
    |
    | Directorios opcionales que puede tener un módulo de juego.
    |
    */

    'optional_directories' => [
        'views',               // Vistas Blade específicas del juego
        'js',                  // JavaScript específico del juego
        'css',                 // Estilos específicos del juego
        'assets',              // Recursos (imágenes, archivos, etc.)
        'Services',            // Servicios específicos del juego
        'Events',              // Eventos específicos del juego
    ],

];
