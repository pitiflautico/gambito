# Convención: Configuración Customizable de Juegos

**Versión:** 1.0
**Última actualización:** 2025-10-21
**Estado:** ✅ Activo

---

## 📋 Descripción

Cada juego puede definir **parámetros configurables** que el master de la sala puede customizar al crear una partida. Esto permite adaptar la experiencia de juego sin modificar código.

**Ejemplos:**
- Número de rondas (automático vs personalizado)
- Duración de turnos (60s, 90s, 120s)
- Dificultad (fácil, media, difícil)
- Opciones booleanas (permitir pistas, modo equipo, etc.)

---

## 🎯 Objetivos

1. **Flexibilidad:** Permitir personalizar partidas sin tocar código
2. **Consistencia:** Todos los juegos usan el mismo sistema declarativo
3. **UI Automática:** Generar formularios dinámicos desde `config.json`
4. **Validación:** Garantizar que los valores sean válidos
5. **Defaults inteligentes:** Valores por defecto razonables para cada juego

---

## 📦 Estructura del `config.json`

Cada juego debe tener un archivo `games/{slug}/config.json` con la siguiente estructura:

```json
{
  "id": "game-slug",
  "name": "Game Name",
  "slug": "game-slug",
  "description": "Descripción breve del juego",
  "minPlayers": 2,
  "maxPlayers": 10,
  "estimatedDuration": "15-30 minutos",
  "type": "category",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito",

  "customizableSettings": {
    // Configuraciones personalizables (ver sección siguiente)
  },

  "turnSystemConfig": {
    "mode": "sequential",
    "allowModeChange": false,
    "description": "Descripción del comportamiento de turnos"
  }
}
```

---

## ⚙️ Tipos de Campos Configurables

### 1. **Radio Buttons** (Selección única con opciones)

```json
"rounds_mode": {
  "type": "radio",
  "label": "Número de rondas",
  "description": "Cuántas rondas jugará cada partida",
  "default": "auto",
  "options": [
    {
      "value": "auto",
      "label": "Automático (1 por jugador)",
      "description": "Cada jugador jugará una vez"
    },
    {
      "value": "custom",
      "label": "Personalizado",
      "description": "Elige el número manualmente",
      "showField": "rounds_total"
    }
  ]
}
```

**Campos obligatorios:**
- `type`: `"radio"`
- `label`: Etiqueta del campo
- `default`: Valor por defecto
- `options`: Array de opciones (mínimo 2)
  - `value`: Valor interno
  - `label`: Texto visible
  - `description`: (opcional) Ayuda contextual
  - `showField`: (opcional) Mostrar otro campo si esta opción está seleccionada

---

### 2. **Select Dropdown** (Lista desplegable)

```json
"turn_duration": {
  "type": "select",
  "label": "Duración por turno",
  "description": "Cuántos segundos tiene cada jugador",
  "default": 90,
  "options": [
    { "value": 60, "label": "1 minuto (rápido)" },
    { "value": 90, "label": "1.5 minutos (normal)" },
    { "value": 120, "label": "2 minutos (relajado)" }
  ]
}
```

**Campos obligatorios:**
- `type`: `"select"`
- `label`: Etiqueta del campo
- `default`: Valor por defecto
- `options`: Array de opciones
  - `value`: Valor interno (puede ser número o string)
  - `label`: Texto visible

---

### 3. **Number Input** (Campo numérico)

```json
"rounds_total": {
  "type": "number",
  "label": "Total de rondas",
  "description": "Número total de rondas a jugar",
  "default": 5,
  "min": 1,
  "max": 10,
  "step": 1,
  "visibleWhen": {
    "field": "rounds_mode",
    "value": "custom"
  }
}
```

**Campos obligatorios:**
- `type`: `"number"`
- `label`: Etiqueta del campo
- `default`: Valor por defecto
- `min`: Valor mínimo
- `max`: Valor máximo
- `step`: Incremento (normalmente 1)

**Campos opcionales:**
- `visibleWhen`: Condición para mostrar el campo
  - `field`: Campo del que depende
  - `value`: Valor que debe tener ese campo

---

### 4. **Checkbox** (Opción booleana)

```json
"allow_hints": {
  "type": "checkbox",
  "label": "Permitir pistas",
  "description": "El jugador puede dar pistas verbales",
  "default": false
}
```

