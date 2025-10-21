# Pictionary

**Estado:** 🚧 En desarrollo (Fase 3 - MVP Monolítico)
**Versión:** 1.0
**Autor:** Gambito
**Tipo:** Drawing
**Jugadores:** 3-10
**Duración:** 15-20 minutos

---

## Descripción

Dibuja y adivina palabras antes que los demás. Un jugador dibuja mientras el resto intenta adivinar la palabra secreta.

En esta versión monolítica (Fase 3), toda la lógica está contenida en `PictionaryEngine.php`. En Fase 4 se extraerán los módulos opcionales (Turn System, Scoring, Timer, Roles).

---

## Cómo Funciona

### Flujo del Juego

1. **Lobby**: Los jugadores se unen a la sala
2. **Inicio**: Se genera orden de turnos aleatorio
3. **Turno de Dibujo**:
   - Un jugador recibe la palabra secreta (dibujante)
   - Tiene 90 segundos para dibujarla en el canvas
   - Los demás jugadores intentan adivinar
4. **Intento de Respuesta**:
   - Jugador escribe su respuesta
   - El dibujante la ve y confirma si es correcta o incorrecta
   - Si es correcta: Se otorgan puntos y termina el turno
   - Si es incorrecta: El jugador queda eliminado de esta ronda
5. **Siguiente Turno**: Siguiente jugador en el orden se convierte en dibujante
6. **Final**: Después de X rondas, el jugador con más puntos gana

### Roles

- **Dibujante** (1 jugador por turno):
  - Ve la palabra secreta
  - Dibuja en el canvas
  - Confirma respuestas correctas/incorrectas

- **Adivinadores** (resto de jugadores):
  - Ven el canvas en tiempo real
  - NO ven la palabra
  - Escriben sus respuestas

---

## Configuración del Juego

**Archivo:** `games/pictionary/config.json`

```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "slug": "pictionary",
  "description": "Dibuja y adivina palabras antes que los demás...",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito"
}
```

---

## Módulos Utilizados (Fase 3 - Monolítico)

En esta versión, toda la funcionalidad está en `PictionaryEngine.php`. No usa módulos opcionales todavía.

**Funcionalidad implementada de forma monolítica:**
- ✅ **Game Core**: Ciclo de vida del juego
- ✅ **Turn System**: Gestión de turnos (dentro del Engine)
- ✅ **Scoring System**: Puntuación (dentro del Engine)
- ✅ **Timer System**: Temporizador de 90s (dentro del Engine)
- ✅ **Roles System**: Dibujante vs Adivinadores (dentro del Engine)
- 🚧 **Real-time Sync**: WebSockets con Reverb (Task 7.0)

**En Fase 4 se extraerán a módulos opcionales:**
- [ ] `modules/optional/turn-system/`
- [ ] `modules/optional/scoring-system/`
- [ ] `modules/optional/timer-system/`
- [ ] `modules/optional/roles-system/`

---

## Capabilities

**Archivo:** `games/pictionary/capabilities.json`

```json
{
  "slug": "pictionary",
  "version": "1.0",
  "requires": {},
  "provides": {
    "events": [],
    "routes": [],
    "views": []
  }
}
```

**Nota:** En Fase 3 (monolítico), no requiere módulos externos. En Fase 4 se actualizará:

```json
{
  "slug": "pictionary",
  "version": "1.0",
  "requires": {
    "modules": {
      "turn-system": "^1.0",
      "scoring-system": "^1.0",
      "timer-system": "^1.0",
      "roles-system": "^1.0",
      "realtime-sync": "^1.0"
    }
  },
  "provides": {
    "events": ["CanvasDrawEvent", "WordGuessedEvent"],
    "routes": [],
    "views": ["pictionary.canvas"]
  }
}
```

---

## Motor del Juego (Engine)

**Clase:** `Games\Pictionary\PictionaryEngine`
**Implementa:** `App\Contracts\GameEngineInterface`

### Métodos Implementados

#### `initialize(GameMatch $match): void`

Inicializa el juego cuando comienza una partida.

**Setup inicial:**
- Cargar palabras desde `assets/words.json`
- Asignar orden de turnos aleatorio
- Inicializar puntuaciones en 0
- Establecer ronda 1, turno 1

