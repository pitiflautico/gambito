# Documentación para Agentes IA - Proyecto Gambito

Esta carpeta contiene documentación específica para agentes de IA que trabajarán en el proyecto Gambito. El objetivo es proporcionar contexto claro y estructurado sobre el proyecto para facilitar la colaboración con IA.

## Índice

- [Visión General del Proyecto](#visión-general-del-proyecto)
- [Arquitectura](#arquitectura)
- [Stack Tecnológico](#stack-tecnológico)
- [Estructura de Datos](#estructura-de-datos)
- [Convenciones](#convenciones)
- [Roadmap](#roadmap)

---

## Visión General del Proyecto

**Gambito** es una plataforma web para gestionar grupos y juegos, diseñada para facilitar la organización de actividades grupales y el seguimiento de estadísticas.

### Objetivo Principal
Crear una aplicación completa con tres componentes principales:
1. **Backend de Administración** - Panel Filament para gestión administrativa
2. **Frontend Web** - Interfaz pública con Tailwind CSS
3. **App Móvil** - (Próximamente)

### Estado Actual
- ✅ Proyecto Laravel 11 instalado y configurado
- ✅ Base de datos MySQL configurada (groupsgames)
- ✅ **Sistema de autenticación completo con Laravel Breeze**
- ✅ Sistema de usuarios con roles (admin/user)
- ✅ **Panel de administración Filament completamente funcional**
- ✅ **CRUD completo de usuarios en Filament (español)**
- ✅ **Middleware de protección para rutas admin**
- ✅ **Redirección inteligente por rol al login**
- ✅ Layout base frontend con Tailwind CSS
- ✅ Página de inicio pública
- ✅ Configuración Herd con HTTPS (gambito.test)

---

## Arquitectura

### Estructura General
```
Gambito/
├── Backend (Laravel)
│   ├── Admin Panel (Filament)
│   └── API (Futuro)
├── Frontend Web (Blade + Tailwind)
└── Mobile App (Futuro)
```

### Componentes Actuales

#### 1. Backend Laravel
- **Framework**: Laravel 11
- **Base de datos**: MySQL (database: groupsgames)
- **Autenticación**: Laravel Breeze (Blade stack)
- **Sistema de roles**: Admin / User con middleware de protección
- **URL Local**: https://gambito.test (Herd con HTTPS)

#### 2. Panel de Administración (Filament)
- **Ruta**: `/admin`
- **Protección**: Middleware IsAdmin (solo accesible para admins)
- **Idioma**: Completamente en español
- **Recursos implementados**:
  - **UserResource** - CRUD completo de usuarios
    - Crear usuarios con hash de password
    - Editar nombre, email, rol, contraseña
    - Eliminar con confirmación
    - Filtros por rol y verificación
    - Búsqueda por nombre/email
    - Email copiable
    - Iconos de estado de verificación
- **Características**:
  - Sidebar con navegación
  - Notificaciones de éxito personalizadas
  - Redirección automática después de crear/editar
  - Estados vacíos personalizados
  - Badges de color para roles

#### 3. Sistema de Autenticación (Breeze)
- **Rutas**:
  - `/login` - Inicio de sesión
  - `/register` - Registro de usuarios
  - `/dashboard` - Dashboard usuarios normales
  - `/profile` - Edición de perfil
  - Recuperación de contraseña
  - Verificación de email
- **Características**:
  - Redirección inteligente al login:
    - Admins → `/admin`
    - Usuarios → `/dashboard`
  - Protección de rutas con middleware auth
  - Sesiones seguras con CSRF

#### 4. Frontend Web
- **Framework CSS**: Tailwind CSS 4
- **Layouts**:
  - `layouts/guest-public.blade.php` - Página home pública
  - `layouts/guest.blade.php` - Login/Register (Breeze)
  - `layouts/app.blade.php` - Dashboard autenticado (Breeze)
  - `layouts/navigation.blade.php` - Navegación Breeze
- **Páginas**:
  - Home (`/`) - Página de inicio pública con navegación dinámica
  - Dashboard (`/dashboard`) - Para usuarios autenticados
  - Perfil (`/profile`) - Gestión de perfil de usuario

---

## Stack Tecnológico

### Backend
- **PHP**: 8.3
- **Framework**: Laravel 11
- **Autenticación**: Laravel Breeze (Blade stack)
- **Admin Panel**: Filament 3
- **ORM**: Eloquent
- **Base de datos**: MySQL/MariaDB (groupsgames)

### Frontend
- **CSS**: Tailwind CSS 4
- **Templating**: Blade
- **UI Components**: Livewire 3
- **Build Tool**: Vite
- **Fonts**: Figtree (vía Bunny Fonts)

### DevOps
- **Servidor Local**: Laravel Herd
- **Dominio Local**: gambito.test (HTTPS habilitado)
- **Package Manager PHP**: Composer
- **Package Manager JS**: NPM
- **Git**: GitHub - pitiflautico/gambito
- **Node**: v18.20.8 (funcional aunque requiere 20+)

---

## Estructura de Datos

### Modelo User
```php
<?php
namespace App\Models;

class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',  // 'admin' | 'user'
    ];

    // Métodos helper
    public function isAdmin(): bool
    public function isUser(): bool
}
```

### Migraciones Actuales
1. `create_users_table` - Tabla de usuarios base
2. `create_cache_table` - Sistema de caché
3. `create_jobs_table` - Cola de trabajos
4. `add_role_to_users_table` - Campo de rol

### Seeders
- **DatabaseSeeder**: Crea usuarios de prueba
  - Admin: admin@gambito.com / password
  - User: user@gambito.com / password

---

## Rutas y Flujos de Usuario

### Mapa de Rutas

#### Rutas Públicas (sin autenticación)
```
GET  /                    → Home pública (resources/views/home.blade.php)
GET  /login               → Formulario de login
POST /login               → Procesar login
GET  /register            → Formulario de registro
POST /register            → Procesar registro
GET  /forgot-password     → Recuperar contraseña
POST /forgot-password     → Enviar email recuperación
GET  /reset-password      → Formulario nueva contraseña
POST /reset-password      → Establecer nueva contraseña
```

#### Rutas Autenticadas (requiere login)
```
GET  /dashboard           → Dashboard usuarios normales
GET  /profile             → Ver/editar perfil
PATCH /profile            → Actualizar perfil
DELETE /profile           → Eliminar cuenta
POST /logout              → Cerrar sesión
```

#### Rutas Admin (requiere rol admin)
```
GET  /admin               → Dashboard Filament
GET  /admin/login         → Login específico de Filament
GET  /admin/users         → Listado de usuarios
GET  /admin/users/create  → Crear usuario
POST /admin/users         → Guardar usuario
GET  /admin/users/{id}/edit → Editar usuario
PATCH /admin/users/{id}   → Actualizar usuario
DELETE /admin/users/{id}  → Eliminar usuario
```

### Flujos de Usuario

#### 1. Usuario Nuevo (Registro)
```
1. Visita https://gambito.test
2. Click en "Registrarse"
3. Completa formulario (nombre, email, password)
4. Se crea con rol 'user' automáticamente
5. Redirige a /dashboard
```

#### 2. Admin Login
```
1. Visita https://gambito.test/login
2. Ingresa: admin@gambito.com / password
3. Sistema detecta rol 'admin'
4. Redirige automáticamente a /admin (Panel Filament)
5. Ve sidebar con Dashboard y Usuarios
```

#### 3. Usuario Normal Login
```
1. Visita https://gambito.test/login
2. Ingresa credenciales de usuario normal
3. Sistema detecta rol 'user'
4. Redirige a /dashboard (Breeze)
5. NO puede acceder a /admin (error 403)
```

#### 4. Gestión de Usuarios (Admin)
```
1. Login como admin
2. En sidebar, click en "Usuarios"
3. Ve listado completo con filtros
4. Puede:
   - Crear nuevo usuario (botón "Nuevo Usuario")
   - Editar usuario existente (botón editar en fila)
   - Eliminar usuario (requiere confirmación)
   - Buscar por nombre o email
   - Filtrar por rol o estado de verificación
   - Copiar email con un click
```

### Protección de Rutas

**Middleware aplicado:**
- `auth` - Rutas que requieren autenticación
- `guest` - Solo para no autenticados (login, register)
- `admin` - Solo para usuarios con rol admin (rutas /admin)
- `verified` - Email verificado (opcional, configurado en rutas)

**Archivo:** `app/Http/Middleware/IsAdmin.php`
```php
// Verifica autenticación
// Verifica rol admin
// Redirige o lanza error 403
```

---

## Convenciones

### Código
- Seguir PSR-12 para PHP
- Usar camelCase para métodos y variables
- Usar PascalCase para clases
- Comentarios en español para documentación interna

### Base de Datos
- Nombres de tablas en plural y snake_case
- Nombres de columnas en snake_case
- Timestamps automáticos (`created_at`, `updated_at`)

### Git
- Commits en español
- Branches descriptivos: `feature/`, `fix/`, `docs/`
- Commit inicial: "first commit" (ya realizado)

### Filament
- Resources organizados por entidad en carpetas
- Separación de schemas (Forms) y tables
- Uso de badges para estados visuales

---

## Roadmap

### Fase 1: Base del Proyecto ✅ (Completada)
- [x] Instalación Laravel 11 con PHP 8.3
- [x] Configuración base de datos MySQL (groupsgames)
- [x] Instalación y configuración Filament 3
- [x] Sistema de usuarios con roles (admin/user)
- [x] CRUD completo de usuarios en Filament (español)
- [x] Tailwind CSS 4 configurado
- [x] Layouts frontend (público, auth, dashboard)
- [x] Seeders con usuarios de prueba
- [x] Repositorio Git configurado y subido a GitHub
- [x] **Sistema de autenticación con Laravel Breeze**
- [x] **Login, registro, recuperación de contraseña**
- [x] **Middleware de protección para rutas admin**
- [x] **Redirección inteligente por rol**
- [x] **Configuración Herd con HTTPS (gambito.test)**
- [x] **Panel admin completamente funcional en español**

### Fase 2: Funcionalidades Core (Próximo)
- [ ] Gestión de grupos
- [ ] Gestión de juegos
- [ ] Relaciones entre usuarios, grupos y juegos
- [ ] Dashboard con estadísticas básicas
- [ ] Resources de Filament para grupos y juegos

### Fase 3: Características Avanzadas
- [ ] Sistema de notificaciones
- [ ] API RESTful
- [ ] Búsqueda avanzada
- [ ] Exportación de datos
- [ ] Permisos granulares

### Fase 4: Mobile App
- [ ] Diseño de API para mobile
- [ ] Desarrollo app móvil
- [ ] Integración con backend

---

## Notas para Agentes IA

### Contexto Importante
1. Este es un proyecto en **fase inicial** - la base está establecida pero hay mucho por construir
2. El enfoque actual es el **backend web** y **panel admin**
3. La **app móvil** se desarrollará en fases posteriores
4. **Base de datos**: `groupsgames` en MySQL local
5. **Idioma**: Interfaz en español, código en inglés con comentarios en español

### Archivos Clave
```
# Modelos
app/Models/User.php                                      # Modelo User con roles

# Filament (Panel Admin)
app/Filament/Resources/UserResource.php                  # Resource principal
app/Filament/Resources/Users/Pages/ListUsers.php        # Listado
app/Filament/Resources/Users/Pages/CreateUser.php       # Crear
app/Filament/Resources/Users/Pages/EditUser.php         # Editar
app/Filament/Resources/Users/Schemas/UserForm.php       # Formulario
app/Filament/Resources/Users/Tables/UsersTable.php      # Tabla
app/Providers/Filament/AdminPanelProvider.php           # Config Filament

# Autenticación (Breeze)
app/Http/Controllers/Auth/*                              # Controladores auth
app/Http/Controllers/ProfileController.php               # Perfil usuario
app/Http/Middleware/IsAdmin.php                          # Middleware admin
routes/auth.php                                          # Rutas de autenticación

# Vistas
resources/views/layouts/guest-public.blade.php           # Layout home pública
resources/views/layouts/guest.blade.php                  # Layout login/register
resources/views/layouts/app.blade.php                    # Layout dashboard
resources/views/home.blade.php                           # Página home
resources/views/dashboard.blade.php                      # Dashboard usuarios
resources/views/auth/*                                   # Vistas autenticación
resources/views/profile/*                                # Vistas perfil

# Configuración
routes/web.php                                           # Rutas web
bootstrap/app.php                                        # Bootstrap Laravel
database/seeders/DatabaseSeeder.php                      # Datos iniciales
database/migrations/*                                    # Migraciones
.env                                                     # Configuración
```

### Credenciales
- Admin: admin@gambito.com / password
- Usuario: user@gambito.com / password
- Database: groupsgames (local MySQL)

### Comandos Útiles
```bash
# Desarrollo
php artisan serve          # Servidor local (alternativa a Herd)
npm run dev               # Compilar assets en desarrollo
npm run build             # Compilar para producción

# Herd (ya configurado)
herd link gambito         # Crear link (ya hecho)
herd secure gambito       # Habilitar HTTPS (ya hecho)
# URL: https://gambito.test

# Base de datos
php artisan migrate       # Ejecutar migraciones
php artisan db:seed       # Ejecutar seeders
php artisan migrate:fresh --seed  # Reset completo
php artisan migrate:rollback      # Revertir última migración

# Filament
php artisan make:filament-resource NombreModelo  # Crear resource
php artisan filament:user  # Crear usuario admin desde CLI

# Middleware
php artisan make:middleware NombreMiddleware  # Crear middleware

# Cache (ejecutar después de cambios importantes)
php artisan config:clear   # Limpiar config
php artisan cache:clear    # Limpiar cache
php artisan view:clear     # Limpiar vistas compiladas
php artisan route:clear    # Limpiar rutas

# Testing
php artisan test          # Ejecutar tests
```

---

## Próximos Pasos

Esta documentación se irá actualizando conforme el proyecto avance. Los próximos documentos a crear incluirán:

1. **Modelos de Datos** - Especificación detallada de modelos y relaciones
2. **API Specification** - Cuando se implemente la API
3. **Guía de Contribución** - Estándares y procesos
4. **Testing Guide** - Estrategia de testing
5. **Deployment Guide** - Proceso de despliegue

---

**Última actualización**: 2025-10-20
**Versión del proyecto**: 0.2.0 (Autenticación y Panel Admin completos)
