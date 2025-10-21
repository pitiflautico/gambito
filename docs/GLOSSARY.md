# 📖 Glosario de Términos - Gambito

**Última actualización:** 2025-10-21

Definiciones de conceptos clave utilizados en el proyecto.

> 💡 **Tip:** Usa Ctrl+F / Cmd+F para buscar términos específicos

---

## 🎮 Conceptos de Juego

### Master
Usuario registrado que **crea y gestiona** una sala de juego. Tiene control total sobre la sala:
- Puede iniciar la partida
- Puede cerrar la sala
- Ve estadísticas completas
- Debe estar autenticado (Laravel Breeze)

**Modelo:** `User` con autenticación
**Rol en BD:** Campo `master_id` en tabla `rooms`

---

### Jugador / Player
Participante de una partida. Puede ser:
- **Jugador Invitado (Guest):** Sin registro, solo necesita nombre
- **Jugador Registrado:** Usuario con cuenta (futuro)

**Modelo:** `Player`
**Tabla:** `players`

---

### Sala / Room
Espacio virtual donde se **esperan a los jugadores** antes de iniciar.

**Estados:**
- `waiting` - Esperando jugadores, se pueden unir
- `playing` - Partida en curso, no se pueden unir nuevos jugadores
- `finished` - Partida finalizada, sala cerrada

**Modelo:** `Room`
**Tabla:** `rooms`
**Código único:** 6 caracteres alfanuméricos (ej: `ABC123`)

**Documentación:** [`docs/modules/core/ROOM_MANAGER.md`](modules/core/ROOM_MANAGER.md)

---

### Partida / Match
Sesión de juego **activa** con reglas y objetivos. Una sala tiene una partida asociada cuando el juego inicia.

**Modelo:** `GameMatch` (Match es palabra reservada en PHP)
**Tabla:** `matches`
**Estado:** JSON en campo `game_state`

---

### Código de Sala / Room Code
Código único de **6 caracteres** alfanuméricos para unirse a una sala.

**Formato:** `[A-Z0-9]{6}` excluyendo `0`, `O`, `I`, `1` (para evitar confusión)
**Ejemplo:** `ABC123`, `XYZ789`
**Generación:** `RoomService::generateUniqueCode()`

---

### QR Code
Código QR que contiene la **URL de invitación** a la sala.

**URL:** `https://gambito.test/rooms/join?code=ABC123`
**Servicio:** QuickChart.io API
**Generación:** `RoomService::getQrCodeUrl()`

---

### Invitación / Invite
URL compartible para unirse a una sala sin escribir código manualmente.

**Formato:** `https://gambito.test/rooms/join?code=ABC123`
**Método:** `RoomService::getInviteUrl()`

---

## 🏗️ Arquitectura

### Módulo Core / Core Module
Servicios **obligatorios** que siempre están activos. Todos los juegos los utilizan.

**Ejemplos:**
- Game Core
- Room Manager ✅
- Player Session ✅
- Game Registry ✅

**Ubicación:** `app/Services/Core/`
**Documentación:** [`docs/modules/core/`](modules/core/)

---

### Módulo Opcional / Optional Module
Servicios **reutilizables** que los juegos pueden usar o no, según sus necesidades.

**Ejemplos:**
- Turn System
- Scoring System
- Timer System
- Realtime Sync

**Declaración:** En `capabilities.json` del juego
**Ubicación:** `app/Modules/` (futuro)
**Documentación:** [`docs/modules/optional/`](modules/optional/)

---

### Capabilities
**Capacidades** que un juego requiere del sistema. Se declaran en `capabilities.json`.

**Ejemplo:**
```json
{
  "slug": "pictionary",
  "requires": {
    "turn_system": true,
    "scoring_system": true,
    "realtime_sync": true
  }
}
```

**Propósito:** El sistema carga solo los módulos que el juego necesita.

**Documentación:** [`docs/architecture/CAPABILITIES.md`](architecture/CAPABILITIES.md)

---

### Game Engine
Clase principal de cada juego que implementa `GameEngineInterface`. Contiene toda la lógica específica del juego.

**Contrato:** `app/Contracts/GameEngineInterface.php`
**Ubicación:** `games/{nombre}/{Nombre}Engine.php`
**Ejemplo:** `games/pictionary/PictionaryEngine.php`

**Documentación:** [`docs/api/GAME_ENGINE_INTERFACE.md`](api/GAME_ENGINE_INTERFACE.md)

---

### Game Registry
Servicio que **descubre y registra** juegos disponibles en la carpeta `games/`.

**Servicio:** `GameRegistry`
**Ubicación:** `app/Services/Core/GameRegistry.php`
**Comando:** `php artisan games:discover`

**Documentación:** [`docs/modules/core/GAME_REGISTRY.md`](modules/core/GAME_REGISTRY.md)

---

### Plugin System
Sistema que permite **cargar juegos dinámicamente** sin modificar el código core.

