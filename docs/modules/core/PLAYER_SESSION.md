# Player Session (Módulo Core)

**Estado:** ✅ Implementado
**Tipo:** Core (obligatorio)
**Versión:** 1.0.0
**Última actualización:** 2025-10-21

---

## 📋 Descripción

El **Player Session** es un módulo core que gestiona sesiones temporales para jugadores invitados (guests) que participan en partidas sin necesidad de registro. Proporciona funcionalidades para crear sesiones, validar nombres, detectar inactividad y manejar reconexiones.

## 🎯 Responsabilidades

- Crear y gestionar sesiones temporales para jugadores invitados
- Validar nombres únicos por sala
- Implementar sistema de heartbeat/ping para detectar desconexiones
- Manejar reconexiones de jugadores
- Gestionar roles y puntuación de jugadores
- Detectar jugadores inactivos automáticamente

## 🎯 Cuándo Usarlo

**Siempre.** Este es un módulo core que **todos los juegos** utilizan para:
- Permitir que jugadores se unan sin crear cuenta
- Gestionar sesiones temporales durante la partida
- Trackear conexión/desconexión de jugadores
- Mantener estado de jugadores (rol, puntuación)

---

## 📦 Componentes

### Modelo: `Player`

**Ubicación:** `app/Models/Player.php`

**Campos principales:**
```php
id              // Identificador único
match_id        // Relación con partida
user_id         // Usuario registrado (nullable)
session_id      // ID de sesión guest (nullable)
name            // Nombre del jugador
role            // Rol en el juego (nullable)
score           // Puntuación actual
is_connected    // Estado de conexión
last_ping       // Timestamp de último heartbeat
created_at      // Timestamp de creación
updated_at      // Timestamp de última actualización
```

**Relaciones:**
```php
match()         // BelongsTo - Partida asociada
user()          // BelongsTo - Usuario (si está registrado)
```

**Scopes:**
```php
scopeConnected($query)      // Filtra jugadores conectados
scopeDisconnected($query)   // Filtra jugadores desconectados
scopeGuests($query)         // Filtra jugadores invitados
scopeRegistered($query)     // Filtra jugadores registrados
```

**Métodos principales:**
```php
isGuest()                   // Verifica si es jugador invitado
isRegistered()              // Verifica si es usuario registrado
isConnected()               // Verifica si está conectado
isInactive(int $minutes)    // Verifica inactividad
ping()                      // Actualiza heartbeat
disconnect()                // Marca como desconectado
reconnect()                 // Marca como reconectado
```

---

### Servicio: `PlayerSessionService`

**Ubicación:** `app/Services/Core/PlayerSessionService.php`

**Métodos públicos:**

#### `createOrRecoverPlayer(GameMatch $match, string $name): Player`
Crea un nuevo jugador o recupera uno existente por sesión.

**Parámetros:**
- `$match`: Partida actual
- `$name`: Nombre del jugador

**Retorna:** Instancia de `Player`

**Funcionalidad:**
- Si existe sesión previa en la misma partida → recupera jugador
- Si no existe → crea nuevo jugador con sesión temporal

**Ejemplo:**
```php
$player = $playerSessionService->createOrRecoverPlayer($match, 'Juan');
```

---

#### `validatePlayerName(string $name, GameMatch $match): bool`
Valida que un nombre sea válido y único en la partida.

**Validaciones:**
- Longitud: 2-20 caracteres
- Caracteres permitidos: letras, números, espacios, guiones
- No duplicado en la partida actual

**Parámetros:**
- `$name`: Nombre a validar
- `$match`: Partida actual

**Retorna:** `true` si es válido, `false` si no

**Ejemplo:**
```php
if ($playerSessionService->validatePlayerName('Juan', $match)) {
    // Nombre válido
}
```

---

#### `isNameTaken(string $name, GameMatch $match): bool`
Verifica si un nombre ya está en uso en la partida.

**Parámetros:**
- `$name`: Nombre a verificar
- `$match`: Partida actual

