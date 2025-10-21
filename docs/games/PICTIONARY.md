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

### Métodos Privados

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

## Vistas (TODO: Task 5.0)

**Directorio:** `games/pictionary/views/`

### `canvas.blade.php` (TODO)

Vista principal del canvas de dibujo.

**Componentes:**
- Canvas HTML5 para dibujar
- Herramientas: Lápiz, borrador, colores, grosor
- Botón para limpiar canvas
- Área de chat para respuestas

---

## JavaScript (TODO: Task 5.0)

**Directorio:** `games/pictionary/js/`

### `canvas.js` (TODO)

Lógica del canvas de dibujo.

**Funcionalidades:**
- Capturar eventos de mouse/touch
- Dibujar líneas en el canvas
- Emitir eventos WebSocket con coordenadas
- Escuchar eventos de otros jugadores

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

---

### 🚧 Task 5.0 - Pictionary Canvas System (SIGUIENTE)

**Pendiente:**
- [ ] Crear vista `views/canvas.blade.php`
- [ ] Crear JavaScript `js/canvas.js` (dibujo local)
- [ ] Crear CSS `css/canvas.css`
- [ ] Implementar herramientas de dibujo (lápiz, borrador, colores)
- [ ] Botón para limpiar canvas

---

### ⏳ Task 6.0 - Pictionary Game Logic (Monolítico)

**Pendiente:**
- [ ] Implementar lógica de turnos en `PictionaryEngine`
- [ ] Implementar sistema de puntuación
- [ ] Implementar temporizador de 90 segundos
- [ ] Implementar roles (dibujante/adivinadores)
- [ ] Implementar selección aleatoria de palabras
- [ ] Implementar confirmación de respuestas

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

**Mantenido por:** Equipo de desarrollo Gambito
**Última actualización:** 2025-10-21
