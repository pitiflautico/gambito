# Round Timer vs PhaseManager - Guía de Decisión

**Fecha:** 2025-10-29
**Contexto:** Bug crítico encontrado en Mentiroso - conflicto entre round_duration y PhaseManager

---

## 🎯 TL;DR

**Regla de oro:**
```
Si tu juego tiene PhaseManager → round_duration: null
Si tu juego NO tiene PhaseManager → round_duration: <segundos>
```

**NUNCA uses ambos simultáneamente** - causará que los votos/acciones se pierdan.

---

## 🐛 El Bug que lo Descubrió

### Síntomas
- Jugadores votando cerca del final del timer perdían sus votos
- La ronda terminaba prematuramente sin contar todas las acciones
- Solo ocurría cuando faltaban menos de 10 segundos

### Causa Raíz
Mentiroso tenía **DOS timers corriendo simultáneamente**:

1. **Round Timer** - Configurado en `config.json` con `round_duration: 15`
2. **PhaseManager Timers** - Preparation (2s) + Persuasion (5s) + Voting (15s) = 22s total

### ¿Qué Pasaba?

```
Ronda de Mentiroso:
├─ Round Timer: 15 segundos
│  └─> Expira en segundo 15 → llama endCurrentRound()
│
└─ PhaseManager:
   ├─ Preparation: 2s (segundos 0-2)
   ├─ Persuasion: 5s (segundos 2-7)
   └─ Voting: 15s (segundos 7-22)
       └─> Player vota en segundo 10
       └─> Round timer ya expiró en segundo 15 ❌
       └─> Voto se pierde
```

**Timeline del bug:**
```
00:00 - Ronda inicia, ambos timers empiezan
00:02 - Preparation termina
00:07 - Persuasion termina, Voting empieza
00:10 - Jugador vota (1 de 2 votos)
00:15 - Round Timer expira → endCurrentRound() ← ⚠️ BUG
00:22 - Voting Timer expiraría (pero ronda ya terminó)

Resultado: Solo se guarda 1 voto de 2, ronda termina prematuramente
```

### Solución
```json
// games/mentiroso/config.json
"timer_system": {
  "enabled": true,
  "round_duration": null,  // ✅ Deshabilitar round timer
  "preparation_duration": 15,
  "persuasion_duration": 30,
  "voting_duration": 10
}
```

---

## 📊 Comparación: Round Timer vs PhaseManager

| Característica | Round Timer | PhaseManager |
|----------------|-------------|--------------|
| **Cuándo usar** | 1 fase por ronda | Múltiples fases por ronda |
| **Configuración** | `round_duration: <segundos>` | `round_duration: null` |
| **Timer único** | ✅ Sí | ❌ No (1 timer por fase) |
| **Avance automático** | ✅ Al expirar | ✅ Entre fases |
| **Duración total** | Fija | Variable (suma de fases) |
| **Complejidad** | Baja | Media |
| **Control fino** | ❌ No | ✅ Sí |

---

## 🎮 Tipos de Juegos y su Configuración

### Tipo 1: Juegos de 1 Fase por Ronda

**Características:**
- Una acción única por ronda
- Todos los jugadores hacen lo mismo
- Timer global para toda la ronda

**Ejemplos:**
- **Trivia** - Responder pregunta
- **Pictionary** - Dibujar/Adivinar
- **UNO** - Jugar carta

**Configuración:**
```json
"timer_system": {
  "enabled": true,
  "round_duration": 15  // ✅ Usar round timer
}
```

**Flujo:**
```
Ronda Trivia:
├─ Timer: 15 segundos
├─ Acción: Todos responden
└─ Timer expira → endCurrentRound()
```

### Tipo 2: Juegos de Múltiples Fases por Ronda

**Características:**
- Secuencia de acciones diferentes
- Cada fase tiene su propia duración
- Diferentes jugadores pueden tener roles en cada fase

**Ejemplos:**
- **Mentiroso** - Preparation → Persuasion → Voting
- **Detective** (hipotético) - Investigation → Discussion → Accusation
- **Debate** (hipotético) - Opening → Rebuttal → Closing

