# Guía para Comando `/create-game`

**Propósito**: Este documento es la guía completa para el comando `/create-game` que ayuda a crear nuevos juegos de forma interactiva, guiada y sin duplicar código.

## Visión General

El comando `/create-game` debe:
1. Hacer preguntas al usuario sobre el juego
2. Generar la estructura completa del juego
3. Configurar módulos automáticamente
4. No duplicar código existente en BaseGameEngine
5. Pedir permiso antes de modificar core
6. Crear lista de tareas fase por fase
7. Validar que todo está correcto

---

## Fase 1: Preguntas Iniciales

### Pregunta 1: Información Básica
```
🎮 ¿Cómo se llama tu juego?
Ejemplo: Pictionary, Uno, Trivia
```
- Validar que el nombre no exista en `games/`
- Convertir a slug: "Trivia Game" → "trivia"

### Pregunta 2: Descripción
```
📝 Describe brevemente el juego (1-2 líneas)
```

### Pregunta 3: Tipo de Juego
```
🎯 ¿Qué tipo de juego es?

a) Preguntas y Respuestas (trivia, quiz)
b) Creatividad (pictionary, palabra secreta)
c) Cartas (uno, poker)
d) Estrategia por turnos (ajedrez, damas)
e) Tiempo real (speed challenges)
f) Otro (especificar)
```

### Pregunta 4: Jugadores
```
👥 ¿Cuántos jugadores?

Min: ___ (mínimo 2)
Max: ___ (recomendado ≤ 10)

¿Permite invitados sin registro? (sí/no)
```

### Pregunta 5: Equipos
```
👫 ¿Tiene equipos?

a) Solo individual
b) Solo por equipos
c) Ambos (configurable)
```

Si tiene equipos:
```
¿Cuántos equipos?
Min: ___
Max: ___
```

### Pregunta 6: Rondas
```
🔄 ¿Tiene rondas?

Sí → ¿Cuántas rondas? ___
No → Es una sola partida continua
```

### Pregunta 7: Turnos
```
🎲 ¿Cómo funciona el turno?

a) Simultáneo - Todos juegan al mismo tiempo
b) Secuencial - Un jugador a la vez
c) Libre - No hay concepto de turno
d) Por equipos - Los equipos juegan por turnos
```

### Pregunta 8: Puntuación
```
🏆 ¿Tiene sistema de puntos?

Sí → Describe cómo se puntúa:
  - Puntos por acierto
  - Puntos por rapidez
  - Penalties por error
  - Otro
```

### Pregunta 9: Timer
```
⏱️ ¿Necesita timer?

a) Sí, por ronda (ej: 15 segundos por pregunta)
b) Sí, por turno (ej: 30 segundos para dibujar)
c) Sí, para toda la partida (ej: 5 minutos totales)
d) No necesita timer
```

Si sí:
```
¿Qué pasa cuando expira?
a) Se completa la ronda automáticamente
b) Se pasa turno al siguiente jugador
c) Penalización pero se continúa
d) Otro (especificar)
```

### Pregunta 10: Roles
```
👤 ¿Tiene roles especiales?

Ejemplos:
- Pictionary: drawer vs guessers
- Mafia: mafia vs ciudadanos
- Trivia: no tiene roles

¿Roles? (sí/no)
```

Si sí:
```
Lista los roles:
1. ___
2. ___
...
```

### Pregunta 11: Estado del Jugador
```
🔒 ¿Los jugadores pueden bloquearse/eliminarse temporalmente?

Ejemplos:
- Trivia: bloqueado después de responder incorrectamente
- Pictionary: no aplica
- Uno: eliminación temporal si dice "UNO" tarde

¿Necesita locks/eliminaciones? (sí/no)
```

### Pregunta 12: Elementos Custom
```
🎨 ¿Necesita elementos especiales?

a) Mazo de cartas
b) Tablero/grid
c) Canvas de dibujo
d) Chat en tiempo real
e) Modo espectador
f) Replay/historial
g) Bots/IA
h) Ninguno
```

---

## Fase 2: Análisis y Mapeo de Módulos

### Basándose en las respuestas, determinar módulos:

| Respuesta | Módulo | Configuración |
|-----------|--------|---------------|
| Pregunta 4: permite invitados | `guest_system` | enabled: true |
| Pregunta 5: equipos | `teams_system` | enabled: true, min/max teams |
| Pregunta 6: rondas | `round_system` | enabled: true, total_rounds |
| Pregunta 7: turnos secuenciales | `turn_system` | enabled: true, mode: sequential |
| Pregunta 8: puntuación | `scoring_system` | enabled: true + ScoreCalculator |
| Pregunta 9: timer | `timer_system` | enabled: true, round_duration |
| Pregunta 10: roles | `roles_system` | enabled: true, roles list |
| Pregunta 11: locks | `player_state_system` | enabled: true, uses locks |
| Pregunta 12a: cartas | `card_deck_system` | enabled: true |
| Pregunta 12b: tablero | `board_grid_system` | enabled: true |
| Pregunta 12e: espectador | `spectator_mode` | enabled: true |
| Pregunta 12f: replay | `replay_history` | enabled: true |
| Pregunta 12g: bots | `ai_players` | enabled: true |

