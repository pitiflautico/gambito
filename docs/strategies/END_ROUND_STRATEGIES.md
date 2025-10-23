# End Round Strategies - Strategy Pattern

**Versión:** 1.0
**Fecha:** 2025-10-23

## 🎯 Objetivo

Proporcionar un sistema extensible y mantenible para determinar cuándo finalizar rondas/turnos en juegos, permitiendo que cada modo de juego tenga su propia lógica de finalización sin modificar el `BaseGameEngine`.

---

## 📐 Problema Resuelto

### ❌ Antes (Sin Strategy Pattern)

```php
// BaseGameEngine::processAction() - Lógica hardcodeada
if ($turnMode === 'simultaneous') {
    // Lógica para simultaneous
    $playerResults = $this->getAllPlayerResults($match);
    $roundStatus = $roundManager->shouldEndSimultaneousRound($playerResults);
} elseif ($turnMode === 'sequential') {
    // Lógica para sequential
    $shouldEnd = $actionResult['should_end_turn'] ?? false;
    $roundStatus = ['should_end' => $shouldEnd, ...];
}
// ❌ ¿Y 'free'? ¿Y 'shuffle'? → No soportados
// ❌ Para agregar nuevo modo → Modificar BaseGameEngine
```

**Problemas:**
- ❌ Solo soporta 2 de 4 modos
- ❌ No es extensible
- ❌ Viola Open/Closed Principle
- ❌ No soporta juegos con múltiples fases

### ✅ Después (Con Strategy Pattern)

```php
// BaseGameEngine::processAction() - Usa estrategias
$strategy = $this->getEndRoundStrategy($turnMode);
$roundStatus = $strategy->shouldEnd($match, $actionResult, $roundManager, ...);

// Para agregar nuevo modo → Crear nueva Strategy
// Para modo custom → Sobrescribir getEndRoundStrategy()
```

**Beneficios:**
- ✅ Extensible para nuevos modos
- ✅ Cumple Open/Closed Principle
- ✅ Soporta modos custom
- ✅ Testeable de forma aislada

---

## 🏗️ Arquitectura

### **Interface: EndRoundStrategy**

```php
interface EndRoundStrategy
{
    public function shouldEnd(
        GameMatch $match,
        array $actionResult,
        RoundManager $roundManager,
        callable $getAllPlayerResults
    ): array;
}
```

**Retorna:**
```php
[
    'should_end' => bool,      // ¿Debe finalizar la ronda/turno?
    'reason' => string,        // Motivo: 'all_answered', 'turn_completed', etc.
    'delay_seconds' => int,    // Delay antes de siguiente ronda
]
```

---

## 📦 Estrategias Implementadas

### **1. SimultaneousEndStrategy**

**Modo:** `simultaneous`
**Juegos:** Trivia, Quiz
**Lógica:** Todos los jugadores actúan al mismo tiempo

**Termina cuando:**
- ✅ Primer jugador acierta (si `first_to_win: true`)
- ✅ Todos los jugadores respondieron
- ✅ Condición custom del juego

```php
$strategy = new SimultaneousEndStrategy([
    'first_to_win' => true,    // Terminar al primer acierto
    'delay_seconds' => 5,      // Delay antes de siguiente ronda
]);
```

**Ejemplo de uso:**
```php
// TriviaEngine usa modo simultaneous por defecto
protected function getEndRoundStrategy(string $turnMode): EndRoundStrategy
{
    return match ($turnMode) {
        'simultaneous' => new SimultaneousEndStrategy([
            'first_to_win' => false,  // En trivia, esperar a que todos respondan
            'delay_seconds' => 3,
        ]),
        default => parent::getEndRoundStrategy($turnMode),
    };
}
```

---

### **2. SequentialEndStrategy**

**Modo:** `sequential`, `shuffle`
**Juegos:** Pictionary, UNO
**Lógica:** Jugadores actúan por turnos