**Retorna:** `true` si está tomado, `false` si está disponible

**Ejemplo:**
```php
if ($playerSessionService->isNameTaken('Juan', $match)) {
    return back()->withErrors(['name' => 'Nombre ya en uso']);
}
```

---

#### `recoverPlayer(string $sessionId, GameMatch $match): ?Player`
Recupera un jugador por su session_id.

**Parámetros:**
- `$sessionId`: ID de sesión
- `$match`: Partida actual

**Retorna:** Instancia de `Player` o `null` si no existe

**Uso:** Reconexiones de jugadores que perdieron conexión

**Ejemplo:**
```php
$player = $playerSessionService->recoverPlayer($sessionId, $match);
if ($player) {
    $player->reconnect();
}
```

---

#### `generateSessionId(): string`
Genera un ID de sesión único.

**Retorna:** String único (UUID)

**Ejemplo:**
```php
$sessionId = $playerSessionService->generateSessionId();
// Resultado: "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
```

---

#### `ping(Player $player): void`
Actualiza el heartbeat del jugador.

**Parámetros:**
- `$player`: Jugador

**Funcionalidad:**
- Actualiza campo `last_ping` con timestamp actual
- Marca como conectado si estaba desconectado

**Uso:** Llamar periódicamente desde frontend (cada 10 segundos)

**Ejemplo:**
```php
$playerSessionService->ping($player);
```

---

#### `disconnect(Player $player): void`
Marca al jugador como desconectado.

**Parámetros:**
- `$player`: Jugador

**Funcionalidad:**
- Cambia `is_connected` a `false`
- Registra timestamp de desconexión

**Ejemplo:**
```php
$playerSessionService->disconnect($player);
```

---

#### `detectInactivePlayers(GameMatch $match, int $inactiveMinutes = 5): Collection`
Detecta jugadores inactivos en una partida.

**Parámetros:**
- `$match`: Partida
- `$inactiveMinutes`: Minutos de inactividad (default: 5)

**Retorna:** Collection de jugadores inactivos

**Funcionalidad:**
- Busca jugadores con `last_ping` > $inactiveMinutes
- Los marca como desconectados automáticamente

**Uso:** Ejecutar en background job o comando scheduled

**Ejemplo:**
```php
$inactive = $playerSessionService->detectInactivePlayers($match, 3);
foreach ($inactive as $player) {
    // Notificar a otros jugadores
    broadcast(new PlayerDisconnected($player));
}
```

---

#### `getCurrentPlayer(): ?Player`
Obtiene el jugador de la sesión actual.

**Retorna:** Instancia de `Player` o `null` si no hay sesión

**Ejemplo:**
```php
$player = $playerSessionService->getCurrentPlayer();
if ($player) {
    // Jugador autenticado en sesión
}
```

---

#### `hasActiveSession(): bool`
Verifica si existe una sesión activa (guest o registrado).

**Retorna:** `true` si hay sesión, `false` si no

**Ejemplo:**
```php
if ($playerSessionService->hasActiveSession()) {
    return redirect()->route('rooms.lobby', $code);
}
```

---

#### `hasGuestSession(): bool`
Verifica si existe una sesión de invitado activa.

**Retorna:** `true` si es guest, `false` si no

**Ejemplo:**
```php
if ($playerSessionService->hasGuestSession()) {
    // Mostrar UI de guest
}
```

---

#### `createGuestSession(string $name, string $roomCode): void`
Crea una sesión temporal de invitado.

**Parámetros:**
- `$name`: Nombre del invitado
- `$roomCode`: Código de la sala

**Funcionalidad:**
- Genera session_id único
- Almacena en sesión de Laravel
- Expira en 4 horas de inactividad

**Ejemplo:**
```php
$playerSessionService->createGuestSession('Juan', 'ABC123');
```

---

#### `getGuestData(): ?array`
Obtiene datos de la sesión guest actual.

**Retorna:** Array con `['name' => ..., 'session_id' => ..., 'room_code' => ...]` o `null`