**Configuración:**
```json
"timer_system": {
  "enabled": true,
  "round_duration": null,  // ✅ Deshabilitar round timer
  "preparation_duration": 15,
  "persuasion_duration": 30,
  "voting_duration": 10
}
```

**Flujo:**
```
Ronda Mentiroso:
├─ Phase 1: Preparation (2s)
│  └─> Timer expira → advanceToNextPhase()
├─ Phase 2: Persuasion (5s)
│  └─> Timer expira → advanceToNextPhase()
└─ Phase 3: Voting (15s)
   └─> Timer expira → endCurrentRound()

Duración total: 22 segundos (suma de fases)
```

---

## 🏗️ Arquitectura: Cómo Funciona

### Round Timer (Simple)

```php
// BaseGameEngine::handleNewRound()
if ($this->isModuleEnabled($match, 'timer_system')) {
    $roundManager = $this->getRoundManager($match);
    $timerService = $this->getTimerService($match);
    $config = $match->game_state['_config'] ?? [];

    // ✅ Inicia round timer si round_duration está configurado
    if ($roundManager->startRoundTimer($match, $timerService, $config)) {
        $this->saveTimerService($match, $timerService);
    }
}
```

**Cuando expira:**
```php
// RoundTimerExpiredEvent → BaseGameEngine::onTimerExpired()
protected function onTimerExpired(GameMatch $match, string $timerType): void
{
    if ($timerType === 'round') {
        $this->endCurrentRound($match);  // ✅ Termina ronda
    }
}
```

### PhaseManager (Complejo)

```php
// GameEngine::onRoundStarting()
protected function onRoundStarting(GameMatch $match): void
{
    // Crear PhaseManager con múltiples fases
    $phaseManager = new PhaseManager([
        ['name' => 'preparation', 'duration' => 2],
        ['name' => 'persuasion', 'duration' => 5],
        ['name' => 'voting', 'duration' => 15]
    ]);

    // Registrar callbacks para cada fase
    $phaseManager->onPhaseExpired('preparation', function($match) {
        $this->advanceToNextPhase($match);  // ✅ Avanza fase
    });

    $phaseManager->onPhaseExpired('persuasion', function($match) {
        $this->advanceToNextPhase($match);  // ✅ Avanza fase
    });

    $phaseManager->onPhaseExpired('voting', function($match) {
        $this->endCurrentRound($match);  // ✅ Termina ronda
    });

    // Iniciar primera fase
    $phaseManager->start($this->timerService, $match);
}
```

**Cuando expira cada fase:**
```php
// PhaseManager detecta timer expirado
// → Ejecuta callback correspondiente
// → advanceToNextPhase() o endCurrentRound()
```

---

## ⚙️ Cómo BaseGameEngine Decide

### Código Relevante

```php
// app/Contracts/BaseGameEngine.php:488-500
protected function handleNewRound(GameMatch $match, bool $advanceRound = true): void
{
    // ... código ...

    // 3.1. Iniciar timer de ronda automáticamente (si está configurado)
    if ($this->isModuleEnabled($match, 'round_system') &&
        $this->isModuleEnabled($match, 'timer_system')) {

        $roundManager = $this->getRoundManager($match);
        $timerService = $this->getTimerService($match);
        $config = $match->game_state['_config'] ?? [];

        // ⚠️ AQUÍ se decide si iniciar round timer
        if ($roundManager->startRoundTimer($match, $timerService, $config)) {
            $this->saveTimerService($match, $timerService);
            Log::info("[{$this->getGameSlug()}] Round timer started");
        }
    }
}
```

### RoundManager::startRoundTimer()

