# GroupsGames - Contexto del Proyecto para Claude Code

## 📌 Identidad del Proyecto

**Nombre**: GroupsGames
**Tipo**: Plataforma de juegos multijugador para reuniones presenciales
**Stack**: Laravel 11 + Laravel Reverb (WebSockets) + Blade + Vanilla JS
**Arquitectura**: Modular, basada en plugins de juegos independientes

---

## 🎯 Filosofía de Desarrollo

### Principios Fundamentales

1. **Desacoplamiento Total**: Los módulos del sistema (RoundManager, TurnManager, ScoringManager) son genéricos y NO contienen lógica específica de juegos.

2. **BaseGameEngine como Coordinador**: El `BaseGameEngine` coordina módulos pero NO toma decisiones específicas de juegos (timing, delays, mecánicas).

3. **Cada Juego es Independiente**: Cada juego en `games/{slug}/` es un plugin autocontenido con su Engine, Controller, rutas, vistas y configuración.

4. **Eventos Genéricos Primero**: Usar eventos genéricos (`RoundStartedEvent`, `RoundEndedEvent`, etc.) siempre que sea posible. Solo crear eventos custom cuando sea absolutamente necesario.

5. **NO Modificar el Core Sin Consultar**: Cambios en `app/Contracts/`, `app/Services/Modules/`, `app/Events/Game/` afectan TODOS los juegos. Siempre consultar primero.

---

## 🚫 REGLAS CRÍTICAS (Verificar SIEMPRE antes de implementar)

### ❌ NUNCA Hacer (sin consultar):

1. **Modificar visibilidad de métodos en BaseGameEngine**
   ```php
   // ❌ NO HACER
   abstract public function startNewRound(GameMatch $match): void;
   ```

2. **Agregar lógica de juegos específicos en módulos genéricos**
   ```php
   // ❌ NO HACER en RoundManager
   if ($gameSlug === 'trivia') {
       // Lógica específica de Trivia
   }
   ```

3. **Poner rutas de juegos en `routes/web.php` o `routes/api.php`**
   ```php
   // ❌ NO HACER en routes/api.php
   Route::post('/api/trivia/answer', ...);
   ```

4. **Hacer que BaseGameEngine tome decisiones de timing**
   ```php
   // ❌ NO HACER en BaseGameEngine
   protected function scheduleNextRound() {
       sleep(5);
       $this->startNewRound();
   }
   ```

### ✅ SIEMPRE Hacer:

1. **Exponer API pública en cada GameEngine**
   ```php
   // ✅ En TriviaEngine
   public function advanceToNextRound(GameMatch $match): void {
       $this->startNewRound($match);
   }
   ```

2. **Declarar todas las rutas en `games/{slug}/routes.php`**
   ```php
   // ✅ En games/trivia/routes.php
   Route::post('/answer', [TriviaController::class, 'answer']);
   ```

3. **Actualizar `capabilities.json` cuando se agregan rutas o eventos**
   ```json
   {
     "provides": {
       "routes": ["/api/trivia/answer", "/api/trivia/next-round"]
     }
   }
   ```

4. **Usar eventos genéricos y mapearlos en `capabilities.json`**
   ```json
   {
     "event_config": {
       "events": {
         "RoundStartedEvent": {
           "name": ".game.round.started",
           "handler": "handleRoundStarted"
         }
       }
     }
   }
   ```

---

## 📁 Estructura del Proyecto

### Core del Sistema (NO modificar sin consultar)
```
app/
├── Contracts/
│   └── BaseGameEngine.php          # Contract base para todos los juegos
├── Services/Modules/               # Módulos genéricos reutilizables
│   ├── RoundSystem/
│   │   └── RoundManager.php
│   ├── TurnSystem/
│   │   └── TurnManager.php
│   ├── ScoringSystem/
│   │   └── ScoreManager.php
│   └── ...
├── Events/Game/                    # Eventos genéricos
│   ├── RoundStartedEvent.php
│   ├── RoundEndedEvent.php
│   └── ...
└── Providers/
    └── GameServiceProvider.php     # Carga automática de juegos
```