**Ejemplo:**
```php
$guestData = $playerSessionService->getGuestData();
if ($guestData) {
    echo "Hola, {$guestData['name']}";
}
```

---

#### `createGuestPlayer(GameMatch $match, string $name): Player`
Crea un jugador invitado en la BD.

**Parámetros:**
- `$match`: Partida
- `$name`: Nombre

**Retorna:** Instancia de `Player`

**Funcionalidad:**
- Crea registro en tabla `players`
- Asocia con `session_id` de la sesión actual
- Marca como conectado

**Ejemplo:**
```php
$player = $playerSessionService->createGuestPlayer($match, 'Juan');
```

---

#### `clearSession(): void`
Limpia la sesión actual (guest o registrado).

**Uso:** Al salir de la sala o terminar partida

**Ejemplo:**
```php
$playerSessionService->clearSession();
return redirect()->route('home');
```

---

#### `assignRole(Player $player, string $role): void`
Asigna un rol al jugador.

**Parámetros:**
- `$player`: Jugador
- `$role`: Nombre del rol (ej: 'drawer', 'guesser', 'werewolf')

**Ejemplo:**
```php
$playerSessionService->assignRole($player, 'drawer');
```

---

#### `updateScore(Player $player, int $points, bool $add = true): void`
Actualiza la puntuación del jugador.

**Parámetros:**
- `$player`: Jugador
- `$points`: Puntos a añadir/establecer
- `$add`: Si `true` suma, si `false` reemplaza (default: true)

**Ejemplo:**
```php
// Sumar 50 puntos
$playerSessionService->updateScore($player, 50);

// Establecer puntuación exacta
$playerSessionService->updateScore($player, 100, false);
```

---

#### `getRanking(GameMatch $match): Collection`
Obtiene el ranking de jugadores de una partida ordenado por puntuación.

**Parámetros:**
- `$match`: Partida

**Retorna:** Collection de jugadores ordenados (mayor a menor)

**Ejemplo:**
```php
$ranking = $playerSessionService->getRanking($match);
foreach ($ranking as $index => $player) {
    echo ($index + 1) . ". {$player->name} - {$player->score} pts\n";
}
```

---

#### `getPlayerStats(Player $player): array`
Obtiene estadísticas del jugador.

**Retorna:** Array con:
- `score`: Puntuación actual
- `role`: Rol asignado
- `is_connected`: Estado de conexión
- `position`: Posición en ranking

**Ejemplo:**
```php
$stats = $playerSessionService->getPlayerStats($player);
// ['score' => 150, 'role' => 'drawer', 'is_connected' => true, 'position' => 2]
```

---

## 🔄 Sistema de Heartbeat

El módulo implementa un sistema de ping-pong para detectar desconexiones:

### Frontend (JavaScript)
```javascript
// Enviar ping cada 10 segundos
setInterval(() => {
    fetch('/api/players/ping', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    });
}, 10000);
```

### Backend (Controller)
```php
public function ping(PlayerSessionService $service)
{
    $player = $service->getCurrentPlayer();
    if ($player) {
        $service->ping($player);
    }
    return response()->json(['status' => 'ok']);
}
```

