# Room Manager (Módulo Core)

**Estado:** ✅ Implementado
**Tipo:** Core (obligatorio)
**Versión:** 1.0.0
**Última actualización:** 2025-10-21

---

## 📋 Descripción

El **Room Manager** es un módulo core que gestiona la creación, acceso y administración de salas de juego. Proporciona funcionalidades para generar códigos únicos, QR codes, gestionar el lobby y controlar el ciclo de vida de las salas.

## 🎯 Responsabilidades

- Crear salas de juego con códigos únicos de 6 caracteres
- Generar URLs de invitación y QR codes
- Gestionar estados de sala (waiting, playing, finished)
- Validar condiciones para iniciar partidas
- Administrar el lobby con lista de jugadores en tiempo real
- Limpiar salas antiguas automáticamente

## 🎯 Cuándo Usarlo

**Siempre.** Este es un módulo core que **todos los juegos** utilizan para:
- Crear salas antes de iniciar cualquier partida
- Permitir que jugadores se unan mediante código/QR
- Gestionar el estado de espera en el lobby
- Controlar cuándo una partida puede comenzar

## 📦 Componentes

### Modelo: `Room`

**Ubicación:** `app/Models/Room.php`

**Campos principales:**
```php
id              // Identificador único
code            // Código de 6 caracteres (único)
game_id         // Relación con juego
master_id       // Usuario que creó la sala (master)
status          // Estado: waiting, playing, finished
settings        // JSON con configuración personalizada
created_at      // Timestamp de creación
updated_at      // Timestamp de última actualización
```

**Relaciones:**
```php
game()          // BelongsTo - Juego asociado
master()        // BelongsTo - Usuario master
match()         // HasOne - Partida asociada
```

**Métodos principales:**
```php
generateCode()              // Genera código único de 6 caracteres
isWaiting()                 // Verifica si está en estado 'waiting'
isPlaying()                 // Verifica si está en estado 'playing'
isFinished()                // Verifica si está en estado 'finished'
canStart()                  // Verifica si puede iniciar (suficientes jugadores)
getInviteUrl()              // Genera URL de invitación
getQrCodeUrl()              // Genera URL del QR code
```

---

### Servicio: `RoomService`

**Ubicación:** `app/Services/Core/RoomService.php`

**Métodos públicos:**

#### `createRoom(Game $game, User $master, array $settings = []): Room`
Crea una nueva sala de juego.

**Parámetros:**
- `$game`: Juego seleccionado
- `$master`: Usuario que crea la sala
- `$settings`: Configuración personalizada (opcional)

**Retorna:** Instancia de `Room`

**Ejemplo:**
```php
$game = Game::where('slug', 'pictionary')->first();
$master = auth()->user();
$room = $roomService->createRoom($game, $master, [
    'rounds' => 5,
    'timer' => 60
]);
```

---

#### `generateUniqueCode(): string`
Genera un código único de 6 caracteres alfanuméricos.

**Formato:** `[A-Z0-9]{6}` excluyendo `0`, `O`, `I`, `1` (evita confusión)

**Retorna:** String de 6 caracteres (ej: `ABC123`)

**Ejemplo:**
```php
$code = $roomService->generateUniqueCode();
// Resultado: "XYZ789"
```

---

#### `isValidCodeFormat(string $code): bool`
Valida que un código tenga el formato correcto.

**Parámetros:**
- `$code`: Código a validar

**Retorna:** `true` si es válido, `false` si no

**Ejemplo:**
```php
$roomService->isValidCodeFormat('ABC123'); // true
$roomService->isValidCodeFormat('abc123'); // false (minúsculas)
$roomService->isValidCodeFormat('AB12');   // false (longitud incorrecta)
```

---

#### `findRoomByCode(string $code): ?Room`
Busca una sala por su código.

**Parámetros:**
- `$code`: Código de sala

**Retorna:** Instancia de `Room` o `null` si no existe

**Ejemplo:**
```php
$room = $roomService->findRoomByCode('ABC123');
if ($room) {
    // Sala encontrada
}
```

---

#### `getInviteUrl(Room $room): string`
Genera URL de invitación para unirse a la sala.

**Formato:** `https://gambito.test/rooms/join?code={code}`

**Retorna:** String con URL completa

**Ejemplo:**
```php
$url = $roomService->getInviteUrl($room);
// Resultado: "https://gambito.test/rooms/join?code=ABC123"
```

---

#### `getQrCodeUrl(Room $room, int $size = 200): string`
Genera URL del QR code usando QuickChart.io API.

**Parámetros:**
- `$room`: Sala
- `$size`: Tamaño del QR en píxeles (default: 200)

**Retorna:** String con URL del QR code

**Ejemplo:**
```php
$qrUrl = $roomService->getQrCodeUrl($room);
// Resultado: "https://quickchart.io/qr?text=..."
```

---

#### `canStartGame(Room $room): bool`
Verifica si la partida puede iniciar.