**Estado inicial del juego:**

```php
[
    'phase' => 'lobby',
    'round' => 0,
    'current_turn' => 0,
    'current_drawer_id' => null,
    'current_word' => null,
    'turn_order' => [], // IDs de jugadores en orden
    'words_used' => [],
    'eliminated_this_round' => [],
    'rounds_total' => 5,
]
```

**Ubicación:** `games/pictionary/PictionaryEngine.php:32`

---

#### `processAction(GameMatch $match, Player $player, string $action, array $data): array`

Procesa acciones de los jugadores.

**Acciones soportadas:**
- `'draw'`: Trazo en el canvas (Task 7.0 - WebSockets)
- `'answer'`: Jugador intenta responder
- `'confirm_answer'`: Dibujante confirma si respuesta es correcta

**Ubicación:** `games/pictionary/PictionaryEngine.php:70`

---

#### `checkWinCondition(GameMatch $match): ?Player`

Verifica si hay un ganador.

**Condición de victoria:** El jugador con más puntos después de X rondas.

**Ubicación:** `games/pictionary/PictionaryEngine.php:96`

---

#### `getGameStateForPlayer(GameMatch $match, Player $player): array`

Obtiene el estado del juego para un jugador específico.

**Información visible según rol:**
- **Dibujante**: Ve la palabra secreta, canvas, tiempo restante
- **Adivinadores**: Ven canvas, jugadores, NO ven la palabra

**Ubicación:** `games/pictionary/PictionaryEngine.php:122`

---

#### `advancePhase(GameMatch $match): void`

Avanza a la siguiente fase/ronda del juego.

**Fases:**
1. `lobby` → `drawing` (al iniciar)
2. `drawing` → `scoring` (al terminar turno)
3. `scoring` → `drawing` (siguiente turno) o → `results` (fin de partida)

**Ubicación:** `games/pictionary/PictionaryEngine.php:155`

---

#### `handlePlayerDisconnect(GameMatch $match, Player $player): void`

Maneja la desconexión de un jugador.

**Estrategia:**
- Si es el dibujante: Pausar turno, esperar 2 min, si no vuelve → skip turno
- Si es adivinador: Marcar como desconectado, puede reconectar

**Ubicación:** `games/pictionary/PictionaryEngine.php:172`

---

#### `handlePlayerReconnect(GameMatch $match, Player $player): void`

Maneja la reconexión de un jugador.

**Ubicación:** `games/pictionary/PictionaryEngine.php:189`

---

#### `finalize(GameMatch $match): array`

Finaliza la partida.

Calcula puntuaciones finales, determina ganador, genera estadísticas.

**Ubicación:** `games/pictionary/PictionaryEngine.php:207`

---

### Métodos Privados (Implementados)

#### `selectRandomWord(GameMatch $match, string $difficulty = 'random'): ?string`

Selecciona una palabra aleatoria que no haya sido usada.

**Ubicación:** `games/pictionary/PictionaryEngine.php:557`

**Parámetros:**
- `$difficulty`: 'easy', 'medium', 'hard', o 'random'

**Retorna:** Palabra seleccionada o `null` si no hay palabras disponibles

**Lógica:**
- Si es 'random', elige dificultad aleatoria
- Filtra palabras ya usadas (`words_used`)
- Selecciona aleatoriamente de las disponibles
- Si no hay palabras, retorna `null`

---

#### `nextTurn(GameMatch $match): void`

Avanza al siguiente turno del juego.

**Ubicación:** `games/pictionary/PictionaryEngine.php:591`

**Lógica:**
- Incrementa turno circular (`% count(turnOrder)`)
- Si vuelve a 0, incrementa ronda
- Selecciona siguiente dibujante del `turn_order`
- Selecciona nueva palabra aleatoria
- Limpia `eliminated_this_round`
- Limpia `pending_answer`
- Actualiza `turn_started_at`

---

#### `calculatePointsByTime(int $secondsElapsed, array $gameState): int`

Calcula puntos para el adivinador según velocidad de respuesta.

**Ubicación:** `games/pictionary/PictionaryEngine.php:649`