```php
// app/Services/Modules/RoundSystem/RoundManager.php
public function startRoundTimer(GameMatch $match, TimerService $timerService, array $config): bool
{
    $modules = $config['modules'] ?? [];
    $timerConfig = $modules['timer_system'] ?? [];
    $roundDuration = $timerConfig['round_duration'] ?? null;

    // ✅ Si round_duration es null o 0, NO inicia timer
    if (!$roundDuration || $roundDuration <= 0) {
        return false;  // No timer
    }

    // ✅ Si round_duration tiene valor, inicia timer
    $timerService->startRoundTimer(
        $match,
        $roundDuration,
        RoundTimerExpiredEvent::class,
        [$match->id, $this->currentRound, 'round']
    );

    return true;  // Timer iniciado
}
```

**Resumen:**
- `round_duration: null` → NO inicia round timer ✅
- `round_duration: 0` → NO inicia round timer ✅
- `round_duration: 15` → Inicia round timer de 15s ✅

---

## 📝 Checklist de Implementación

### Para Juegos con 1 Fase por Ronda

- [ ] Configurar `round_duration` con valor en segundos
- [ ] NO crear PhaseManager
- [ ] Dejar que BaseGameEngine maneje el timer automáticamente
- [ ] Implementar `getRoundResults()` para calcular puntos

**Ejemplo (Trivia):**
```json
"timer_system": {
  "enabled": true,
  "round_duration": 15
}
```

### Para Juegos con Múltiples Fases por Ronda

- [ ] Configurar `round_duration: null`
- [ ] Crear PhaseManager en `onRoundStarting()`
- [ ] Definir todas las fases con sus duraciones
- [ ] Registrar callbacks para cada fase
- [ ] Última fase debe llamar `endCurrentRound()`
- [ ] Implementar `getRoundResults()` para calcular puntos

**Ejemplo (Mentiroso):**
```json
"timer_system": {
  "enabled": true,
  "round_duration": null,  // ← IMPORTANTE
  "preparation_duration": 15,
  "persuasion_duration": 30,
  "voting_duration": 10
}
```

```php
// MentirosoEngine::onRoundStarting()
protected function onRoundStarting(GameMatch $match): void
{
    $phaseManager = new PhaseManager([
        ['name' => 'preparation', 'duration' => 2],
        ['name' => 'persuasion', 'duration' => 5],
        ['name' => 'voting', 'duration' => 15]
    ]);

    $phaseManager->onPhaseExpired('preparation', fn($m) => $this->advanceToNextPhase($m));
    $phaseManager->onPhaseExpired('persuasion', fn($m) => $this->advanceToNextPhase($m));
    $phaseManager->onPhaseExpired('voting', fn($m) => $this->endCurrentRound($m));

    $phaseManager->start($this->timerService, $match);
    $this->savePhaseManager($match, $phaseManager);
}
```

---

## 🚨 Antipatrones y Errores Comunes

### ❌ Error 1: Usar ambos simultáneamente

```json
// ❌ MAL - Causará pérdida de datos
"timer_system": {
  "enabled": true,
  "round_duration": 15,  // ← Timer de ronda
  "preparation_duration": 2,  // ← PhaseManager también
  "persuasion_duration": 5,
  "voting_duration": 10
}
```

**Síntoma:** Votos/acciones se pierden, ronda termina prematuramente.

**Fix:**
```json
// ✅ BIEN
"timer_system": {
  "enabled": true,
  "round_duration": null,  // ← Deshabilitar round timer
  "preparation_duration": 2,
  "persuasion_duration": 5,
  "voting_duration": 10
}
```

### ❌ Error 2: round_duration = suma de fases

```json
// ❌ MAL - Parece lógico pero NO funciona
"timer_system": {
  "round_duration": 22,  // 2 + 5 + 15 = 22
  "preparation_duration": 2,
  "persuasion_duration": 5,
  "voting_duration": 10
}
```

**Problema:** Sigues teniendo 2 timers corriendo en paralelo.

**Fix:** `round_duration: null`

### ❌ Error 3: No resetear current_statement/current_question

```php
// ❌ MAL - No limpiar estado entre rondas
protected function endCurrentRound(GameMatch $match): void
{
    $results = $this->getRoundResults($match);
    $this->completeRound($match, $results);
    // ← Falta limpiar current_statement
}
```

