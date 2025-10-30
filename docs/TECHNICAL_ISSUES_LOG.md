# Log de Problemas Técnicos - Implementación Mock Game

**Fecha:** 2025-10-30
**Contexto:** Desarrollo del sistema de rondas con countdown automático

---

## 1. Race Condition: Rounds Saltando (1 → 3)

### Síntoma
- Las rondas avanzaban de 1 directamente a 3, saltándose la ronda 2
- Los logs mostraban dos `handler_id` diferentes ejecutándose casi simultáneamente
- Frontend recibía dos eventos `game.round.started` en milisegundos

### Diagnóstico
```
[09:16:27] 🔥 API CALLED - frontend_1761815787304 (UNA sola llamada ✓)
[09:16:27] 🚀 ABOUT TO EMIT - dispatch_69032ceb521385 (UNA sola emisión ✓)
[09:16:27] 🎯 Starting round - handler_69032ceb522041 (PRIMER LISTENER ✓)
[09:16:27] ✅ Round started - handler_69032ceb522041
[09:16:27] 🎯 Starting round - handler_69032ceb5a2ff1 (SEGUNDO LISTENER ✗✗✗)
[09:16:27] ✅ Round started - handler_69032ceb5a2ff1
```

### Causa Raíz
**EventServiceProvider registrado DOS veces:**
1. Laravel 11 auto-detecta el provider automáticamente
2. También estaba listado manualmente en `bootstrap/providers.php`
3. Esto causaba que el método `boot()` se ejecutara dos veces
4. Por ende, `Event::listen()` registraba el listener dos veces

### Evidencia
```bash
$ grep "EventServiceProvider" bootstrap/cache/services.php
53:    49 => 'App\\Providers\\EventServiceProvider',
89:    32 => 'App\\Providers\\EventServiceProvider',
```

### Solución
**Eliminar registro manual del provider:**

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\GameServiceProvider::class,
    // EventServiceProvider is auto-discovered by Laravel, no need to register manually
];
```

### Intentos Fallidos
1. ❌ `Cache::add()` con timestamp - Ambas ejecuciones obtenían el lock
2. ❌ `Cache::lock()` con Redis - Ejecuciones demasiado rápidas (milisegundos)
3. ❌ Cambiar `__invoke()` a `handle()` - Laravel seguía auto-detectando
4. ❌ Registrar con `@handle` en EventServiceProvider - Seguía duplicado
5. ❌ Registrar manualmente en `boot()` - Se registraba por duplicado

### Lección Aprendida
**El problema NO era un race condition real, sino un problema de configuración.**
- Los locks de Redis no funcionan cuando el código se ejecuta dos veces sincrónicamente
- Siempre verificar `php artisan event:list` para ver listeners registrados
- En Laravel 11, los providers se auto-detectan si extienden de las clases base de Laravel

---

## 2. Acumulación de Timers en Frontend

### Síntoma
- `notifiedTimers` Set acumulaba nombres sin limpiarse nunca
- Timers con el mismo nombre en nuevas rondas no podían notificar al backend
- El sistema de race control impedía notificaciones legítimas

### Causa Raíz
El Set `notifiedTimers` nunca se limpiaba, causando que:
- Timer "phase1" de ronda 1 → se marca como notificado
- Timer "phase1" de ronda 2 → bloqueado porque ya está en el Set
- Timer "phase1" de ronda 3 → bloqueado también

### Solución
**Triple protección de limpieza:**

```javascript
class TimingModule {
    constructor() {
        this.notifiedTimers = new Set();
        this.subscribeToGameEvents();
    }

    subscribeToGameEvents() {
        // 1. Limpiar en cada nueva ronda
        window.addEventListener('game:round:started', (e) => {
            this.clearNotifiedTimers();
        });
    }

    startServerSyncedCountdown(serverTime, durationMs, element, callback, name) {
        if (this.activeCountdowns.has(name)) {
            this.cancelCountdown(name);
        }
        // 2. Limpiar cuando se crea nuevo timer con mismo nombre
        this.notifiedTimers.delete(name);
        // ...
    }