**Condiciones:**
- Sala en estado `waiting`
- Número de jugadores >= mínimo del juego
- Número de jugadores <= máximo del juego

**Retorna:** `true` si puede iniciar, `false` si no

**Ejemplo:**
```php
if ($roomService->canStartGame($room)) {
    // Mostrar botón "Iniciar Partida"
}
```

---

#### `startGame(Room $room): GameMatch`
Inicia la partida asociada a la sala.

**Cambios:**
- Sala pasa a estado `playing`
- Crea instancia de `GameMatch`
- Asocia jugadores a la partida

**Retorna:** Instancia de `GameMatch`

**Ejemplo:**
```php
$match = $roomService->startGame($room);
```

---

#### `finishGame(Room $room, ?Player $winner = null): void`
Finaliza la partida.

**Cambios:**
- Sala pasa a estado `finished`
- Registra ganador (si existe)
- Marca timestamp de fin

**Ejemplo:**
```php
$roomService->finishGame($room, $winner);
```

---

#### `closeRoom(Room $room): void`
Cierra una sala (equivalente a `finishGame` sin ganador).

**Ejemplo:**
```php
$roomService->closeRoom($room);
```

---

#### `getRoomStats(Room $room): array`
Obtiene estadísticas de la sala.

**Retorna:** Array con:
- `players_count`: Número de jugadores
- `duration`: Duración de la partida (si finalizó)
- `status`: Estado actual

**Ejemplo:**
```php
$stats = $roomService->getRoomStats($room);
// ['players_count' => 5, 'duration' => 1200, 'status' => 'finished']
```

---

#### `cleanupOldRooms(int $hoursOld = 24): int`
Limpia salas antiguas finalizadas.

**Parámetros:**
- `$hoursOld`: Horas de antigüedad (default: 24)

**Retorna:** Número de salas eliminadas

**Ejemplo:**
```php
// Ejecutar en un comando scheduled
$deleted = $roomService->cleanupOldRooms(48); // Limpia salas de +48h
```

---

### Controlador: `RoomController`

**Ubicación:** `app/Http/Controllers/RoomController.php`

**Rutas web:**
```php
GET  /rooms/create                    → create()        (Auth)
POST /rooms                            → store()         (Auth)
GET  /rooms/join                       → join()          (Guest)
POST /rooms/join                       → joinByCode()    (Guest)
GET  /rooms/{code}/guest-name          → guestName()     (Guest)
POST /rooms/{code}/guest-name          → storeGuestName() (Guest)
GET  /rooms/{code}/lobby               → lobby()         (Auth/Guest)
GET  /rooms/{code}                     → show()          (Auth/Guest)
GET  /rooms/{code}/results             → results()       (Auth/Guest)
```

**Rutas API:**
```php
POST /api/rooms/{code}/start           → apiStart()      (Auth - Master only)
GET  /api/rooms/{code}/stats           → apiStats()      (Auth/Guest)
POST /api/rooms/{code}/close           → apiClose()      (Auth - Master only)
```

**Métodos principales:**

#### `create()`
Muestra formulario de creación de sala.

**Vista:** `resources/views/rooms/create.blade.php`

---

#### `store(Request $request)`
Procesa creación de sala.

**Validación:**
- `game_id`: requerido, existe en BD
- `settings`: opcional, array

**Redirección:** Lobby de la sala creada

---

#### `join()`
Muestra formulario para unirse por código.

**Vista:** `resources/views/rooms/join.blade.php`

---

#### `joinByCode(Request $request)`
Procesa unión a sala por código.

**Validación:**
- `code`: requerido, 6 caracteres, existe en BD

**Redirección:**
- Si es guest → formulario de nombre
- Si está autenticado → lobby

---

#### `guestName(string $code)`
Muestra formulario para ingresar nombre de invitado.

**Vista:** `resources/views/rooms/guest-name.blade.php`

---

#### `storeGuestName(Request $request, string $code)`
Procesa nombre de invitado y crea sesión.

**Validación:**
- `name`: requerido, 2-20 caracteres, único en la sala

**Redirección:** Lobby

---

#### `lobby(string $code)`
Muestra lobby con lista de jugadores.

**Vista:** `resources/views/rooms/lobby.blade.php`

**Características:**
- Lista de jugadores en tiempo real (auto-refresh 3s)
- Botón "Iniciar Partida" (solo master)
- Código y QR de invitación
- URL compartible

---

#### `show(string $code)`
Muestra la partida en curso.

**Vista:** `resources/views/rooms/show.blade.php`

**Nota:** Actualmente es un placeholder que carga vistas específicas del juego.

---

#### `results(string $code)`
Muestra resultados finales de la partida.

**Vista:** `resources/views/rooms/results.blade.php`

**Características:**
- Ranking de jugadores ordenado por puntuación
- Estadísticas de la partida
- Duración total
- Opciones: jugar de nuevo, cambiar juego, salir

---