### Módulos SIEMPRE activos:
- `game_core` (obligatorio)
- `room_manager` (obligatorio)
- `real_time_sync` (WebSockets, obligatorio)

---

## Fase 3: Generación de Estructura

### 3.1 Crear directorio base
```
games/{slug}/
├── {GameName}Engine.php
├── {GameName}ScoreCalculator.php  (si scoring_system)
├── config.json
├── questions.json  (si es tipo Q&A)
├── rules.json
├── views/
│   └── game.blade.php
├── js/
│   └── {GameName}GameClient.js
└── assets/
    ├── images/
    └── sounds/
```

### 3.2 Generar config.json
```json
{
  "name": "{Game Name}",
  "slug": "{slug}",
  "description": "{description}",
  "version": "1.0.0",
  "min_players": {min},
  "max_players": {max},
  "supports_teams": {true/false},

  "modules": {
    "guest_system": {
      "enabled": {true/false}
    },
    "turn_system": {
      "enabled": {true/false},
      "mode": "{free/sequential/simultaneous}"
    },
    "round_system": {
      "enabled": {true/false}
    },
    "scoring_system": {
      "enabled": {true/false}
    },
    "timer_system": {
      "enabled": {true/false},
      "round_duration": {seconds}
    },
    "teams_system": {
      "enabled": {true/false},
      "min_teams": {min},
      "max_teams": {max}
    },
    "player_state_system": {
      "enabled": {true/false},
      "uses_locks": {true/false}
    }
  },

  "customizableSettings": {
    "rounds": {
      "type": "integer",
      "default": {total_rounds},
      "min": 1,
      "max": 50,
      "label": "Número de rondas"
    }
  }
}
```

### 3.3 Generar {GameName}Engine.php

**Template base**:
```php
<?php

namespace Games\{GameName};

use App\Contracts\BaseGameEngine;
use App\Models\GameMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

/**
 * {Game Name} Game Engine
 *
 * {Description}
 */
class {GameName}Engine extends BaseGameEngine
{
    /**
     * Inicializar el juego - FASE 1 (LOBBY)
     */
    public function initialize(GameMatch $match): void
    {
        Log::info("[{GameName}] Initializing", ['match_id' => $match->id]);

        // TODO: Cargar recursos del juego
        // - Preguntas, cartas, tablero, etc.

        $match->game_state = [
            '_config' => [
                'game' => '{slug}',
                'initialized_at' => now()->toDateTimeString(),
            ],
            'phase' => 'waiting',
        ];

        $match->save();

        // Cachear players (1 query, 1 sola vez)
        $this->cachePlayersInState($match);

        // Inicializar módulos
        $this->initializeModules($match, [
            // TODO: Configurar módulos específicos
            'scoring_system' => [
                'calculator' => new {GameName}ScoreCalculator()
            ],
            'round_system' => [
                'total_rounds' => 10  // TODO: Cargar de config
            ]
        ]);
    }

    /**
     * Hook: El juego está listo para empezar - FASE 3
     */
    protected function onGameStart(GameMatch $match): void
    {
        Log::info("[{GameName}] Game starting", ['match_id' => $match->id]);

        $match->game_state = array_merge($match->game_state, [
            'phase' => 'playing',
            'started_at' => now()->toDateTimeString(),
        ]);

        $match->save();

        // Iniciar primera ronda
        $this->handleNewRound($match, advanceRound: false);
    }

    /**
     * Procesar acción de jugador en la ronda actual
     *
     * @return array ['success' => bool, 'force_end' => bool, ...]
     */
    protected function processRoundAction(GameMatch $match, Player $player, array $data): array
    {
        // TODO: Implementar lógica de acción

        return [
            'success' => true,
            'force_end' => false,
            'player_id' => $player->id,
        ];
    }

    /**
     * Iniciar nueva ronda - Preparar estado de ronda
     */
    protected function startNewRound(GameMatch $match): void
    {
        Log::info("[{GameName}] Starting new round", ['match_id' => $match->id]);

        // TODO: Preparar ronda
        // - Cargar siguiente pregunta/carta/nivel
        // - Resetear locks de jugadores
        // - Asignar roles si aplica
    }

    /**
     * Finalizar ronda actual - Calcular resultados
     */
    public function endCurrentRound(GameMatch $match): void
    {
        // TODO: Calcular resultados de la ronda
        // - Determinar ganador
        // - Actualizar puntuaciones
        // - Preparar datos para RoundEndedEvent
    }

    /**
     * Obtener resultados de todos los jugadores
     */
    protected function getAllPlayerResults(GameMatch $match): array
    {
        // TODO: Retornar resultados de todos
        return [];
    }
}
```