    cancelCountdown(name) {
        // 3. Limpiar cuando se cancela un timer
        this.notifiedTimers.delete(name);
        // ...
    }
}
```

### Lección Aprendida
- Los sistemas de race control necesitan limpieza periódica
- Usar eventos de ciclo de vida (round started, phase ended) para resetear estado
- Documentar explícitamente cuándo y cómo se limpian los Sets/Maps de control

---

## 3. Eventos Deprecated en Base de Datos

### Síntoma
- Se emitía `RoundTimerExpiredEvent` en lugar de `StartNewRoundEvent`
- Estados antiguos del juego en DB tenían configuración de timer obsoleta

### Causa Raíz
Durante refactoring eliminamos el sistema de timers de ronda (`RoundTimerExpiredEvent`), pero:
- Estados viejos en BD aún referenciaban el evento eliminado
- El código intentaba emitir un evento que ya no existía
- Convivían dos sistemas (viejo y nuevo) sin migración clara

### Solución
**Limpieza completa del sistema viejo:**

1. Eliminar archivo del evento:
```bash
rm app/Events/Game/RoundTimerExpiredEvent.php
```

2. Eliminar código relacionado:
```php
// BaseGameEngine.php - ELIMINADOS:
- startRoundTimer()
- checkTimerAndAutoAdvance()
- onRoundTimerExpired()

// RoundManager.php - ELIMINADO:
- startRoundTimer()

// PlayController.php - ELIMINADO:
- Legacy fallback para checkTimerAndAutoAdvance()
```

3. Limpiar base de datos:
```sql
-- Resetear estados de juegos con timers viejos
```

### Lección Aprendida
- Al deprecar funcionalidad, hacer migración completa (código + BD)
- Nunca dejar sistemas "legacy" conviviendo con sistemas nuevos
- Documentar qué eventos/métodos están deprecated antes de eliminar
- Considerar migraciones de datos cuando se cambia estructura de estado

---

## 4. Caché Corrupto de Laravel

### Síntoma
- Después de limpiar `php artisan optimize:clear`, el problema persistía
- El archivo `bootstrap/cache/services.php` se regeneraba con duplicados

### Causa Raíz
Laravel genera un archivo de caché que mapea providers a sus servicios. Si un provider está registrado dos veces en las fuentes (providers.php + auto-discovery), el caché refleja esa duplicación.

### Solución
```bash
rm bootstrap/cache/services.php
php artisan optimize:clear
```

### Lección Aprendida
- Los comandos de clear cache no siempre eliminan archivos corruptos
- A veces es necesario eliminar manualmente archivos de `bootstrap/cache/`
- Verificar el contenido de archivos de caché cuando el comportamiento es inconsistente
- Laravel regenera estos archivos automáticamente, así que es seguro eliminarlos

---

## 5. Auto-Discovery de Laravel 11

### Síntoma
```bash
$ php artisan event:list
App\Events\Game\StartNewRoundEvent
  ⇂ App\Listeners\HandleStartNewRound@handle
  ⇂ App\Listeners\HandleStartNewRound@handle  # ← Duplicado
```

### Causa Raíz
En Laravel 11, el framework tiene un sistema de auto-discovery mejorado que:
1. Detecta automáticamente los Service Providers que extienden clases base
2. Registra listeners basándose en type hints en métodos `handle()` o `__invoke()`
3. Si además registras manualmente en `bootstrap/providers.php`, hay doble registro

### Intentos de Workaround
```php
// INTENTO 1: Especificar método explícitamente
protected $listen = [
    StartNewRoundEvent::class => [
        HandleStartNewRound::class . '@handle',  // ← Seguía duplicado
    ],
];

// INTENTO 2: Cambiar nombre del método
public function handleStartNewRound(StartNewRoundEvent $event) {
    // ← Laravel seguía auto-detectando por type hint
}

