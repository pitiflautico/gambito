# Pictionary - Fases del Juego

**Versión:** 1.0
**Fecha:** 2025-10-23

## 🎮 Descripción del Juego

Pictionary es un juego de dibujo y adivinanzas donde:
- Un jugador (**dibujante**) recibe una palabra secreta
- Debe dibujarla en un canvas compartido
- Los demás jugadores (**adivinadores**) intentan adivinar la palabra
- El primero en adivinar correctamente gana puntos

---

## 📊 Fases del Juego

Pictionary tiene **3 fases principales** que controlan el flujo del juego:

### **1. PLAYING** 🎨

**Descripción:** Fase activa donde se desarrolla el juego

**Duración:** Variable (hasta que alguien adivine o se acabe el tiempo)

**Acciones permitidas:**
- **Dibujante:**
  - Dibujar en el canvas
  - Ver la palabra secreta
  - Confirmar respuestas de adivinadores (✓ Correcta / ✗ Incorrecta)

- **Adivinadores:**
  - Ver el canvas en tiempo real
  - Presionar botón "¡YA LO SÉ!" para indicar que saben la respuesta
  - Decir la respuesta en voz alta al dibujante

**Finaliza cuando:**
- ✅ El dibujante confirma una respuesta como **correcta**
- ✅ Todos los adivinadores han **fallado** (eliminación temporal)
- ✅ Se agota el **tiempo** del turno (90 segundos por defecto)

**Transición:**
- `PLAYING` → `SCORING` (al finalizar el turno)

---

### **2. SCORING** 📈

**Descripción:** Muestra los resultados de la ronda

**Duración:** 3 segundos (controlado por Frontend)

**Acciones permitidas:**
- ❌ Ninguna (fase de visualización)

**Visualiza:**
- Jugador que adivinó correctamente
- Puntos otorgados (dibujante + adivinador)
- Ranking actualizado

**Finaliza cuando:**
- Frontend detecta la fase `scoring`
- Espera 3 segundos
- Llama a `advancePhase()` automáticamente

**Transición:**
- `SCORING` → `PLAYING` (siguiente turno) si quedan rondas
- `SCORING` → `RESULTS` (fin del juego) si se completaron todas las rondas

---

### **3. RESULTS** 🏆

**Descripción:** Resultados finales del juego

**Duración:** Permanente hasta salir de la sala

**Acciones permitidas:**
- Ver ranking final
- **Master:** Botón "Nueva Partida"
- **Guest:** Botón "Volver a Juegos"

**Visualiza:**
- Ranking final ordenado por puntuación
- Estadísticas del juego
- Ganador destacado

**Finaliza cuando:**
- Usuario sale de la sala
- Master inicia una nueva partida

**Transición:**
- No hay (estado final)

---

## 🔄 Diagrama de Flujo

```
┌─────────────┐
│  INITIALIZE │
│   (setup)   │
└─────┬───────┘
      │
      ▼
┌─────────────────────────────────────┐
│          PLAYING                     │
│  ┌──────────────────────────────┐  │
│  │ Dibujante dibuja             │  │
│  │ Adivinadores intentan        │  │
│  │ Timer corre (90s)            │  │
│  └──────────────────────────────┘  │
└─────┬───────────────────────────────┘
      │ (respuesta correcta / todos fallan / timeout)
      ▼
┌─────────────────────────────────────┐
│          SCORING                     │
│  ┌──────────────────────────────┐  │
│  │ Mostrar resultados (3s)      │  │
│  │ Frontend controla timing     │  │
│  └──────────────────────────────┘  │
└─────┬───────────────────────────────┘
      │
      ├─── ¿Quedan rondas? ───┐
      │                        │
   SI │                        │ NO
      │                        │
      ▼                        ▼
┌─────────────┐        ┌─────────────┐
│   PLAYING   │        │  RESULTS    │
│ (next turn) │        │ (game over) │
└─────────────┘        └─────────────┘
```

---

## 🎯 Estrategia de Finalización

