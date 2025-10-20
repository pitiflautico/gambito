# Task List: Plataforma de Juegos Sociales Modulares

**PRD Reference:** `tasks/0001-prd-plataforma-juegos-sociales.md`
**Created:** 2025-10-20
**Status:** In Progress

---

## Relevant Files

### Backend - Core Models & Database (Compartido)
- `database/migrations/2025_10_20_180901_create_games_table.php` - Database schema for games catalog (name, slug, path to module, metadata cache, is_premium, is_active)
- `database/migrations/2025_10_20_181109_create_rooms_table.php` - Database schema for game rooms/lobbies (code, game_id, master_id, status enum, settings JSON)
- `database/migrations/2025_10_20_181110_create_matches_table.php` - Database schema for active game matches (room_id, started_at, finished_at, winner_id without FK, game_state JSON)
- `database/migrations/2025_10_20_181111_create_players_table.php` - Database schema for player sessions (match_id, name, role, score, is_connected, last_ping)
- `database/migrations/2025_10_20_181111_create_match_events_table.php` - Database schema for game event logging (match_id, event_type, data JSON, created_at)
- `app/Models/Game.php` - Eloquent model for games with path to module, config/capabilities accessors (reads from files), metadata caching, scopes (active, premium, free), and validation helpers
- `app/Models/Room.php` - Eloquent model for rooms with auto code generation, status management (waiting/playing/finished), relationships, and invite URL helper
- `app/Models/GameMatch.php` - Eloquent model for matches (Match is reserved in PHP) with game_state management, start/finish methods, duration calculation, and player filtering
- `app/Models/Player.php` - Eloquent model for temporary player sessions with connection tracking, ping/heartbeat, score management, role assignment, and inactivity detection
- `app/Models/MatchEvent.php` - Eloquent model for event logging with static log() helper and type filtering
- `database/seeders/GameSeeder.php` - Seeder to populate Pictionary game configuration

### Backend - Core Controllers (Compartido)
- `app/Http/Controllers/RoomController.php` - Handles room CRUD (create, join, lobby management)
- `app/Http/Controllers/GameController.php` - Lists available games and loads game configs
- `app/Http/Controllers/MatchController.php` - Manages match lifecycle and delegates to game engine
- `app/Http/Controllers/PlayerController.php` - Handles guest player join and session management

### Backend - Core Services (Compartido - Opcionales para cada juego)
- `app/Services/Core/RoomService.php` - Service to generate unique codes and QR codes
- `app/Services/Core/GameRegistry.php` - Service to register and discover available games
- `app/Services/Core/PlayerSessionService.php` - Service to manage temporary guest sessions
- `app/Services/Core/ScoreService.php` - OPTIONAL - Generic scoring service (games can use or ignore)

### Backend - Optional Shared Services (Solo si el juego los necesita)
- `app/Services/Shared/WebSocketService.php` - OPTIONAL - WebSocket helper (solo si juego usa sockets)
- `app/Services/Shared/TurnService.php` - OPTIONAL - Turn rotation logic (solo para juegos por turnos)
- `app/Services/Shared/PhaseService.php` - OPTIONAL - Phase management (solo si juego tiene fases)
- `app/Services/Shared/RoleService.php` - OPTIONAL - Role assignment (solo si juego tiene roles)
- `app/Services/Shared/TimerService.php` - OPTIONAL - Timer/timeout management (solo si juego necesita timers)

### Backend - Game Engine Interface (Contrato para todos los juegos)
- `app/Contracts/GameEngineInterface.php` - Interface that all games must implement
- `app/Contracts/GameConfigInterface.php` - Interface for game configuration validation

### Backend - Broadcasting & WebSockets (OPCIONAL)
- `app/Events/Core/PlayerJoined.php` - Core event: player joins room (siempre)
- `app/Events/Core/PlayerLeft.php` - Core event: player leaves room (siempre)
- `app/Events/Core/GameStarted.php` - Core event: match starts (siempre)
- `app/Events/Core/GameFinished.php` - Core event: match ends (siempre)
- `routes/channels.php` - WebSocket channel definitions and authorization (solo si se usan sockets)