// INTENTO 3: Registrar en boot()
public function boot() {
    Event::listen(
        StartNewRoundEvent::class,
        [HandleStartNewRound::class, 'handle']
    );
    // ← Se ejecutaba boot() dos veces por provider duplicado
}
```

### Solución Real
**NO registrar el EventServiceProvider en `bootstrap/providers.php`:**

Laravel 11 lo detecta automáticamente, solo hay que dejarlo trabajar.

### Lección Aprendida
- En Laravel 11, los EventServiceProvider se auto-detectan por defecto
- Solo registrar providers en `bootstrap/providers.php` si NO extienden clases base de Laravel
- Usar `php artisan event:list` para verificar registros
- Si ves duplicados, buscar en `bootstrap/cache/services.php`

---

## 6. Logging Insuficiente para Debugging

### Síntoma
- Al principio no sabíamos si el problema era:
  - Frontend llamando API dos veces?
  - Backend emitiendo evento dos veces?
  - Listener ejecutándose dos veces?

### Solución Implementada
**IDs de correlación únicos en cada capa:**

```javascript
// Frontend - TimingModule.js
const frontendCallId = `frontend_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
console.warn('🔥 FRONTEND CALLING API', { frontend_call_id: frontendCallId });
```

```php
// Backend - PlayController.php
$frontendCallId = $request->input('frontend_call_id', 'unknown');
\Log::warning('🔥 API CALLED FROM FRONTEND', ['frontend_call_id' => $frontendCallId]);

$dispatchId = uniqid('dispatch_', true);
\Log::warning('🚀 ABOUT TO EMIT', ['dispatch_id' => $dispatchId]);
```

```php
// Listener - HandleStartNewRound.php
$handlerId = uniqid('handler_', true);
\Log::info('🎯 Starting new round', ['handler_id' => $handlerId]);
```

### Resultado
Logs correlacionados que permiten tracing completo:
```
Frontend:  🔥 frontend_1761815787304_10cboq2jt
Backend:   🔥 frontend_1761815787304_10cboq2jt → 🚀 dispatch_69032ceb521385
Listener:  🎯 handler_69032ceb522041 ← Primera ejecución
Listener:  🎯 handler_69032ceb5a2ff1 ← Segunda ejecución (PROBLEMA!)
```

### Lección Aprendida
- Siempre usar IDs de correlación para debugging de flujos complejos
- Incluir timestamps e identificadores únicos en logs críticos
- Usar emojis consistentes para facilitar búsqueda en logs
- Agregar backtrace cuando se investiga doble ejecución

---

## Convenciones Técnicas Derivadas

### 1. Service Providers
```php
// ✅ CORRECTO - Laravel 11
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\GameServiceProvider::class,
    // EventServiceProvider se auto-detecta, NO incluir
];

// ❌ INCORRECTO
return [
    App\Providers\EventServiceProvider::class, // ← NO HACER
];
```

### 2. Event Listeners
```php
// ✅ CORRECTO - Registro en EventServiceProvider
protected $listen = [
    MyEvent::class => [
        MyListener::class,  // Laravel llamará al método handle()
    ],
];

// ❌ INCORRECTO - No registrar manualmente en boot()
public function boot() {
    Event::listen(MyEvent::class, MyListener::class); // ← Puede duplicar
}
```

### 3. Race Control en Frontend
```javascript
// ✅ CORRECTO - Con limpieza de estado
class TimingModule {
    constructor() {
        this.notifiedTimers = new Set();
        this.subscribeToGameEvents(); // ← Registrar limpiezas
    }

    subscribeToGameEvents() {
        window.addEventListener('game:round:started', () => {
            this.clearNotifiedTimers(); // ← Limpiar en cambios de estado
        });
    }

    notifyTimerExpired(name) {
        if (this.notifiedTimers.has(name)) return; // ← Race control
        this.notifiedTimers.add(name);
        // ... API call
    }

    clearNotifiedTimers() {
        this.notifiedTimers.clear(); // ← Exponer método público
    }
}
```

### 4. Deprecación de Funcionalidad
```bash
# ✅ CORRECTO - Proceso completo
1. Marcar como @deprecated con fecha límite
2. Crear sistema nuevo en paralelo
3. Migrar todos los usos
4. Migrar datos en BD si aplica
5. Eliminar código viejo
6. Eliminar referencias en configs
7. Limpiar cachés

# ❌ INCORRECTO - Eliminar sin migración
1. Borrar archivo
2. Esperar que falle para encontrar usos
```

