# 📖 Cómo Usar el Sistema de Convenciones con Claude Code

Este directorio contiene la configuración y contexto para que Claude Code siempre siga las convenciones del proyecto GroupsGames.

---

## 🎯 Objetivo

Asegurar que Claude Code:
- **Nunca modifique el core** sin consultar primero
- **Siempre respete la arquitectura modular**
- **Siga las convenciones establecidas** en cada implementación
- **Use los patrones correctos** para cada tipo de tarea

---

## 📁 Archivos en este Directorio

### `.claude/project-context.md`
**Contexto principal del proyecto** que Claude Code debe tener siempre presente.

Contiene:
- Filosofía de desarrollo
- Reglas críticas (qué NUNCA hacer)
- Patrones específicos por tipo de tarea
- Checklist rápido antes de cada implementación

### `.claude/commands/`
**Slash commands personalizados** para automatizar tareas comunes.

Comandos disponibles:
- `/create-game` - Asistente interactivo para crear nuevos juegos

Ver [commands/README.md](commands/README.md) para más detalles.

### `.claude/README.md` (este archivo)
Guía de uso del sistema de convenciones.

---

## 🚀 Cómo Activar las Convenciones

### Método 1: Frases Clave (Recomendado)

Claude Code detecta automáticamente estas frases y activa el contexto completo:

```
"siguiendo las convenciones"
"con la metodología del proyecto"
"respetando la arquitectura"
"sin modificar el core"
"como lo hacemos siempre"
"verifica las convenciones primero"
```

**Ejemplo de uso:**
```
Usuario: "Agrega un endpoint para skip question en Trivia, siguiendo las convenciones"
```

Claude Code automáticamente:
1. Leerá `.claude/project-context.md`
2. Verificará `DEVELOPMENT_CONVENTIONS.md`
3. Implementará usando los patrones correctos
4. NO modificará el core
5. Actualizará `routes.php` y `capabilities.json`

### Método 2: Referencia Explícita

Puedes referenciar el archivo directamente:

```
Usuario: "Lee .claude/project-context.md y luego implementa X"
```

### Método 3: Inicio de Sesión

Al comenzar una sesión de desarrollo, puedes decir:

```
Usuario: "Vamos a trabajar en el proyecto siguiendo las convenciones establecidas"
```

---

## ✅ Verificación: ¿Está Funcionando?

Cuando Claude Code esté usando las convenciones correctamente, verás:

1. **Antes de modificar el core**, te consultará primero:
   ```
   "Esto requiere modificar BaseGameEngine. ¿Deberíamos consultar primero
   o prefieres que cree un wrapper público en TriviaEngine?"
   ```

2. **Al agregar rutas**, automáticamente:
   - Las pondrá en `games/{slug}/routes.php`
   - Actualizará `capabilities.json`
   - Verificará que los paths coincidan

3. **Al trabajar con eventos**, preferirá genéricos:
   ```
   "Voy a usar RoundStartedEvent (genérico) en vez de crear
   TriviaQuestionStartedEvent (custom)"
   ```

4. **Expondrá APIs públicas** cuando necesite métodos protegidos:
   ```php
   // Creará esto en TriviaEngine
   public function advanceToNextRound(GameMatch $match): void
   {
       $this->startNewRound($match);
   }
   ```

---

## 🔧 Mantenimiento del Sistema

### Actualizar Convenciones

Si las convenciones cambian:

1. Actualiza `DEVELOPMENT_CONVENTIONS.md` (documento principal)
2. Actualiza `.claude/project-context.md` (contexto para Claude)
3. Sincroniza ambos documentos

### Agregar Nuevos Patrones

Cuando descubras un nuevo patrón útil:

1. Documéntalo en `DEVELOPMENT_CONVENTIONS.md`
2. Agrégalo a `.claude/project-context.md` en la sección "Patrones Específicos"
3. Incluye ejemplo de código

### Agregar Nueva Frase Clave

Para agregar una frase que active el contexto:

Edita `.claude/project-context.md` y agrégala en la sección:
```markdown
## 💬 Frases Clave para Activar este Contexto
```

---

## 📚 Documentos Relacionados

| Documento | Propósito | Cuándo Leer |
|-----------|-----------|-------------|
| `.claude/project-context.md` | Contexto para Claude Code | Automático con frases clave |
| `DEVELOPMENT_CONVENTIONS.md` | Checklist completo de desarrollo | Al implementar features |
| `MODULAR_ARCHITECTURE.md` | Diseño de módulos | Al diseñar nuevos módulos |
| `TECHNICAL_DECISIONS.md` | Decisiones arquitectónicas | Al tomar decisiones técnicas |

---

## 🎓 Entrenamiento: Cómo Usar Esto en tu Flujo

### Flujo de Trabajo Ideal

1. **Al iniciar sesión:**
   ```
   "Hola, vamos a trabajar siguiendo las convenciones del proyecto"
   ```

2. **Al pedir una feature:**
   ```
   "Implementa X, respetando la arquitectura"
   ```

3. **Si Claude empieza a modificar el core:**
   ```
   "Espera, verifica las convenciones primero"
   ```

4. **Al hacer code review:**
   ```
   "Revisa esto con la metodología del proyecto en mente"
   ```

### Ejemplo Completo

```
Usuario: "Quiero agregar un sistema de hints en Trivia, siguiendo las convenciones"

Claude Code:
✅ Lee .claude/project-context.md
✅ Verifica que NO modifica el core
✅ Crea endpoint en games/trivia/routes.php
✅ Actualiza capabilities.json
✅ Expone API pública en TriviaEngine
✅ Usa eventos genéricos si es posible
✅ Verifica que paths coincidan

Usuario: "Perfecto, ahora haz lo mismo para Pictionary"

Claude Code:
✅ Aplica el mismo patrón
✅ Mantiene consistencia entre juegos
```

---

## 🐛 Troubleshooting

### "Claude modificó el core sin consultar"

**Solución:** Usa frases clave más explícitas:
```
"sin modificar el core"
"consultando primero si es necesario"
```

### "Claude no actualizó capabilities.json"

**Solución:** Recuérdale explícitamente:
```
"siguiendo las convenciones" (incluye actualizar capabilities.json)
```

### "Claude creó evento custom innecesario"

**Solución:** Especifica:
```
"usando eventos genéricos cuando sea posible"
```

---

## 🎯 Objetivo Final

**Meta:** Que puedas decir simplemente:

```
"Implementa X siguiendo las convenciones"
```

Y Claude Code automáticamente:
- ✅ NO modifique el core
- ✅ Use la estructura correcta de archivos
- ✅ Actualice `routes.php` y `capabilities.json`
- ✅ Exponga APIs públicas cuando sea necesario
- ✅ Use eventos genéricos
- ✅ Verifique que todo coincida

**Sin necesidad de microgestión.**

---

## 📞 Frases Útiles para Recordar

| Situación | Frase Clave |
|-----------|-------------|
| Implementar feature nueva | "siguiendo las convenciones" |
| Antes de modificar core | "verifica las convenciones primero" |
| Code review | "respetando la arquitectura" |
| Debugging | "como lo hacemos siempre" |
| Consultar antes | "sin modificar el core" |

---

**¡Listo!** Ahora tienes un sistema consistente para que Claude Code siempre trabaje según las convenciones del proyecto.