Pictionary usa `PictionaryPhaseStrategy` que cambia su lógica según la fase:

### **Fase PLAYING**
```php
// El juego decide cuándo terminar
return [
    'should_end_turn' => true,  // ← Dibujante confirmó respuesta
    'end_reason' => 'correct_answer',
    'delay_seconds' => 0,
];
```

### **Fase SCORING**
```php
// Frontend controla timing, NO terminar automáticamente
return [
    'should_end' => false,
    'reason' => 'awaiting_frontend',
];
```

### **Fase RESULTS**
```php
// Juego terminado
return [
    'should_end' => false,
    'reason' => 'game_finished',
];
```

---

## 📋 Estado del Juego por Fase

### **PLAYING**
```php
[
    'phase' => 'playing',
    'current_round' => 2,
    'rounds_total' => 5,
    'current_drawer_id' => 3,
    'current_word' => 'perro',  // Solo visible para dibujante
    'pending_answer' => [
        'player_id' => 5,
        'player_name' => 'Ana',
    ],
    'timer' => [
        'turn_timer' => [
            'started_at' => '2025-10-23 10:00:00',
            'duration' => 90,
        ],
    ],
]
```

### **SCORING**
```php
[
    'phase' => 'scoring',
    'current_round' => 2,
    'last_guesser' => [
        'player_id' => 5,
        'player_name' => 'Ana',
        'points_earned' => 80,
    ],
    'drawer_bonus' => [
        'player_id' => 3,
        'player_name' => 'Carlos',
        'points_earned' => 50,
    ],
    'scores' => [
        3 => 150,  // Carlos (dibujante)
        5 => 180,  // Ana (adivinó)
        7 => 100,  // Luis
    ],
]
```

### **RESULTS**
```php
[
    'phase' => 'results',
    'final_ranking' => [
        ['player_id' => 5, 'name' => 'Ana', 'score' => 420],
        ['player_id' => 3, 'name' => 'Carlos', 'score' => 380],
        ['player_id' => 7, 'name' => 'Luis', 'score' => 290],
    ],
    'statistics' => [
        'total_rounds' => 5,
        'total_turns' => 20,
        'average_time_per_turn' => 45,
    ],
]
```

---

## 🎭 Roles por Fase

### **Dibujante (Drawer)**

| Fase | Puede Ver | Puede Hacer |
|------|-----------|-------------|
| PLAYING | Palabra secreta, canvas, timer | Dibujar, confirmar respuestas |
| SCORING | Resultados | - |
| RESULTS | Ranking final | Iniciar nueva partida (si es master) |

### **Adivinador (Guesser)**

| Fase | Puede Ver | Puede Hacer |
|------|-----------|-------------|
| PLAYING | Canvas, timer, NO palabra | Presionar "¡YA LO SÉ!" |
| SCORING | Resultados | - |
| RESULTS | Ranking final | Volver a juegos |

---

## ⏱️ Timers por Fase

### **PLAYING**
- **`turn_timer`**: 90 segundos por turno
- Se crea al iniciar el turno
- Se detiene al confirmar respuesta

### **SCORING**
- **Frontend countdown**: 3 segundos
- Controlado por JavaScript, no por Backend

### **RESULTS**
- Sin timers

---

## 🚀 Próximas Fases (Futuras)

Posibles fases adicionales para extensiones:

### **WORD_SELECTION** (futuro)
- Dibujante elige entre 3 palabras aleatorias
- Aumenta variedad y estrategia

### **PAUSE** (futuro)
- Master puede pausar el juego
- Timer se detiene
- Se puede reanudar

---

## 📚 Referencias

- `PictionaryEngine.php` - Lógica principal del juego
- `PictionaryPhaseStrategy.php` - Estrategia de finalización
- `pictionary-canvas.js` - Frontend que detecta fases
- `docs/strategies/END_ROUND_STRATEGIES.md` - Documentación de estrategias

---

**Última actualización:** 2025-10-23