### Estructura de Cada Juego (SÍ modificar)
```
games/
└── {slug}/                         # ej: trivia, pictionary
    ├── {GameName}Engine.php        # Motor del juego (extiende BaseGameEngine)
    ├── {GameName}Controller.php    # Controlador HTTP
    ├── routes.php                  # ⚠️ TODAS las rutas aquí
    ├── capabilities.json           # ⚠️ Declaración de capacidades
    ├── config.json                 # Configuración del juego
    ├── views/
    │   └── game.blade.php
    └── Events/                     # Solo eventos custom del juego
        └── GameFinishedEvent.php
```

---

## 🔄 Flujo de Trabajo Obligatorio

### Al Crear/Modificar Features

1. **Preguntarse PRIMERO**:
   - ¿Esto es lógica genérica (va en módulo) o específica (va en juego)?
   - ¿Necesito modificar el core o puedo extender/wrapear?
   - ¿Puedo usar eventos genéricos o necesito crear uno custom?

2. **Verificar Checklist de Convenciones**:
   - Leer `DEVELOPMENT_CONVENTIONS.md` sección relevante
   - Verificar que NO estoy modificando el core
   - Verificar estructura de archivos correcta

3. **Implementar**:
   - Crear/modificar archivos en `games/{slug}/`
   - Actualizar `routes.php` y `capabilities.json`
   - Exponer API pública en Engine si es necesario

4. **Verificar Antes de Commit**:
   - [ ] ¿Rutas en `games/{slug}/routes.php`?
   - [ ] ¿Actualizé `capabilities.json`?
   - [ ] ¿NO modifiqué el core?
   - [ ] ¿Usé eventos genéricos cuando era posible?
   - [ ] ¿Paths del frontend coinciden con rutas?
   - [ ] ¿Revisé logs para verificar que no hay errores?

---

## 🎮 Patrones Específicos por Tipo de Tarea

### Patrón: Agregar Nuevo Endpoint a Juego

```php
// 1. En games/{slug}/{GameName}Engine.php - API pública
public function performCustomAction(GameMatch $match, array $data): array
{
    // Lógica del juego
    return ['success' => true, 'data' => ...];
}

// 2. En games/{slug}/{GameName}Controller.php
public function customAction(Request $request)
{
    $engine = new GameNameEngine();
    $result = $engine->performCustomAction($match, $data);
    return response()->json($result);
}

// 3. En games/{slug}/routes.php
Route::post('/custom-action', [GameNameController::class, 'customAction']);

// 4. En games/{slug}/capabilities.json
{
  "provides": {
    "routes": [
      "/api/{slug}/custom-action"  // ⚠️ NO OLVIDAR
    ]
  }
}
```

### Patrón: Avanzar Ronda con Delay Controlado por Frontend

```javascript
// Frontend: {slug}-game.js
startCountdown() {
    let seconds = 5;
    const interval = setInterval(() => {
        seconds--;
        if (seconds <= 0) {
            clearInterval(interval);
            this.requestNextRound();  // Frontend controla timing
        }
    }, 1000);
}

async requestNextRound() {
    await fetch(`/api/{slug}/next-round`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ room_code: this.roomCode })
    });
}
```

```php
// Backend: {GameName}Controller.php
public function nextRound(Request $request)
{
    $engine = new GameNameEngine();

    if ($engine->checkIfGameComplete($match)) {
        return response()->json(['game_complete' => true]);
    }

    $engine->advanceToNextRound($match);  // ✅ API pública
    return response()->json(['success' => true]);
}

// Backend: {GameName}Engine.php
public function advanceToNextRound(GameMatch $match): void
{
    $this->startNewRound($match);  // ✅ Wrapper de método protegido
}
```

### Patrón: Usar Evento Genérico en Nuevo Juego

```json
// capabilities.json
{
  "event_config": {
    "events": {
      "RoundStartedEvent": {
        "name": ".game.round.started",
        "handler": "handleRoundStarted"
      }
    }
  }
}
```

