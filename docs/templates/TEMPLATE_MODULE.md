# [Nombre del Módulo] (Módulo [Core/Opcional])

**Estado:** ✅ Implementado | 🚧 En desarrollo | ⏳ Pendiente
**Tipo:** Core (obligatorio) | Opcional (configurable)
**Versión:** X.Y.Z
**Última actualización:** YYYY-MM-DD

---

## 📋 Descripción

[Describe brevemente qué hace el módulo en 2-3 líneas]

## 🎯 Responsabilidades

- [Responsabilidad 1]
- [Responsabilidad 2]
- [Responsabilidad 3]

## 🎯 Cuándo Usarlo

[Describe cuándo un juego necesita este módulo. Ejemplos:]

**Siempre.** Este es un módulo core que **todos los juegos** utilizan para...

O

**Cuando el juego necesite [funcionalidad específica].** Por ejemplo:
- Pictionary: Para gestionar turnos secuenciales de dibujante
- UNO: Para rotar turnos entre jugadores
- Trivia: Para turnos simultáneos

---

## ⚙️ Configuración (Solo para módulos opcionales)

[Cómo se declara en `capabilities.json`]

```json
{
  "nombre_modulo": {
    "enabled": true,
    "opciones": "..."
  }
}
```

**Ejemplo completo (Pictionary):**
```json
{
  "turn_system": {
    "enabled": true,
    "mode": "sequential"
  }
}
```

---

## 🔧 API / Servicios

### Clases Principales

**Ubicación:** `app/Modules/[NombreModulo]/` o `app/Services/Core/`

#### Clase: `[NombreClase]`

**Ubicación:** `app/.../[NombreClase].php`

**Responsabilidad:** [Qué hace esta clase]

**Métodos públicos:**

---

#### `nombreMetodo(parametros): tipo`

[Descripción de qué hace el método]

**Parámetros:**
- `$param1` (tipo): Descripción
- `$param2` (tipo): Descripción

**Retorna:** [Tipo y descripción]

**Ejemplo:**
```php
$resultado = $servicio->nombreMetodo($param1, $param2);
```

---

#### `otroMetodo(): tipo`

[Descripción]

**Retorna:** [Tipo]

**Ejemplo:**
```php
$data = $servicio->otroMetodo();
```

---

## 📦 Modelos (Si aplica)

### Modelo: `[NombreModelo]`

**Ubicación:** `app/Models/[NombreModelo].php`

**Tabla:** `nombre_tabla`

**Campos principales:**
```php
id              // Descripción
campo1          // Descripción
campo2          // Descripción
created_at
updated_at
```

**Relaciones:**
```php
relacionUno()   // Tipo - Descripción
relacionDos()   // Tipo - Descripción
```

**Scopes:**
```php
scopeNombre($query)  // Descripción
```

**Métodos:**
```php
metodoUtil()         // Descripción
```

---

## 💡 Ejemplos de Uso

### Ejemplo 1: [Título descriptivo]

```php
// Código de ejemplo bien comentado
$servicio = app(NombreServicio::class);
$resultado = $servicio->metodo($param);

if ($resultado) {
    // Hacer algo
}
```

### Ejemplo 2: Uso en un Controller

```php
public function metodoController(Request $request, NombreServicio $servicio)
{
    $data = $servicio->procesarAlgo($request->input('dato'));
    return view('vista', compact('data'));
}
```

### Ejemplo 3: Uso en una Vista Blade

```blade
@if($condicion)
    <div>{{ $variable }}</div>
@endif
```

---

## 🎨 Vistas (Si aplica)

### `nombre-vista.blade.php`

**Ubicación:** `resources/views/[modulo]/nombre-vista.blade.php`

**Descripción:** [Qué muestra esta vista]

**Variables esperadas:**
- `$variable1`: Descripción
- `$variable2`: Descripción

**Componentes:**
- [Componente 1]
- [Componente 2]

---

## 🧪 Tests

**Ubicación:**
- Feature: `tests/Feature/[Modulo]/[Nombre]Test.php`
- Unit: `tests/Unit/[Modulo]/[Nombre]Test.php`

**Tests implementados:**
- ✅ [Descripción del test 1]
- ✅ [Descripción del test 2]
- ✅ [Descripción del test 3]

**Ejecutar tests:**
```bash
php artisan test --filter=[NombreTest]
php artisan test tests/Unit/[Modulo]/[Nombre]Test.php
```

**Ejemplo de test:**
```php
public function test_descripcion_del_comportamiento()
{
    // Arrange
    $dato = factory(Model::class)->create();

    // Act
    $resultado = $servicio->metodo($dato);

    // Assert
    $this->assertTrue($resultado);
}
```

---

## 📦 Dependencias

### Internas:
- `[Modelo1]` - Descripción
- `[Servicio1]` - Descripción

### Externas:
- `[Librería externa]` - Para qué se usa

### Módulos Opcionales (Si este módulo depende de otros):
- `[OtroModulo]` - Por qué lo necesita

---

## ⚙️ Configuración (Si aplica)

**Archivo:** `config/[nombre].php`

```php
<?php

return [
    'opcion1' => env('OPCION1', 'default'),
    'opcion2' => [
        'sub1' => true,
        'sub2' => 100,
    ],
];
```

**Variables de entorno:**
```env
OPCION1=valor
```

---

## 🚨 Limitaciones Conocidas

- [Limitación 1]
- [Limitación 2]

## 🔮 Mejoras Futuras

- [ ] [Mejora 1]
- [ ] [Mejora 2]

---

## 🔗 Referencias

- **Código:** [`app/.../[Clase].php`](../../app/.../[Clase].php)
- **Tests:** [`tests/Feature/[Test].php`](../../tests/Feature/[Test].php)
- **Migration:** [`database/migrations/[migration].php`](../../database/migrations/[migration].php)
- **Glosario:** [`docs/GLOSSARY.md`](../GLOSSARY.md#termino-relacionado)
- **PRD:** [`tasks/0001-prd-plataforma-juegos-sociales.md`](../../tasks/0001-prd-plataforma-juegos-sociales.md)
- **Módulos relacionados:**
  - [`docs/modules/core/[MODULO].md`](../modules/core/[MODULO].md)
  - [`docs/modules/optional/[MODULO].md`](../modules/optional/[MODULO].md)

---

**Mantenido por:** Todo el equipo de desarrollo
**Última revisión:** YYYY-MM-DD
