# Troubleshooting - Guía de Errores Comunes

Esta guía documenta errores comunes al desarrollar juegos y cómo solucionarlos.

## 📋 Tabla de Contenidos

1. [Error: "Vista de juego no encontrada"](#error-vista-de-juego-no-encontrada)
2. [Error: "No hay una partida en progreso"](#error-no-hay-una-partida-en-progreso)
3. [Error: Controller no encontrado](#error-controller-no-encontrado)
4. [Error: Rutas del juego no cargadas](#error-rutas-del-juego-no-cargadas)
5. [Buenas Prácticas](#buenas-prácticas)

---

## Error: "Vista de juego no encontrada"

### Síntoma
Al iniciar la partida desde el lobby, se muestra error 404 o mensaje "Vista no encontrada".

### Causa
El juego no tiene una ruta específica `{slug}.game` configurada, por lo que `RoomController::show()` no sabe dónde redirigir.

### Solución

**1. Verificar que existe la ruta del juego:**
```php
// games/{slug}/routes.php
Route::prefix('{slug}')->name('{slug}.')->group(function () {
    Route::get('/{roomCode}', [{GameName}Controller::class, 'game'])->name('game');
});
```

**2. Verificar que el controller tiene el método `game()`:**
```php
// games/{slug}/{GameName}Controller.php
public function game(string $roomCode)
{
    $room = Room::where('code', $roomCode)->firstOrFail();
    // ... lógica del juego
    return view('{slug}::game', compact('room', 'match'));
}
```

**3. Verificar que existe la vista:**
```
games/{slug}/views/game.blade.php
```

**4. Limpiar cachés:**
```bash
php artisan route:clear
php artisan view:clear
php artisan config:clear
```

### Prevención
- ✅ Todos los juegos DEBEN tener la ruta `{slug}.game`
- ✅ La vista principal debe llamarse `game.blade.php`
- ✅ El `GameServiceProvider` carga las vistas automáticamente

---

## Error: "No hay una partida en progreso"

### Síntoma
Al acceder a la vista del juego, el controller devuelve 404 con mensaje "No hay una partida en progreso".

### Causa Raíz
El controller estaba verificando un campo `status` que no existe en la tabla `matches`. Esta tabla solo tiene `started_at` y `finished_at`.

### ❌ Código Incorrecto
```php
$match = GameMatch::where('room_id', $room->id)
    ->where('status', 'in_progress')  // ❌ Campo 'status' no existe
    ->first();
```

### ✅ Código Correcto
```php
$match = GameMatch::where('room_id', $room->id)
    ->whereNotNull('started_at')      // ✅ Partida iniciada
    ->whereNull('finished_at')        // ✅ Partida no finalizada
    ->first();
```

### Estructura de la tabla `matches`
```php
Schema::create('matches', function (Blueprint $table) {
    $table->id();
    $table->foreignId('room_id')->constrained()->onDelete('cascade');
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->foreignId('winner_id')->nullable()->constrained('players');
    $table->json('game_state')->nullable();
    $table->timestamps();
});
```

### Estados del Match
- **Sin iniciar:** `started_at = NULL`, `finished_at = NULL`
- **En progreso:** `started_at != NULL`, `finished_at = NULL`
- **Finalizado:** `started_at != NULL`, `finished_at != NULL`

### Prevención
- ✅ Usar `whereNotNull('started_at')` y `whereNull('finished_at')` para verificar partidas activas
- ✅ NO usar un campo `status` ficticio
- ✅ Consultar la documentación del modelo antes de asumir campos

---

## Error: Controller no encontrado

### Síntoma
```
Class 'App\Http\Controllers\TriviaController' not found
```

### Causa
El controller está en la ubicación incorrecta o usa el namespace incorrecto.

### ❌ Ubicación Incorrecta
```
app/Http/Controllers/TriviaController.php
namespace App\Http\Controllers;
```

### ✅ Ubicación Correcta
```
games/trivia/TriviaController.php
namespace Games\Trivia;
```

### Solución
1. Mover el controller a `games/{slug}/{GameName}Controller.php`
2. Cambiar namespace a `Games\{GameName}`
3. Importar `use App\Http\Controllers\Controller;`
4. Actualizar rutas para usar el namespace correcto

```php
// games/{slug}/routes.php
use Games\{GameName}\{GameName}Controller;  // ✅ Correcto
use App\Http\Controllers\{GameName}Controller;  // ❌ Incorrecto
```

### Prevención
- ✅ El test `test_games_have_valid_controller()` valida esto automáticamente
- ✅ Seguir la convención documentada en `GAMES_CONVENTION.md`

---

## Error: Rutas del juego no cargadas

### Síntoma
```
Route [trivia.game] not defined
```

### Causa
El archivo `routes.php` del juego no existe o tiene errores de sintaxis.

### Solución

**1. Verificar que existe el archivo:**
```bash
ls -la games/{slug}/routes.php
```

**2. Verificar sintaxis del archivo:**
```php
<?php

use Games\{GameName}\{GameName}Controller;
use Illuminate\Support\Facades\Route;

// API Routes
Route::prefix('api/{slug}')->name('api.{slug}.')->group(function () {
    Route::post('/answer', [{GameName}Controller::class, 'answer'])->name('answer');
});

// Web Routes - REQUERIDO
Route::prefix('{slug}')->name('{slug}.')->group(function () {
    Route::get('/{roomCode}', [{GameName}Controller::class, 'game'])->name('game');
});
```

**3. Limpiar caché de rutas:**
```bash
php artisan route:clear
php artisan route:list | grep {slug}
```

### Prevención
- ✅ Usar la plantilla estándar de `routes.php`
- ✅ Verificar con `php artisan route:list` después de crear el archivo
- ✅ El `GameServiceProvider` carga las rutas automáticamente

---

## Error: RoomController redirige a vista genérica

### Síntoma
Al iniciar el juego, se carga `rooms.show` en lugar de la vista específica del juego.

### Causa
Antes, `RoomController::show()` siempre cargaba la vista genérica `rooms.show`.

### ✅ Solución Implementada
```php
// app/Http/Controllers/RoomController.php - método show()
public function show(string $code)
{
    // ... código de validación ...

    // Redirigir a la ruta específica del juego si existe
    $gameSlug = $room->game->slug;
    $gameRouteName = "{$gameSlug}.game";

    if (\Route::has($gameRouteName)) {
        // El juego tiene su propia ruta, redirigir ahí
        return redirect()->route($gameRouteName, ['roomCode' => $code]);
    }

    // Fallback: vista genérica si el juego no tiene ruta específica
    return view('rooms.show', compact('room', 'playerId', 'role', 'players'));
}
```

### Prevención
- ✅ Todos los juegos DEBEN tener su propia vista y ruta `{slug}.game`
- ✅ El `RoomController` redirige automáticamente
- ✅ La vista genérica `rooms.show` solo es fallback

---

## Buenas Prácticas

### ✅ Checklist al Crear un Nuevo Juego

**Archivos Requeridos:**
- [ ] `games/{slug}/{GameName}Engine.php` - Implementa `GameEngineInterface`
- [ ] `games/{slug}/{GameName}Controller.php` - Namespace `Games\{GameName}`
- [ ] `games/{slug}/routes.php` - Con ruta `{slug}.game`
- [ ] `games/{slug}/views/game.blade.php` - Vista principal
- [ ] `games/{slug}/config.json` - Configuración del juego
- [ ] `games/{slug}/capabilities.json` - Módulos requeridos
- [ ] `resources/js/{slug}-game.js` - JavaScript del juego
- [ ] `public/games/{slug}/css/game.css` - Estilos del juego

**Verificaciones:**
```bash
# 1. Verificar que los tests de convención pasen
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php

# 2. Verificar que las rutas se cargaron
php artisan route:list | grep {slug}

# 3. Verificar que las vistas se registraron
php artisan tinker --execute="echo view()->exists('{slug}::game') ? 'OK' : 'ERROR';"

# 4. Compilar assets
npm run build
```

### 🚨 Errores Comunes a Evitar

1. **❌ No usar campo `status` en GameMatch**
   - ✅ Usar `whereNotNull('started_at')` y `whereNull('finished_at')`

2. **❌ Controller en `app/Http/Controllers/`**
   - ✅ Controller en `games/{slug}/`

3. **❌ Olvidar crear la ruta `{slug}.game`**
   - ✅ Toda la lógica del juego debe estar en su propia ruta

4. **❌ No limpiar cachés después de cambios**
   - ✅ Ejecutar `php artisan route:clear && php artisan view:clear`

5. **❌ Asumir estructura de base de datos sin verificar**
   - ✅ Revisar migraciones o modelo antes de escribir queries

### 📚 Documentación Relacionada

- [GAMES_CONVENTION.md](GAMES_CONVENTION.md) - Convenciones completas
- [HOW_TO_CREATE_A_GAME.md](HOW_TO_CREATE_A_GAME.md) - Guía paso a paso
- [DYNAMIC_ROUTES.md](architecture/DYNAMIC_ROUTES.md) - Sistema de rutas

---

**Última actualización:** 2025-10-22