**Funcionamiento:**
1. Sistema escanea `games/` folder
2. Valida que implementen `GameEngineInterface`
3. Carga módulos opcionales según `capabilities.json`
4. Registra rutas, eventos y vistas del juego

**Documentación:** [`docs/architecture/MODULAR_SYSTEM.md`](architecture/MODULAR_SYSTEM.md)

---

## 🎲 Mecánicas de Juego

### Fase / Phase
Etapa de una partida.

**Ejemplos:**
- `lobby` - Esperando jugadores
- `assignment` - Asignando roles/turnos
- `playing` - Jugando
- `scoring` - Calculando puntos
- `results` - Mostrando ganador

**Gestión:** Game Core (cada juego maneja sus propias fases)

---

### Turno / Turn
Momento en que un **jugador específico** tiene la acción principal.

**Modos:**
- **Sequential:** Un jugador a la vez, orden fijo
- **Free:** Cualquier jugador puede actuar cuando quiera
- **Simultaneous:** Todos actúan al mismo tiempo

**Módulo:** Turn System
**Documentación:** [`docs/modules/optional/TURN_SYSTEM.md`](modules/optional/TURN_SYSTEM.md)

---

### Rol / Role
Identidad asignada a un jugador con permisos/acciones específicas.

**Visibilidad:**
- **Público:** Todos ven el rol del jugador
- **Secreto:** Solo el jugador conoce su rol

**Ejemplos:**
- Pictionary: `drawer` (dibujante), `guesser` (adivinador)
- Werewolf: `werewolf`, `villager`, `seer`

**Módulo:** Roles System
**Documentación:** [`docs/modules/optional/ROLES_SYSTEM.md`](modules/optional/ROLES_SYSTEM.md)

---

### Puntuación / Score
Sistema de **puntos** para determinar ganadores.

**Tipos:**
- **Individual:** Cada jugador tiene su puntuación
- **Por equipos:** Los equipos acumulan puntos

**Módulo:** Scoring System
**Documentación:** [`docs/modules/optional/SCORING_SYSTEM.md`](modules/optional/SCORING_SYSTEM.md)

---

### Ranking
Listado ordenado de jugadores/equipos por puntuación.

**Actualización:** En tiempo real durante la partida
**Servicio:** `ScoreManager::getRanking()` (futuro)

---

### Temporizador / Timer
Límite de tiempo para acciones del juego.

**Ejemplos:**
- Turno de dibujo: 60 segundos
- Respuesta en Trivia: 30 segundos

**Avisos:** Configurables (ej: avisos a 30s, 15s, 5s)
**Módulo:** Timer System
**Documentación:** [`docs/modules/optional/TIMER_SYSTEM.md`](modules/optional/TIMER_SYSTEM.md)

---

### Canvas
Área de dibujo HTML5 donde el dibujante crea su ilustración (específico de Pictionary).

**Tecnología:** HTML5 Canvas API
**Eventos:** Touch (móvil) y Mouse (desktop)
**Sincronización:** WebSockets (Realtime Sync)

---

### Trazo / Stroke
Línea individual dibujada en el canvas.

**Datos:**
```json
{
  "x": 120,
  "y": 340,
  "color": "#000000",
  "width": 3,
  "tool": "pen"
}
```

**Transmisión:** Via WebSocket en tiempo real

---

## 🔌 Tecnología

### WebSocket
Protocolo de comunicación **bidireccional en tiempo real** entre servidor y cliente.

**Implementación:** Laravel Reverb
**Cliente:** Laravel Echo + Pusher JS
**Uso:** Sincronización de canvas, eventos de juego

**Documentación:** [`docs/modules/optional/REALTIME_SYNC.md`](modules/optional/REALTIME_SYNC.md)

---

### Broadcasting
Sistema de Laravel para **enviar eventos** a múltiples clientes simultáneamente.

**Canales:**
- Públicos: Cualquiera puede escuchar
- Privados: Requieren autenticación
- Presence: Muestra quién está conectado

**Ejemplo:** `sala.{code}` - Canal privado por sala

---

### Reverb
Servidor **WebSocket oficial de Laravel** (gratis, open-source).

**Instalación:** `composer require laravel/reverb`
**Comando:** `php artisan reverb:start`
**Límite:** ~1000 conexiones simultáneas (suficiente para MVP)

**Decisión:** Ver [`docs/decisions/ADR-003-WEBSOCKETS_REVERB.md`](decisions/ADR-003-WEBSOCKETS_REVERB.md)

---

### Echo
Librería **JavaScript cliente** para WebSockets de Laravel.

**Instalación:** `npm install laravel-echo pusher-js`
**Uso:** Escuchar eventos en el frontend

---

### Heartbeat / Ping-Pong
Mecanismo para **detectar desconexiones** de jugadores.

**Funcionamiento:**
1. Cliente envía `ping` cada 10 segundos
2. Servidor responde `pong`
3. Si no hay respuesta en 30s, marca como desconectado

**Servicio:** `PlayerSessionService::ping()`

