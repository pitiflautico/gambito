# Task List: Plataforma de Juegos Sociales Modulares

**PRD Reference:** `tasks/0001-prd-plataforma-juegos-sociales.md`
**Created:** 2025-10-20
**Última actualización:** 2025-10-21
**Status:** In Progress

> ⚠️ **IMPORTANTE:** Antes de trabajar en este proyecto, lee [`docs/INSTRUCTIONS_FOR_AGENTS.md`](../docs/INSTRUCTIONS_FOR_AGENTS.md)

> 📚 **Documentación:** Ver índice completo en [`docs/README.md`](../docs/README.md)

---

## 🎯 Estrategia de Desarrollo

**Decisión:** Desarrollo Iterativo (Opción C)

Ver: [`docs/decisions/ADR-002-ITERATIVE_DEVELOPMENT.md`](../docs/decisions/ADR-002-ITERATIVE_DEVELOPMENT.md)

**Fases:**
1. ✅ **Fase 1:** Core Infrastructure (DB, Models, Game Registry) - **COMPLETADA**
2. ✅ **Fase 2:** Room Management & Lobby - **COMPLETADA**
3. 🚧 **Fase 3:** Pictionary MVP Monolítico (sin módulos opcionales) - **EN DESARROLLO**
4. ⏳ **Fase 4:** Extracción de Módulos Opcionales - **PENDIENTE**
5. ⏳ **Fase 5:** Segundo Juego (validación de módulos) - **PENDIENTE**

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

### ✅ FASE 1: Core Infrastructure (COMPLETADA)

- [x] **1.0 Core Infrastructure: Database Schema and Base Models**
  - [x] 1.1 Create migration for `games` table
  - [x] 1.2 Create migration for `rooms` table
  - [x] 1.3 Create migration for `matches` table
  - [x] 1.4 Create migration for `players` table
  - [x] 1.5 Create migration for `match_events` table
  - [x] 1.6 Create `Game` model
  - [x] 1.7 Create `Room` model
  - [x] 1.8 Create `GameMatch` model
  - [x] 1.9 Create `Player` model
  - [x] 1.10 Create `MatchEvent` model
  - [x] 1.11 Run migrations and verify database schema
  - **Documentación:** ✅ Modelos documentados

- [x] **2.0 Game Registry System and Plugin Architecture**
  - [x] 2.1 Create `GameEngineInterface` contract
  - [x] 2.2 Create `GameConfigInterface` for validation
  - [x] 2.3 Create `config/games.php` configuration file
  - [x] 2.4 Create `GameRegistry` service
  - [x] 2.5 Implement game discovery (scan games/)
  - [x] 2.6 Implement config.json validation
  - [x] 2.7 Implement capabilities.json parsing
  - [x] 2.8 Create Artisan command `games:discover`
  - [x] 2.9 Create Artisan command `games:validate`
  - [x] 2.10 Write tests (14 tests, 46 assertions - passing)
  - **Documentación:** ✅ `docs/modules/core/GAME_REGISTRY.md`

---

### ✅ FASE 2: Room Management & Lobby (COMPLETADA)

- [x] **3.0 Room Management and Lobby System**
  - [x] 3.1 RoomController (create, join, lobby, show, results)
  - [x] 3.2 RoomService (codes únicos, QR, validación)
  - [x] 3.3 PlayerSessionService (guests, heartbeat, reconnect)
  - [x] 3.4 Vistas: create.blade.php, join.blade.php, lobby.blade.php
  - [x] 3.5 Vistas: guest-name.blade.php, show.blade.php, results.blade.php
  - [x] 3.6 Rutas web y API
  - [x] 3.7 Tests Feature y Unit
  - **Documentación:** ✅ `docs/modules/core/ROOM_MANAGER.md`, `docs/modules/core/PLAYER_SESSION.md`

---

### 🚧 FASE 3: Pictionary MVP Monolítico (EN DESARROLLO)

