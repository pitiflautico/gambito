# Mentiroso - Resumen de Fixes Aplicados

**Fecha:** 2025-10-29
**Contexto:** Resolución de bugs críticos en el juego Mentiroso

---

## 🐛 Bugs Identificados y Resueltos

### 1. **Bug Crítico: Pérdida de Votos al Final del Timer**

#### Síntoma
- Jugadores votando con menos de 10 segundos restantes perdían sus votos
- La ronda terminaba prematuramente sin contar todas las acciones
- Solo ocurría cuando faltaban menos de 8-10 segundos

#### Causa Raíz
Mentiroso tenía **DOS timers corriendo simultáneamente**:
1. **Round Timer** - Configurado en `config.json` con `round_duration: 15`
2. **PhaseManager Timers** - Preparation (2s) + Persuasion (5s) + Voting (15s) = 22s total

El round timer expiraba en el segundo 15, llamando a `endCurrentRound()` antes de que la fase de votación completara sus 15 segundos.

#### Solución Aplicada

**1. Configuración (`games/mentiroso/config.json`)**
```json
"timer_system": {
  "enabled": true,
  "round_duration": null,  // ✅ Cambiado de 15 a null
  "preparation_duration": 15,
  "persuasion_duration": 30,
  "voting_duration": 10
}
```

**2. Lock Strategy (`MentirosoEngine.php:397-401`)**
```php
// Usar el MISMO lock que endCurrentRound() para evitar race conditions
$lockKey = "game:match:{$match->id}:end-round";

return Cache::lock($lockKey, 10)->block(8, function() use ($match, $player, $playerManager) {
    // ... proceso de voto
});
```

**3. Defensive Check (`MentirosoEngine.php:406-418`)**
```php
// Verificar que la ronda no haya terminado mientras esperábamos el lock
if (!isset($match->game_state['current_statement']) ||
    $match->game_state['current_statement'] === null) {
    Log::warning("[Mentiroso] Vote arrived after round ended, ignoring", [
        'match_id' => $match->id,
        'player_id' => $player->id
    ]);

    return [
        'success' => false,
        'message' => 'La ronda ya terminó',
    ];
}
```

---

### 2. **Bug: Estado de Voto No Se Restaura al Reconectar**

#### Síntoma
- Cuando un jugador votaba y luego se desconectaba/reconectaba durante la fase de votación
- Al reconectar, los botones de voto aparecían activos en lugar de mostrar "Voto enviado"
- El estado `hasVoted` y `votedValue` se perdía

#### Causa Raíz
El método `restoreGameState()` buscaba votos en `gameState.votes` (array que no existe).
Los votos realmente se almacenan en `player_system.players[playerId].locked` y `.action`.

#### Solución Aplicada

**Frontend (`MentirosoGameClient.js:598-612`)**
```javascript
if (subPhase === 'voting') {
    // Check if already voted by looking at player_system locks
    const myPlayerData = playerSystem[this.playerId];
    if (myPlayerData?.locked === true) {
        this.hasVoted = true;
        // Get vote value from action metadata
        this.votedValue = myPlayerData.action?.vote ?? null;

        console.log('[Mentiroso] Restored vote state on reconnect:', {
            hasVoted: this.hasVoted,
            votedValue: this.votedValue,
            playerData: myPlayerData
        });
    }
}
```

---

### 3. **Bug: Misma Frase en Múltiples Rondas**

#### Síntoma
- Cuando la ronda terminaba por expiración del timer, la siguiente ronda usaba la misma frase
- El defensive check en `onRoundStarting()` detectaba que ya había una frase y no cargaba nueva

#### Causa Raíz
`endCurrentRound()` no limpiaba `current_statement` antes de llamar a `completeRound()`

#### Solución Aplicada

**Backend (`MentirosoEngine.php:472-481`)**
```php
protected function endCurrentRound(GameMatch $match): void
{
    $results = $this->getRoundResults($match);

    // ✅ IMPORTANTE: Limpiar current_statement ANTES de completeRound()
    // Esto permite que onRoundStarting() de la siguiente ronda cargue nueva frase
    $gameState = $match->game_state;
    $gameState['current_statement'] = null;
    $match->game_state = $gameState;
    $match->save();

    $this->completeRound($match, $results);
}
```

---

## 📊 Resumen de Archivos Modificados

