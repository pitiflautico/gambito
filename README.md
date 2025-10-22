# Gambito

Plataforma para gestionar grupos y juegos construida con Laravel 11, Filament y Tailwind CSS.

## Características

- 🎮 Panel de administración con Filament
- 👥 Sistema de usuarios con roles (admin/user)
- 🎨 Frontend responsive con Tailwind CSS
- 🔒 Autenticación y autorización
- 📊 CRUD completo de usuarios

## Requisitos

- PHP 8.3+
- MySQL/MariaDB
- Composer
- Node.js y NPM

## Instalación

1. Clonar el repositorio
```bash
git clone git@github.com:pitiflautico/gambito.git
cd gambito
```

2. Instalar dependencias de PHP
```bash
composer install
```

3. Instalar dependencias de NPM
```bash
npm install
```

4. Configurar el archivo de entorno
```bash
cp .env.example .env
php artisan key:generate
```

5. Configurar la base de datos en `.env`
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=groupsgames
DB_USERNAME=root
DB_PASSWORD=
```

6. Ejecutar migraciones y seeders
```bash
php artisan migrate --seed
```

7. Compilar assets
```bash
npm run dev
```

## Credenciales de prueba

**Administrador:**
- Email: admin@gambito.com
- Password: password

**Usuario:**
- Email: user@gambito.com
- Password: password

## Uso

### Panel de Administración
Accede a `/admin` para el panel de administración Filament donde podrás:
- Gestionar usuarios
- Ver estadísticas
- Administrar roles

### Frontend
La página principal muestra información del proyecto y permite a los usuarios:
- Registrarse
- Iniciar sesión
- Ver características de la plataforma

## Estructura del Proyecto

```
├── app/
│   ├── Filament/         # Recursos de Filament
│   ├── Models/           # Modelos Eloquent
│   └── Providers/        # Service Providers
├── database/
│   ├── migrations/       # Migraciones de base de datos
│   └── seeders/          # Seeders
├── resources/
│   ├── css/              # Estilos CSS
│   ├── js/               # JavaScript
│   └── views/            # Vistas Blade
└── routes/               # Rutas de la aplicación
```

## Tecnologías

- Laravel 11
- Filament 3
- Tailwind CSS 4
- Livewire 3
- MySQL

## Desarrollo

Para desarrollo local, puedes usar Laravel Herd o el servidor integrado:

```bash
php artisan serve
```

Y en otra terminal:

```bash
npm run dev
```

### Testing de Convenciones

**IMPORTANTE:** Antes de hacer commit de un nuevo juego, ejecuta los tests de convenciones:

```bash
php artisan test tests/Unit/ConventionTests/GameConventionsTest.php
```

Este test suite valida automáticamente que tu juego cumple con TODAS las convenciones establecidas:
- Estructura de archivos correcta
- Formato de config.json y capabilities.json
- Uso de camelCase vs snake_case
- Módulos declarados coinciden con uso real
- No hay archivos JavaScript en ubicaciones incorrectas
- Engine implementa correctamente GameEngineInterface

**Documentación completa:** Ver `docs/conventions/CONVENTIONS_TESTING.md`

**Resultado esperado:** ✅ 12/12 tests passing (197 assertions)

### Crear un Nuevo Juego

**Guía completa:** `docs/HOW_TO_CREATE_A_GAME.md`

**Convenciones obligatorias:**
- `docs/GAMES_CONVENTION.md` - Estructura de archivos
- `docs/conventions/GAME_CONFIGURATION_CONVENTION.md` - Formato de configuración

**Validación automática:** Los tests de convenciones detectarán cualquier violación antes del commit.