**Termina cuando:**
- ✅ El juego lo decide (retorna `should_end_turn: true`)
- ✅ Timeout del turno
- ✅ Jugador completa su acción

```php
$strategy = new SequentialEndStrategy([
    'delay_seconds' => 3,      // Delay antes de siguiente turno
    'auto_advance' => false,   // Avanzar automáticamente si no hay acción
]);
```

**Ejemplo de uso:**
```php
// PictionaryEngine::confirmAnswer()
return [
    'success' => true,
    'is_correct' => $isCorrect,
    'should_end_turn' => true,  // ← El juego decide cuándo terminar
    'delay_seconds' => 3,
];
```

---

### **3. FreeEndStrategy**

**Modo:** `free`
**Juegos:** Juegos de mesa libres, tiempo real, asíncronos
**Lógica:** No hay turnos fijos, jugadores actúan cuando quieran

**Termina cuando:**
- ✅ El juego lo decide (retorna `should_end_round: true`)
- ✅ Condición de victoria alcanzada
- ✅ Timeout global

```php
$strategy = new FreeEndStrategy([
    'delay_seconds' => 3,
    'allow_simultaneous' => true,  // Permitir acciones simultáneas
]);
```

**Ejemplo de uso:**
```php
// BattleRoyaleEngine (ejemplo futuro)
return [
    'success' => true,
    'winner_id' => $winnerId,
    'should_end_round' => $this->hasWinner($match),  // ← El juego decide
    'delay_seconds' => 5,
];
```

---

## 🔧 Cómo Usar

### **1. Uso por Defecto**

BaseGameEngine ya provee estrategias para todos los modos estándar:

```php
// En tu Engine, no necesitas hacer nada especial
class MyGameEngine extends BaseGameEngine
{
    // Automáticamente usa la estrategia correcta según el modo
}
```

### **2. Configurar Estrategia**

Sobrescribir `getEndRoundStrategy()` para personalizar:

```php
class TriviaEngine extends BaseGameEngine
{
    protected function getEndRoundStrategy(string $turnMode): EndRoundStrategy
    {
        return match ($turnMode) {
            'simultaneous' => new SimultaneousEndStrategy([
                'first_to_win' => false,  // Esperar a que todos respondan
                'delay_seconds' => 3,
            ]),
            default => parent::getEndRoundStrategy($turnMode),
        };
    }
}
```

### **3. Crear Estrategia Custom**

Para modos custom o lógica especial:

```php
class TeamBattleEndStrategy implements EndRoundStrategy
{
    public function shouldEnd(
        GameMatch $match,
        array $actionResult,
        RoundManager $roundManager,
        callable $getAllPlayerResults
    ): array {
        // Lógica custom para modo equipos
        $teamResults = $this->aggregateByTeam($getAllPlayerResults($match));

        $allTeamsReady = count($teamResults) === $this->totalTeams;

        return [
            'should_end' => $allTeamsReady,
            'reason' => 'all_teams_ready',
            'delay_seconds' => 5,
        ];
    }

    private function aggregateByTeam(array $playerResults): array
    {
        // Agrupar resultados por equipo
        // ...
    }
}
```

**Uso:**
```php
class TeamBattleEngine extends BaseGameEngine
{
    protected function getEndRoundStrategy(string $turnMode): EndRoundStrategy
    {
        return new TeamBattleEndStrategy();
    }
}
```

---

## 🎮 Casos de Uso Avanzados

### **1. Múltiples Fases con Diferentes Modos**

**Ejemplo: Mafia/Werewolf**

```php
class MafiaEngine extends BaseGameEngine
{
    protected function getEndRoundStrategy(string $turnMode): EndRoundStrategy
    {
        $gameState = $this->getCurrentMatch()->game_state;
        $phase = $gameState['current_phase']; // 'day' o 'night'

        return match ($phase) {
            'day' => new SimultaneousEndStrategy([
                'first_to_win' => false,  // Todos votan
            ]),
            'night' => new SequentialEndStrategy([
                'auto_advance' => true,   // Mafia elige víctima
            ]),
            default => parent::getEndRoundStrategy($turnMode),
        };
    }
}
```