**Estrategia:** Implementar Pictionary de forma monolítica (sin módulos opcionales). Toda la lógica en `PictionaryEngine.php`.

- [x] **4.0 Pictionary Game Structure** - ✅ **COMPLETADO**
  - [x] 4.1 Crear carpeta `games/pictionary/`
  - [x] 4.2 Crear `PictionaryEngine.php` (implementa GameEngineInterface, métodos avanzados)
  - [x] 4.3 Crear `config.json` (metadata completo del juego)
  - [x] 4.4 Crear `capabilities.json` (versión monolítica vacía)
  - [x] 4.5 Crear `assets/words.json` (120 palabras en español, 3 dificultades)
  - [x] 4.6 Registrar juego con GameRegistry (automático al escanear)
  - [x] 4.7 Añadir namespace `Games\` a composer autoload
  - [x] **Documentación:** ✅ `docs/games/PICTIONARY.md` creado y actualizado

- [x] **5.0 Pictionary Canvas System** - ✅ **COMPLETADO**
  - [x] 5.1 Vista `resources/views/games/pictionary/canvas.blade.php` (completa, responsive)
  - [x] 5.2 Vista spectator (NO - misma vista con roles diferentes)
  - [x] 5.3 JavaScript `public/games/pictionary/js/canvas.js` (Clase `PictionaryCanvas` completa)
  - [x] 5.4 CSS `public/games/pictionary/css/canvas.css` (diseño moderno)
  - [x] 5.5 Herramientas: lápiz, borrador, 12 colores, 4 grosores, botón limpiar
  - [x] 5.6 Eventos touch y mouse (soporte móvil completo)
  - [x] 5.7 Controlador `PictionaryController` con método `demo()`
  - [x] 5.8 Ruta `/pictionary/demo` y `/pictionary/demo?role=guesser`
  - [x] 5.9 Funcionalidades extra: botón "YO SÉ", confirmación, eliminación visual
  - [x] **Documentación:** ✅ Actualizado `docs/games/PICTIONARY.md`

- [x] **6.0 Pictionary Game Logic (Monolítico en Engine)** - ✅ **COMPLETADO**
  - [x] 6.1 Selección aleatoria de palabras (método `selectRandomWord()`)
  - [x] 6.2 Sistema de turnos (método `nextTurn()`, rotación circular)
  - [x] 6.3 Asignación de roles: drawer/guesser (campo `current_drawer_id`)
  - [x] 6.4 Sistema de puntuación (inicialización de `scores`, `checkWinCondition()`)
  - [x] 6.5 Timer de 90 segundos (campos en game_state, cálculo en `getGameStateForPlayer()`)
  - [x] 6.6 Botón "¡Ya lo sé!" y confirmación de respuesta (Frontend + Backend completos)
  - [x] 6.7 Eliminación de jugadores en ronda (Frontend + Backend completos)
  - [x] 6.8 Cálculo de puntos según tiempo (método `calculatePointsByTime()`, 150/100/50 pts)
  - [x] 6.9 Condición de victoria (método `checkWinCondition()`, mayor puntuación)
  - [x] 6.10 Métodos completados: `processAction()`, `getGameStateForPlayer()`, `handlePlayerDisconnect()`
  - [x] **Documentación:** ✅ Actualizado `docs/games/PICTIONARY.md`
  - **Nota:** Timer automático con Jobs/Queue se implementará con WebSockets en Task 7.0

- [x] **7.0 Pictionary Real-time Sync (WebSockets)** - ✅ **100% COMPLETADO**
  - [x] 7.1 Instalar Laravel Reverb: `composer require laravel/reverb` ✅
  - [x] 7.2 Configurar broadcasting con `php artisan install:broadcasting` ✅
  - [x] 7.3 Crear eventos WebSocket ✅
    - [x] `CanvasDrawEvent` - Sincroniza trazos del canvas
    - [x] `PlayerAnsweredEvent` - Notifica cuando alguien pulsa "YO SÉ"
    - [x] `PlayerEliminatedEvent` - Notifica eliminación de jugador
    - [x] `GameStateUpdatedEvent` - Sincroniza estado general (fase, ronda, puntos)
    - [x] `TestEvent` - Evento de prueba para testing
  - [x] 7.4 Configurar canal privado `room.{code}` en routes/channels.php ✅
  - [x] 7.5 Frontend: Laravel Echo + Pusher JS instalados y configurados ✅
  - [x] 7.6 Frontend: WebSocket conectado y testeado ✅
  - [x] 7.7 Sistema de broadcasting funcionando (canales públicos y privados) ✅
  - [x] 7.8 Testing de sincronización completado (página /test-websocket) ✅
  - [x] 7.9 Configuración de desarrollo documentada (HTTP, QUEUE=sync) ✅
  - [x] 7.10 Configuración de producción documentada (SSL, proxy Nginx) ✅
  - **Documentación:** ✅ `docs/WEBSOCKET_SETUP.md` + `docs/INSTALLATION.md`
  - **Próximo paso:** Integrar eventos en Pictionary canvas

- [ ] **8.0 Pictionary Testing**
  - [ ] 8.1 Feature tests: flujo completo de juego
  - [ ] 8.2 Unit tests: PictionaryEngine
  - [ ] 8.3 Unit tests: WordService
  - [ ] 8.4 Unit tests: Cálculo de puntos
  - [ ] 8.5 Tests de canvas (JS tests con Jest - opcional)
  - **Documentación:** Actualizar `docs/games/PICTIONARY.md`

---

### ⏳ FASE 4: Extracción de Módulos Opcionales (PENDIENTE)

**Estrategia:** Refactorizar Pictionary para extraer lógica reutilizable como módulos.

- [x] **9.0 Extraer Turn System Module** - ✅ **COMPLETADO**
  - [x] 9.1 Crear `app/Services/Modules/TurnSystem/TurnManager.php`
  - [x] 9.2 Extraer lógica de turnos de PictionaryEngine
  - [x] 9.3 Crear tests para TurnManager (35 tests, 127 assertions)
  - [x] 9.4 Refactorizar Pictionary para usar TurnManager
  - [x] 9.5 Actualizar capabilities.json de Pictionary
  - [x] 9.6 Crear sistema de configuración declarativa (config.json)
  - [x] 9.7 Crear GameConfigService para leer/validar configs
  - [x] 9.8 Implementar UI dinámica en room creation
  - **Documentación:** `docs/modules/optional/TURN_SYSTEM.md` ✅
  - **Convención:** `docs/conventions/GAME_CONFIGURATION_CONVENTION.md` ✅

- [ ] **10.0 Extraer Scoring System Module**
  - [ ] 10.1 Crear `app/Modules/ScoringSystem/ScoreManager.php`
  - [ ] 10.2 Crear `ScoreCalculatorInterface`
  - [ ] 10.3 Extraer lógica de puntuación de PictionaryEngine
  - [ ] 10.4 Crear `PictionaryScoreCalculator` (implementa interface)
  - [ ] 10.5 Crear tests para ScoreManager
  - [ ] 10.6 Refactorizar Pictionary para usar ScoreManager
  - **Documentación:** `docs/modules/optional/SCORING_SYSTEM.md`

- [ ] **11.0 Extraer Timer System Module**
  - [ ] 11.1 Crear `app/Modules/TimerSystem/TimerService.php`
  - [ ] 11.2 Extraer lógica de timer de PictionaryEngine
  - [ ] 11.3 Crear tests para TimerService
  - [ ] 11.4 Refactorizar Pictionary para usar TimerService
  - **Documentación:** `docs/modules/optional/TIMER_SYSTEM.md`

- [ ] **12.0 Extraer Roles System Module**
  - [ ] 12.1 Crear `app/Modules/RolesSystem/RoleManager.php`
  - [ ] 12.2 Extraer lógica de roles de PictionaryEngine
  - [ ] 12.3 Crear tests para RoleManager
  - [ ] 12.4 Refactorizar Pictionary para usar RoleManager
  - **Documentación:** `docs/modules/optional/ROLES_SYSTEM.md`

- [ ] **13.0 Formalizar Realtime Sync Module**
  - [ ] 13.1 Crear `app/Modules/RealtimeSync/WebSocketService.php`
  - [ ] 13.2 Crear `BroadcastManager`
  - [ ] 13.3 Documentar eventos estándar
  - [ ] 13.4 Crear tests
  - **Documentación:** `docs/modules/optional/REALTIME_SYNC.md`

- [ ] **14.0 Actualizar Pictionary para Modularidad**
  - [ ] 14.1 Actualizar `capabilities.json` con módulos requeridos
  - [ ] 14.2 Simplificar `PictionaryEngine.php` (delegar a módulos)
  - [ ] 14.3 Verificar que todos los tests pasan
  - [ ] 14.4 Actualizar documentación
  - **Documentación:** Actualizar `docs/games/PICTIONARY.md`

---

### ⏳ FASE 5: Segundo Juego - Validación (PENDIENTE)

**Estrategia:** Implementar segundo juego (Trivia o UNO) reutilizando módulos extraídos.

- [ ] **15.0 Implementar Segundo Juego (TBD: Trivia o UNO)**
  - [ ] 15.1 Crear carpeta `games/{nombre}/`
  - [ ] 15.2 Implementar `{Nombre}Engine.php`
  - [ ] 15.3 Crear `config.json` y `capabilities.json`
  - [ ] 15.4 Reutilizar módulos: Turn, Scoring, Timer, etc.
  - [ ] 15.5 Implementar lógica específica del juego
  - [ ] 15.6 Crear vistas y assets
  - [ ] 15.7 Escribir tests
  - [ ] 15.8 Validar que módulos son realmente reutilizables
  - **Documentación:** `docs/games/{NOMBRE}.md`

---

### ⏳ FASE 6: Admin Panel & Polish (PENDIENTE)

- [ ] **16.0 Admin Panel with Filament**
  - [ ] 16.1 GameResource (CRUD de juegos)
  - [ ] 16.2 RoomResource (ver salas activas)
  - [ ] 16.3 MatchResource (historial de partidas)
  - [ ] 16.4 Dashboard con estadísticas
  - **Documentación:** Actualizar `docs/ARCHITECTURE.md`

- [ ] **17.0 Sistema de Rutas Dinámicas por Juego**
  - [ ] 17.1 Crear `routes.php` en cada juego (`games/{game}/routes.php`)
  - [ ] 17.2 Service Provider para cargar rutas dinámicamente
  - [ ] 17.3 Cada juego declara sus rutas API y Web propias
  - [ ] 17.4 Prefijos automáticos basados en slug del juego
  - [ ] 17.5 Middleware configurables por juego
  - [ ] 17.6 Actualizar Pictionary para usar rutas dinámicas
  - **Objetivo:** Evitar hardcodear rutas de juegos en `routes/api.php` y `routes/web.php`
  - **Documentación:** `docs/architecture/DYNAMIC_ROUTES.md`

- [ ] **18.0 Extracción de Módulos Opcionales desde Pictionary**
  - [ ] 18.1 Extraer Turn System (orden de turnos, rotación)
  - [ ] 18.2 Extraer Round System (control de rondas)
  - [ ] 18.3 Extraer Scoring System (puntuación genérica)
  - [ ] 18.4 Extraer Timer System (temporizadores configurables)
  - [ ] 18.5 Extraer Roles System (asignación de roles)
  - [ ] 18.6 Extraer Real-time Sync (WebSocket helpers)
  - [ ] 18.7 Actualizar PictionaryEngine para usar módulos
  - [ ] 18.8 Documentar cada módulo extraído
  - **Objetivo:** Separar lógica reutilizable de Pictionary en módulos independientes
  - **Documentación:** `docs/modules/optional/` (actualizar cada módulo)

- [ ] **19.0 Testing and Quality Assurance**
  - [ ] 19.1 Tests end-to-end completos
  - [ ] 19.2 Refinamiento de UX
  - [ ] 19.3 Optimización de performance
  - [ ] 19.4 Documentación completa
  - **Documentación:** `docs/testing/TESTING_STRATEGY.md`

---

## 🏗️ Architecture Philosophy

> **Documentación completa:** [`docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md)

