# [Nombre del Juego]

**Estado:** ✅ Implementado | 🚧 En desarrollo | ⏳ Pendiente
**Versión:** X.Y.Z
**Jugadores:** Min-Max
**Duración estimada:** XX-XX minutos
**Última actualización:** YYYY-MM-DD

---

## 📋 Descripción

[Descripción del juego en 2-3 líneas. Qué es y cómo se juega]

**Ejemplo:** Pictionary es un juego de dibujo y adivinanza donde un jugador dibuja una palabra mientras los demás intentan adivinarla. El primero en acertar gana puntos, y el dibujante también recibe puntos si alguien acierta rápido.

---

## 🎮 Mecánicas del Juego

### Objetivo
[Cuál es el objetivo del juego]

### Cómo se Juega

**Setup inicial:**
1. [Paso 1]
2. [Paso 2]
3. [Paso 3]

**Durante la partida:**
1. [Mechanic 1]
2. [Mechanic 2]
3. [Mechanic 3]

**Condición de Victoria:**
[Cómo se gana el juego]

---

## 🧩 Módulos Utilizados

Este juego requiere los siguientes módulos según su `capabilities.json`:

### Módulos Core (Siempre disponibles)
- ✅ **Room Manager** - Para crear y gestionar la sala
- ✅ **Player Session** - Para jugadores invitados
- ✅ **Game Registry** - Para registrar el juego

### Módulos Opcionales (Declarados en capabilities.json)

| Módulo | Usado | Configuración |
|--------|-------|---------------|
| **Guest System** | ✅ | Jugadores sin registro |
| **Turn System** | ✅ | Turnos secuenciales |
| **Scoring System** | ✅ | Puntuación individual |
| **Timer System** | ✅ | 60 segundos por turno |
| **Roles System** | ✅ | Drawer/Guesser |
| **Realtime Sync** | ✅ | WebSockets para canvas |
| **Teams System** | ❌ | No usado |
| **Card System** | ❌ | No usado |

---

## 📂 Estructura de Archivos

```
games/[nombre-juego]/
├── config.json                 ← Metadata del juego
├── capabilities.json           ← Módulos requeridos
├── [Nombre]Engine.php          ← Motor del juego (implementa GameEngineInterface)
├── assets/
│   ├── [recurso1]
│   └── [recurso2]
├── views/
│   ├── [vista1].blade.php
│   └── [vista2].blade.php
├── js/
│   └── [script].js
├── css/
│   └── [estilo].css
└── Events/
    ├── [Evento1].php
    └── [Evento2].php
```

---

## ⚙️ Configuración

### `config.json`

```json
{
  "id": "[slug]",
  "name": "[Nombre del Juego]",
  "description": "[Descripción breve]",
  "minPlayers": X,
  "maxPlayers": Y,
  "estimatedDuration": "XX-XX minutos",
  "type": "[tipo]",
  "isPremium": false,
  "version": "X.Y",
  "author": "Gambito",
  "thumbnail": "/games/[slug]/assets/thumbnail.jpg"
}
```

### `capabilities.json`

```json
{
  "slug": "[slug]",
  "version": "X.Y",
  "requires": {
    "guest_system": true,
    "turn_system": {
      "enabled": true,
      "mode": "sequential"
    },
    "scoring_system": {
      "enabled": true,
      "type": "individual"
    }
  },
  "provides": {
    "events": ["[Evento1]", "[Evento2]"],
    "routes": ["[ruta1]", "[ruta2]"],
    "views": ["[vista1].blade.php", "[vista2].blade.php"]
  }
}
```

---

## 🔧 Motor del Juego

### `[Nombre]Engine.php`

**Ubicación:** `games/[slug]/[Nombre]Engine.php`

**Namespace:** `Games\[Nombre]`

**Implementa:** `GameEngineInterface`

**Responsabilidad:** [Qué hace el Engine]

#### Métodos Principales

##### `initializeGame(GameMatch $match): void`
[Descripción de qué hace al inicializar]

**Ejemplo:**
```php
public function initializeGame(GameMatch $match): void
{
    // Lógica de inicialización
}
```

---

##### `startRound(GameMatch $match): void`
[Descripción de inicio de ronda]

---

##### `processAction(GameMatch $match, Player $player, array $action): void`
[Descripción de procesar acción]

**Acciones soportadas:**
- `[accion1]`: Descripción
- `[accion2]`: Descripción

---

##### `checkWinCondition(GameMatch $match): ?Player`
[Descripción de verificar victoria]

**Retorna:** Jugador ganador o `null`

---

### Servicios Internos del Juego

#### `[Nombre]Service.php`

[Descripción del servicio interno]

**Métodos:**
- `metodo1()`: Descripción
- `metodo2()`: Descripción

---

## 🎨 Vistas

### `[vista1].blade.php`

**Ubicación:** `games/[slug]/views/[vista1].blade.php`

**Descripción:** [Qué muestra esta vista]

**Variables:**
- `$variable1`: Descripción
- `$variable2`: Descripción

**Componentes:**
- [Componente 1]
- [Componente 2]

---

### `[vista2].blade.php`

**Ubicación:** `games/[slug]/views/[vista2].blade.php`

**Descripción:** [Qué muestra esta vista]

---

## 🎯 JavaScript

### `[script].js`

**Ubicación:** `games/[slug]/js/[script].js`

**Responsabilidad:** [Qué hace el script]

**Funciones principales:**
```javascript
function funcion1() {
    // Descripción
}

function funcion2() {
    // Descripción
}
```

---

## 🎨 Estilos

### `[estilo].css`

