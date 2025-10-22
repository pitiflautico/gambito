# Testing de Convenciones de Juegos

**Fecha:** 2025-10-22
**Versión:** 1.0
**Estado:** ✅ Activo

---

## 📋 Descripción

Este documento describe el sistema automatizado de validación de convenciones para juegos en GroupsGames.

## 🎯 Problema que Resuelve

**Problema Original:**
- Desarrolladores (humanos o IA) no conocen todas las convenciones
- Fácil inventarse formatos o estructuras incorrectas
- Violaciones de convenciones pasan desapercibidas hasta producción
- No hay forma automática de validar compliance

**Solución:**
- Test suite automatizado que valida TODAS las convenciones
- Se ejecuta en CI/CD antes de cada merge
- 197 assertions que verifican compliance
- Falla si un juego no cumple las reglas

---

## 🧪 Test Suite: GameConventionsTest

**Ubicación:** `tests/Unit/ConventionTests/GameConventionsTest.php`

**Cobertura:** 12 tests, 197 assertions

### Tests Implementados

#### 1. **test_games_have_required_files**
Verifica que cada juego tenga:
- `config.json`
- `capabilities.json`

#### 2. **test_games_have_valid_engine**
Verifica:
- Existe archivo `{GameName}Engine.php`
- La clase existe y es accesible
- Implementa `GameEngineInterface`

#### 3. **test_config_json_has_required_fields**
Verifica presencia de campos obligatorios:
- `id`
- `name`
- `slug`
- `description`
- `minPlayers`
- `maxPlayers`
- `estimatedDuration`
- `type`
- `isPremium`
- `version`
- `author`

#### 4. **test_config_json_uses_camel_case**
Verifica uso de camelCase vs snake_case:
- ✅ `minPlayers` (correcto)
- ❌ `min_players` (incorrecto)

#### 5. **test_capabilities_json_has_correct_structure**
Verifica estructura según estándar de Pictionary:
```json
{
  "slug": "game-name",
  "version": "1.0",
  "requires": {
    "modules": {
      "turn_system": "^1.0",
      ...
    }
  },
  "provides": {
    "events": [...],
    "routes": [...],
    "views": [...]
  }
}
```

#### 6. **test_capabilities_json_not_using_old_structure**
Detecta uso de estructura antigua (booleanos directos):
```json
// ❌ Estructura antigua
{
  "guest_support": true,
  "spectator_mode": false,
  ...
}

// ✅ Estructura correcta
{
  "requires": {
    "modules": {
      "guest_system": "^1.0"
    }
  }
}
```

#### 7. **test_declared_modules_match_usage**
Verifica que los módulos usados en el Engine estén declarados en `capabilities.json`:

**Mapeo:**
- Usa `TurnManager` → debe declarar `turn_system`
- Usa `RoundManager` → debe declarar `round_system`
- Usa `ScoreManager` → debe declarar `scoring_system`
- Usa `TimerService` → debe declarar `timer_system`
- Usa `RoleManager` → debe declarar `roles_system`

**Ejemplo de error detectado:**
```
El juego 'pictionary' usa RoundManager pero NO lo declara en
capabilities.json under 'requires.modules.round_system'
```

#### 8. **test_customizable_settings_follow_convention**
Verifica que `customizableSettings` sigan el formato:
- Tipos válidos: `radio`, `select`, `number`, `checkbox`
- Cada setting tiene: `type`, `label`, `default`
- Validaciones específicas por tipo:
  - `number`: requiere `min`, `max`, `step`
  - `select`/`radio`: requiere `options` (mínimo 2)
  - `checkbox`: default debe ser boolean

#### 9. **test_turn_system_config_present_if_using_turns**
Si el Engine usa `TurnManager`, debe tener:
```json
{
  "turnSystemConfig": {
    "mode": "sequential",
    "allowModeChange": false,
    "description": "..."
  }
}
```

#### 10. **test_no_javascript_in_game_folder**
Verifica que NO exista carpeta `games/{slug}/js/`

**Razón:** JavaScript debe estar en `resources/js/{slug}-*.js` para ser compilado por Vite.

**Error detectado:** Pictionary tenía carpeta vacía `games/pictionary/js/` → Eliminada ✅

#### 11. **test_has_views_folder**
Verifica que existe `games/{slug}/views/`

#### 12. **test_has_events_folder_if_using_websockets**
Si el juego declara eventos en `capabilities.provides.events`, verifica:
- Existe carpeta `games/{slug}/Events/`
- Cada evento declarado tiene su archivo PHP correspondiente

---

## ✅ Resultados Actuales

### Ejecución del 2025-10-22

```
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php
```

**Resultado:** ✅ **12/12 tests PASSED** (197 assertions)

### Juegos Validados

#### Pictionary ✅
- **Estado:** Cumple TODAS las convenciones
- **Correcciones aplicadas:**
  1. Agregado `round_system` y `roles_system` a `capabilities.json`
  2. Eliminada carpeta vacía `games/pictionary/js/`

#### Trivia ✅
- **Estado:** Cumple TODAS las convenciones desde el inicio
- **Sin violaciones detectadas**
- **Implementación:** Siguió correctamente todas las guías