### **2. Condiciones de Victoria Custom**

**Ejemplo: Battle Royale**

```php
class BattleRoyaleEndStrategy implements EndRoundStrategy
{
    public function shouldEnd(...): array
    {
        $activePlayers = $roundManager->getActivePlayers();

        // Terminar cuando queda 1 jugador
        if (count($activePlayers) === 1) {
            return [
                'should_end' => true,
                'reason' => 'winner_determined',
                'delay_seconds' => 5,
            ];
        }

        // Continuar jugando
        return [
            'should_end' => false,
            'reason' => 'battle_ongoing',
            'delay_seconds' => 0,
        ];
    }
}
```

### **3. Timeout Automático**

**Ejemplo: Speed Chess**

```php
class TimeoutEndStrategy implements EndRoundStrategy
{
    private int $maxSeconds;

    public function __construct(int $maxSeconds = 30)
    {
        $this->maxSeconds = $maxSeconds;
    }

    public function shouldEnd(...): array
    {
        $gameState = $match->game_state;
        $turnStarted = $gameState['turn_started_at'] ?? null;

        if ($turnStarted) {
            $elapsed = now()->diffInSeconds($turnStarted);

            if ($elapsed >= $this->maxSeconds) {
                return [
                    'should_end' => true,
                    'reason' => 'timeout',
                    'delay_seconds' => 2,
                ];
            }
        }

        // Delegar a lógica normal
        return [
            'should_end' => $actionResult['should_end_turn'] ?? false,
            'reason' => 'action_completed',
            'delay_seconds' => 3,
        ];
    }
}
```

---

## 📊 Tabla Comparativa

| Estrategia | Modo(s) | Quién Decide | Cuándo Termina |
|-----------|---------|--------------|----------------|
| **SimultaneousEndStrategy** | `simultaneous` | RoundManager | Todos respondieron o primer acierto |
| **SequentialEndStrategy** | `sequential`, `shuffle` | Engine del juego | `should_end_turn: true` |
| **FreeEndStrategy** | `free` | Engine del juego | `should_end_round: true` |

---

## ✅ Checklist para Crear Nueva Estrategia

- [ ] Implementar interface `EndRoundStrategy`
- [ ] Definir lógica en `shouldEnd()`
- [ ] Retornar array con `should_end`, `reason`, `delay_seconds`
- [ ] Documentar cuándo termina la ronda/turno
- [ ] Crear tests unitarios para la estrategia
- [ ] Sobrescribir `getEndRoundStrategy()` en el Engine
- [ ] Verificar que tests del juego pasen

---

## 🧪 Testing

### **Test de Estrategia**

```php
class SimultaneousEndStrategyTest extends TestCase
{
    public function test_ends_when_all_players_answered()
    {
        $strategy = new SimultaneousEndStrategy();

        $playerResults = [
            1 => ['success' => true],
            2 => ['success' => false],
            3 => ['success' => true],
        ];

        $result = $strategy->shouldEnd(
            $match,
            $actionResult,
            $roundManager,
            fn() => $playerResults
        );

        $this->assertTrue($result['should_end']);
        $this->assertEquals('all_answered', $result['reason']);
    }
}
```

---

## 📚 Referencias

- `app/Contracts/Strategies/EndRoundStrategy.php` - Interface
- `app/Contracts/Strategies/SimultaneousEndStrategy.php` - Modo simultáneo
- `app/Contracts/Strategies/SequentialEndStrategy.php` - Modo secuencial
- `app/Contracts/Strategies/FreeEndStrategy.php` - Modo libre
- `app/Contracts/BaseGameEngine.php` - Uso de estrategias
- `docs/ENGINE_ARCHITECTURE.md` - Arquitectura general

---

**Última actualización:** 2025-10-23