**Ubicación:** `games/[slug]/css/[estilo].css`

**Clases principales:**
- `.clase1`: Descripción
- `.clase2`: Descripción

---

## 📡 Eventos en Tiempo Real (Si usa Realtime Sync)

### `[Evento1]`

**Namespace:** `Games\[Nombre]\Events`

**Implementa:** `ShouldBroadcast`

**Canal:** `sala.{code}`

**Payload:**
```json
{
  "tipo": "evento1",
  "data": {
    "campo1": "valor",
    "campo2": 123
  }
}
```

**Cuándo se emite:** [Descripción]

**Ejemplo:**
```php
broadcast(new [Evento1]($match, $data));
```

---

### `[Evento2]`

[Similar estructura]

---

## 🧪 Tests

**Ubicación:** `tests/Feature/Games/[Nombre]Test.php`

**Tests implementados:**
- ✅ [Test 1]
- ✅ [Test 2]
- ✅ [Test 3]

**Ejecutar tests:**
```bash
php artisan test --filter=[Nombre]Test
```

**Ejemplo de test:**
```php
public function test_inicializar_juego_asigna_roles_correctamente()
{
    $match = GameMatch::factory()->create();
    $players = Player::factory()->count(5)->create(['match_id' => $match->id]);

    $engine = new [Nombre]Engine();
    $engine->initializeGame($match);

    // Assertions
    $this->assertNotNull($players->first()->role);
}
```

---

## 📊 Sistema de Puntuación

[Describe cómo se calculan los puntos]

**Reglas:**
- [Regla 1]: X puntos
- [Regla 2]: Y puntos
- [Regla 3]: Z puntos

**Ejemplo:**
```php
if ($tiempoRespuesta < 15) {
    $puntos = 100;
} elseif ($tiempoRespuesta < 30) {
    $puntos = 75;
} else {
    $puntos = 50;
}
```

---

## 🎭 Roles (Si usa Roles System)

### `[Rol1]`
**Cantidad:** 1 por ronda
**Visibilidad:** Público/Secreto
**Acciones:**
- [Acción 1]
- [Acción 2]

### `[Rol2]`
**Cantidad:** Resto de jugadores
**Visibilidad:** Público/Secreto
**Acciones:**
- [Acción 1]
- [Acción 2]

---

## ⏱️ Timers (Si usa Timer System)

| Timer | Duración | Avisos | Descripción |
|-------|----------|--------|-------------|
| `[timer1]` | 60s | 30s, 15s, 5s | [Descripción] |
| `[timer2]` | 120s | 60s, 30s | [Descripción] |

---

## 📦 Assets

### Recursos incluidos:

- `assets/[recurso1]` - Descripción
- `assets/[recurso2]` - Descripción
- `assets/thumbnail.jpg` - Miniatura del juego (200x200px)

---

## 🚀 Flujo de Juego

```
1. Master crea sala y selecciona el juego
   ↓
2. Jugadores se unen escaneando QR/código
   ↓
3. Master inicia partida
   ↓
4. Sistema asigna roles y turnos
   ↓
5. RONDA 1:
   a) [Paso a]
   b) [Paso b]
   c) [Paso c]
   ↓
6. RONDA 2-N: Repetir
   ↓
7. Verificar condición de victoria
   ↓
8. Mostrar resultados finales
   ↓
9. Opciones: Jugar de nuevo / Cambiar juego / Salir
```

---

## 💡 Ejemplos de Uso

### Iniciar el juego desde RoomController

```php
public function start(string $code)
{
    $room = Room::where('code', $code)->firstOrFail();
    $match = $room->match;

    // Obtener engine del juego
    $engine = app(GameRegistry::class)->getGameEngine($room->game->slug);

    // Inicializar juego
    $engine->initializeGame($match);

    broadcast(new GameStarted($room));

    return redirect()->route('rooms.show', $code);
}
```

### Procesar acción de jugador

```php
public function action(Request $request, string $code)
{
    $room = Room::where('code', $code)->firstOrFail();
    $player = $playerService->getCurrentPlayer();
    $engine = app(GameRegistry::class)->getGameEngine($room->game->slug);

    $engine->processAction($room->match, $player, [
        'type' => $request->input('action_type'),
        'data' => $request->input('action_data')
    ]);

    return response()->json(['status' => 'ok']);
}
```

---

## 🚨 Limitaciones Conocidas

- [Limitación 1]
- [Limitación 2]

---

## 🔮 Mejoras Futuras

- [ ] [Mejora 1]
- [ ] [Mejora 2]
- [ ] [Mejora 3]

---

## 🔗 Referencias

- **Engine:** [`games/[slug]/[Nombre]Engine.php`](../../games/[slug]/[Nombre]Engine.php)
- **Config:** [`games/[slug]/config.json`](../../games/[slug]/config.json)
- **Capabilities:** [`games/[slug]/capabilities.json`](../../games/[slug]/capabilities.json)
- **Tests:** [`tests/Feature/Games/[Nombre]Test.php`](../../tests/Feature/Games/[Nombre]Test.php)
- **Glosario:** [`docs/GLOSSARY.md`](../GLOSSARY.md)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../tasks/0001-prd-plataforma-juegos-sociales.md)
- **Módulos utilizados:**
  - [`docs/modules/core/ROOM_MANAGER.md`](../modules/core/ROOM_MANAGER.md)
  - [`docs/modules/optional/TURN_SYSTEM.md`](../modules/optional/TURN_SYSTEM.md)
  - [`docs/modules/optional/SCORING_SYSTEM.md`](../modules/optional/SCORING_SYSTEM.md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** YYYY-MM-DD
