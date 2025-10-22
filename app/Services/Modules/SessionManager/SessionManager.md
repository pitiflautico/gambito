# SessionManager Module

**Versión:** 1.0
**Ubicación:** `app/Services/Modules/SessionManager/SessionManager.php`

## 📋 Descripción

El **SessionManager** es un módulo que gestiona la identificación del jugador actual en una partida, manejando tanto usuarios autenticados como invitados (guests).

Este módulo elimina la duplicación de código en los controllers de los juegos, centralizando la lógica de identificación de jugadores.

## 🎯 Responsabilidades

1. **Identificar jugador actual** - Determinar quién es el jugador basado en autenticación o sesión guest
2. **Obtener Player del match** - Recuperar el modelo Player asociado al usuario/guest en un match específico
3. **Validar pertenencia** - Verificar que el jugador pertenezca al match
4. **Debug de sesiones** - Proporcionar información útil para debugging

## 🔧 API Pública

### `getCurrentPlayer(GameMatch $match): ?Player`

Obtiene el jugador actual de un match.

**Flujo de identificación:**
1. Si hay usuario autenticado → busca por `user_id`
2. Si hay sesión guest → busca por `session_id`
3. Si no hay ninguno → retorna `null`

**Ejemplo:**
```php
use App\Services\Modules\SessionManager\SessionManager;

$player = SessionManager::getCurrentPlayer($match);

if (!$player) {
    return redirect()->route('rooms.lobby', ['code' => $roomCode])
        ->with('error', 'Debes unirte a la partida primero');
}
```

### `getCurrentPlayerOrFail(GameMatch $match): Player`

Obtiene el jugador actual o lanza excepción si no existe.

**Ejemplo:**
```php
try {
    $player = SessionManager::getCurrentPlayerOrFail($match);
} catch (\Exception $e) {
    return redirect()->route('rooms.lobby', ['code' => $roomCode])
        ->with('error', $e->getMessage());
}
```

### `isPlayerInMatch(GameMatch $match): bool`

Verifica si el jugador actual pertenece al match.

**Ejemplo:**
```php
if (!SessionManager::isPlayerInMatch($match)) {
    abort(403, 'No perteneces a esta partida');
}
```

### `getCurrentPlayerId(GameMatch $match): ?int`

Obtiene el ID del jugador actual.

**Ejemplo:**
```php
$playerId = SessionManager::getCurrentPlayerId($match);

return view('trivia::game', [
    'playerId' => $playerId,
    // ...
]);
```

### `getDebugInfo(): array`

Obtiene información de debug sobre la sesión actual.

**Ejemplo:**
```php
$debugInfo = SessionManager::getDebugInfo();
// [
//     'is_authenticated' => true,
//     'user_id' => 5,
//     'has_guest_session' => false,
//     'guest_session_id' => null,
// ]
```

## 📦 Declaración en capabilities.json

Los juegos que necesiten identificar al jugador actual deben declarar este módulo:

```json
{
  "slug": "trivia",
  "version": "1.0",
  "requires": {
    "modules": {
      "session_manager": "^1.0",
      "turn_system": "^1.0",
      "scoring_system": "^1.0"
    }
  }
}
```

## 🎮 Uso en Controllers

### Antes (código duplicado)

```php
// TriviaController.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->first();

    // ❌ Código duplicado en cada controller
    $player = null;
    $playerId = null;

    if (\Auth::check()) {
        $player = $match->players()->where('user_id', \Auth::id())->first();
    } elseif (session()->has('guest_session_id')) {
        $guestSessionId = session('guest_session_id');
        $player = $match->players()->where('session_id', $guestSessionId)->first();
    }

    if (!$player) {
        return redirect()->route('rooms.lobby', ['code' => $roomCode])
            ->with('error', 'Debes unirte a la partida primero');
    }

    $playerId = $player->id;

    return view('trivia::game', compact('playerId'));
}
```

### Después (usando SessionManager)

```php
// TriviaController.php
use App\Services\Modules\SessionManager\SessionManager;

public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();
    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->first();

    // ✅ Una sola línea
    $player = SessionManager::getCurrentPlayer($match);

    if (!$player) {
        return redirect()->route('rooms.lobby', ['code' => $roomCode])
            ->with('error', 'Debes unirte a la partida primero');
    }

    return view('trivia::game', [
        'playerId' => $player->id,
    ]);
}
```

## 🔒 Seguridad

El SessionManager implementa las siguientes medidas de seguridad:

1. **Verificación de pertenencia** - Solo retorna el Player si pertenece al match específico
2. **No confía en input del usuario** - Usa Auth y sesiones del servidor
3. **Validación doble** - Verifica tanto user_id como session_id según el caso

## ⚠️ Consideraciones

### Cuándo usar `getCurrentPlayer()` vs `getCurrentPlayerId()`

- **`getCurrentPlayer()`** - Cuando necesitas el modelo completo (nombre, avatar, etc.)
- **`getCurrentPlayerId()`** - Cuando solo necesitas el ID (para pasar a vistas, comparaciones)

### Guest Sessions

El módulo depende de que el sistema de guests establezca correctamente `session('guest_session_id')`. Esto se gestiona en:
- `GuestSystemModule` - Crea la sesión guest
- `RoomController::join()` - Asigna session_id al Player

### En API Endpoints

Para endpoints API que reciben `player_id` en el request, es recomendable validar:

```php
public function answer(Request $request)
{
    $validated = $request->validate([
        'room_code' => 'required|string',
        'player_id' => 'required|integer',
        'answer' => 'required|integer',
    ]);

    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->firstOrFail();

    // ✅ Validar que el player_id del request coincide con el jugador actual
    $currentPlayer = SessionManager::getCurrentPlayer($match);

    if (!$currentPlayer || $currentPlayer->id !== $validated['player_id']) {
        return response()->json([
            'success' => false,
            'error' => 'No autorizado'
        ], 403);
    }

    // Procesar acción...
}
```

## 🧪 Testing

```php
use App\Services\Modules\SessionManager\SessionManager;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SessionManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_current_player_for_authenticated_user()
    {
        $user = User::factory()->create();
        $match = GameMatch::factory()->create();
        $player = Player::factory()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        $currentPlayer = SessionManager::getCurrentPlayer($match);

        $this->assertNotNull($currentPlayer);
        $this->assertEquals($player->id, $currentPlayer->id);
    }

    public function test_get_current_player_for_guest()
    {
        $match = GameMatch::factory()->create();
        $player = Player::factory()->create([
            'match_id' => $match->id,
            'session_id' => 'test-session-123',
        ]);

        session(['guest_session_id' => 'test-session-123']);

        $currentPlayer = SessionManager::getCurrentPlayer($match);

        $this->assertNotNull($currentPlayer);
        $this->assertEquals($player->id, $currentPlayer->id);
    }

    public function test_returns_null_when_player_not_in_match()
    {
        $match = GameMatch::factory()->create();

        $currentPlayer = SessionManager::getCurrentPlayer($match);

        $this->assertNull($currentPlayer);
    }
}
```

## 📚 Referencias

- **Módulos relacionados:** GuestSystemModule, GameCoreModule
- **Controllers que lo usan:** TriviaController, PictionaryController
- **Documentación:** `docs/GAMES_CONVENTION.md`

---

**Última actualización:** 2025-10-22