Esta plataforma sigue una **arquitectura modular** donde:

### 1️⃣ Core Modules (Siempre Activos)

Servicios **obligatorios** que todos los juegos utilizan:

| Módulo | Estado | Descripción | Docs |
|--------|--------|-------------|------|
| **Game Core** | ⏳ Pendiente | Motor del ciclo de vida del juego | [`docs/modules/core/GAME_CORE.md`](../docs/modules/core/GAME_CORE.md) |
| **Room Manager** | ✅ Implementado | Crear salas, códigos, QR | [`docs/modules/core/ROOM_MANAGER.md`](../docs/modules/core/ROOM_MANAGER.md) |
| **Player Session** | ✅ Implementado | Jugadores invitados, heartbeat | [`docs/modules/core/PLAYER_SESSION.md`](../docs/modules/core/PLAYER_SESSION.md) |
| **Game Registry** | ✅ Implementado | Descubrimiento de juegos | [`docs/modules/core/GAME_REGISTRY.md`](../docs/modules/core/GAME_REGISTRY.md) |

### 2️⃣ Optional Modules (Configurables)

Servicios **reutilizables** que los juegos pueden usar o no. Se declaran en `capabilities.json`:

| Módulo | Prioridad | Descripción | Docs |
|--------|-----------|-------------|------|
| **Guest System** | 🔥 MVP | Jugadores sin registro | [`docs/modules/optional/GUEST_SYSTEM.md`](../docs/modules/optional/GUEST_SYSTEM.md) |
| **Turn System** | 🔥 MVP | Turnos secuenciales/simultáneos | [`docs/modules/optional/TURN_SYSTEM.md`](../docs/modules/optional/TURN_SYSTEM.md) |
| **Round System** | 🔥 MVP | Control de rondas (fijas o configurables) | [`docs/modules/optional/ROUND_SYSTEM.md`](../docs/modules/optional/ROUND_SYSTEM.md) |
| **Scoring System** | 🔥 MVP | Puntuación y ranking | [`docs/modules/optional/SCORING_SYSTEM.md`](../docs/modules/optional/SCORING_SYSTEM.md) |
| **Timer System** | 🔥 MVP | Temporizadores | [`docs/modules/optional/TIMER_SYSTEM.md`](../docs/modules/optional/TIMER_SYSTEM.md) |
| **Roles System** | 🔥 MVP | Asignación de roles | [`docs/modules/optional/ROLES_SYSTEM.md`](../docs/modules/optional/ROLES_SYSTEM.md) |
| **Realtime Sync** | 🔥 MVP | WebSockets (Reverb) | [`docs/modules/optional/REALTIME_SYNC.md`](../docs/modules/optional/REALTIME_SYNC.md) |
| **Teams System** | ⏳ Post-MVP | Equipos | [`docs/modules/optional/TEAMS_SYSTEM.md`](../docs/modules/optional/TEAMS_SYSTEM.md) |
| **Card/Deck System** | ⏳ Post-MVP | Mazos de cartas | [`docs/modules/optional/CARD_SYSTEM.md`](../docs/modules/optional/CARD_SYSTEM.md) |
| **Board/Grid System** | ⏳ Post-MVP | Tableros | [`docs/modules/optional/BOARD_SYSTEM.md`](../docs/modules/optional/BOARD_SYSTEM.md) |
| **Spectator Mode** | ⏳ Post-MVP | Observadores | [`docs/modules/optional/SPECTATOR_MODE.md`](../docs/modules/optional/SPECTATOR_MODE.md) |
| **AI Players** | ⏳ Post-MVP | Bots/IA | [`docs/modules/optional/AI_PLAYERS.md`](../docs/modules/optional/AI_PLAYERS.md) |
| **Replay System** | ⏳ Post-MVP | Grabación de partidas | [`docs/modules/optional/REPLAY_SYSTEM.md`](../docs/modules/optional/REPLAY_SYSTEM.md) |

