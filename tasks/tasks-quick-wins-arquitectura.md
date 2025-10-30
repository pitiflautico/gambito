# Task List: Quick Wins - Mejoras de Arquitectura

**Basado en**: `docs/ANALISIS_ARQUITECTURA_REFACTORIZACION.md`
**Fase**: Quick Wins (Semana 1)
**Estimado**: 14 horas
**Impacto**: 30% mejora en estabilidad

---

## Relevant Files

- `app/Contracts/BaseGameEngine.php` - Agregar error logging mejorado y try-catch en métodos críticos
- `app/Http/Controllers/GameController.php` - Agregar validación básica en performAction
- `app/Http/Controllers/HealthController.php` - **NUEVO** - Health check endpoint
- `routes/api.php` - Agregar ruta /health
- `games/mockup/MockupEngine.php` - Ejemplo de implementación de mejoras
- `games/trivia/TriviaEngine.php` - Ejemplo de implementación de mejoras
- `games/pictionary/PictionaryEngine.php` - Ejemplo de implementación de mejoras
- `tests/Feature/HealthCheckTest.php` - **NUEVO** - Tests para health check
- `tests/Feature/GameControllerTest.php` - Tests de validación

---

## Tasks

### 1.0 Error Logging Mejorado (2h)

**Objetivo**: Agregar logging estructurado con contexto completo en métodos críticos de BaseGameEngine.

- [ ] **1.1 Agregar logging en initialize() - BaseGameEngine.php** (20 min)
  - Agregar log de inicio con match_id, game_slug, config
  - Agregar log de finalización con módulos inicializados
  - Formato: `[{GameName}] Initializing game | match_id: {id} | config: {json}`

- [ ] **1.2 Agregar logging en onGameStart() - BaseGameEngine.php** (20 min)
  - Log de inicio de partida con players, rondas totales
  - Formato: `[{GameName}] ===== PARTIDA INICIADA ===== | match_id: {id} | players: {count} | rounds: {total}`

- [ ] **1.3 Agregar logging en startNewRound() - BaseGameEngine.php** (20 min)
  - Log de nueva ronda con número de ronda, players desbloqueados
  - Formato: `[{GameName}] Starting new round | round: {num}/{total} | players_unlocked: {count}`

- [ ] **1.4 Agregar logging en processRoundAction() - BaseGameEngine.php** (30 min)
  - Log al recibir acción: player_id, action_type, game_state relevante
  - Log de resultado: success, points_awarded, player_locked
  - Formato: `[{GameName}] Action received | player_id: {id} | action: {type} | state: {json}`
  - Formato: `[{GameName}] Action processed | success: {bool} | points: {n} | locked: {bool}`

- [ ] **1.5 Agregar logging en endCurrentRound() - BaseGameEngine.php** (20 min)
  - Log de fin de ronda con resultados, scores finales
  - Formato: `[{GameName}] Round ended successfully | round: {num} | results: {json} | scores: {json}`

- [ ] **1.6 Agregar logging en callbacks de fase - BaseGameEngine.php** (30 min)
  - Log al inicio de cada callback handle{Fase}Ended
  - Log al finalizar (fase siguiente o fin de ronda)
  - Formato: `[{GameName}] FASE X ENDED | callback: handle{Fase}Ended | next_phase: {name} | cycle_completed: {bool}`

---

### 2.0 Try-Catch en Callbacks Críticos (4h)

**Objetivo**: Proteger métodos críticos con bloques try-catch y recuperación de errores.

- [ ] **2.1 Proteger callbacks handle{Fase}Ended en BaseGameEngine.php** (90 min)
  - Agregar try-catch en método abstracto/template para callbacks
  - Capturar excepciones en setMatch(), nextPhase()
  - En caso de error: log detallado + forzar fin de ronda
  - Template:
    ```php
    try {
        $phaseManager->setMatch($match);
        $nextPhaseInfo = $phaseManager->nextPhase();
    } catch (\Exception $e) {
        Log::error("[{GameName}] Phase callback failed", [
            'callback' => 'handle{Fase}Ended',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        $this->forceEndRound($match, reason: 'phase_error');
        return;
    }
    ```