---

### Session Temporal / Guest Session
Sesión **sin autenticación** para jugadores invitados.

**Duración:** Hasta salir de la sala o 4 horas de inactividad
**Almacenamiento:** Laravel session
**Servicio:** `PlayerSessionService`

**Documentación:** [`docs/modules/core/PLAYER_SESSION.md`](modules/core/PLAYER_SESSION.md)

---

## 📊 Base de Datos

### Match Event
Registro de eventos importantes durante una partida para **auditoría e historial**.

**Modelo:** `MatchEvent`
**Tabla:** `match_events`
**Campos:** `event_type`, `data` (JSON), `created_at`

**Ejemplos de eventos:**
- `player.joined`
- `canvas.draw`
- `player.answered`
- `score.updated`

---

### Game State
Estado completo de la partida almacenado en **JSON**.

**Ubicación:** Campo `game_state` en tabla `matches`
**Contenido:** Específico de cada juego (turnos, roles, puntos, etc.)

**Ejemplo Pictionary:**
```json
{
  "current_turn": 2,
  "current_drawer": 5,
  "current_word": "perro",
  "eliminated_players": [3, 7],
  "round": 3
}
```

---

## 🧪 Testing

### Feature Test
Test que verifica **flujos completos** de usuario (HTTP, base de datos, etc.).

**Ubicación:** `tests/Feature/`
**Ejemplo:** `RoomTest.php` - Crear sala, unirse, iniciar partida

---

### Unit Test
Test que verifica **lógica específica** de una clase o método (aislado).

**Ubicación:** `tests/Unit/`
**Ejemplo:** `TurnManagerTest.php` - Calcular siguiente turno

---

### Mock
Objeto **falso** para simular dependencias en tests.

**Uso:** Aislar la clase bajo test
**Laravel:** `$this->mock(RoomService::class)`

---

## 📋 Gestión de Proyecto

### ADR (Architecture Decision Record)
Documento que registra una **decisión arquitectónica importante** y su razonamiento.

**Ubicación:** `docs/decisions/ADR-XXX-TITULO.md`
**Ejemplo:** `ADR-002-ITERATIVE_DEVELOPMENT.md`

**Plantilla:** [`docs/templates/TEMPLATE_ADR.md`](templates/TEMPLATE_ADR.md)

---

### MVP (Minimum Viable Product)
Versión mínima funcional del producto para **validar hipótesis** con usuarios reales.

**Gambito MVP:** Pictionary funcional con sistema de salas, turnos, scoring y WebSockets

---

### PRD (Product Requirements Document)
Documento que define **qué** debe hacer el producto (requisitos funcionales, user stories, etc.).

**Ubicación:** `tasks/0001-prd-plataforma-juegos-sociales.md`

---

### Iterative Development (Opción C)
Estrategia de desarrollo elegida para este proyecto.

**Fases:**
1. Implementar Room Management + Lobby
2. Implementar Pictionary MVP (monolítico)
3. Extraer módulos opcionales
4. Validar con segundo juego

**Decisión:** Ver [`docs/decisions/ADR-002-ITERATIVE_DEVELOPMENT.md`](decisions/ADR-002-ITERATIVE_DEVELOPMENT.md)

---

## 🛠️ Laravel/PHP

### Scope
Query reutilizable en modelos Eloquent.

**Ejemplo:**
```php
// app/Models/Game.php
public function scopeActive($query) {
    return $query->where('is_active', true);
}

// Uso
Game::active()->get();
```

---

### Casting
Conversión automática de tipos de datos en modelos Eloquent.

**Ejemplo:**
```php
protected $casts = [
    'game_state' => 'array',  // JSON → array PHP
    'is_active' => 'boolean'
];
```

---

### Middleware
Filtro que se ejecuta **antes** de que una petición llegue al controlador.

**Ejemplos:**
- `auth` - Verifica autenticación
- `admin` - Verifica rol administrador
- `guest` - Solo usuarios no autenticados

---

### Service Provider
Clase que **registra servicios** en el contenedor de Laravel.

**Ejemplo:** `GameServiceProvider` registra GameRegistry

---

### Facade
Interfaz estática para acceder a servicios del contenedor.

**Ejemplo:** `Storage::disk('public')` es facade de filesystem

---

## 🔗 Referencias Cruzadas

- **Arquitectura general:** [`docs/ARCHITECTURE.md`](ARCHITECTURE.md)
- **Instrucciones para agentes:** [`docs/INSTRUCTIONS_FOR_AGENTS.md`](INSTRUCTIONS_FOR_AGENTS.md)
- **Índice completo:** [`docs/README.md`](README.md)
- **Task list actualizado:** [`tasks/tasks-0001-prd-plataforma-juegos-sociales.md`](../tasks/tasks-0001-prd-plataforma-juegos-sociales.md)

---

**Última actualización:** 2025-10-21
**Versión:** 1.0
**Mantenido por:** Todo el equipo de desarrollo