**Leyenda:**
- 🔥 MVP = Necesario para Pictionary (extracción en Fase 4)
- ⏳ Post-MVP = Para juegos futuros

### 3️⃣ Game Modules (Plugins Independientes)

Cada juego es un módulo autocontenido en `games/{nombre}/`:

- **Implementa:** `GameEngineInterface` (contrato obligatorio)
- **Declara:** Dependencias en `capabilities.json`
- **Contiene:** Lógica, vistas, JS, CSS, eventos propios
- **Registro:** Automático al detectarse en `games/` folder

### 4️⃣ Plugin System

Los juegos se cargan dinámicamente:

1. Sistema escanea `games/` folder
2. Valida que implementen `GameEngineInterface`
3. Carga solo los módulos opcionales que el juego necesita
4. Registra rutas, eventos y vistas del juego

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

## 📝 Decisiones Arquitectónicas Importantes

Ver ADRs completos en [`docs/decisions/`](../docs/decisions/)

### ADR-001: Sistema Modular vs Monolítico
**Decisión:** Arquitectura modular con plugins de juegos
**Razón:** Escalabilidad, reutilización de código, facilita agregar nuevos juegos

### ADR-002: Desarrollo Iterativo (Opción C)
**Decisión:** Implementar Pictionary primero de forma monolítica, luego extraer módulos
**Razón:** Evita sobre-ingeniería, valida módulos con casos de uso reales
**Fases:**
1. ✅ Core + Room Management
2. 🚧 Pictionary MVP (monolítico)
3. ⏳ Extracción de módulos
4. ⏳ Validación con segundo juego