## 🎨 Vistas

### `create.blade.php`
Formulario de creación de sala con:
- Selector de juego
- Configuración opcional del juego
- Botón "Crear Sala"

---

### `join.blade.php`
Formulario para unirse por código con:
- Input de código de 6 caracteres
- Opción de escanear QR (móvil)
- Botón "Unirse"

---

### `lobby.blade.php`
Lobby de espera con:
- Código de sala destacado
- QR code para compartir
- URL de invitación con botón copiar
- Lista de jugadores conectados
- Estado de conexión en tiempo real
- Botón "Iniciar Partida" (solo master)
- Botón "Cerrar Sala" (solo master)
- Auto-refresh cada 3 segundos

---

### `guest-name.blade.php`
Formulario de nombre para invitados con:
- Input de nombre (2-20 caracteres)
- Validación de nombres duplicados
- Botón "Continuar"

---

### `show.blade.php`
Vista de partida en curso (placeholder):
- Carga vista específica del juego
- Layout base para juegos

---

### `results.blade.php`
Pantalla de resultados finales con:
- Ranking completo ordenado por puntuación
- Destacado del ganador
- Estadísticas de la partida
- Duración total
- Botones: "Jugar de nuevo", "Cambiar juego", "Salir"

---

## 🧪 Tests

**Ubicación:** `tests/Feature/Core/RoomTest.php`, `tests/Unit/Services/Core/RoomServiceTest.php`

**Tests implementados:**
- ✅ Crear sala requiere autenticación
- ✅ Código generado es único
- ✅ QR code se genera correctamente
- ✅ Jugadores pueden unirse con código válido
- ✅ No se puede unir a sala inexistente
- ✅ Master puede iniciar partida con suficientes jugadores
- ✅ No se puede iniciar sin jugadores mínimos
- ✅ Nombres de invitados son únicos por sala
- ✅ Sala cambia a estado 'playing' al iniciar
- ✅ Sala cambia a estado 'finished' al terminar

**Ejecutar tests:**
```bash
php artisan test --filter=RoomTest
php artisan test tests/Unit/Services/Core/RoomServiceTest.php
```

---

## 💡 Ejemplos de Uso

### Crear sala desde controlador
```php
use App\Services\Core\RoomService;
use App\Models\Game;

public function store(Request $request, RoomService $roomService)
{
    $game = Game::findOrFail($request->game_id);
    $room = $roomService->createRoom($game, auth()->user(), [
        'rounds' => $request->input('rounds', 5)
    ]);

    return redirect()->route('rooms.lobby', $room->code);
}
```

### Validar y unirse a sala
```php
public function joinByCode(Request $request, RoomService $roomService)
{
    $room = $roomService->findRoomByCode($request->code);

    if (!$room || !$room->isWaiting()) {
        return back()->withErrors(['code' => 'Sala no encontrada o ya iniciada']);
    }

    // Redirigir según tipo de usuario
    if (auth()->check()) {
        return redirect()->route('rooms.lobby', $room->code);
    }

    return redirect()->route('rooms.guestName', $room->code);
}
```

### Generar invitación en vista
```blade
<div class="invitation">
    <p>Código: <strong>{{ $room->code }}</strong></p>
    <img src="{{ $room->getQrCodeUrl() }}" alt="QR Code">
    <input type="text" value="{{ $room->getInviteUrl() }}" readonly>
    <button onclick="copyToClipboard()">Copiar URL</button>
</div>
```

### Verificar si puede iniciar partida
```blade
@if($room->canStart())
    <form action="{{ route('api.rooms.start', $room->code) }}" method="POST">
        @csrf
        <button type="submit">Iniciar Partida</button>
    </form>
@else
    <p>Esperando más jugadores ({{ $playersCount }}/{{ $game->min_players }})</p>
@endif
```

---

## 📦 Dependencias

### Externas:
- **QuickChart.io API** - Generación de QR codes (servicio externo gratuito)

### Internas:
- `Game` model
- `User` model (Laravel Breeze)
- `GameMatch` model
- `Player` model
- `PlayerSessionService` (para gestión de invitados)

---

## 🔗 Referencias

- **Modelo:** [`app/Models/Room.php`](../../../app/Models/Room.php)
- **Servicio:** [`app/Services/Core/RoomService.php`](../../../app/Services/Core/RoomService.php)
- **Controlador:** [`app/Http/Controllers/RoomController.php`](../../../app/Http/Controllers/RoomController.php)
- **Migration:** [`database/migrations/2025_10_20_181109_create_rooms_table.php`](../../../database/migrations/2025_10_20_181109_create_rooms_table.php)
- **Tests:** [`tests/Feature/Core/RoomTest.php`](../../../tests/Feature/Core/RoomTest.php)
- **Glosario:** [`docs/GLOSSARY.md`](../../GLOSSARY.md#sala--room)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../../tasks/0001-prd-plataforma-juegos-sociales.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** 2025-10-21