**Sistema de puntuación:**
```
0-30s  (rápido): 150 puntos
31-60s (normal): 100 puntos
61-90s (lento):  50 puntos
>90s   (tarde):  0 puntos
```

**Parámetros:**
- `$secondsElapsed`: Tiempo transcurrido desde inicio del turno
- `$gameState`: Estado actual (usa `turn_duration`)

**Retorna:** Puntos calculados (int)

---

#### `getDrawerPointsByTime(int $secondsElapsed, array $gameState): int`

Calcula puntos para el dibujante cuando alguien adivina.

**Ubicación:** `games/pictionary/PictionaryEngine.php:683`

**Sistema de puntuación:**
```
0-30s  (rápido): 50 puntos
31-60s (normal): 30 puntos
61-90s (lento):  10 puntos
>90s   (tarde):  0 puntos
```

El dibujante recibe menos puntos que el adivinador.

---

### Métodos Privados (Con TODOs)

#### `handleDrawAction(GameMatch $match, Player $player, array $data): array`

Maneja acción de dibujar en el canvas.

**TODO (Task 7.0 - WebSockets):**
- Validar que el jugador es el dibujante
- Broadcast del trazo a todos los espectadores

**Ubicación:** `games/pictionary/PictionaryEngine.php:230`

---

#### `handleAnswerAction(GameMatch $match, Player $player, array $data): array`

Maneja intento de respuesta de un adivinador.

**TODO (Task 6.0):**
- Validar que el jugador no es el dibujante
- Validar que no está eliminado en esta ronda
- Notificar al dibujante para confirmación

**Ubicación:** `games/pictionary/PictionaryEngine.php:242`

---

#### `handleConfirmAnswer(GameMatch $match, Player $player, array $data): array`

Maneja confirmación del dibujante (respuesta correcta/incorrecta).

**TODO (Task 6.0):**
- Validar que el jugador es el dibujante
- Si es correcta: Calcular puntos, terminar ronda
- Si es incorrecta: Eliminar jugador de esta ronda

**Ubicación:** `games/pictionary/PictionaryEngine.php:255`

---

## Assets

### Palabras (`assets/words.json`)

**Archivo:** `games/pictionary/assets/words.json`

Contiene 120 palabras en español distribuidas en 3 niveles de dificultad:

- **Fácil (40 palabras)**: "casa", "perro", "gato", "sol", "luna", etc.
- **Medio (40 palabras)**: "bicicleta", "guitarra", "computadora", etc.
- **Difícil (40 palabras)**: "arquitecto", "dinosaurio", "paleontólogo", etc.

**Estructura:**

```json
{
  "easy": ["casa", "perro", "gato", ...],
  "medium": ["bicicleta", "guitarra", ...],
  "hard": ["arquitecto", "dinosaurio", ...]
}
```

---

## Vistas

**Directorio:** `resources/views/games/pictionary/`
**Assets:** `public/games/pictionary/{css,js}/`

### `canvas.blade.php` ✅

Vista principal del canvas de dibujo.

**Ubicación:** `resources/views/games/pictionary/canvas.blade.php`

**Componentes implementados:**
- ✅ Canvas HTML5 (800x600px) para dibujar
- ✅ Header con nombre de sala, código, ronda y temporizador
- ✅ Palabra secreta (visible solo para dibujante)
- ✅ Herramientas de dibujo:
  - Lápiz y borrador
  - Paleta de 12 colores
  - 4 tamaños de pincel (2px, 5px, 10px, 20px)
  - Botón limpiar canvas
- ✅ Panel de jugadores con puntuaciones
- ✅ Panel de respuestas con input para adivinadores
- ✅ Botones de confirmación para dibujante (correcta/incorrecta)
- ✅ Modales para resultados de ronda y finales
- ✅ Diseño responsive (desktop, tablet, móvil)

**Ruta de demo:** `/pictionary/demo`

---

## JavaScript

**Archivo:** `public/games/pictionary/js/canvas.js`

### Clase `PictionaryCanvas` ✅

Lógica completa del canvas de dibujo.