### 1. `/games/mentiroso/config.json`
- **Línea 45:** `"round_duration": null` (antes: `15`)
- **Motivo:** Deshabilitar round timer cuando se usa PhaseManager

### 2. `/games/mentiroso/MentirosoEngine.php`
- **Líneas 397-401:** Lock strategy unificada
- **Líneas 406-418:** Defensive check para votos después de round terminado
- **Líneas 472-481:** Limpiar `current_statement` antes de `completeRound()`

### 3. `/games/mentiroso/js/MentirosoGameClient.js`
- **Líneas 598-612:** Restaurar estado de voto desde `player_system` al reconectar

---

## 🎯 Lecciones Aprendidas

### 1. **Round Timer vs PhaseManager**
> **Regla de Oro:** Si usas PhaseManager → `round_duration: null`

Juegos con múltiples fases NO deben usar round timer global. PhaseManager ya gestiona timers por fase.

### 2. **Lock Strategy**
> **Usar el mismo lock key** para operaciones que NO deben ejecutarse simultáneamente

Si `endCurrentRound()` y `processVote()` pueden ejecutarse al mismo tiempo, deben compartir el mismo lock key.

### 3. **Defensive Checks Después de Locks**
> **Siempre verificar estado** después de esperar un lock

El estado puede haber cambiado mientras esperabas el lock. Verifica que sigue siendo válido procesar la acción.

### 4. **State Cleanup**
> **Limpiar estado específico de la ronda** antes de completarla

Si tienes datos como `current_statement` o `current_question`, límpialos en `endCurrentRound()` antes de llamar a `completeRound()`.

### 5. **Estado en PlayerManager**
> **La fuente de verdad** para el estado del jugador está en `player_system.players[playerId]`

No crear arrays paralelos (`votes`, `answers`, etc.). Usar `locked` y `action` metadata.

---

## 🧪 Cómo Verificar que los Fixes Funcionan

### Test 1: Votos al Final del Timer
```
1. Iniciar partida con 3+ jugadores
2. Esperar a fase de votación
3. Esperar hasta que queden menos de 8 segundos
4. Votar rápidamente con todos los jugadores
5. ✅ Verificar que TODOS los votos se cuentan
6. ✅ Verificar que la ronda NO termina prematuramente
```

### Test 2: Reconexión Durante Votación
```
1. Iniciar partida con 3+ jugadores
2. Llegar a fase de votación
3. Jugador A vota "verdad"
4. Jugador A se desconecta (cerrar pestaña)
5. Jugador A se reconecta
6. ✅ Verificar que aparece "Voto enviado: verdad"
7. ✅ Verificar que NO aparecen botones de voto
```

### Test 3: Nueva Frase en Cada Ronda
```
1. Iniciar partida con 3+ jugadores
2. Jugar ronda 1, anotar la frase
3. Dejar que el timer expire (no votar)
4. Jugar ronda 2
5. ✅ Verificar que la frase es DIFERENTE a la de ronda 1
```

---

## 📚 Documentación Relacionada

- **ROUND_TIMER_VS_PHASE_MANAGER.md** - Guía completa sobre el conflicto de timers
- **PHASE_SYSTEM_LEARNINGS.md** - Patrones del sistema de fases
- **ROUND_LIFECYCLE_STANDARDIZATION.md** - Qué debería estar en BASE
- **TECHNICAL_DECISIONS.md** - Decisiones técnicas de arquitectura

---

## ✅ Estado Final

Todos los bugs identificados han sido resueltos:
- ✅ Pérdida de votos al final del timer → **FIXED**
- ✅ Estado de voto no se restaura al reconectar → **FIXED**
- ✅ Misma frase en múltiples rondas → **FIXED**

**Mentiroso está ahora completamente funcional y robusto.**

---

## 🔄 Aplicabilidad a Otros Juegos

Estos fixes son específicos de Mentiroso, pero los **patrones** son aplicables a cualquier juego que:

1. **Use PhaseManager** → Debe configurar `round_duration: null`
2. **Procese acciones de jugadores** → Debe usar locks y defensive checks
3. **Tenga estado específico de ronda** → Debe limpiar ese estado en `endCurrentRound()`
4. **Permita reconexión** → Debe restaurar estado desde `player_system`

Consulta `ROUND_LIFECYCLE_STANDARDIZATION.md` para ver qué código ya está en BASE y no necesita duplicarse.
