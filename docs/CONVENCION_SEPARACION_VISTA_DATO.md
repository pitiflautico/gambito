# Convención: Separación Vista / Dato

## Concepto

El `game_state` se divide en dos secciones principales:
- `_data`: Datos del modelo/negocio (se guardan en BD, se incluyen en snapshots)
- `_ui`: Datos de presentación (solo para frontend, NO se guardan en BD, NO en snapshots)

## Estructura de _ui

La sección `_ui` se organiza en niveles según su visibilidad:

```php
game_state: {
    _data: {
        // Datos del modelo que se guardan en BD y snapshots
        player_system: {...},
        round_system: {...},
        // ...
    },
    _ui: {
        // 1. GENERAL: Elementos siempre visibles
        general: {
            show_header: true,
            show_scores: true,
            show_round_counter: true,
            animations: {
                confetti: false,
                shake: false
            }
        },

        // 2. POR FASE: Elementos específicos de cada fase
        phases: {
            answer: {
                show_input: true,
                show_timer: true,
                input_placeholder: "Escribe tu respuesta..."
            },
            voting: {
                show_options: true,
                show_votes_count: false,
                options_layout: "grid"  // "grid" | "list"
            },
            results: {
                show_winner: true,
                show_all_answers: true,
                highlight_best: true
            }
        },

        // 3. TRANSICIONES: Estados temporales de animaciones
        transitions: {
            phase_changing: false,
            round_ending: false,
            player_joined: null  // player_id que acaba de unirse
        }
    }
}
```

## Helpers en BaseGameEngine

```php
// Establecer datos de modelo
$this->setData($match, 'player_system.current_player', 123);

// Obtener datos de modelo
$currentPlayer = $this->getData($match, 'player_system.current_player', null);

// Establecer datos de presentación general
$this->setUI($match, 'general.show_scores', true);

// Establecer datos de presentación por fase
$this->setUI($match, 'phases.voting.show_votes_count', false);

// Establecer animaciones temporales
$this->setUI($match, 'transitions.phase_changing', true);

// Obtener datos de presentación
$showTimer = $this->getUI($match, 'phases.answer.show_timer', false);
```

## Convención de Nombres

### General (siempre visible)
- `show_*`: Booleanos de visibilidad (ej: `show_header`, `show_scores`)
- `animations.*`: Animaciones globales (ej: `confetti`, `shake`)

### Por Fase
- `phases.{fase}.show_*`: Elementos visibles en la fase
- `phases.{fase}.*_layout`: Tipo de diseño ("grid", "list", etc.)
- `phases.{fase}.*_style`: Estilo visual ("minimal", "detailed", etc.)

### Transiciones
- `transitions.*_changing`: Estados de transición (ej: `phase_changing`)
- `transitions.*_ending`: Estados de finalización (ej: `round_ending`)
- `transitions.player_*`: Eventos de jugadores (ej: `player_joined`)

## Ejemplo Completo: MockupEngine

```php
// En initialize()
$this->setUI($match, 'general.show_header', true);
$this->setUI($match, 'general.show_scores', true);
$this->setUI($match, 'phases.answer.show_input', false);  // Inicialmente oculto

// Al cambiar a fase "answer"
$this->setUI($match, 'phases.answer.show_input', true);
$this->setUI($match, 'phases.answer.input_placeholder', '¿Verdadero o Falso?');
$this->setUI($match, 'transitions.phase_changing', true);

// Al terminar transición
$this->setUI($match, 'transitions.phase_changing', false);

// Al mostrar resultados
$this->setUI($match, 'phases.results.show_winner', true);
$this->setUI($match, 'general.animations.confetti', true);
```

## Beneficios

1. **Snapshots Eficientes**: `_ui` se excluye de snapshots, reduciendo tamaño
2. **Separación Clara**: Frontend sabe que `_ui` es solo presentación
3. **Organización**: Estructura jerárquica facilita encontrar datos
4. **Convención**: Todos los juegos siguen el mismo patrón

## Reglas Importantes

1. **NUNCA** guardar datos de lógica en `_ui`
2. **SIEMPRE** usar `_data` para cálculos y decisiones del juego
3. `_ui` puede regenerarse desde `_data` en cualquier momento
4. `_ui` es opcional - el juego debe funcionar sin él