**Funcionalidades implementadas:**
- ✅ Capturar eventos de mouse/touch (soporte móvil)
- ✅ Dibujar líneas suaves en el canvas
- ✅ Cambiar herramientas (lápiz/borrador)
- ✅ Selector de colores (12 colores)
- ✅ Selector de grosor (4 tamaños)
- ✅ Limpiar canvas
- ✅ Gestión de roles (dibujante/adivinador)
- ✅ Submit de respuestas
- ✅ Confirmación de respuestas
- ✅ Actualización de lista de jugadores
- ✅ Actualización de temporizador
- ✅ Mostrar resultados de ronda y finales
- ✅ Modo demo automático (detecta `/demo` en URL)

**Métodos principales:**
- `startDrawing(e)` - Inicia trazo
- `draw(e)` - Dibuja línea
- `stopDrawing()` - Termina trazo
- `setTool(tool)` - Cambia herramienta
- `setColor(color)` - Cambia color
- `setSize(size)` - Cambia grosor
- `clearCanvas()` - Limpia canvas
- `setRole(isDrawer, word)` - Establece rol de jugador
- `submitAnswer()` - Envía respuesta
- `confirmAnswer(isCorrect)` - Confirma respuesta
- `updatePlayersList(players)` - Actualiza jugadores
- `updateTimer(seconds)` - Actualiza temporizador
- `showRoundResults(results)` - Muestra resultados ronda
- `showFinalResults(results)` - Muestra resultados finales

**TODOs para Task 6.0:**
- Conectar `submitAnswer()` con endpoint del servidor
- Conectar `confirmAnswer()` con endpoint del servidor

**TODOs para Task 7.0:**
- `drawRemoteStroke(data)` - Dibujar trazos remotos
- Emitir eventos WebSocket de trazos
- Conectar WebSocket para sincronización

---

## Eventos WebSocket (TODO: Task 7.0)

**Directorio:** `games/pictionary/Events/`

### `CanvasDrawEvent` (TODO)

Evento para sincronizar trazos del canvas en tiempo real.

**Datos:**
- `match_id`: ID de la partida
- `drawer_id`: ID del dibujante
- `stroke_data`: Coordenadas del trazo

---

### `WordGuessedEvent` (TODO)

Evento cuando un jugador adivina correctamente.

**Datos:**
- `match_id`: ID de la partida
- `player_id`: ID del jugador que adivinó
- `word`: Palabra adivinada
- `points_awarded`: Puntos otorgados

---

## Servicios (TODO: Fase 4)

**Directorio:** `games/pictionary/Services/`

En Fase 4 se crearán servicios específicos cuando se extraigan módulos:

- `TurnService.php`: Gestión de turnos
- `ScoringService.php`: Cálculo de puntos
- `TimerService.php`: Temporizadores
- `WordService.php`: Selección de palabras

---

## Testing (TODO: Task 8.0)

### Unit Tests

**Tests a crear:**
- `PictionaryEngineTest.php`: Tests del motor
- `WordSelectionTest.php`: Tests de selección de palabras

### Feature Tests

**Tests a crear:**
- `PictionaryGameFlowTest.php`: Flujo completo del juego
- `CanvasSyncTest.php`: Sincronización del canvas

---

## Roadmap de Implementación

### ✅ Task 4.0 - Pictionary Game Structure (COMPLETADO)

