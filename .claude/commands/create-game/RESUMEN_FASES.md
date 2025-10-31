# Resumen de Fases para /create-game

## ⚡ FASES 7-12: Continuación

### FASE 7: Engine - Estructura Base (initialize + onGameStart)

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 5 (Pasos 5.2-5.3)

**Convenciones**:
- `initialize()`: Una sola vez al crear el juego
- Patrón game_state: obtener → modificar → reasignar → guardar
- Usar `initializeModules()` - inicializa TODOS los módulos automáticamente
- Crear `PlayerManager` (NO PlayerStateManager)
- **roles_system**: Se inicializa AUTOMÁTICAMENTE en `initializeModules()`

**IMPORTANTE - Sistema de Roles**:
- `BaseGameEngine::initializeModules()` inicializa `roles_system` automáticamente
- Lee roles desde `config.json` → `modules.roles_system.roles`
- Si no hay roles definidos, usa rol por defecto "player"
- Crea `game_state['roles_system']` con `enabled: true`
- **NO necesitas código manual** para inicializar roles
- **SÍ necesitas asignar roles** iniciales a jugadores con `PlayerManager::autoAssignRolesFromConfig()`

**Hook opcional: onRoundStarting()**:
- Se ejecuta ANTES de emitir eventos de fase (antes de `PhaseManager.startPhase()`)
- Útil si necesitas establecer UI (`game_state['_ui']`) antes de que se emita el primer evento
- Ejemplo Trivia: cargar pregunta en `onRoundStarting()` → `startNewRound()` → establecer UI → eventos ya tienen datos
- Si NO necesitas preparar datos antes de eventos, NO necesitas implementar este hook
- Ver `games/trivia/TriviaEngine.php::onRoundStarting()` para ejemplo

**Tareas**:
1. Crear {GameName}Engine.php extendiendo BaseGameEngine
2. Implementar initialize() con:
   - Llamar `initializeModules()` con configuración de módulos
   - Crear PlayerManager y asignar roles con `autoAssignRolesFromConfig()`
   - Guardar PlayerManager con `savePlayerManager()`
3. Implementar onGameStart() llamando handleNewRound(advanceRound: false)
4. (Opcional) Implementar onRoundStarting() si necesitas preparar datos antes de eventos

---

### FASE 8: Engine - Ciclo de Rondas (startNewRound + processRoundAction + endCurrentRound)

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 5 (Pasos 5.4-5.6)

**Convenciones startNewRound()**:
- ⚠️ **IMPORTANTE**: `PlayerManager::reset()` ya se llama AUTOMÁTICAMENTE en `BaseGameEngine::handleNewRound()` (línea 452)
- NO necesitas desbloquear jugadores manualmente - se hace automáticamente antes de `onRoundStarting()`
- Solo hacer lógica específica del juego: cargar datos (preguntas, palabras, etc.), establecer UI, limpiar `game_state['actions']`
- Ejemplo Trivia: seleccionar pregunta, establecer UI con `setUI()`, guardar `game_state`

**Ejemplo correcto**:
```php
protected function startNewRound(GameMatch $match): void
{
    // NOTA: reset() ya se llama automáticamente en handleNewRound()
    // Solo lógica específica del juego
    
    // Limpiar acciones del game_state
    $gameState = $match->game_state;
    $gameState['actions'] = [];
    $match->game_state = $gameState;
    $match->save();
    
    // Preparar datos específicos (ej: cargar pregunta)
    $this->loadRoundData($match);
}
```

**Referencia**: Ver `games/trivia/TriviaEngine.php::startNewRound()` y `games/mockup/MockupEngine.php::startNewRound()` para ejemplos correctos.

**Convenciones processRoundAction()**:
- Validar `isPlayerLocked()` ANTES de procesar
- Usar `awardPoints()` para dar puntos
- Usar `lockPlayer()` para bloquear
- Retornar array con success, force_end, end_reason

**Convenciones endCurrentRound()**:
- Llamar `completeRound($match, $results, $scores)`
- NO emitir RoundEndedEvent manualmente

**Patrón game_state**:
```php
$gameState = $match->game_state;
$gameState['key'] = 'value';
$match->game_state = $gameState;
$match->save();
```

---

### FASE 9: Engine - Fases y Callbacks (handle{Fase}Ended)

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 5 (Paso 5.7)