### Backend - Pictionary Specific (Ejemplo de juego modular)
- `games/pictionary/PictionaryEngine.php` - Implementa GameEngineInterface
- `games/pictionary/Events/CanvasDrawEvent.php` - Pictionary-specific event
- `games/pictionary/Events/PlayerAnswered.php` - Pictionary-specific event
- `games/pictionary/Events/PlayerEliminated.php` - Pictionary-specific event
- `games/pictionary/Services/CanvasService.php` - Pictionary-specific canvas logic
- `games/pictionary/Services/WordService.php` - Pictionary-specific word selection
- `games/pictionary/config.json` - Pictionary game configuration (metadata)
- `games/pictionary/capabilities.json` - Declares which shared services it uses (websockets: true, turns: true, etc.)
- `games/pictionary/assets/words.json` - Word list for Pictionary

### Backend - Filament Resources (Compartido)
- `app/Filament/Resources/GameResource.php` - Admin panel resource for managing games
- `app/Filament/Resources/RoomResource.php` - Admin panel resource for viewing active rooms
- `app/Filament/Resources/MatchResource.php` - Admin panel resource for match history

### Frontend - Core Views (Compartido)
- `resources/views/layouts/game.blade.php` - Base layout for game interfaces
- `resources/views/rooms/create.blade.php` - View for masters to create a room
- `resources/views/rooms/join.blade.php` - View for players to join via code/QR
- `resources/views/rooms/lobby.blade.php` - Lobby view showing players waiting (customizable per game)
- `resources/views/rooms/show.blade.php` - Active game room view (loads game-specific view)
- `resources/views/components/game-loader.blade.php` - Component that loads game-specific views dynamically

### Frontend - Pictionary Specific Views
- `games/pictionary/views/canvas.blade.php` - Pictionary drawer view with canvas
- `games/pictionary/views/spectator.blade.php` - Pictionary spectator view
- `games/pictionary/views/results.blade.php` - Pictionary results screen

### Frontend - Core JavaScript (Compartido - Opcional)
- `resources/js/core/game-loader.js` - Dynamically loads game-specific JS modules
- `resources/js/core/websocket-manager.js` - OPTIONAL - WebSocket manager (solo si juego lo usa)
- `resources/js/core/player-session.js` - Manages player session state

### Frontend - Pictionary Specific JavaScript
- `games/pictionary/js/canvas.js` - HTML5 Canvas drawing functionality
- `games/pictionary/js/pictionary-game.js` - Pictionary game state and logic
- `games/pictionary/js/websocket-handlers.js` - Pictionary WebSocket event handlers

### Frontend - Styles
- `resources/css/games.css` - Base game styles
- `games/pictionary/css/pictionary.css` - Pictionary-specific styles

### Routes
- `routes/web.php` - Updated with room and game routes
- `routes/api.php` - API routes for game state updates
- `routes/games.php` - NUEVO - Game-specific routes registered dynamically

### Configuration
- `config/games.php` - NUEVO - Game system configuration (paths, enabled games, etc.)

### Tests
- `tests/Feature/Core/RoomTest.php` - Feature tests for room creation, joining, and management
- `tests/Feature/Core/GameRegistryTest.php` - Feature tests for game discovery and loading
- `tests/Feature/Games/PictionaryTest.php` - Feature tests for Pictionary game flow
- `tests/Unit/Services/Core/RoomServiceTest.php` - Unit tests for room service
- `tests/Unit/Services/Shared/TurnServiceTest.php` - Unit tests for turn rotation (if used)
- `tests/Unit/Games/Pictionary/PictionaryEngineTest.php` - Unit tests for Pictionary engine

---

## Tasks