### 5. Debugging de Race Conditions
```php
// ✅ CORRECTO - IDs de correlación
public function handle(MyEvent $event) {
    $handlerId = uniqid('handler_', true);
    \Log::info('Processing', ['handler_id' => $handlerId, 'event' => $event]);

    // Agregar backtrace si sospechas doble ejecución
    if (config('app.debug')) {
        \Log::debug('Backtrace', [
            'handler_id' => $handlerId,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ]);
    }
}
```

### 6. Locks Distribuidos
```php
// ⚠️ LIMITACIÓN - Locks no funcionan para código síncrono
$lock = Cache::lock("key", 10);
if (!$lock->get()) return; // ← No previene doble ejecución si se llama dos veces sincrónicamente

// ✅ CORRECTO - Solo para prevenir concurrencia entre requests
// NO usar para prevenir doble registro/ejecución en mismo proceso
```

### 7. Verificación de Configuración
```bash
# Comandos de verificación después de cambios en providers/listeners
php artisan optimize:clear
php artisan event:list | grep "MiEvento"  # Ver si hay duplicados
ls -la bootstrap/cache/services.php        # Verificar archivo de caché
grep "MiProvider" bootstrap/cache/services.php | wc -l  # Contar ocurrencias
```

---

## Checklist de Implementación

### Al agregar nuevo Event Listener:
- [ ] Verificar que EventServiceProvider NO esté en `bootstrap/providers.php`
- [ ] Registrar en array `$listen` del EventServiceProvider
- [ ] NO registrar manualmente en método `boot()`
- [ ] Ejecutar `php artisan optimize:clear`
- [ ] Verificar con `php artisan event:list` que no esté duplicado
- [ ] Agregar logging con ID único para debugging

### Al implementar Race Control:
- [ ] Identificar eventos de ciclo de vida donde limpiar estado
- [ ] Implementar método público para limpiar caches/Sets
- [ ] Documentar cuándo se limpia el estado
- [ ] Probar que funciona correctamente en múltiples rondas/fases
- [ ] NO confiar solo en locks para prevenir duplicados

### Al deprecar funcionalidad:
- [ ] Marcar como @deprecated en código
- [ ] Buscar todos los usos (grep, IDE)
- [ ] Crear sistema de reemplazo
- [ ] Migrar datos en BD si aplica
- [ ] Eliminar código viejo
- [ ] Eliminar archivos de eventos/listeners
- [ ] Actualizar documentación

---

## Comandos Útiles para Debugging

```bash
# Ver todos los listeners registrados
php artisan event:list

# Ver listeners de un evento específico
php artisan event:list | grep -A 3 "MiEvento"

# Limpiar todas las cachés
php artisan optimize:clear

# Ver providers cacheados
cat bootstrap/cache/services.php | grep -n "MiProvider"

# Eliminar caché corrupto manualmente
rm bootstrap/cache/services.php
rm bootstrap/cache/packages.php

# Ver logs en tiempo real con filtro
tail -f storage/logs/laravel.log | grep "PATRON"

# Contar ocurrencias en logs
grep "PATRON" storage/logs/laravel.log | wc -l
```

---

## Lecciones Clave

1. **La configuración puede causar "race conditions" aparentes**
   - No siempre es concurrencia real
   - Puede ser código ejecutándose dos veces sincrónicamente por mala config

2. **Laravel 11 cambió cómo se registran providers**
   - Auto-discovery es más agresivo
   - Menos configuración manual necesaria
   - Revisar docs de migración de Laravel 10 → 11

3. **Los logs son tu mejor amigo**
   - IDs de correlación únicos en cada capa
   - Timestamps precisos
   - Backtrace cuando hay dudas

4. **El estado frontend necesita limpieza**
   - Sets y Maps de control deben resetearse
   - Usar eventos de ciclo de vida del juego
   - Documentar estrategias de limpieza

5. **La caché puede ser tu enemigo**
   - A veces hay que eliminarla manualmente
   - `optimize:clear` no siempre es suficiente
   - Verificar contenido de archivos cacheados cuando hay inconsistencias
