# Arquitectura Round/Turn System

## Separación de Responsabilidades

En la nueva arquitectura, hemos separado claramente dos conceptos que antes estaban mezclados:

### RoundManager (Módulo de Rondas)
**Responsabilidad:** Gestionar el ciclo completo de rondas del juego

**Funcionalidades:**
- Contar rondas (actual, total)
- Detectar fin de juego por rondas
- Eliminar jugadores (permanente/temporal)
- Auto-limpiar eliminaciones temporales al completar ronda
- Delegar turnos a TurnManager

**Ubicación:** `app/Services/Modules/RoundSystem/RoundManager.php`

**Tests:** `tests/Unit/Services/Modules/RoundSystem/RoundManagerTest.php` (15 tests, 59 assertions)

### TurnManager (Módulo de Turnos)
**Responsabilidad:** SOLO gestionar turnos (quién juega ahora)

**Funcionalidades:**
- Crear orden de turnos (sequential, shuffle, simultaneous, free)
- Avanzar al siguiente turno
- Detectar cuando se completa un ciclo (todos jugaron una vez)
- Pausar/reanudar turnos
- Invertir dirección (para juegos como UNO)
- Saltar turnos

**Ubicación:** `app/Services/Modules/TurnSystem/TurnManager.php`

**Tests:** Tests simplificados (eliminados tests de rondas/eliminaciones)

---

## Conceptos Clave

### ¿Qué es una Ronda?
Una **ronda** es un ciclo completo donde todos los jugadores han participado una vez.

Ejemplo en Pictionary con 4 jugadores:
- **Ronda 1:** Jugador 1 dibuja → Jugador 2 dibuja → Jugador 3 dibuja → Jugador 4 dibuja
- **Ronda 2:** Jugador 1 dibuja → ... (y así sucesivamente)

### ¿Qué es un Turno?
Un **turno** es el momento específico en que un jugador está activo.

Ejemplo:
- Ahora es el turno del Jugador 2 (índice 1)
- Siguiente turno será del Jugador 3 (índice 2)

### ¿Qué es un Ciclo?
Un **ciclo** (en TurnManager) es equivalente a una ronda. Cuando TurnManager detecta que completó un ciclo (todos jugaron), RoundManager incrementa el contador de rondas.

---

## Relación entre Módulos

```
RoundManager
  ├── Contiene TurnManager internamente
  ├── Detecta cuando TurnManager completa un ciclo
  ├── Incrementa currentRound
  └── Limpia eliminaciones temporales

TurnManager
  ├── Solo sabe de turnos (índices, orden)
  ├── Detecta cuando completa un ciclo
  └── NO sabe de rondas ni eliminaciones
```

---

## Ejemplo de Uso en Pictionary

```php
// Inicializar
$turnManager = new TurnManager(
    playerIds: [1, 2, 3, 4],
    mode: 'sequential'
);

$roundManager = new RoundManager(
    turnManager: $turnManager,
    totalRounds: 5,
    currentRound: 1
);

// Avanzar turno
$turnInfo = $roundManager->nextTurn();
// $turnInfo = ['player_id' => 2, 'turn_index' => 1, 'cycle_completed' => false]

// Después de 4 turnos, se completa la ronda
$roundManager->nextTurn(); // player_id: 3
$roundManager->nextTurn(); // player_id: 4
$roundManager->nextTurn(); // player_id: 1, cycle_completed: true

// RoundManager detecta que se completó un ciclo
if ($roundManager->isNewRound()) {
    echo "¡Nueva ronda! Ahora en ronda " . $roundManager->getCurrentRound();
    // Eliminaciones temporales se limpian automáticamente
}

// Verificar fin de juego
if ($roundManager->isGameComplete()) {
    echo "¡Juego terminado!";
}
```

---

## Tipos de Eliminación

### Eliminación Permanente
El jugador queda fuera del juego para siempre.

**Casos de uso:**
- Battle Royale
- Mafia (cuando matan a un jugador)
- Werewolf

```php
$roundManager->eliminatePlayer($playerId, permanent: true);
```

### Eliminación Temporal
El jugador queda fuera temporalmente. Por defecto, se restaura automáticamente al comenzar la siguiente ronda.

**Casos de uso estándar:**
- Battle Royale (jugador fuera de una ronda)
- Trivia (jugador que responde mal en esta ronda)

```php
$roundManager->eliminatePlayer($playerId, permanent: false);

// Al completar ronda, se limpia automáticamente
$roundManager->nextTurn(); // ...
if ($roundManager->isNewRound()) {
    // temporarilyEliminated se limpia automáticamente
}
```

**Casos de uso con lógica específica:**

Algunos juegos necesitan limpiar eliminaciones temporales en cada TURNO, no solo al completar RONDA:

- **Pictionary**: Cada turno es independiente. Los jugadores que respondieron incorrectamente pueden volver a responder en el siguiente turno (nuevo dibujante).

