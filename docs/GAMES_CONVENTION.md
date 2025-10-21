# Convención de Estructura para Juegos

## 📁 Estructura de Carpetas para Juegos

Cada juego debe seguir esta estructura estándar:

```
games/
└── {game-slug}/                    # Slug del juego (ej: pictionary, trivia, uno)
    ├── {GameName}Engine.php       # Motor del juego (ej: PictionaryEngine.php)
    ├── capabilities.json          # Módulos que requiere el juego
    ├── config.php                 # Configuración específica del juego
    ├── Events/                    # Eventos de broadcasting del juego
    │   ├── PlayerAnsweredEvent.php
    │   ├── GameStateUpdatedEvent.php
    │   └── ...
    └── views/                     # Vistas Blade del juego
        ├── canvas.blade.php
        └── ...
```

## ⚠️ IMPORTANTE: JavaScript y Assets

**NO** colocar archivos JavaScript en `games/{slug}/js/`.

**SÍ** colocar los archivos JavaScript en `resources/js/` con el siguiente patrón:

```
resources/
└── js/
    ├── {game-slug}-canvas.js      # JavaScript principal del juego
    ├── {game-slug}-lobby.js       # JavaScript del lobby (si aplica)
    └── app.js                     # Importa los módulos del juego
```

### Importación en app.js

```javascript
// resources/js/app.js
import './pictionary-canvas.js';
import './trivia-game.js';
// etc...
```

## 🎨 CSS/Estilos

Los estilos deben estar en:

```
public/
└── games/
    └── {game-slug}/
        └── css/
            └── canvas.css         # Estilos del juego
```

Y se cargan directamente en las vistas Blade:

```blade
@push('styles')
    <link rel="stylesheet" href="{{ asset('games/pictionary/css/canvas.css') }}">
@endpush
```

## 🔧 Motor del Juego (Engine)

**Ubicación:** `games/{slug}/{GameName}Engine.php`

**Namespace:** `Games\{GameName}\`

**Ejemplo:** `Games\Pictionary\PictionaryEngine`

**Debe implementar:** `App\Contracts\GameEngineInterface`

## 📝 Vistas Blade

**Ubicación:** `games/{slug}/views/`

**Registro:** Las vistas deben registrarse en el `GameServiceProvider`:

```php
// app/Providers/GameServiceProvider.php
public function boot(): void
{
    $this->loadViewsFrom(__DIR__.'/../../games/pictionary/views', 'pictionary');
    $this->loadViewsFrom(__DIR__.'/../../games/trivia/views', 'trivia');
}
```

**Uso:** `@extends('pictionary::canvas')`

## 🎯 Resumen de Ubicaciones

| Tipo de Archivo | Ubicación | ¿Se compila? |
|-----------------|-----------|-------------|
| Motor (Engine) | `games/{slug}/{Game}Engine.php` | ❌ No |
| Eventos | `games/{slug}/Events/*.php` | ❌ No |
| Vistas Blade | `games/{slug}/views/*.blade.php` | ❌ No |
| **JavaScript** | **`resources/js/{slug}-*.js`** | **✅ SÍ (Vite)** |
| CSS | `public/games/{slug}/css/*.css` | ❌ No (se carga directamente) |
| Imágenes/Assets | `public/games/{slug}/assets/` | ❌ No |

## 🚀 Workflow de Desarrollo

1. **Crear archivos PHP** en `games/{slug}/`
2. **Crear JavaScript** en `resources/js/{slug}-*.js`
3. **Importar JS** en `resources/js/app.js`
4. **Compilar:** `npm run build` o `npm run dev`
5. **Crear CSS** en `public/games/{slug}/css/`
6. **Cargar CSS** en vista Blade con `@push('styles')`

## ❌ NO HACER

- ❌ NO crear JavaScript en `games/{slug}/js/` - **No se compila**
- ❌ NO duplicar archivos - Solo una versión
- ❌ NO usar `public/build/` manualmente - Lo genera Vite
- ❌ NO importar archivos desde `games/` en JavaScript

## ✅ HACER

- ✅ Todo JavaScript en `resources/js/`
- ✅ Compilar con `npm run build` después de cambios JS
- ✅ Usar un solo archivo fuente (sin duplicados)
- ✅ Seguir la convención de nombres

## 🔄 Migración de Juegos Existentes

Si ya tienes archivos en ubicaciones incorrectas:

1. Mover JS de `games/{slug}/js/` a `resources/js/{slug}-*.js`
2. Borrar archivos antiguos en `games/{slug}/js/`
3. Borrar archivos en `public/games/{slug}/js/` (son copias estáticas)
4. Importar en `resources/js/app.js`
5. Ejecutar `npm run build`

## 📌 Ejemplo Completo: Pictionary

```
# Estructura correcta de Pictionary
games/pictionary/
├── PictionaryEngine.php           ✅ Motor del juego
├── capabilities.json
├── Events/
│   ├── PlayerAnsweredEvent.php
│   └── GameStateUpdatedEvent.php
└── views/
    └── canvas.blade.php           ✅ Vista Blade

resources/js/
├── pictionary-canvas.js           ✅ JavaScript (se compila)
└── app.js                         ✅ Importa pictionary-canvas.js

public/games/pictionary/
└── css/
    └── canvas.css                 ✅ Estilos (se carga directo)

# NO debe existir:
games/pictionary/js/               ❌ BORRAR
public/games/pictionary/js/        ❌ BORRAR
```

---

**Última actualización:** 2025-10-21