- [ ] **2.2 Proteger processRoundAction() en BaseGameEngine.php** (60 min)
  - Agregar try-catch alrededor de lógica de procesamiento
  - Capturar errores en lockPlayer(), awardPoints(), game_state modificación
  - Retornar `['success' => false, 'error' => 'message']` en caso de excepción
  - NO crash del match, solo fallo de acción individual

- [ ] **2.3 Proteger startNewRound() en BaseGameEngine.php** (45 min)
  - Agregar try-catch para unlockAllPlayers, reset de estado
  - Si falla: intentar rollback parcial o forzar estado limpio mínimo
  - Emitir evento de error si no se puede iniciar ronda

- [ ] **2.4 Implementar método forceEndRound() en BaseGameEngine.php** (45 min)
  - Nuevo método protegido para terminar ronda por error
  - Params: GameMatch $match, string $reason
  - Lógica: calcular scores actuales, llamar completeRound con reason
  - Log: `[{GameName}] Force ending round | reason: {reason}`

---

### 3.0 Validación Básica en Endpoints (3h)

**Objetivo**: Agregar validación usando Laravel Form Requests antes de procesar acciones.

- [ ] **3.1 Crear Form Request para performAction - PerformActionRequest.php** (45 min)
  - Ubicación: `app/Http/Requests/Game/PerformActionRequest.php`
  - Validar: action_type (required, string), action_data (required, array)
  - Custom messages en español
  - Método authorize() retornar true (autenticación ya manejada en middleware)

- [ ] **3.2 Actualizar GameController::performAction para usar Request** (30 min)
  - Cambiar signature: `performAction(PerformActionRequest $request, $roomCode)`
  - Usar `$request->validated()` en lugar de `$request->all()`
  - Agregar log si validación falla (automático con Laravel)

- [ ] **3.3 Agregar validación de estado del match en GameController** (45 min)
  - Verificar `$match->status === 'in_progress'` antes de processAction
  - Retornar 400 si match no está activo
  - Response: `['success' => false, 'error' => 'Match not active']`

- [ ] **3.4 Agregar validación de jugador en GameController** (45 min)
  - Verificar que player_id existe en $match->players
  - Verificar que player no está desconectado
  - Retornar 403 si jugador no válido
  - Response: `['success' => false, 'error' => 'Player not in match']`

- [ ] **3.5 Crear tests para validación en GameControllerTest.php** (15 min)
  - Test: action_type requerido
  - Test: action_data debe ser array
  - Test: match debe estar in_progress
  - Test: player debe estar en match

---

### 4.0 Health Check Endpoint (2h)

**Objetivo**: Crear endpoint /api/health para monitoreo de sistema.

- [ ] **4.1 Crear HealthController.php** (45 min)
  - Ubicación: `app/Http/Controllers/HealthController.php`
  - Método: `index()` retorna JSON
  - Estructura response:
    ```json
    {
      "status": "healthy",
      "timestamp": "2025-01-15T10:30:00Z",
      "services": {
        "database": "up",
        "redis": "up",
        "reverb": "up"
      },
      "metrics": {
        "active_matches": 5,
        "active_connections": 12
      }
    }
    ```

- [ ] **4.2 Implementar checks de servicios** (45 min)
  - Database check: `DB::connection()->getPdo()`
  - Redis check: `Redis::ping()`
  - Reverb check: verificar proceso activo o ping WebSocket
  - Capturar excepciones, marcar servicio como "down"

- [ ] **4.3 Agregar métricas básicas** (15 min)
  - active_matches: `GameMatch::where('status', 'in_progress')->count()`
  - active_connections: desde Redis/Reverb presence channels

- [ ] **4.4 Agregar ruta en routes/api.php** (10 min)
  - Ruta: `GET /api/health`
  - Sin autenticación (public)
  - Controller: `HealthController@index`