### ADR-003: WebSockets con Laravel Reverb
**Decisión:** Laravel Reverb para WebSockets (no Pusher, no Socket.io)
**Razón:** Nativo de Laravel, gratuito, suficiente para MVP (~1000 conexiones)

### ADR-004: Sin Chat en MVP
**Decisión:** No implementar chat de texto en MVP
**Razón:** Juegos presenciales (hablan cara a cara), reduce complejidad

### ADR-005: Phase System en Game Core
**Decisión:** Fases gestionadas por cada juego (no módulo separado)
**Razón:** Cada juego tiene fases muy específicas, poca reutilización

---

## 📋 Notes

- **Laravel Reverb** se instala SOLO si algún juego activo requiere WebSockets: `composer require laravel/reverb`
- **Laravel Echo** y **Pusher JS** se cargan SOLO si el juego en la sala usa sockets: `npm install laravel-echo pusher-js`
- The project follows Laravel 11 conventions with existing Breeze authentication
- Spanish language is used for all user-facing interfaces
- Existing Filament 3 admin panel already configured with IsAdmin middleware
- Game modules are **completely isolated** in `games/` folder (own namespace, own dependencies)
- Each game can have its own Composer dependencies in `games/{name}/composer.json` (optional)
- Shared services are **injected on-demand** based on game's `capabilities.json`
- Testing strategy: Core tests, Shared service tests, Per-game tests (isolated)

---

## 🎯 Próximos Pasos Inmediatos

1. **Documentar módulos core existentes** (Room Manager, Player Session, Game Registry)
2. **Crear plantillas** de documentación para módulos y juegos
3. **Empezar Fase 3:** Pictionary MVP Mon olítico (Task 4.0)

---

**Última actualización:** 2025-10-21
**Responsable:** Todo el equipo de desarrollo