### 3.4 Generar {GameName}ScoreCalculator.php (si scoring_system)

```php
<?php

namespace Games\{GameName};

use App\Services\Modules\ScoringSystem\ScoreCalculatorInterface;

class {GameName}ScoreCalculator implements ScoreCalculatorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'correct_answer' => 10,
            'speed_bonus_max' => 5,
            // TODO: Más configuración
        ], $config);
    }

    public function calculate(string $action, array $context = []): int
    {
        return match($action) {
            'correct_answer' => $this->calculateCorrectAnswer($context),
            'incorrect_answer' => $this->calculateIncorrectAnswer($context),
            // TODO: Más acciones
            default => 0,
        };
    }

    protected function calculateCorrectAnswer(array $context): int
    {
        $basePoints = $this->config['correct_answer'];

        // TODO: Speed bonus si hay timer
        // $speedBonus = $this->calculateSpeedBonus($context);

        return $basePoints;
    }

    protected function calculateIncorrectAnswer(array $context): int
    {
        // TODO: Penalización si aplica
        return 0;
    }
}
```

### 3.5 Generar views/game.blade.php

```blade
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            🎮 {Game Name} - Sala: {{ $code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <!-- Header -->
                <div class="bg-blue-600 px-6 py-4 text-center">
                    <h3 class="text-2xl font-bold text-white">¡EL JUEGO HA EMPEZADO!</h3>
                </div>

                <!-- Body -->
                <div id="game-container" class="px-6 py-12">
                    <!-- Loading State -->
                    <x-game.loading-state
                        id="loading-state"
                        emoji="⏳"
                        message="Esperando..."
                        :roomCode="$code"
                    />

                    <!-- Playing State -->
                    <div id="playing-state" class="hidden">
                        <!-- TODO: UI del juego -->
                    </div>

                    <!-- Finished State -->
                    <div id="finished-state" class="hidden">
                        <x-game.results-screen
                            :roomCode="$code"
                            gameTitle="{Game Name}"
                            containerId="podium"
                            :embedded="true"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script type="module">
        // TODO: Cargar {GameName}GameClient
        // await window.load{GameName}GameClient();

        const config = {
            roomCode: '{{ $code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            gameSlug: '{slug}',
        };

        // TODO: Crear instancia del cliente
        // const gameClient = new {GameName}GameClient(config);

        // Cargar estado inicial y luego conectar EventManager
        // ... (copiar de trivia/views/game.blade.php)
    </script>
</x-app-layout>
```

### 3.6 Generar js/{GameName}GameClient.js

```javascript
import BaseGameClient from '../../resources/js/core/BaseGameClient.js';

/**
 * {Game Name} Game Client
 *
 * Maneja la lógica específica del cliente para {Game Name}
 */
export class {GameName}GameClient extends BaseGameClient {
    constructor(config) {
        super(config);

        // TODO: Estado específico del juego
    }

    /**
     * Handler: Ronda iniciada
     */
    handleRoundStarted(event) {
        console.log('[{GameName}] Round started:', event);

        // TODO: Renderizar UI de la ronda
        // - Mostrar pregunta/carta/tablero
        // - Iniciar timer si aplica
        // - Habilitar controles
    }

    /**
     * Handler: Acción de jugador
     */
    async handlePlayerAction(playerId, actionData) {
        console.log('[{GameName}] Player action:', playerId, actionData);

        // TODO: Actualizar UI con acción de otro jugador
    }

    /**
     * Enviar acción del jugador local
     */
    async submitAction(action, data) {
        return await this.sendGameAction(action, data);
    }
}

// Lazy loading para performance
window.load{GameName}GameClient = async () => {
    window.{GameName}GameClient = {GameName}GameClient;
};

export default {GameName}GameClient;
```

---

## Fase 4: Validaciones y Permisos

### Checklist de Validación

Antes de generar archivos, verificar:

- [ ] El slug del juego no existe en `games/`
- [ ] Todos los módulos seleccionados están soportados
- [ ] La configuración de módulos es consistente
- [ ] No hay conflictos (ej: turnos libres + timer por turno)
- [ ] El ScoreCalculator es necesario (scoring_system enabled)

### Permisos para Modificar Core

Si el juego requiere modificar core (muy raro), **SIEMPRE** pedir permiso:

```
⚠️  ADVERTENCIA: Este juego requiere modificar archivos del core:

Archivos a modificar:
- app/Contracts/BaseGameEngine.php
  Razón: Agregar nuevo método abstracto para X

¿Deseas continuar? (sí/no)

Si continúas:
1. Se creará backup automático
2. Se harán los cambios mínimos necesarios
3. Se ejecutarán tests para verificar que nada se rompió
4. Se generará commit separado para review
```

**Regla de oro**: Si algo se puede hacer en el GameEngine específico, NUNCA modificar BaseGameEngine.

---

## Fase 5: Plan de Tareas

Después de generar la estructura, crear lista de tareas:

### Ejemplo de Lista de Tareas:

```markdown
## 🎮 Tareas para completar {Game Name}

### Fase 1: Setup Básico ✅
- [x] Estructura de archivos creada
- [x] Módulos configurados en config.json
- [x] Engine base generado

### Fase 2: Lógica Core 🚧
- [ ] Implementar `initialize()` - Cargar recursos
- [ ] Implementar `onGameStart()` - Preparar primera ronda
- [ ] Implementar `processRoundAction()` - Manejar acciones
- [ ] Implementar `startNewRound()` - Preparar cada ronda
- [ ] Implementar `endCurrentRound()` - Calcular resultados

### Fase 3: Puntuación (si aplica) ⏳
- [ ] Completar ScoreCalculator con todas las acciones
- [ ] Implementar speed bonus si hay timer
- [ ] Implementar penalties si aplica
- [ ] Probar cálculo de puntos end-to-end

### Fase 4: Frontend ⏳
- [ ] Implementar GameClient con handlers
- [ ] Crear UI para estado de juego
- [ ] Integrar timer visual (si aplica)
- [ ] Probar interacción usuario

### Fase 5: Testing 📝
- [ ] Test: Inicialización del juego
- [ ] Test: Flujo de rondas completo
- [ ] Test: Puntuación correcta
- [ ] Test: Timer expiration (si aplica)
- [ ] Test: Múltiples jugadores simultáneos

### Fase 6: Polish 🎨
- [ ] Assets (imágenes, sonidos)
- [ ] Animaciones y transiciones
- [ ] Mensajes de error amigables
- [ ] Documentación del juego
```

---

## Fase 6: Testing Guiado

Para cada fase, ejecutar tests:

### Test 1: Estructura
```bash
# Verificar que todos los archivos existen
ls -la games/{slug}/

# Verificar sintaxis PHP
php -l games/{slug}/{GameName}Engine.php

# Verificar sintaxis JSON
cat games/{slug}/config.json | jq
```

### Test 2: Engine Initialization
```bash
# Crear una partida de prueba
php artisan tinker
>>> $game = \App\Models\Game::where('slug', '{slug}')->first();
>>> $room = \App\Models\Room::factory()->create(['game_id' => $game->id]);
>>> $match = $room->match;
>>> $engine = app($game->getEngineClass());
>>> $engine->initialize($match);
>>> $match->refresh();
>>> dd($match->game_state);
```

### Test 3: Flujo Completo
```bash
# Feature test
php artisan test --filter={GameName}Test
```

### Test 4: Frontend
```
1. Abrir navegador
2. Crear sala
3. Iniciar partida
4. Verificar que UI se renderiza
5. Probar acciones del usuario
6. Verificar que eventos WebSocket funcionan
```

---

## Preguntas Frecuentes para el Comando

### Q: ¿Puedo modificar BaseGameEngine?
**A**: Solo si es absolutamente necesario y no hay alternativa. Siempre pedir permiso y explicar por qué.

### Q: ¿Debo implementar todos los métodos abstractos?
**A**: Sí, todos los métodos abstractos son obligatorios. Los hooks opcionales (como `beforeTimerExpiredAdvance`) no.

### Q: ¿Qué pasa si mi juego es muy diferente?
**A**: BaseGameEngine es flexible. Usa hooks y sobrescribe métodos. Si aún así no funciona, analizar si necesitas un nuevo tipo de Engine (muy raro).

### Q: ¿Dónde va la lógica del juego?
**A**: En `{GameName}Engine.php`, nunca en controllers o modelos.

### Q: ¿Puedo usar módulos de forma diferente?
**A**: Sí, pero sigue las convenciones. Si necesitas algo custom, documenta claramente por qué.

---

## Resumen del Flujo del Comando

```
1. Hacer preguntas interactivas
   ↓
2. Mapear respuestas a módulos
   ↓
3. Validar configuración
   ↓
4. Generar estructura de archivos
   ↓
5. Crear lista de tareas
   ↓
6. Ejecutar tests básicos
   ↓
7. Mostrar next steps al usuario
```

**Principio clave**: El comando genera el esqueleto completo, el desarrollador solo rellena TODOs.