- [ ] **4.5 Crear tests en HealthCheckTest.php** (15 min)
  - Test: endpoint retorna 200
  - Test: estructura JSON correcta
  - Test: services incluye database, redis, reverb
  - Test: metrics incluye active_matches

---

### 5.0 Rollback Manual en Errores (3h)

**Objetivo**: Implementar sistema de snapshots de game_state para rollback manual en caso de errores.

- [ ] **5.1 Agregar campo game_state_snapshot en BaseGameEngine** (30 min)
  - Propiedad protected: `$gameStateSnapshot`
  - Método: `takeSnapshot(GameMatch $match)` guarda copia de game_state
  - Método: `restoreSnapshot(GameMatch $match)` restaura desde snapshot
  - Log: `[{GameName}] Snapshot taken | match_id: {id}`

- [ ] **5.2 Tomar snapshot al inicio de startNewRound()** (30 min)
  - Llamar `$this->takeSnapshot($match)` al inicio del método
  - Guardar snapshot ANTES de modificar estado
  - Snapshot incluye: game_state completo, round_manager serializado

- [ ] **5.3 Tomar snapshot antes de callbacks de fase** (45 min)
  - En template de handle{Fase}Ended, tomar snapshot antes de nextPhase()
  - Permite rollback si fase siguiente falla al iniciar

- [ ] **5.4 Implementar rollback automático en catch de processRoundAction** (45 min)
  - En catch de processRoundAction(), llamar `restoreSnapshot()`
  - Log: `[{GameName}] Rolling back to snapshot | reason: action_error`
  - Retornar error al cliente indicando que acción no se procesó

- [ ] **5.5 Crear comando artisan para rollback manual** (30 min)
  - Comando: `php artisan game:rollback {match_id}`
  - Lee snapshot desde DB o Redis
  - Restaura game_state a snapshot más reciente
  - Uso: en caso de partida corrupta, admin puede ejecutar rollback manual
  - Log: `[Admin] Manual rollback executed | match_id: {id} | by: {admin}`

---

## Notas Importantes

**Convenciones a Seguir**:
- Logs estructurados con contexto completo (match_id, player_id, etc.)
- Try-catch solo en métodos críticos (callbacks de fases, processAction)
- Validación usando Laravel Form Requests
- Health check debe retornar JSON con status, timestamp, y métricas básicas
- Rollback manual usando snapshot de game_state

**Testing**:
- Ejecutar tests después de cada tarea: `php artisan test`
- Validar logs en `storage/logs/laravel.log`
- Probar health check: `curl http://localhost:8000/api/health`

---

## Resumen de Subtareas

**Total de subtareas**: 26
**Distribución por tarea padre**:
- 1.0 Error Logging: 6 subtareas
- 2.0 Try-Catch: 4 subtareas
- 3.0 Validación: 5 subtareas
- 4.0 Health Check: 5 subtareas
- 5.0 Rollback: 6 subtareas

**Orden de implementación recomendado**:
1. Empezar con 1.0 (Error Logging) - base para debugging
2. Continuar con 2.0 (Try-Catch) - protección inmediata
3. Implementar 4.0 (Health Check) - monitoreo desde el inicio
4. Agregar 3.0 (Validación) - prevención de errores
5. Finalizar con 5.0 (Rollback) - recuperación avanzada

**Archivos nuevos a crear**:
- `app/Http/Requests/Game/PerformActionRequest.php`
- `app/Http/Controllers/HealthController.php`
- `tests/Feature/HealthCheckTest.php`
- `app/Console/Commands/GameRollback.php`

**Archivos a modificar**:
- `app/Contracts/BaseGameEngine.php` (mayoría de cambios)
- `app/Http/Controllers/GameController.php`
- `routes/api.php`
- `games/mockup/MockupEngine.php` (ejemplo)
- `games/trivia/TriviaEngine.php` (ejemplo)
- `games/pictionary/PictionaryEngine.php` (ejemplo)

---

**Estado**: ✅ Subtareas detalladas generadas.

**Siguiente paso**: Revisar las subtareas y comenzar implementación. Se recomienda trabajar tarea por tarea, ejecutando tests después de cada una.