```javascript
// {slug}-game.js
setupEventManager() {
    this.eventManager = new window.EventManager({
        eventConfig: this.eventConfig,
        handlers: {
            handleRoundStarted: (event) => this.handleRoundStarted(event)
        }
    });
}

handleRoundStarted(event) {
    // Adaptar evento genérico a lógica específica del juego
    const gameState = event.game_state;
    this.renderGameSpecificUI(gameState);
}
```

---

## 📚 Documentos de Referencia Clave

1. **`DEVELOPMENT_CONVENTIONS.md`** - Checklist completo de convenciones
2. **`MODULAR_ARCHITECTURE.md`** - Diseño de módulos y arquitectura
3. **`TECHNICAL_DECISIONS.md`** - Decisiones técnicas y roadmap
4. **`games/trivia/`** - Ejemplo de juego simultáneo
5. **`games/pictionary/`** - Ejemplo de juego con turnos y roles

---

## 🤖 Instrucciones para Claude Code

Cuando trabajes en este proyecto:

1. **ANTES de modificar cualquier archivo**, verifica si está en el core del sistema (`app/Contracts/`, `app/Services/Modules/`, `app/Events/Game/`). Si es así, **DETENTE y consulta primero**.

2. **SIEMPRE lee `DEVELOPMENT_CONVENTIONS.md`** antes de implementar nuevas features.

3. **Cuando agregues rutas**, verifica:
   - ¿Están en `games/{slug}/routes.php`?
   - ¿Están declaradas en `capabilities.json`?
   - ¿Coinciden los paths del frontend?

4. **Cuando necesites métodos protegidos del Engine desde Controller**:
   - NO cambies visibilidad en BaseGameEngine
   - Crea métodos públicos wrapper en el Engine específico
   - Ejemplo: `advanceToNextRound()` wrappea `startNewRound()`

5. **Cuando trabajes con eventos**:
   - Preferir eventos genéricos siempre que sea posible
   - Solo crear eventos custom si es absolutamente necesario
   - Mapear eventos genéricos en `capabilities.json → event_config`

6. **Antes de commit, ejecutar mentalmente este checklist**:
   ```
   [ ] ¿Modifiqué el core? → Si SÍ, revertir y consultar
   [ ] ¿Agregué rutas? → Verificar routes.php y capabilities.json
   [ ] ¿Cambié visibilidad de métodos? → Usar wrappers en su lugar
   [ ] ¿Usé eventos genéricos? → Verificar mapeo en event_config
   [ ] ¿Paths coinciden? → Frontend debe coincidir con backend
   ```

7. **Si tienes duda**, consulta primero en lugar de modificar el core.

8. **Recuerda la filosofía**: Extender, no modificar. Cada juego es independiente, los módulos son genéricos.

---

## 💬 Frases Clave para Activar este Contexto

Cuando el usuario diga cualquiera de estas frases, ACTIVA este contexto completo:

- "siguiendo las convenciones"
- "con la metodología del proyecto"
- "respetando la arquitectura"
- "sin modificar el core"
- "como lo hacemos siempre"
- "verifica las convenciones primero"

Cuando escuches estas frases, debes:
1. Leer mentalmente este documento
2. Verificar `DEVELOPMENT_CONVENTIONS.md`
3. NO modificar el core sin consultar
4. Usar patrones establecidos

---

## ✅ Checklist Rápido (Memorizar)

**Antes de CADA implementación**:

1. ¿Estoy modificando el core? → **Consultar primero**
2. ¿Dónde van las rutas? → **`games/{slug}/routes.php`**
3. ¿Actualicé capabilities.json? → **Siempre**
4. ¿Necesito método protegido? → **Crear wrapper público**
5. ¿Puedo usar evento genérico? → **Preferir genéricos**
6. ¿Frontend coincide con backend? → **Verificar paths**
7. ¿Timing/delays? → **Juego decide, no BaseEngine**
8. ¿Tests funcionan? → **Ejecutar antes de commit**

---

**IMPORTANTE**: Este documento es la fuente de verdad para el desarrollo. Cuando tengas dudas, consulta aquí PRIMERO antes de implementar.
