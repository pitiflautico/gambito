# Game Core Module

## 📋 Descripción

El módulo **GameCore** es el módulo base que todos los juegos heredan automáticamente. Proporciona funcionalidades esenciales para el ciclo de vida de una partida.

## 🎯 Responsabilidades

### 1. Ciclo de Vida del Juego
- Inicialización de partidas (`initialize()`)
- Verificación de condiciones de victoria (`checkWinCondition()`)
- Finalización de partidas (`endGame()`)

### 2. Gestión de Estado
- Serialización del estado del juego (`game_state` JSON)
- Validación de estados
- Sincronización con base de datos

### 3. Gestión de Jugadores
- Manejo de desconexiones (`handlePlayerDisconnect()`)
- Estado personalizado por jugador (`getGameStateForPlayer()`)
- Validación de acciones de jugadores

### 4. Consistencia de Datos
- Garantiza que todos los juegos usen los mismos campos de `GameMatch`
- Previene errores de campo `status` inexistente
- Estandariza queries de partidas activas

## 📦 Estructura de GameMatch

```php
// Tabla: matches
Schema::create('matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_id')->constrained()->onDelete('cascade');
    $table->timestamp('started_at')->nullable();    // NULL = no iniciado
    $table->timestamp('finished_at')->nullable();   // NULL = en progreso
    $table->foreignId('winner_id')->nullable()->constrained('players');
    $table->json('game_state')->nullable();         // Estado del juego
    $table->timestamps();
});
```

### Estados del Match

| Estado | started_at | finished_at | Descripción |
|--------|-----------|-------------|-------------|
| **Creado** | NULL | NULL | Match creado pero no iniciado |
| **En Progreso** | DateTime | NULL | Partida activa |
| **Finalizado** | DateTime | DateTime | Partida terminada |

## 🔧 Métodos Estandarizados

### Query Helper: Partidas Activas

```php
// ✅ Forma correcta de buscar partidas activas
$match = GameMatch::where('room_id', $room->id)
    ->whereNotNull('started_at')
    ->whereNull('finished_at')
    ->first();
```

```php
// ❌ NUNCA usar esto (campo no existe)
$match = GameMatch::where('status', 'in_progress')->first();
```

### Verificar Estado del Match

```php
// En cualquier Engine
public function isMatchActive(GameMatch $match): bool
{
    return !is_null($match->started_at) && is_null($match->finished_at);
}

public function isMatchFinished(GameMatch $match): bool
{
    return !is_null($match->finished_at);
}
```

## 🎮 Integración en GameEngineInterface

Todos los métodos de `GameEngineInterface` deben seguir estas convenciones:

### initialize()
```php
public function initialize(GameMatch $match): void
{
    // 1. Crear estado inicial del juego
    $initialState = [
        'phase' => 'waiting',
        'scores' => [],
        'round' => 1,
        // ... estado específico del juego
    ];

    // 2. Guardar en game_state
    $match->update([
        'game_state' => $initialState,
    ]);

    // 3. El RoomService llama a $match->start() que actualiza started_at
}
```

### checkWinCondition()
```php
public function checkWinCondition(GameMatch $match): ?int
{
    // Verificar que el match esté activo
    if (!$this->isMatchActive($match)) {
        return null;
    }

    $gameState = $match->game_state;

    // Lógica de victoria específica del juego
    // Retornar player_id del ganador o null
}
```

### handlePlayerDisconnect()
```php
public function handlePlayerDisconnect(GameMatch $match, Player $player): void
{
    // Verificar que el match esté activo
    if (!$this->isMatchActive($match)) {
        return;
    }

    // Lógica específica del juego para manejar desconexión
}
```

## 🛡️ Validaciones Comunes

### En Controllers

```php
// games/{slug}/{GameName}Controller.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();

    // Verificar que el juego sea correcto
    if ($room->game->slug !== '{slug}') {
        abort(404, 'Esta sala no es de {GameName}');
    }

    // ✅ Query estandarizado para partidas activas
    $match = GameMatch::where('room_id', $room->id)
        ->whereNotNull('started_at')
        ->whereNull('finished_at')
        ->first();

    if (!$match) {
        abort(404, 'No hay una partida en progreso');
    }

    return view('{slug}::game', compact('room', 'match'));
}
```

## 📊 game_state Structure

El campo `game_state` es un JSON que debe seguir esta estructura base:

```json
{
  "phase": "waiting|playing|finished",
  "round": 1,
  "scores": {
    "1": 0,
    "2": 0
  },
  // ... campos específicos del juego
}
```

### Campos Comunes Recomendados

```php
$gameState = [
    // Obligatorios (recomendado)
    'phase' => 'playing',           // Estado general
    'scores' => [],                 // Puntuaciones por player_id
    'round' => 1,                   // Ronda actual

    // Opcionales según el juego
    'current_player_id' => null,    // Jugador actual (si aplica)
    'timer_expires_at' => null,     // Timestamp de expiración
    'answers' => [],                // Respuestas de jugadores

    // Específicos del juego
    'current_question' => null,     // Para Trivia
    'current_word' => null,         // Para Pictionary
    'deck' => [],                   // Para juegos de cartas
];
```

## 🔄 Flujo de Inicio de Partida

```
1. Master crea sala
   ↓
2. Jugadores se unen al lobby
   ↓
3. Master presiona "Iniciar Partida"
   ↓
4. RoomController::startGame()
   ├─ Room->status = 'playing'
   ├─ Engine->initialize($match)  // Crea game_state
   └─ Match->start()               // Actualiza started_at
   ↓
5. RoomController::show($code)
   ├─ Detecta route "{slug}.game"
   └─ redirect()->route('{slug}.game', ['roomCode' => $code])
   ↓
6. {GameName}Controller::game($roomCode)
   ├─ Busca match activo (started_at NOT NULL, finished_at NULL)
   └─ return view('{slug}::game')
```

## ✅ Checklist de Implementación

Al crear un nuevo juego, verificar:

- [ ] Engine implementa todos los métodos de `GameEngineInterface`
- [ ] `initialize()` crea el `game_state` inicial
- [ ] Queries usan `whereNotNull('started_at')` y `whereNull('finished_at')`
- [ ] NO se asume campo `status` en `matches`
- [ ] Controller tiene método `game(string $roomCode)`
- [ ] Existe ruta `{slug}.game`
- [ ] Vista principal es `game.blade.php`
- [ ] Tests de convención pasan

## 🚨 Errores Comunes a Evitar

1. **❌ Asumir campo `status` en matches**
   ```php
   // INCORRECTO
   $match->where('status', 'in_progress')
   ```

2. **❌ No verificar si match está activo**
   ```php
   // INCORRECTO - puede ser NULL
   $gameState = $match->game_state;
   ```

3. **❌ No manejar match NULL en controller**
   ```php
   // INCORRECTO
   $match = GameMatch::where(...)->first();
   $match->game_state; // Puede ser NULL
   ```

4. **✅ Siempre verificar:**
   ```php
   // CORRECTO
   $match = GameMatch::where('room_id', $room->id)
       ->whereNotNull('started_at')
       ->whereNull('finished_at')
       ->first();

   if (!$match) {
       abort(404, 'No hay partida activa');
   }
   ```

## 📚 Ver También

- [GameEngineInterface.php](../../../app/Contracts/GameEngineInterface.php)
- [GAMES_CONVENTION.md](../../../docs/GAMES_CONVENTION.md)
- [TROUBLESHOOTING.md](../../../docs/TROUBLESHOOTING.md)

---

**Última actualización:** 2025-10-22