**Campos obligatorios:**
- `type`: `"checkbox"`
- `label`: Etiqueta del campo
- `default`: `true` o `false`

**Campos opcionales:**
- `description`: Ayuda contextual

---

## 📝 Ejemplo Completo: Pictionary

`games/pictionary/config.json`:

```json
{
  "id": "pictionary",
  "name": "Pictionary",
  "slug": "pictionary",
  "description": "Dibuja y adivina palabras antes que los demás",
  "minPlayers": 3,
  "maxPlayers": 10,
  "estimatedDuration": "15-20 minutos",
  "type": "drawing",
  "isPremium": false,
  "version": "1.0",
  "author": "Gambito",

  "customizableSettings": {
    "rounds_mode": {
      "type": "radio",
      "label": "Número de rondas",
      "description": "Cuántas rondas jugará cada partida",
      "default": "auto",
      "options": [
        {
          "value": "auto",
          "label": "Automático (1 por jugador)",
          "description": "Cada jugador dibujará una vez"
        },
        {
          "value": "custom",
          "label": "Personalizado",
          "description": "Elige el número de rondas manualmente",
          "showField": "rounds_total"
        }
      ]
    },
    "rounds_total": {
      "type": "number",
      "label": "Total de rondas",
      "description": "Número total de rondas a jugar",
      "default": 5,
      "min": 1,
      "max": 10,
      "step": 1,
      "visibleWhen": {
        "field": "rounds_mode",
        "value": "custom"
      }
    },
    "turn_duration": {
      "type": "select",
      "label": "Duración por turno",
      "description": "Cuántos segundos tiene cada jugador para dibujar",
      "default": 90,
      "options": [
        { "value": 60, "label": "1 minuto (rápido)" },
        { "value": 90, "label": "1.5 minutos (normal)" },
        { "value": 120, "label": "2 minutos (relajado)" }
      ]
    },
    "word_difficulty": {
      "type": "select",
      "label": "Dificultad de palabras",
      "description": "Nivel de dificultad de las palabras a adivinar",
      "default": "mixed",
      "options": [
        { "value": "easy", "label": "Fácil" },
        { "value": "medium", "label": "Media" },
        { "value": "hard", "label": "Difícil" },
        { "value": "mixed", "label": "Mixta (todas)" }
      ]
    },
    "allow_hints": {
      "type": "checkbox",
      "label": "Permitir pistas",
      "description": "El dibujante puede dar pistas verbales (no recomendado)",
      "default": false
    }
  },

  "turnSystemConfig": {
    "mode": "sequential",
    "allowModeChange": false,
    "description": "Los turnos siempre son secuenciales para que todos dibujen la misma cantidad de veces"
  }
}
```

---

## 💻 Implementación en el GameEngine

### 1. Leer configuración del juego

```php
class PictionaryEngine implements GameEngineInterface
{
    private function getGameConfig(): array
    {
        $configPath = base_path('games/pictionary/config.json');
        return json_decode(file_get_contents($configPath), true);
    }
}
```

### 2. Usar configuración en `initialize()`

```php
public function initialize(GameMatch $match): void
{
    $gameConfig = $this->getGameConfig();
    $roomSettings = $match->room->settings ?? [];

    // Leer configuración customizable con fallback a defaults
    $roundsMode = $roomSettings['rounds_mode']
        ?? $gameConfig['customizableSettings']['rounds_mode']['default'];

    if ($roundsMode === 'auto') {
        $totalRounds = count($playerIds); // Dinámico
    } else {
        $totalRounds = $roomSettings['rounds_total']
            ?? $gameConfig['customizableSettings']['rounds_total']['default'];
    }

    $turnDuration = $roomSettings['turn_duration']
        ?? $gameConfig['customizableSettings']['turn_duration']['default'];

    $wordDifficulty = $roomSettings['word_difficulty']
        ?? $gameConfig['customizableSettings']['word_difficulty']['default'];

    // Usar valores en la inicialización
    $turnManager = new TurnManager(
        playerIds: $playerIds,
        mode: $gameConfig['turnSystemConfig']['mode'],
        totalRounds: $totalRounds,
        startingRound: 1
    );

    $match->game_state = [
        'turn_duration' => $turnDuration,
        'word_difficulty' => $wordDifficulty,
        // ... resto del estado
    ];
}
```

---

## 🎨 Generación Automática de UI (Futuro)

### Vista Blade dinámica (Fase 5)