### Detección de Inactividad (Scheduled Command)
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function (PlayerSessionService $service) {
        $matches = GameMatch::where('status', 'playing')->get();
        foreach ($matches as $match) {
            $service->detectInactivePlayers($match, 3); // 3 minutos
        }
    })->everyMinute();
}
```

---

## 🧪 Tests

**Ubicación:** `tests/Feature/Core/PlayerSessionTest.php`, `tests/Unit/Services/Core/PlayerSessionServiceTest.php`

**Tests implementados:**
- ✅ Crear sesión de invitado
- ✅ Validar nombres únicos por sala
- ✅ Recuperar jugador por session_id
- ✅ Heartbeat actualiza last_ping
- ✅ Detectar jugadores inactivos
- ✅ Marcar como desconectado
- ✅ Reconectar jugador
- ✅ Asignar roles
- ✅ Actualizar puntuación
- ✅ Obtener ranking ordenado

**Ejecutar tests:**
```bash
php artisan test --filter=PlayerSessionTest
php artisan test tests/Unit/Services/Core/PlayerSessionServiceTest.php
```

---

## 💡 Ejemplos de Uso

### Crear jugador invitado desde formulario
```php
public function storeGuestName(Request $request, string $code, PlayerSessionService $service)
{
    $request->validate([
        'name' => 'required|min:2|max:20'
    ]);

    $room = Room::where('code', $code)->firstOrFail();
    $match = $room->match;

    // Validar nombre único
    if ($service->isNameTaken($request->name, $match)) {
        return back()->withErrors(['name' => 'Nombre ya en uso']);
    }

    // Crear sesión guest
    $service->createGuestSession($request->name, $code);

    // Crear jugador
    $player = $service->createGuestPlayer($match, $request->name);

    return redirect()->route('rooms.lobby', $code);
}
```

### Verificar sesión en middleware
```php
public function handle($request, Closure $next, PlayerSessionService $service)
{
    if (!$service->hasActiveSession()) {
        return redirect()->route('rooms.guestName', $request->route('code'));
    }

    return $next($request);
}
```

### Mostrar ranking en vista
```blade
<div class="ranking">
    <h2>Ranking</h2>
    <ol>
        @foreach($ranking as $player)
            <li class="{{ $player->id === $currentPlayer->id ? 'current' : '' }}">
                <span class="name">{{ $player->name }}</span>
                <span class="score">{{ $player->score }} pts</span>
                <span class="status {{ $player->is_connected ? 'connected' : 'disconnected' }}">
                    {{ $player->is_connected ? '🟢' : '🔴' }}
                </span>
            </li>
        @endforeach
    </ol>
</div>
```

### Manejar reconexión
```php
public function reconnect(Request $request, PlayerSessionService $service)
{
    $guestData = $service->getGuestData();
    if (!$guestData) {
        return redirect()->route('rooms.join');
    }

    $room = Room::where('code', $guestData['room_code'])->first();
    if (!$room || !$room->match) {
        return redirect()->route('rooms.join')
            ->withErrors(['error' => 'Sala no encontrada']);
    }

    $player = $service->recoverPlayer($guestData['session_id'], $room->match);
    if ($player) {
        $player->reconnect();
        broadcast(new PlayerReconnected($player));
    }

    return redirect()->route('rooms.show', $room->code);
}
```

---

## 📦 Dependencias

### Internas:
- `GameMatch` model
- `Room` model
- Laravel Session (para sesiones temporales)
- Laravel Broadcasting (para notificaciones en tiempo real)

### Externas:
- Ninguna

---

## ⚙️ Configuración

### Session Timeout
Las sesiones de invitados expiran después de 4 horas de inactividad (configurable en `config/session.php`):

```php
'lifetime' => 240, // 4 horas en minutos
```

### Heartbeat Interval
El intervalo de ping se configura en el frontend:

```javascript
const PING_INTERVAL = 10000; // 10 segundos en milisegundos
```

### Inactivity Detection
La detección de inactividad se configura en el scheduled command:

```php
$service->detectInactivePlayers($match, 3); // 3 minutos
```

---

## 🔗 Referencias

- **Modelo:** [`app/Models/Player.php`](../../../app/Models/Player.php)
- **Servicio:** [`app/Services/Core/PlayerSessionService.php`](../../../app/Services/Core/PlayerSessionService.php)
- **Migration:** [`database/migrations/2025_10_20_181111_create_players_table.php`](../../../database/migrations/2025_10_20_181111_create_players_table.php)
- **Tests:** [`tests/Feature/Core/PlayerSessionTest.php`](../../../tests/Feature/Core/PlayerSessionTest.php)
- **Glosario:** [`docs/GLOSSARY.md`](../../GLOSSARY.md#jugador--player)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../../tasks/0001-prd-plataforma-juegos-sociales.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** 2025-10-21