- [ ] 1.0 Core Infrastructure: Database Schema and Base Models
  - [x] 1.1 Create migration for `games` table with columns: id, name, slug, description, config (JSON), is_premium, is_active, timestamps
  - [x] 1.2 Create migration for `rooms` table with columns: id, code (6 chars unique), game_id, master_id (user), status (enum), settings (JSON), timestamps
  - [x] 1.3 Create migration for `matches` table with columns: id, room_id, started_at, finished_at, winner_id, game_state (JSON), timestamps
  - [x] 1.4 Create migration for `players` table with columns: id, match_id, name, role, score, is_connected, last_ping, timestamps
  - [x] 1.5 Create migration for `match_events` table with columns: id, match_id, event_type, data (JSON), created_at
  - [x] 1.6 Create `Game` model with relationships and config casting
  - [x] 1.7 Create `Room` model with relationships, status enum, and code generation helper
  - [x] 1.8 Create `GameMatch` model (Match is reserved) with relationships and game_state casting
  - [x] 1.9 Create `Player` model with relationships and connection tracking
  - [x] 1.10 Create `MatchEvent` model with relationships and data casting
  - [x] 1.11 Run migrations and verify database schema

- [ ] 2.0 Game Registry System and Plugin Architecture
- [ ] 3.0 Room Management and Lobby System (Core Compartido)
- [ ] 4.0 Shared Optional Services (Microservicios Reutilizables)
- [ ] 5.0 WebSocket Infrastructure (Optional Service)
- [ ] 6.0 Pictionary Game Module (Ejemplo de Implementación Modular)
- [ ] 7.0 Admin Panel Integration with Filament
- [ ] 8.0 Testing and Quality Assurance

---

## Architecture Philosophy

Esta plataforma sigue una **arquitectura de microservicios internos** donde:

1. **Core Services (Obligatorios):** Servicios básicos que todos los juegos necesitan
   - Room management (crear salas, códigos, QR)
   - Player sessions (jugadores invitados)
   - Game registry (descubrir juegos disponibles)

2. **Shared Services (Opcionales):** Servicios reutilizables que los juegos pueden usar o no
   - WebSocketService (solo si juego necesita tiempo real)
   - TurnService (solo si juego es por turnos)
   - PhaseService (solo si juego tiene fases)
   - TimerService (solo si juego necesita timers)
   - RoleService (solo si juego tiene roles)

3. **Game Modules (Independientes):** Cada juego es un módulo autocontenido en `games/{nombre}/`
   - Implementa `GameEngineInterface` (contrato obligatorio)
   - Declara sus dependencias en `capabilities.json`
   - Tiene su propia lógica, vistas, JS, CSS, eventos
   - Se registra automáticamente al detectarse en `games/` folder

4. **Plugin System:** Los juegos se cargan dinámicamente
   - Sistema detecta carpetas en `games/`
   - Valida que implementen la interfaz requerida
   - Carga solo los shared services que el juego necesita
   - Registra rutas, eventos y vistas del juego

## Ejemplo: capabilities.json

```json
{
  "slug": "pictionary",
  "requires": {
    "websockets": true,
    "turns": true,
    "phases": true,
    "roles": false,
    "timers": true,
    "scoring": true
  },
  "provides": {
    "events": ["CanvasDrawEvent", "PlayerAnswered", "PlayerEliminated"],
    "routes": ["canvas", "spectator"],
    "views": ["canvas.blade.php", "spectator.blade.php", "results.blade.php"]
  }
}
```

## Notes

- **Laravel Reverb** se instala SOLO si algún juego activo requiere WebSockets: `composer require laravel/reverb`
- **Laravel Echo** y **Pusher JS** se cargan SOLO si el juego en la sala usa sockets: `npm install laravel-echo pusher-js`
- The project follows Laravel 11 conventions with existing Breeze authentication
- Spanish language is used for all user-facing interfaces
- Existing Filament 3 admin panel already configured with IsAdmin middleware
- Game modules are **completely isolated** in `games/` folder (own namespace, own dependencies)
- Each game can have its own Composer dependencies in `games/{name}/composer.json` (optional)
- Shared services are **injected on-demand** based on game's `capabilities.json`
- Testing strategy: Core tests, Shared service tests, Per-game tests (isolated)