```php
// En PictionaryEngine::nextTurn()
$roundManager->nextTurn();

// IMPORTANTE: En Pictionary, limpiar en cada turno
// (no esperar a que complete la ronda)
$roundManager->clearTemporaryEliminations();
```

Esta flexibilidad permite que cada juego defina su propia lógica de eliminación temporal según sus reglas específicas.

---

## Serialización

### RoundManager
```php
$data = $roundManager->toArray();
/*
[
    'current_round' => 2,
    'total_rounds' => 5,
    'permanently_eliminated' => [3],
    'temporarily_eliminated' => [1, 2],
    'turn_system' => [
        'turn_order' => [1, 2, 3, 4],
        'current_turn_index' => 1,
        'mode' => 'sequential',
        'is_paused' => false,
        'direction' => 1,
    ]
]
*/

$roundManager = RoundManager::fromArray($data);
```

### Acceso al TurnManager Interno
```php
// Acceso directo a métodos comunes
$roundManager->getCurrentPlayer(); // Delega a turnManager
$roundManager->getTurnOrder();
$roundManager->isPlayerTurn($playerId);
$roundManager->pause();
$roundManager->resume();

// Acceso al TurnManager si necesitas algo específico
$turnManager = $roundManager->getTurnManager();
$turnManager->reverse(); // Invertir dirección
$turnManager->skipTurn();
```

---

## Migración desde TurnManager Antiguo

### Antes (TurnManager con todo mezclado)
```php
$turnManager = new TurnManager(
    playerIds: [1, 2, 3],
    mode: 'sequential',
    totalRounds: 5,
    startingRound: 1
);

$turnManager->eliminatePlayer($playerId, permanent: false);
$turnManager->getCurrentRound();
$turnManager->isGameComplete();
```

### Ahora (Separación Round/Turn)
```php
$turnManager = new TurnManager(
    playerIds: [1, 2, 3],
    mode: 'sequential'
);

$roundManager = new RoundManager(
    turnManager: $turnManager,
    totalRounds: 5,
    currentRound: 1
);

$roundManager->eliminatePlayer($playerId, permanent: false);
$roundManager->getCurrentRound();
$roundManager->isGameComplete();
```

**Cambios clave:**
1. `totalRounds` y `currentRound` ahora van en RoundManager
2. Eliminaciones van en RoundManager
3. TurnManager solo gestiona turnos
4. `isGameComplete()` está en RoundManager (verifica rondas)
5. Game engines pueden agregar lógica adicional de fin de juego

---

## Beneficios de la Nueva Arquitectura

✅ **Separación de Responsabilidades**
- Cada módulo hace UNA cosa bien
- Más fácil de entender y mantener

✅ **Reutilizable**
- TurnManager puro puede usarse sin rondas
- RoundManager puede usarse con diferentes modos de turnos

✅ **Flexible**
- Juegos complejos (Mafia, Werewolf) pueden tener múltiples fases por ronda
- Cada juego decide su lógica de fin de juego

✅ **Testeable**
- Tests independientes para cada módulo
- 15 tests de RoundManager (eliminación, rondas, delegación)
- Tests simplificados de TurnManager (solo turnos)

✅ **Escalable**
- Preparado para juegos futuros
- Arquitectura robusta y profesional

---

## Casos de Uso por Juego

### Pictionary
- **Rondas:** 5 rondas configurables
- **Eliminación:** Temporal por TURNO (jugadores que responden mal pueden volver a responder en el siguiente turno)
- **Turnos:** Sequential (cada jugador dibuja en orden)
- **Fin de juego:** Al completar todas las rondas
- **Lógica especial:** Limpia eliminaciones temporales en cada turno (no al completar ronda)

### Battle Royale (futuro)
- **Rondas:** Infinitas (totalRounds: 0)
- **Eliminación:** Permanente (jugadores derrotados)
- **Turnos:** Simultaneous (todos juegan a la vez)
- **Fin de juego:** Cuando queda 1 jugador activo

### Trivia (futuro)
- **Rondas:** N rondas configurables
- **Eliminación:** Ninguna o permanente
- **Turnos:** Simultaneous (todos responden)
- **Fin de juego:** Al completar todas las rondas

### Mafia/Werewolf (futuro)
- **Rondas:** Infinitas hasta eliminar mafia o civiles
- **Eliminación:** Permanente (votaciones)
- **Turnos:** Por fase (día/noche)
- **Fin de juego:** Cuando se eliminan todos de un bando

---

## Próximos Pasos

1. ✅ RoundManager creado (15/15 tests pasando)
2. ✅ TurnManager simplificado
3. ⏳ Refactorizar Pictionary completamente (en progreso)
4. ⬜ Actualizar tests de Pictionary
5. ⬜ Crear juegos adicionales usando esta arquitectura
6. ⬜ Documentar patrones de uso avanzados

---

**Versión:** 1.0
**Fecha:** 2025-01-21
**Autor:** Claude Code