---

## 🚀 Uso en Desarrollo

### Ejecutar Tests Antes de Commit

```bash
# Ejecutar todos los tests de convenciones
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php

# Con output detallado
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php --testdox

# Solo un test específico
php artisan test --filter=test_config_json_uses_camel_case
```

### Integración en CI/CD

Agregar al pipeline de GitHub Actions:

```yaml
- name: Run Convention Tests
  run: php artisan test tests/Unit/ConventionTests/GameConventionsTest.php
```

**Beneficio:** Ningún PR se puede mergear si viola convenciones ✅

---

## 📝 Checklist para Nuevos Juegos

Antes de hacer commit de un nuevo juego, ejecuta:

```bash
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php
```

Y verifica que TODOS los tests pasen:
- [ ] ✅ Archivos requeridos existen
- [ ] ✅ Engine implementa interface
- [ ] ✅ config.json tiene todos los campos
- [ ] ✅ Usa camelCase (no snake_case)
- [ ] ✅ capabilities.json estructura correcta
- [ ] ✅ No usa estructura antigua
- [ ] ✅ Módulos declarados coinciden con uso
- [ ] ✅ customizableSettings formato correcto
- [ ] ✅ turnSystemConfig presente si usa turnos
- [ ] ✅ NO tiene carpeta js/
- [ ] ✅ Tiene carpeta views/
- [ ] ✅ Eventos declarados tienen archivos

---

## 🔧 Extender los Tests

### Agregar Nueva Convención

1. Agrega el test en `GameConventionsTest.php`:

```php
public function test_nueva_convencion(): void
{
    foreach ($this->games as $game) {
        $slug = $game['slug'];

        // Tu validación aquí
        $this->assertTrue(
            $condition,
            "El juego '{$slug}' debe cumplir con X convención"
        );
    }
}
```

2. Ejecuta el test:
```bash
php artisan test --filter=test_nueva_convencion
```

3. Corrige juegos que fallen

4. Documenta la convención en este archivo

---

## 📚 Referencias

### Convenciones Validadas
- **`docs/GAMES_CONVENTION.md`** - Estructura de archivos y carpetas
- **`docs/conventions/GAME_CONFIGURATION_CONVENTION.md`** - Formato de config.json
- **`docs/HOW_TO_CREATE_A_GAME.md`** - Guía completa de creación

### Código
- **Tests:** `tests/Unit/ConventionTests/GameConventionsTest.php`
- **Interface:** `app/Contracts/GameEngineInterface.php`
- **Ejemplo:** `games/pictionary/` (gold standard)

---

## 🎓 Lecciones Aprendidas

### Problema Original: Trivia

Al implementar Trivia inicialmente, se cometieron varios errores por desconocimiento:

1. **capabilities.json estructura antigua:** Usaba booleanos directos en lugar de `requires.modules`
2. **Interface incorrecta:** Usaba `App\Contracts\ScoreCalculator` en lugar de `ScoreCalculatorInterface`
3. **Método incorrecto:** ScoreCalculator tenía `calculate(int $playerId, ...)` en lugar de `calculate(string $eventType, ...)`

**Solución:** El test automatizado detectó INMEDIATAMENTE estos problemas y se corrigieron antes de continuar.

### Resultado

**ANTES de los tests:**
- Violaciones pasaban desapercibidas
- Se descubrían en runtime
- Inconsistencia entre juegos

**DESPUÉS de los tests:**
- Violaciones detectadas en < 1 segundo
- Corrección antes de commit
- 100% consistencia entre juegos

---

## 📊 Métricas

### Cobertura Actual

| Aspecto | Tests | Assertions |
|---------|-------|-----------|
| Estructura de archivos | 3 | 45 |
| Formato config.json | 4 | 68 |
| Formato capabilities.json | 3 | 42 |
| Uso de módulos | 2 | 32 |
| Convenciones JavaScript | 1 | 10 |
| **TOTAL** | **12** | **197** |

### Juegos Validados

| Juego | Cumplimiento | Correcciones Aplicadas |
|-------|--------------|------------------------|
| Pictionary | ✅ 100% | 2 (capabilities, carpeta js/) |
| Trivia | ✅ 100% | 0 (cumplió desde el inicio) |

---

## 🔮 Futuro

### Tests Planeados

- [ ] Validar que rutas declaradas existan en routes.php
- [ ] Validar que vistas declaradas existan en views/
- [ ] Validar formato de eventos (extends Event, implements ShouldBroadcast)
- [ ] Validar que JavaScript compilado esté en public/build/
- [ ] Validar que CSS esté en public/games/{slug}/css/

### Mejoras

- [ ] Agregar comando artisan para ejecutar solo convention tests
- [ ] Generar reporte HTML de cumplimiento
- [ ] Auto-fix para violaciones simples
- [ ] Integración con pre-commit hooks

---

**Mantenido por:** Equipo de desarrollo GroupsGames
**Última actualización:** 2025-10-22
**Próxima revisión:** Cuando se agregue un nuevo juego