**Síntoma:** La siguiente ronda usa la misma pregunta/frase.

**Fix:**
```php
// ✅ BIEN - Limpiar antes de completar
protected function endCurrentRound(GameMatch $match): void
{
    $results = $this->getRoundResults($match);

    // Limpiar estado para siguiente ronda
    $gameState = $match->game_state;
    $gameState['current_statement'] = null;
    $match->game_state = $gameState;
    $match->save();

    $this->completeRound($match, $results);
}
```

**Mejor aún:** Usar el hook `onBeforeRoundComplete()` propuesto en ROUND_LIFECYCLE_STANDARDIZATION.md

---

## 🎓 Lecciones Aprendidas

### 1. Un Timer por Responsabilidad

> **"Si PhaseManager gestiona las fases, no necesitas round timer"**

- PhaseManager ya tiene timers internos
- Round timer es para juegos sin fases
- Usar ambos = conflicto garantizado

### 2. Null es Explícito

> **"`round_duration: null` comunica intención, omitir el campo no"**

- `round_duration: null` → "Intencionalmente no usar round timer"
- Campo omitido → "¿Olvido o decisión?"
- Ser explícito previene bugs

### 3. Configuración Declarativa

> **"La configuración debe declarar QUÉ, no CÓMO"**

- **Bueno:** `"round_duration": null` (declara: no usar timer)
- **Malo:** Lógica compleja para decidir si iniciar timer

### 4. Defense in Depth

> **"Locks + defensive checks + state cleanup = robustez"**

Aprendido de los bugs de Mentiroso:
- **Locks:** Previenen race conditions
- **Defensive checks:** Detectan estados inválidos
- **State cleanup:** Garantizan fresh start

---

## 🔄 Migración: Si Ya Tienes el Bug

### Paso 1: Identificar el Problema

```bash
# Ver logs para detectar el bug
tail -f storage/logs/laravel.log | grep "Round timer expired"

# Si ves esto y tu juego usa PhaseManager:
# [mentiroso] Round timer expired
# Tienes el bug
```

### Paso 2: Actualizar config.json

```json
{
  "timer_system": {
    "round_duration": null  // ← Cambiar de 15 a null
  }
}
```

### Paso 3: Limpiar Partidas Activas

```bash
# Las partidas activas todavía tienen el timer iniciado
# Opción 1: Reiniciar partidas
php artisan game:reset-active-matches

# Opción 2: Dejar que terminen naturalmente
```

### Paso 4: Verificar

```bash
# Crear nueva partida y verificar logs
tail -f storage/logs/laravel.log | grep -E "Round timer|Phase"

# Deberías ver:
# [Mentiroso] Phase callback executed
# NO deberías ver:
# [mentiroso] Round timer expired
```

---

## 📚 Referencias

- **PhaseManager:** `app/Services/Modules/TurnSystem/PhaseManager.php`
- **RoundManager:** `app/Services/Modules/RoundSystem/RoundManager.php`
- **BaseGameEngine:** `app/Contracts/BaseGameEngine.php:488-500`
- **Bug Original:** `docs/PHASE_SYSTEM_LEARNINGS.md`
- **Estandarización:** `docs/ROUND_LIFECYCLE_STANDARDIZATION.md`

---

## ✅ Conclusión

**Regla simple:**
```
PhaseManager = múltiples timers = round_duration: null
No PhaseManager = un timer = round_duration: <segundos>
```

**No hagas:**
- ❌ Usar round_duration con PhaseManager
- ❌ Asumir que config por defecto funciona
- ❌ Copiar config sin entender

**Haz:**
- ✅ Elegir explícitamente: round timer XOR PhaseManager
- ✅ Setear round_duration: null si usas PhaseManager
- ✅ Documentar la decisión en comentarios

**Resultado:**
- 🎮 Juegos robustos sin pérdida de datos
- 🐛 Menos bugs difíciles de detectar
- 📖 Código más claro y mantenible
