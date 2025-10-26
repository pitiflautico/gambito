# Slash Commands de Claude Code

Este directorio contiene comandos personalizados para Claude Code que automatizan tareas comunes del proyecto.

## Comandos Disponibles

### `/create-game` - Crear Nuevo Juego

Asistente interactivo para crear nuevos juegos siguiendo la arquitectura modular del proyecto.

**Requisitos**:
- Comando `/generate-tasks` instalado (del sistema [ai-dev-tasks](https://github.com/cloudstudio/ai-dev-tasks))
- Comando `/process-task-list` instalado (opcional, para implementación guiada)

**Instalación de ai-dev-tasks**:
```bash
# Clonar repositorio
git clone https://github.com/cloudstudio/ai-dev-tasks.git /tmp/ai-dev-tasks

# Ejecutar instalador
cd /tmp/ai-dev-tasks && bash install.sh
```

**Uso**:
```
/create-game
```

**Qué hace**:
1. Te hace 12 preguntas sobre tu juego (nombre, tipo, jugadores, equipos, rondas, etc.)
2. Mapea automáticamente las respuestas a los módulos del sistema
3. Genera toda la estructura de archivos:
   - `{GameName}Engine.php` con TODOs
   - `{GameName}ScoreCalculator.php` (si tiene puntuación)
   - `config.json` con módulos configurados
   - `views/game.blade.php` template
   - `js/{GameName}GameClient.js` template
4. Crea lista de tareas fase por fase
5. Valida la configuración y archivos generados

**Resultado**:
- Estructura completa del juego lista para desarrollar
- TODOs marcados en lugares que requieren lógica específica
- Zero código duplicado (hereda de BaseGameEngine)
- Configuración validada contra convenciones del proyecto

**Documentación de referencia**:
- `docs/CREATE_GAME_GUIDE.md` - Guía completa con templates
- `docs/GAME_MODULES_REFERENCE.md` - Detalles de módulos
- `docs/CONVENTIONS.md` - Convenciones del proyecto
- `docs/BASE_ENGINE_CLIENT_DESIGN.md` - Arquitectura base

**Ejemplo de uso**:
```
Usuario: /create-game

Claude: 🎮 ¿Cómo se llama tu juego?
Usuario: Speed Quiz

Claude: 📝 Describe brevemente el juego
Usuario: Quiz de preguntas rápidas con bonus por velocidad

[... más preguntas ...]

Claude: ✨ ¡Juego "Speed Quiz" creado con éxito!

📂 Archivos generados:
✅ games/speed-quiz/SpeedQuizEngine.php
✅ games/speed-quiz/SpeedQuizScoreCalculator.php
✅ games/speed-quiz/config.json
✅ games/speed-quiz/views/game.blade.php
✅ games/speed-quiz/js/SpeedQuizGameClient.js

🎮 Módulos configurados:
- round_system (10 rondas)
- scoring_system (puntos + bonus)
- timer_system (15s por ronda)
- turn_system (simultáneo)

📝 Próximos pasos:
1. Revisar TODOs en SpeedQuizEngine.php
2. Implementar processRoundAction()
3. Completar ScoreCalculator
...
```

---

## Cómo Funcionan los Slash Commands

Los archivos `.md` en este directorio definen comandos personalizados para Claude Code.

**Estructura**:
```markdown
---
description: Breve descripción del comando
---

# Título del Comando

[Instrucciones detalladas para Claude sobre qué hacer cuando se ejecuta el comando]
```

**Uso**:
1. El usuario escribe `/nombre-comando` en el chat
2. Claude lee el archivo `nombre-comando.md` de este directorio
3. Claude ejecuta las instrucciones definidas en el archivo

**Ventajas**:
- Automatización de tareas repetitivas
- Consistencia en cómo se hacen las cosas
- Documentación ejecutable
- Se commitea con el proyecto (disponible para todo el equipo)

---

## Agregar Nuevos Comandos

Para crear un nuevo comando:

1. Crear archivo `mi-comando.md` en este directorio
2. Agregar frontmatter con descripción:
   ```markdown
   ---
   description: Qué hace este comando
   ---
   ```
3. Escribir instrucciones detalladas para Claude
4. Commitear el archivo
5. Usar con `/mi-comando`

**Buenas prácticas**:
- Usar nombres descriptivos en kebab-case
- Incluir ejemplos de uso
- Referenciar documentación relevante del proyecto
- Validar inputs antes de generar archivos
- Mostrar next steps claros al final

---

## Más Información

- [Claude Code Docs - Slash Commands](https://docs.claude.com/claude-code)
- [Claude Code Docs - Custom Commands](https://docs.claude.com/claude-code/custom-commands)