- ✅ Crear carpeta `games/pictionary/`
- ✅ Crear `config.json`
- ✅ Crear `capabilities.json` (versión monolítica)
- ✅ Crear `PictionaryEngine.php` (esqueleto con TODOs)
- ✅ Crear `assets/words.json` (120 palabras)
- ✅ Registrar con GameRegistry
- ✅ Añadir namespace `Games\` a composer autoload

---

### ✅ Task 5.0 - Pictionary Canvas System (COMPLETADO)

**Implementado:**
- ✅ Vista `canvas.blade.php` (standalone para demo)
- ✅ JavaScript `canvas.js` - Clase `PictionaryCanvas` completa
- ✅ CSS `canvas.css` - Diseño responsive moderno
- ✅ Herramientas de dibujo: lápiz, borrador, 12 colores, 4 tamaños
- ✅ Botón limpiar canvas
- ✅ Soporte mouse y touch (móviles)
- ✅ Panel de jugadores y respuestas
- ✅ Modales de resultados
- ✅ Controlador `PictionaryController` con método `demo()`
- ✅ Ruta `/pictionary/demo` para visualización
- ✅ Assets copiados a `public/games/pictionary/`
- ✅ Vista copiada a `resources/views/games/pictionary/`
- ✅ Modo demo funcional (auto-habilita dibujo)
- ✅ Validado en navegador: dibujo funcional

---

### ✅ Task 6.0 - Pictionary Game Logic (Monolítico) - **COMPLETADO**

**Estado:** ✅ Implementación completa

#### ✅ Sub-tareas completadas:

**6.1 - Selección aleatoria de palabras** ✅
- Método `selectRandomWord()` implementado
- Carga desde `game_state['words_available']`
- Evita repetición con `words_used`
- Soporte 3 dificultades: easy, medium, hard

**6.2 - Sistema de turnos** ✅
- Método `nextTurn()` implementado
- Rotación circular de jugadores
- Incremento automático de rondas
- Limpia `eliminated_this_round` cada turno

**6.3 - Asignación de roles (drawer/guesser)** ✅
- Campo `current_drawer_id` en `game_state`
- `advancePhase()` asigna primer dibujante
- `nextTurn()` rota dibujantes automáticamente

**6.4 - Sistema de puntuación** ✅
- Inicialización de scores en `initialize()`
- Campo `scores` en `game_state`
- `checkWinCondition()` encuentra ganador

**6.6 - Botón "¡Ya lo sé!" y confirmación** ✅
- Frontend: Botón implementado en `canvas.js`
- Frontend: Panel de confirmación para dibujante
- Frontend: Método `pressYoSe()` y `confirmAnswer()`
- Backend: Métodos `handleAnswerAction()` y `handleConfirmAnswer()` (con TODOs)

**6.7 - Eliminación de jugadores en ronda** ✅
- Frontend: Método `markAsEliminated()` implementado
- Frontend: Panel rojo visual de eliminación
- Frontend: Sincronización vía localStorage (temporal)
- Frontend: Input deshabilitado, botón "YO SÉ" oculto
- Backend: Campo `eliminated_this_round` en `game_state`

**6.8 - Cálculo de puntos según tiempo** ✅
- Método `calculatePointsByTime()` implementado
- Sistema de puntuación por velocidad:
  - 0-30s: 150 puntos
  - 31-60s: 100 puntos
  - 61-90s: 50 puntos
- Método `getDrawerPointsByTime()` para dibujante

**6.9 - Condición de victoria** ✅
- Método `checkWinCondition()` implementado
- Encuentra jugador con mayor puntuación
- Se ejecuta cuando `round >= rounds_total`

**6.5 - Timer de 90 segundos** ✅
- ✅ Campo `turn_duration: 90` en `game_state`
- ✅ Campo `turn_started_at` guardado al iniciar turno
- ✅ Frontend: método `updateTimer(seconds)` implementado
- ✅ Backend: cálculo de `time_remaining` en `getGameStateForPlayer()`
- ✅ Backend: uso del tiempo en cálculo de puntos
- 📝 Nota: Timer automático con Jobs/Queue se implementará con WebSockets (Task 7.0)

**6.10 - Métodos completados** ✅
- ✅ `processAction()` - Enruta acciones a handlers correctos
- ✅ `getGameStateForPlayer()` - Retorna estado completo personalizado por rol
- ✅ `handlePlayerDisconnect()` - Pausa juego si es dibujante, continúa si es adivinador
- ✅ `handleAnswerAction()` - Validaciones completas (no dibujante, no eliminado, fase correcta)
- ✅ `handleConfirmAnswer()` - Calcula y otorga puntos según tiempo transcurrido

#### 📝 Notas de implementación:

- Todos los métodos públicos de `GameEngineInterface` están implementados
- Todos los métodos privados auxiliares están completos
- Solo quedan TODOs para Task 7.0 (WebSockets/Broadcasting)
- El juego funciona completamente sin WebSockets (modo demo con localStorage)

---

### ⏳ Task 7.0 - Pictionary Real-time Sync (WebSockets)

**Pendiente:**
- [ ] Configurar Laravel Reverb
- [ ] Crear evento `CanvasDrawEvent`
- [ ] Crear evento `WordGuessedEvent`
- [ ] Implementar broadcast de trazos del canvas
- [ ] Implementar listeners en frontend
- [ ] Sincronización en tiempo real

---

### ⏳ Task 8.0 - Pictionary Testing

**Pendiente:**
- [ ] Unit tests del motor
- [ ] Feature tests del flujo de juego
- [ ] Tests de WebSockets
- [ ] Tests de validación

---

## Notas de Desarrollo

### Decisiones Técnicas

- **ADR-002: Desarrollo Iterativo (Opción C)**
  - Implementar Pictionary monolíticamente primero
  - Extraer módulos opcionales en Fase 4
  - Permite validar MVP rápido

- **ADR-003: WebSockets con Laravel Reverb**
  - Nativo de Laravel 11
  - Sin costos adicionales
  - Suficiente para MVP

- **ADR-004: Sin Chat en MVP**
  - Juegos presenciales, hablan cara a cara
  - Solo "respuestas" escritas para adivinar

### Conceptos del Producto

- Plataforma para juegos en reuniones presenciales
- Cada jugador usa su dispositivo móvil
- Interacción social cara a cara
- Sincronización en tiempo real vía WebSockets

---

## Referencias

- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../tasks/0001-prd-plataforma-juegos-sociales.md)
- **Game Registry:** [`docs/modules/core/GAME_REGISTRY.md`](../modules/core/GAME_REGISTRY.md)
- **Arquitectura Modular:** [`docs/MODULAR_ARCHITECTURE.md`](../MODULAR_ARCHITECTURE.md)
- **Decisiones Técnicas:** [`docs/TECHNICAL_DECISIONS.md`](../TECHNICAL_DECISIONS.md)

---

## 📊 Resumen de Estado Actual

### ✅ Funcionalidades Implementadas (Demo Funcional)

#### Frontend (`/pictionary/demo`):
- ✅ Canvas HTML5 con dibujo funcional
- ✅ 12 colores, 4 grosores, lápiz y borrador
- ✅ Soporte mouse y touch (móviles)
- ✅ Botón "¡YA SÉ!" y sistema de confirmación
- ✅ Panel visual de eliminación (rojo)
- ✅ Sincronización vía localStorage (temporal)
- ✅ Roles: dibujante (`/demo`) y adivinador (`/demo?role=guesser`)

#### Backend (PictionaryEngine):
- ✅ Inicialización completa del juego
- ✅ Carga de 120 palabras (3 dificultades)
- ✅ Sistema de turnos circular
- ✅ Sistema de puntuación por velocidad
- ✅ Condición de victoria (mayor puntuación)
- ✅ Rotación automática de roles
- ✅ Manejo de fases (lobby → drawing → scoring → results)

### ⏳ Funcionalidades Pendientes

#### Para completar Task 6.0:
- ❌ Timer con auto-terminar turno (Job/Queue)
- ❌ Completar `processAction()` para rutas API
- ❌ Completar `getGameStateForPlayer()` con datos completos
- ❌ Implementar lógica de desconexión/reconexión

#### Para Task 7.0 (WebSockets):
- ❌ Instalar Laravel Reverb
- ❌ Sincronización en tiempo real del canvas
- ❌ Broadcast de trazos, respuestas, eliminaciones
- ❌ Timer en tiempo real
- ❌ Estado del juego sincronizado automáticamente

### 🧪 URLs de Prueba

```
Dibujante:  https://gambito.test/pictionary/demo
Adivinador: https://gambito.test/pictionary/demo?role=guesser
```

### 📈 Progreso General

```
Task 4.0 - Structure:     ✅ 100% (7/7 sub-tareas)
Task 5.0 - Canvas:        ✅ 100% (9/9 sub-tareas)
Task 6.0 - Game Logic:    ✅ 100% (10/10 sub-tareas)
Task 7.0 - WebSockets:    ⏳   0% (0/8 sub-tareas)
Task 8.0 - Testing:       ⏳   0% (0/5 sub-tareas)

TOTAL FASE 3:             🚧  60% (Pictionary MVP)
```

---

**Mantenido por:** Equipo de desarrollo Gambito
**Última actualización:** 2025-10-21