**Convenciones CRÍTICAS**:
- Nombre: `handle{NombreFase}Ended` (camelCase)
- Obtener PhaseManager: `$roundManager->getTurnManager()`
- ⚠️ **SIEMPRE** llamar `$phaseManager->setMatch($match)` ANTES de nextPhase()
- Llamar `$this->saveRoundManager($match, $roundManager)`
- Si `cycle_completed: true` → llamar `endCurrentRound()`
- Si NO completó → emitir `PhaseChangedEvent`

**Template**:
```php
public function handleFase1Ended(GameMatch $match, array $phaseData): void
{
    $roundManager = $this->getRoundManager($match);
    $phaseManager = $roundManager->getTurnManager();

    if (!$phaseManager) return;

    // ⚠️ CRÍTICO
    $phaseManager->setMatch($match);

    $nextPhaseInfo = $phaseManager->nextPhase();
    $this->saveRoundManager($match, $roundManager);

    if ($nextPhaseInfo['cycle_completed']) {
        $this->endCurrentRound($match);
    } else {
        event(new PhaseChangedEvent(...));
    }
}
```

---

### FASE 10: Frontend - Cliente Base (setupEventManager + handlers)

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 6

**Convenciones**:
- Clase extiende `BaseGameClient`
- Constructor llama a `super(config)`
- `setupEventManager()` registra customHandlers
- Handlers coinciden con capabilities.json
- `handleDomLoaded` llama a `super.handleDomLoaded(event)` primero

**Estructura**:
```javascript
class {GameName}Client extends BaseGameClient {
    constructor(config) {
        super(config);
        this.setupEventManager();
    }

    setupEventManager() {
        this.customHandlers = {
            handleDomLoaded: (event) => {
                super.handleDomLoaded(event);
                this.setupGameControls();
            },
            handleFase1Started: (event) => {
                this.onFase1Started(event);
            },
            handlePlayerLocked: (event) => {
                this.onPlayerLocked(event);
            },
            handlePlayersUnlocked: (event) => {
                this.onPlayersUnlocked(event);
            }
        };

        super.setupEventManager(this.customHandlers);
    }

    onFase1Started(event) {
        console.log('Fase 1 iniciada');
        this.hideAllPhaseUI();
        this.showFase1UI();
    }

    onPlayerLocked(event) {
        if (event.player_id !== this.playerId) return;
        this.hideActionButtons();
        this.showLockedMessage();
    }

    onPlayersUnlocked(event) {
        this.hideLockedMessage();
        this.restorePhaseUI();
    }
}

window.{GameName}Client = {GameName}Client;
```

---

### FASE 11: Frontend - UI y Vistas (game.blade.php + popups)

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 7

**⚠️ CRÍTICO - Nombre de Vista**:
- **SIEMPRE usar**: `games/{slug}/views/game.blade.php`
- **NUNCA usar**: `canvas.blade.php` o cualquier otro nombre
- El controlador (`PlayController`) busca la vista como: `{slug}::game`
- Laravel busca el archivo en: `games/{slug}/views/game.blade.php`
- Si la vista no se llama exactamente `game.blade.php`, Laravel dará error 404

**Convenciones game.blade.php**:
- Incluir CSRF token en head
- Timer principal con id="timer"
- UI de cada fase con id="{fase}-ui" y display:none inicial
- Mensaje bloqueado con id="locked-message"
- Pasar datos a JS con window.{slug}Data
- Crear instancia del cliente en script type="module"
- ⚠️ Incluir `@stack('scripts')` ANTES de </body>
- Incluir popups (round_end, game_end, player_disconnected)

**Estructura básica**:
```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $gameConfig['name'] }} - {{ $room->code }}</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <div id="app">
        <!-- Header con ronda/timer -->
        <!-- UI por fase (display: none inicialmente) -->
        <!-- Mensaje de bloqueado -->
    </div>

    @vite(['resources/js/app.js', 'games/{slug}/js/{GameName}Client.js'])

    <script>
        window.{slug}Data = {
            roomCode: '{{ $room->code }}',
            playerId: {{ $playerId }},
            gameState: @json($match->game_state),
            csrfToken: '{{ csrf_token() }}'
        };
    </script>

    <script type="module">
        const config = {
            roomCode: '{{ $room->code }}',
            matchId: {{ $match->id }},
            playerId: {{ $playerId }},
            userId: {{ $userId }},
            gameSlug: '{slug}',
            gameState: @json($match->game_state),
            eventConfig: @json($eventConfig),
        };

        const client = new window.{GameName}Client(config);
        window.{slug}Client = client;
    </script>

    @stack('scripts')

    @include('{slug}::partials.round_end_popup')
    @include('{slug}::partials.game_end_popup')
    @include('{slug}::partials.player_disconnected_popup')
</body>
</html>
```

