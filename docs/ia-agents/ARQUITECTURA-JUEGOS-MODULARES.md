# Arquitectura de Juegos Modulares - Gambito

**Última actualización:** 2025-10-20
**Versión:** 0.3.0 (Plataforma de Juegos Sociales en desarrollo)

---

## Índice

- [Visión General](#visión-general)
- [Filosofía de Arquitectura](#filosofía-de-arquitectura)
- [Base de Datos](#base-de-datos)
- [Servicios Core](#servicios-core)
- [Servicios Compartidos Opcionales](#servicios-compartidos-opcionales)
- [Sistema de Plugins de Juegos](#sistema-de-plugins-de-juegos)
- [Estado de Implementación](#estado-de-implementación)

---

## Visión General

Gambito está evolucionando de una simple plataforma de gestión de grupos a una **plataforma modular de juegos sociales presenciales**, donde:

- Un **master** crea salas de juego
- Los **jugadores** se unen sin necesidad de registro (invitados)
- Cada **juego es un módulo independiente** que usa solo los servicios que necesita
- La arquitectura sigue principios de **microservicios internos**

---

## Filosofía de Arquitectura

### 🎯 Principios Fundamentales

1. **Modularidad Total:** Cada juego es un plugin autocontenido
2. **Servicios Opcionales:** Los juegos solo usan lo que necesitan (no obligamos WebSockets si no los necesita)
3. **Desacoplamiento:** Un juego no debe depender de otro juego
4. **Inyección de Dependencias:** Los servicios se cargan bajo demanda según `capabilities.json`

### 🏗️ Capas de la Arquitectura

```
┌─────────────────────────────────────────────────────────┐
│                   GAME MODULES (Plugins)                 │
│         games/pictionary/  games/trivia/  etc.          │
│         - Autocontenidos                                 │
│         - Declaran capacidades en capabilities.json      │
│         - Implementan GameEngineInterface                │
└────────────────────┬────────────────────────────────────┘
                     │ usa solo lo que necesita
┌────────────────────┴────────────────────────────────────┐
│          SHARED SERVICES (Opcionales)                    │
│   WebSocketService | TurnService | PhaseService         │
│   TimerService | RoleService | ScoreService             │
│   - Solo se cargan si el juego los requiere              │
└────────────────────┬────────────────────────────────────┘
                     │ usa siempre
┌────────────────────┴────────────────────────────────────┐
│               CORE SERVICES (Obligatorios)               │
│   RoomService | GameRegistry | PlayerSessionService     │
│   - Todos los juegos los necesitan                       │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────┐
│                  DATABASE (Core Tables)                  │
│      games | rooms | matches | players | events         │
└──────────────────────────────────────────────────────────┘
```

---

## Base de Datos

### 📊 Esquema Core (Compartido por todos los juegos)

#### Tabla: `games`

**Propósito:** Catálogo de juegos disponibles en la plataforma

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint unsigned | Primary key |
| `name` | varchar(255) | Nombre del juego (ej: "Pictionary") |
| `slug` | varchar(255) UNIQUE | Identificador único - debe coincidir con carpeta en `games/{slug}/` |
| `description` | text | Descripción del juego para usuarios |
| `path` | varchar(255) | Ruta al módulo del juego (ej: "games/pictionary") |
| `metadata` | json nullable | Cache opcional de config.json para optimizar queries |
| `is_premium` | boolean | Si el juego es de pago (default: false) |
| `is_active` | boolean | Si el juego está disponible (default: true) |
| `created_at` | timestamp | Fecha de creación |
| `updated_at` | timestamp | Fecha de actualización |

**Índices:**
- `slug` (UNIQUE)
- `is_active`

**Filosofía de diseño:**
- El registro en la tabla `games` es solo una **referencia** al módulo físico
- La **configuración real** vive en `games/{slug}/config.json`
- El campo `metadata` es un **cache opcional** para evitar leer el archivo en cada query
- El campo `path` apunta al folder del módulo: `games/{slug}`

**Ejemplo de registro:**
```json
{
  "id": 1,
  "name": "Pictionary",
  "slug": "pictionary",
  "description": "Dibuja y adivina palabras en tiempo real",
  "path": "games/pictionary",
  "metadata": {
    "minPlayers": 3,
    "maxPlayers": 10,
    "estimatedDuration": "15-20 minutos",
    "type": "drawing",
    "version": "1.0"
  },
  "is_premium": false,
  "is_active": true
}
```

**Flujo de configuración:**
1. El módulo existe físicamente en `games/pictionary/`
2. Contiene `config.json` con toda la configuración
3. Al registrar el juego en la BD, se puede cachear la metadata
4. El modelo `Game` puede leer desde cache o desde archivo según necesidad

**Relaciones:**
- `hasMany(Room::class)` - Un juego puede tener muchas salas

**Archivo de migración:** `database/migrations/2025_10_20_180901_create_games_table.php`

---

#### Tabla: `rooms`

**Propósito:** Salas de juego donde los jugadores esperan antes de comenzar

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint unsigned | Primary key |
| `code` | varchar(6) UNIQUE | Código de sala (ej: "ABC123") |
| `game_id` | bigint unsigned | Foreign key a `games` |
| `master_id` | bigint unsigned | Foreign key a `users` (quien creó la sala) |
| `status` | enum | Estado: 'waiting', 'playing', 'finished' |
| `settings` | json | Configuración personalizada de la sala |
| `created_at` | timestamp | Fecha de creación |
| `updated_at` | timestamp | Fecha de actualización |

**Estados posibles:**
- `waiting`: Sala abierta, esperando jugadores
- `playing`: Partida en curso
- `finished`: Partida terminada

**Índices:**
- `code` (UNIQUE)
- `status`
- `game_id, status` (compuesto) - Para consultas de salas activas por juego

**Relaciones:**
- `belongsTo(Game::class)` - La sala pertenece a un juego
- `belongsTo(User::class, 'master_id')` - La sala fue creada por un master
- `hasOne(Match::class)` - Una sala tiene una partida

**Archivo de migración:** `database/migrations/2025_10_20_181110_create_rooms_table.php`

---

#### Tabla: `matches`

**Propósito:** Partidas activas con estado del juego

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint unsigned | Primary key |
| `room_id` | bigint unsigned | Foreign key a `rooms` |
| `started_at` | timestamp nullable | Cuándo comenzó la partida |
| `finished_at` | timestamp nullable | Cuándo terminó la partida |
| `winner_id` | bigint unsigned nullable | Foreign key a `players` (ganador) |
| `game_state` | json | Estado completo del juego (turnos, fase actual, etc.) |
| `created_at` | timestamp | Fecha de creación |
| `updated_at` | timestamp | Fecha de actualización |

**Índices:**
- `room_id`
- `room_id, started_at` (compuesto) - Para consultas de historial

**Relaciones:**
- `belongsTo(Room::class)` - La partida pertenece a una sala
- `hasMany(Player::class)` - Una partida tiene muchos jugadores
- `hasMany(MatchEvent::class)` - Una partida tiene muchos eventos (opcional)
- `belongsTo(Player::class, 'winner_id')` - El ganador es un jugador

**Archivo de migración:** `database/migrations/2025_10_20_181111_create_matches_table.php`

---

#### Tabla: `players`

**Propósito:** Jugadores temporales (invitados sin registro) en una partida

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint unsigned | Primary key |
| `match_id` | bigint unsigned | Foreign key a `matches` |
| `name` | varchar(255) | Nombre/apodo del jugador |
| `role` | varchar(255) nullable | Rol en el juego (si aplica) |
| `score` | integer | Puntuación actual (default: 0) |
| `is_connected` | boolean | Si está conectado (default: true) |
| `last_ping` | timestamp nullable | Última actividad detectada |
| `created_at` | timestamp | Fecha de creación |
| `updated_at` | timestamp | Fecha de actualización |

**Índices:**
- `match_id`
- `match_id, is_connected` (compuesto) - Para consultas de jugadores activos

**Relaciones:**
- `belongsTo(Match::class)` - El jugador pertenece a una partida

**Archivo de migración:** `database/migrations/2025_10_20_181111_create_players_table.php`

---

#### Tabla: `match_events`

**Propósito:** Log de eventos importantes durante una partida (opcional, solo si el juego lo necesita)

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | bigint unsigned | Primary key |
| `match_id` | bigint unsigned | Foreign key a `matches` |
| `event_type` | varchar(255) | Tipo de evento (ej: "draw", "answer", "eliminate") |
| `data` | json | Datos del evento |
| `created_at` | timestamp | Cuándo ocurrió el evento (sin updated_at) |

**Índices:**
- `match_id`
- `match_id, event_type` (compuesto) - Para consultas de eventos específicos
- `created_at` - Para consultas temporales

**Relaciones:**
- `belongsTo(Match::class)` - El evento pertenece a una partida

**Archivo de migración:** `database/migrations/2025_10_20_181111_create_match_events_table.php`

---

## Modelos Eloquent

### 📦 Modelo: `Game`

**Ubicación:** `app/Models/Game.php`

**Propósito:** Representa un juego disponible en la plataforma (ej: Pictionary, Trivia, etc.)

#### Atributos Fillable
```php
[
    'name',           // Nombre del juego
    'slug',           // Identificador único (debe coincidir con carpeta)
    'description',    // Descripción para usuarios
    'path',           // Ruta al módulo (ej: "games/pictionary")
    'metadata',       // Cache opcional de config.json
    'is_premium',     // Si es de pago
    'is_active',      // Si está disponible
]
```

#### Casts (Conversión automática)
```php
[
    'metadata' => 'array',      // JSON → Array PHP (cache)
    'is_premium' => 'boolean',  // 0/1 → true/false
    'is_active' => 'boolean',   // 0/1 → true/false
]
```

#### Relaciones
- **`rooms()`** - `hasMany(Room::class)` - Un juego puede tener muchas salas

#### Scopes (Query helpers)
- **`active()`** - Filtra solo juegos activos
  ```php
  Game::active()->get(); // Solo juegos con is_active = true
  ```
- **`premium()`** - Filtra solo juegos premium
  ```php
  Game::premium()->get(); // Solo juegos de pago
  ```
- **`free()`** - Filtra solo juegos gratuitos
  ```php
  Game::free()->get(); // Solo juegos gratis
  ```

#### Accessors (Atributos calculados)
- **`config`** - Lee config.json desde el módulo (usa cache si existe)
  ```php
  $game->config; // Array con toda la configuración del juego
  ```
- **`capabilities`** - Lee capabilities.json desde el módulo
  ```php
  $game->capabilities; // ['requires' => ['websockets' => true, ...]]
  ```
- **`min_players`** - Extrae `minPlayers` del config
  ```php
  $game->min_players; // 3 (desde config.minPlayers)
  ```
- **`max_players`** - Extrae `maxPlayers` del config
  ```php
  $game->max_players; // 10 (desde config.maxPlayers)
  ```
- **`estimated_duration`** - Extrae `estimatedDuration` del config
  ```php
  $game->estimated_duration; // "15-20 minutos"
  ```

#### Métodos Helper
- **`isValidPlayerCount(int $count): bool`** - Valida si el número de jugadores es válido
  ```php
  $game->isValidPlayerCount(5); // true si 5 está entre min y max
  ```
- **`cacheMetadata(): void`** - Cachea config.json en la columna metadata
  ```php
  $game->cacheMetadata(); // Lee config.json y lo guarda en BD
  ```
- **`moduleExists(): bool`** - Verifica si el folder del juego existe
  ```php
  $game->moduleExists(); // true si games/pictionary/ existe
  ```

#### Ejemplo de Uso
```php
// Registrar un juego (el módulo ya debe existir en games/pictionary/)
$game = Game::create([
    'name' => 'Pictionary',
    'slug' => 'pictionary',
    'description' => 'Dibuja y adivina palabras',
    'path' => 'games/pictionary',
    'is_premium' => false,
    'is_active' => true,
]);

// Cachear la configuración desde config.json
$game->cacheMetadata();

// Consultar juegos activos y gratuitos
$freeGames = Game::active()->free()->get();

// Validar número de jugadores
if ($game->isValidPlayerCount(5)) {
    // Crear sala con 5 jugadores
}

// Acceder a configuración (lee desde cache o desde archivo)
echo $game->min_players; // 3 (desde config.json)
echo $game->max_players; // 10
$config = $game->config; // Array completo
$capabilities = $game->capabilities; // Lee capabilities.json

// Verificar que el módulo existe físicamente
if ($game->moduleExists()) {
    // El folder games/pictionary/ existe
}
```

---

## Servicios Core

### 🔧 Servicios Obligatorios (Todos los juegos los usan)

#### 1. `RoomService` *(Pendiente)*
**Ubicación:** `app/Services/Core/RoomService.php`

**Responsabilidades:**
- Generar códigos únicos de sala (6 caracteres alfanuméricos)
- Generar códigos QR para compartir
- Validar códigos de sala
- Gestionar URLs de invitación

---

#### 2. `GameRegistry` *(Pendiente)*
**Ubicación:** `app/Services/Core/GameRegistry.php`

**Responsabilidades:**
- Descubrir juegos disponibles en `games/` folder
- Validar que implementen `GameEngineInterface`
- Cargar configuración de cada juego (`config.json`)
- Leer capacidades de cada juego (`capabilities.json`)
- Registrar rutas, eventos y vistas dinámicamente

**Ejemplo de uso:**
```php
$registry = app(GameRegistry::class);
$availableGames = $registry->getActiveGames(); // Lista de juegos activos
$game = $registry->loadGame('pictionary'); // Carga juego específico
```

---

#### 3. `PlayerSessionService` *(Pendiente)*
**Ubicación:** `app/Services/Core/PlayerSessionService.php`

**Responsabilidades:**
- Crear sesiones temporales para jugadores invitados
- Validar nombres de jugadores (duplicados, caracteres)
- Gestionar reconexiones
- Limpiar sesiones expiradas

---

## Servicios Compartidos Opcionales

### 🔌 Servicios que los juegos pueden usar o ignorar

Estos servicios **NO** son obligatorios. Cada juego declara en su `capabilities.json` cuáles necesita.

#### 1. `WebSocketService` *(Pendiente)*
**Ubicación:** `app/Services/Shared/WebSocketService.php`
**Requiere:** Laravel Reverb instalado

**Se usa si:** El juego necesita sincronización en tiempo real
**Responsabilidades:**
- Helper para broadcast de eventos
- Gestión de canales por sala
- Autenticación de conexiones WebSocket

---

#### 2. `TurnService` *(Pendiente)*
**Ubicación:** `app/Services/Shared/TurnService.php`

**Se usa si:** El juego es por turnos
**Responsabilidades:**
- Determinar orden de turnos (aleatorio, secuencial, custom)
- Avanzar al siguiente turno
- Manejar timeouts de turno
- Saltar turnos de jugadores desconectados

---

#### 3. `PhaseService` *(Pendiente)*
**Ubicación:** `app/Services/Shared/PhaseService.php`

**Se usa si:** El juego tiene fases (ej: asignación → juego → votación → resultados)
**Responsabilidades:**
- Cargar fases desde `rules.json` del juego
- Gestionar transiciones entre fases
- Validar que se cumplan condiciones para avanzar

---

#### 4. `TimerService` *(Pendiente)*
**Ubicación:** `app/Services/Shared/TimerService.php`

**Se usa si:** El juego necesita temporizadores
**Responsabilidades:**
- Crear timers con duración específica
- Emitir eventos de advertencia (30s, 15s, 5s restantes)
- Ejecutar callback al terminar el tiempo

---

#### 5. `RoleService` *(Pendiente)*
**Ubicación:** `app/Services/Shared/RoleService.php`

**Se usa si:** El juego tiene roles (ej: dibujante/adivinador, impostor/tripulante)
**Responsabilidades:**
- Asignar roles aleatorios según configuración
- Gestionar roles secretos vs públicos
- Rotar roles entre rondas

---

## Sistema de Plugins de Juegos

### 📦 Estructura de un Game Module

Cada juego vive en `games/{nombre}/` con esta estructura:

```
games/
└── pictionary/
    ├── PictionaryEngine.php          # Implementa GameEngineInterface (OBLIGATORIO)
    ├── config.json                   # Metadata del juego (OBLIGATORIO)
    ├── capabilities.json             # Qué servicios usa (OBLIGATORIO)
    ├── composer.json                 # Dependencias específicas (OPCIONAL)
    ├── Services/
    │   ├── CanvasService.php         # Lógica específica del juego
    │   └── WordService.php
    ├── Events/
    │   ├── CanvasDrawEvent.php       # Eventos específicos
    │   ├── PlayerAnswered.php
    │   └── PlayerEliminated.php
    ├── views/
    │   ├── canvas.blade.php          # Vistas del juego
    │   ├── spectator.blade.php
    │   └── results.blade.php
    ├── js/
    │   ├── canvas.js                 # JavaScript específico
    │   └── pictionary-game.js
    ├── css/
    │   └── pictionary.css            # Estilos específicos
    └── assets/
        └── words.json                # Recursos del juego
```

---

### 📄 Archivos Obligatorios de un Juego

#### 1. `config.json` - Metadata del juego
```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "description": "Dibuja y adivina palabras en tiempo real",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito",
  "thumbnail": "/games/pictionary/assets/thumbnail.jpg"
}
```

---

#### 2. `capabilities.json` - Declaración de dependencias
```json
{
  "slug": "pictionary",
  "requires": {
    "websockets": true,    // ✅ Necesita tiempo real
    "turns": true,         // ✅ Es por turnos
    "phases": true,        // ✅ Tiene fases
    "roles": false,        // ❌ No usa roles
    "timers": true,        // ✅ Usa temporizadores
    "scoring": true        // ✅ Usa sistema de puntuación
  },
  "provides": {
    "events": [
      "CanvasDrawEvent",
      "PlayerAnswered",
      "PlayerEliminated"
    ],
    "routes": [
      "canvas",
      "spectator"
    ],
    "views": [
      "canvas.blade.php",
      "spectator.blade.php",
      "results.blade.php"
    ]
  }
}
```

---

#### 3. `{GameName}Engine.php` - Implementación del contrato
```php
<?php

namespace Games\Pictionary;

use App\Contracts\GameEngineInterface;

class PictionaryEngine implements GameEngineInterface
{
    public function start(Match $match): void
    {
        // Lógica de inicio del juego
    }

    public function processAction(Match $match, string $action, array $data): void
    {
        // Procesar acciones del jugador
    }

    public function checkWinCondition(Match $match): ?Player
    {
        // Determinar si hay ganador
    }

    public function getGameState(Match $match): array
    {
        // Retornar estado actual
    }
}
```

---

## Estado de Implementación

### ✅ Completado

#### Base de Datos - Migraciones
- [x] Migración de tabla `games` creada (id, name, slug, description, config JSON, is_premium, is_active)
- [x] Migración de tabla `rooms` creada (id, code, game_id, master_id, status enum, settings JSON)
- [x] Migración de tabla `matches` creada (id, room_id, started_at, finished_at, winner_id, game_state JSON)
- [x] Migración de tabla `players` creada (id, match_id, name, role, score, is_connected, last_ping)
- [x] Migración de tabla `match_events` creada (id, match_id, event_type, data JSON, created_at)
- [x] Índices optimizados en todas las tablas para queries frecuentes

### 🚧 En Progreso

#### Base de Datos - Modelos
- [x] Modelo `Game` creado con todas sus funcionalidades
- [ ] Creando resto de modelos (Room, Match, Player, MatchEvent)

### 📋 Pendiente

#### Base de Datos
- [ ] Crear modelo `Room` con relaciones, status enum, y helper para códigos
- [ ] Crear modelo `Match` con relaciones y game_state casting
- [ ] Crear modelo `Player` con relaciones y connection tracking
- [ ] Crear modelo `MatchEvent` con relaciones y data casting
- [ ] Ejecutar migraciones en base de datos

#### Servicios Core
- [ ] `RoomService` - Códigos únicos y QR
- [ ] `GameRegistry` - Descubrimiento automático de juegos
- [ ] `PlayerSessionService` - Sesiones de invitados

#### Servicios Compartidos
- [ ] `WebSocketService` (opcional)
- [ ] `TurnService` (opcional)
- [ ] `PhaseService` (opcional)
- [ ] `TimerService` (opcional)
- [ ] `RoleService` (opcional)

#### Contratos e Interfaces
- [ ] `GameEngineInterface` - Contrato que todos los juegos deben implementar
- [ ] `GameConfigInterface` - Validación de configuración

#### Juego MVP: Pictionary
- [ ] Estructura de carpetas en `games/pictionary/`
- [ ] Archivos de configuración (config.json, capabilities.json)
- [ ] PictionaryEngine implementando GameEngineInterface
- [ ] Canvas con sincronización en tiempo real
- [ ] Sistema de palabras y turnos

---

## Convenciones de Código

### Nombres de Clases y Archivos
- **Modelos:** PascalCase singular (`Game`, `Room`, `Match`)
- **Servicios Core:** PascalCase + "Service" (`RoomService`, `GameRegistry`)
- **Servicios Shared:** PascalCase + "Service" (`TurnService`, `PhaseService`)
- **Game Engines:** `{GameName}Engine` (`PictionaryEngine`)
- **Eventos:** PascalCase + "Event" (`PlayerJoined`, `CanvasDrawEvent`)

### Namespaces
- **Core Services:** `App\Services\Core\`
- **Shared Services:** `App\Services\Shared\`
- **Contratos:** `App\Contracts\`
- **Game Modules:** `Games\{GameName}\` (ej: `Games\Pictionary\`)

### Base de Datos
- **Tablas:** snake_case plural (`games`, `rooms`, `matches`, `players`)
- **Columnas:** snake_case (`is_active`, `game_id`, `created_at`)
- **Foreign Keys:** `{table_singular}_id` (`game_id`, `room_id`)

---

## Próximos Pasos

1. ✅ **Crear tabla `games`** - COMPLETADO
2. Crear tablas `rooms`, `matches`, `players`, `match_events`
3. Crear modelos Eloquent con relaciones
4. Implementar `GameRegistry` para descubrimiento de juegos
5. Crear contrato `GameEngineInterface`
6. Implementar servicios Core (`RoomService`, `PlayerSessionService`)
7. Comenzar con Pictionary como primer juego de ejemplo

---

**Documento actualizado por:** Claude Code
**Próxima revisión:** Al completar cada grupo de subtareas
