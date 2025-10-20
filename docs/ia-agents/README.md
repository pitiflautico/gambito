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
- ✅ Base de datos MySQL configurada
- ✅ Panel de administración Filament implementado
- ✅ Sistema de usuarios con roles (admin/user)
- ✅ CRUD de usuarios en Filament
- ✅ Layout base frontend con Tailwind CSS
- ✅ Página de inicio pública

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
- **Autenticación**: Laravel Auth con roles

#### 2. Panel de Administración (Filament)
- **Ruta**: `/admin`
- **Recursos implementados**:
  - UserResource (CRUD completo)
- **Características**:
  - Gestión de usuarios
  - Filtros por rol
  - Badges para roles (admin/user)

#### 3. Frontend Web
- **Framework CSS**: Tailwind CSS 4
- **Layout**: `resources/views/layouts/app.blade.php`
- **Páginas**:
  - Home (`/`) - Página de inicio pública

---

## Stack Tecnológico

### Backend
- **PHP**: 8.3
- **Framework**: Laravel 11
- **Admin Panel**: Filament 3
- **ORM**: Eloquent
- **Base de datos**: MySQL/MariaDB

### Frontend
- **CSS**: Tailwind CSS 4
- **Templating**: Blade
- **UI Components**: Livewire 3
- **Build Tool**: Vite

### DevOps
- **Servidor Local**: Laravel Herd
- **Package Manager PHP**: Composer
- **Package Manager JS**: NPM

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
- [x] Instalación Laravel
- [x] Configuración base de datos
- [x] Instalación Filament
- [x] Sistema de usuarios con roles
- [x] CRUD usuarios en Filament
- [x] Tailwind CSS configurado
- [x] Layout base frontend
- [x] Seeders iniciales
- [x] Repositorio Git configurado

### Fase 2: Funcionalidades Core (Próximo)
- [ ] Sistema de autenticación completo (login/register)
- [ ] Gestión de grupos
- [ ] Gestión de juegos
- [ ] Relaciones entre usuarios, grupos y juegos
- [ ] Dashboard con estadísticas básicas

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
app/Models/User.php                               # Modelo principal
app/Filament/Resources/Users/UserResource.php    # Resource Filament
resources/views/layouts/app.blade.php            # Layout principal
routes/web.php                                    # Rutas web
database/seeders/DatabaseSeeder.php              # Datos iniciales
.env                                              # Configuración
```

### Credenciales
- Admin: admin@gambito.com / password
- Usuario: user@gambito.com / password
- Database: groupsgames (local MySQL)

### Comandos Útiles
```bash
# Desarrollo
php artisan serve          # Servidor local
npm run dev               # Compilar assets

# Base de datos
php artisan migrate       # Ejecutar migraciones
php artisan db:seed       # Ejecutar seeders
php artisan migrate:fresh --seed  # Reset completo

# Filament
php artisan make:filament-resource NombreModelo  # Crear resource
php artisan filament:user  # Crear usuario admin desde CLI

# Cache
php artisan config:clear   # Limpiar config
php artisan cache:clear    # Limpiar cache
php artisan view:clear     # Limpiar vistas compiladas
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
**Versión del proyecto**: 0.1.0 (Base inicial)