```blade
@foreach($game->customizableSettings as $key => $setting)
    @if($setting['type'] === 'radio')
        <div class="form-group">
            <label>{{ $setting['label'] }}</label>
            <small class="text-muted">{{ $setting['description'] }}</small>

            @foreach($setting['options'] as $option)
                <div class="form-check">
                    <input type="radio"
                           name="{{ $key }}"
                           value="{{ $option['value'] }}"
                           {{ $option['value'] === $setting['default'] ? 'checked' : '' }}>
                    <label>{{ $option['label'] }}</label>
                    <small>{{ $option['description'] ?? '' }}</small>
                </div>
            @endforeach
        </div>
    @endif

    @if($setting['type'] === 'select')
        <div class="form-group">
            <label>{{ $setting['label'] }}</label>
            <small class="text-muted">{{ $setting['description'] }}</small>
            <select name="{{ $key }}">
                @foreach($setting['options'] as $option)
                    <option value="{{ $option['value'] }}"
                            {{ $option['value'] === $setting['default'] ? 'selected' : '' }}>
                        {{ $option['label'] }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    @if($setting['type'] === 'number')
        @if(!isset($setting['visibleWhen']) || shouldShowField($setting['visibleWhen']))
            <div class="form-group">
                <label>{{ $setting['label'] }}</label>
                <input type="number"
                       name="{{ $key }}"
                       value="{{ $setting['default'] }}"
                       min="{{ $setting['min'] }}"
                       max="{{ $setting['max'] }}"
                       step="{{ $setting['step'] }}">
            </div>
        @endif
    @endif

    @if($setting['type'] === 'checkbox')
        <div class="form-check">
            <input type="checkbox"
                   name="{{ $key }}"
                   {{ $setting['default'] ? 'checked' : '' }}>
            <label>{{ $setting['label'] }}</label>
            <small>{{ $setting['description'] }}</small>
        </div>
    @endif
@endforeach
```

---

## ✅ Validación

### Validación en `RoomController::store()`

```php
public function store(Request $request)
{
    $game = Game::findOrFail($request->game_id);
    $config = json_decode(file_get_contents(base_path("games/{$game->slug}/config.json")), true);

    // Validar campos customizables
    $rules = [];
    foreach ($config['customizableSettings'] as $key => $setting) {
        switch ($setting['type']) {
            case 'number':
                $rules[$key] = ['nullable', 'integer', "min:{$setting['min']}", "max:{$setting['max']}"];
                break;
            case 'select':
            case 'radio':
                $validValues = array_column($setting['options'], 'value');
                $rules[$key] = ['nullable', Rule::in($validValues)];
                break;
            case 'checkbox':
                $rules[$key] = ['nullable', 'boolean'];
                break;
        }
    }

    $validated = $request->validate($rules);

    // Guardar en room settings
    $room = Room::create([
        'game_id' => $game->id,
        'code' => $this->generateRoomCode(),
        'settings' => $validated, // Settings personalizados
    ]);

    return redirect()->route('rooms.lobby', $room->code);
}
```

---

## 📊 Almacenamiento

### Tabla `rooms`

```php
Schema::create('rooms', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->foreignId('game_id')->constrained();
    $table->json('settings')->nullable(); // <-- Aquí se guardan las customizaciones
    $table->timestamps();
});
```

### Ejemplo de `settings` guardados:

```json
{
  "rounds_mode": "custom",
  "rounds_total": 7,
  "turn_duration": 120,
  "word_difficulty": "hard",
  "allow_hints": false
}
```

---

## 🚀 Roadmap

### Fase 4 (Actual)
- ✅ Definir estructura en `config.json`
- ✅ Implementar lectura en `GameEngine`
- ✅ Usar valores en Pictionary

### Fase 5 (Futuro)
- [ ] Generar UI automática desde `config.json`
- [ ] Validación dinámica en backend
- [ ] Preview de configuración en lobby
- [ ] Guardar configuraciones favoritas

---

## 📚 Referencias

- **Ejemplo:** [`games/pictionary/config.json`](../../games/pictionary/config.json)
- **Engine:** [`games/pictionary/PictionaryEngine.php`](../../games/pictionary/PictionaryEngine.php)
- **Turn System:** [`docs/modules/optional/TURN_SYSTEM.md`](../modules/optional/TURN_SYSTEM.md)

---

**Mantenido por:** Equipo de desarrollo Gambito
**Última revisión:** 2025-10-21