---

### FASE 12: Testing y Validación Final

Leer: `docs/CREAR_JUEGO_PASO_A_PASO.md` → FASE 8

**Validaciones Automáticas**:
```bash
# 1. Validar sintaxis PHP
php -l games/{slug}/*.php
php -l app/Events/{GameName}/*.php

# 2. Validar JSON
php -r "json_decode(file_get_contents('games/{slug}/config.json'));"
php -r "json_decode(file_get_contents('games/{slug}/capabilities.json'));"

# 3. Validar JavaScript
node --check games/{slug}/js/{GameName}Client.js

# 4. Compilar assets
npm run build

# 5. Ver archivo compilado
ls public/build/assets/ | grep {GameName}Client
```

**Checklist Manual**:
- [ ] Crear sala con el juego
- [ ] Unirse desde 2+ navegadores
- [ ] Iniciar partida
- [ ] Validar CADA fase:
  - [ ] Timer aparece y cuenta
  - [ ] UI de fase se muestra
  - [ ] Acciones funcionan
  - [ ] Bloqueos funcionan
  - [ ] Fase avanza correctamente
- [ ] Validar fin de ronda:
  - [ ] Popup aparece
  - [ ] Scores correctos
  - [ ] Countdown funciona
  - [ ] Siguiente ronda inicia
- [ ] Validar fin de juego:
  - [ ] Popup final aparece
  - [ ] Ganador correcto
  - [ ] Ranking correcto

**Verificar Logs**:
```bash
tail -f storage/logs/laravel.log | grep "{GameName}"
```

**Buscar en logs**:
- `[{GameName}] Initializing`
- `[{GameName}] ===== PARTIDA INICIADA =====`
- `[{GameName}] Starting new round`
- `[{GameName}] FASE X ENDED`
- `[{GameName}] Round ended successfully`

---

## 📋 Checklist Global Final

### Backend ✅
- [ ] PlayerManager inicializado (NO PlayerStateManager)
- [ ] scoreCalculator como propiedad
- [ ] startNewRound() incluye reset() + savePlayerManager()
- [ ] processRoundAction() usa awardPoints() y lockPlayer()
- [ ] endCurrentRound() llama completeRound()
- [ ] filterGameStateForBroadcast() implementado
- [ ] Eventos usan ShouldBroadcastNow + PresenceChannel
- [ ] Nombres de eventos coinciden con capabilities.json
- [ ] Todos los callbacks handle{Fase}Ended implementados
- [ ] $phaseManager->setMatch($match) en TODOS los callbacks

### Frontend ✅
- [ ] Cliente hereda de BaseGameClient
- [ ] Constructor llama a super(config)
- [ ] setupEventManager() registra customHandlers
- [ ] Todos los handlers definidos en capabilities.json implementados
- [ ] handleDomLoaded() llama a super primero
- [ ] onPlayerLocked() y onPlayersUnlocked() implementados
- [ ] restoreGameState() implementado
- [ ] game.blade.php incluye @stack('scripts')
- [ ] game.blade.php incluye todos los popups

### Configuración ✅
- [ ] config.json válido (JSON)
- [ ] capabilities.json válido (JSON)
- [ ] event_config completo con TODOS los eventos custom
- [ ] Punto inicial: CON punto en config.json, SIN punto en capabilities.json
- [ ] broadcastAs() SIN punto inicial
- [ ] Todos los handlers coinciden entre archivos
- [ ] timing.round_ended con auto_next configurado
- [ ] Todos los módulos necesarios habilitados

### Validación ✅
- [ ] Sintaxis PHP válida (php -l)
- [ ] Sintaxis JS válida (node --check)
- [ ] JSON válido (php -r)
- [ ] Assets compilados (npm run build)
- [ ] Nombres de eventos consistentes
- [ ] Testing manual completo
- [ ] Logs sin errores

---

## 🚨 Errores Críticos a Recordar

1. **capabilities.json es CRÍTICO** - Sin él, eventos no llegan
2. **Punto Inicial** - SIN punto en broadcastAs() y capabilities.json, CON punto en config.json
3. **PresenceChannel** - Siempre usar para events de room
4. **game_state** - Siempre patrón: obtener → modificar → reasignar → guardar
5. **setMatch()** - SIEMPRE llamar antes de nextPhase()
6. **Timer** - Incluir duration, timer_id, server_time, event_class en broadcastWith()
7. **Handlers** - Deben coincidir en capabilities.json, config.json y Client.js
