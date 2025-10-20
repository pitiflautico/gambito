# Testing y Deployment - Gambito

**Última actualización:** 2025-10-20
**Versión:** 0.3.0

---

## Índice

- [Estrategia de Testing](#estrategia-de-testing)
- [Tests Implementados](#tests-implementados)
- [Comandos de Testing](#comandos-de-testing)
- [CI/CD Pipeline](#cicd-pipeline)
- [Checklist de Deployment](#checklist-de-deployment)
- [Scripts de Deployment](#scripts-de-deployment)

---

## Estrategia de Testing

### 🎯 Niveles de Testing

```
┌─────────────────────────────────────────────────────────┐
│                    E2E Tests (Futuros)                   │
│         - Flujo completo de un juego                     │
│         - Interacción multi-jugador                      │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────┐
│                  Feature Tests                           │
│         - Flujo de creación de salas                     │
│         - Registro de juegos                             │
│         - Join de jugadores                              │
└────────────────────┬────────────────────────────────────┘
                     │
┌────────────────────┴────────────────────────────────────┐
│                   Unit Tests                             │
│         - Servicios (GameRegistry, RoomService)          │
│         - Modelos (Game, Room, Match)                    │
│         - Validaciones                                   │
└──────────────────────────────────────────────────────────┘
```

### 📝 Convenciones de Testing

1. **Nombres de archivos:**
   - Unit tests: `tests/Unit/{Namespace}/{ClassName}Test.php`
   - Feature tests: `tests/Feature/{Feature}/{TestName}Test.php`

2. **Nombres de métodos:**
   - Usar snake_case con prefijo `test_` o anotación `@test`
   - Descriptivos: `test_it_validates_config_with_all_required_fields()`

3. **Estructura de test:**
   ```php
   // Arrange (Preparar)
   $data = [...];

   // Act (Ejecutar)
   $result = $service->method($data);

   // Assert (Verificar)
   $this->assertTrue($result);
   ```

4. **Uso de traits:**
   - `RefreshDatabase` - Para tests que interactúan con BD
   - `WithFaker` - Para generar datos de prueba

---

## Tests Implementados

### ✅ Unit Tests

#### 1. GameRegistryTest
**Ubicación:** `tests/Unit/Services/Core/GameRegistryTest.php`
**Cobertura:** Servicio `GameRegistry`
**Tests:** 14 tests, 46 assertions

**Casos de prueba:**

1. ✅ **test_it_returns_required_config_fields**
   - Verifica que el servicio retorna todos los campos obligatorios de config.json
   - Valida: id, name, slug, description, minPlayers, maxPlayers, estimatedDuration, version

2. ✅ **test_it_returns_available_capabilities**
   - Verifica que el servicio retorna todas las capabilities disponibles
   - Valida: websockets, turns, phases, roles, timers, scoring

3. ✅ **test_it_validates_config_with_all_required_fields**
   - Valida que un config.json completo y correcto pasa la validación
   - Expected: `['valid' => true, 'errors' => []]`

4. ✅ **test_it_fails_validation_when_required_fields_are_missing**
   - Verifica que falla si faltan campos obligatorios
   - Expected: `['valid' => false, 'errors' => [...]]`

5. ✅ **test_it_validates_player_count_ranges**
   - Valida que minPlayers y maxPlayers estén dentro del rango permitido (1-100)
   - Caso: minPlayers = 101 → debe fallar

6. ✅ **test_it_validates_max_players_greater_than_min_players**
   - Verifica que maxPlayers >= minPlayers
   - Caso: minPlayers=8, maxPlayers=2 → debe fallar

7. ✅ **test_it_validates_version_format**
   - Valida formato semver (ej: 1.0, 1.0.0, 2.5.3)
   - Válidos: "1.0", "1.0.0", "2.5.3"
   - Inválidos: "1", "v1.0", "1.0.0.0", "abc"

8. ✅ **test_it_validates_capabilities_with_required_structure**
   - Verifica que capabilities.json tenga estructura correcta
   - Requiere: slug, requires (con valores booleanos)

9. ✅ **test_it_fails_validation_when_capabilities_structure_is_invalid**
   - Valida que falla si falta 'slug' o 'requires'

10. ✅ **test_it_fails_validation_when_invalid_capability_is_specified**
    - Verifica que solo se permitan capabilities conocidas
    - Caso: "invalid_capability" → debe fallar

11. ✅ **test_it_fails_validation_when_capability_value_is_not_boolean**
    - Valida que los valores de requires sean booleanos
    - Caso: "websockets": "yes" → debe fallar

12. ✅ **test_it_returns_empty_array_when_no_games_exist**
    - Verifica comportamiento cuando no hay juegos en games/

13. ✅ **test_it_can_clear_game_cache**
    - Verifica que clearGameCache() y clearAllCache() no lanzan excepciones

14. ✅ **test_it_returns_active_games_from_database**
    - Valida que getActiveGames() solo retorna juegos con is_active=true
    - Crea 3 juegos (2 activos, 1 inactivo)
    - Expected: 2 juegos retornados

**Ejecución:**
```bash
php artisan test --filter=GameRegistryTest
# PASS  Tests\Unit\Services\Core\GameRegistryTest (14 tests, 46 assertions)
```

---

### 📋 Feature Tests (Pendientes)

#### 1. GameDiscoveryTest *(Pendiente)*
**Ubicación:** `tests/Feature/Core/GameDiscoveryTest.php`

**Casos de prueba a implementar:**
- [ ] test_it_can_discover_valid_games_in_games_folder
- [ ] test_it_ignores_invalid_game_modules
- [ ] test_it_can_register_discovered_games_in_database
- [ ] test_discover_command_lists_valid_games
- [ ] test_discover_command_can_register_games_with_flag

---

#### 2. GameValidationTest *(Pendiente)*
**Ubicación:** `tests/Feature/Core/GameValidationTest.php`

**Casos de prueba a implementar:**
- [ ] test_validate_command_shows_errors_for_invalid_game
- [ ] test_validate_command_shows_success_for_valid_game
- [ ] test_validate_all_command_validates_all_games
- [ ] test_validate_command_shows_detailed_info_in_verbose_mode

---

#### 3. RoomCreationTest *(Pendiente)*
**Ubicación:** `tests/Feature/Core/RoomCreationTest.php`

**Casos de prueba a implementar:**
- [ ] test_master_can_create_room_for_a_game
- [ ] test_room_generates_unique_6_char_code
- [ ] test_room_cannot_be_created_for_inactive_game
- [ ] test_room_cannot_be_created_with_invalid_player_count

---

## Comandos de Testing

### Ejecutar todos los tests
```bash
# Todos los tests
php artisan test

# Solo unit tests
php artisan test --testsuite=Unit

# Solo feature tests
php artisan test --testsuite=Feature

# Con cobertura de código (requiere Xdebug)
php artisan test --coverage

# Cobertura mínima requerida
php artisan test --coverage --min=80
```

### Ejecutar tests específicos
```bash
# Un archivo específico
php artisan test tests/Unit/Services/Core/GameRegistryTest.php

# Un método específico
php artisan test --filter=test_it_validates_config_with_all_required_fields

# Todos los tests que coincidan con un patrón
php artisan test --filter=GameRegistry
```

### Opciones útiles
```bash
# Modo verbose (muestra detalles)
php artisan test --verbose

# Parar en el primer error
php artisan test --stop-on-failure

# Ejecutar tests en paralelo (más rápido)
php artisan test --parallel

# Recrear base de datos antes de cada test
php artisan test --recreate-databases
```

---

## CI/CD Pipeline

### 🔄 GitHub Actions (Recomendado)

**Archivo:** `.github/workflows/tests.yml` *(Pendiente de crear)*

```yaml
name: Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: testing
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, mysql, bcmath
          coverage: xdebug

      - name: Copy .env
        run: php -r "file_exists('.env') || copy('.env.example', '.env');"

      - name: Install Dependencies
        run: composer install -q --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist

      - name: Generate key
        run: php artisan key:generate

      - name: Directory Permissions
        run: chmod -R 777 storage bootstrap/cache

      - name: Run Migrations
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: testing
          DB_USERNAME: root
          DB_PASSWORD: password
        run: php artisan migrate --force

      - name: Execute tests (Unit and Feature tests)
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: testing
          DB_USERNAME: root
          DB_PASSWORD: password
        run: php artisan test --coverage --min=70

      - name: Upload coverage reports to Codecov
        uses: codecov/codecov-action@v3
        with:
          file: ./coverage.xml
```

---

## Checklist de Deployment

### 📋 Pre-Deployment Checklist

#### 1. Código y Tests
- [ ] Todos los tests unitarios pasan
- [ ] Todos los tests de feature pasan
- [ ] Cobertura de código >= 70%
- [ ] No hay warnings de PHPStan/Psalm
- [ ] Código revisado (pull request aprobado)

#### 2. Base de Datos
- [ ] Todas las migraciones están versionadas
- [ ] Seeders funcionan correctamente
- [ ] Backup de base de datos de producción creado
- [ ] Migraciones probadas en staging

#### 3. Configuración
- [ ] Variables de entorno configuradas en producción
- [ ] Claves de API configuradas
- [ ] Cache configurado (Redis recomendado)
- [ ] Queue workers configurados
- [ ] Logs configurados (sentry, bugsnag, etc.)

#### 4. Seguridad
- [ ] APP_DEBUG=false en producción
- [ ] APP_ENV=production
- [ ] HTTPS configurado
- [ ] CORS configurado correctamente
- [ ] Rate limiting configurado
- [ ] Secrets rotados si es necesario

#### 5. Optimización
- [ ] `composer install --optimize-autoloader --no-dev`
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `npm run build` (assets compilados)

---

## Scripts de Deployment

### 🚀 Script de Deployment Básico

**Archivo:** `deploy.sh` *(Pendiente de crear)*

```bash
#!/bin/bash

echo "🚀 Starting deployment..."

# 1. Activar modo mantenimiento
php artisan down

# 2. Pull código del repositorio
git pull origin main

# 3. Instalar dependencias de Composer
composer install --optimize-autoloader --no-dev

# 4. Instalar dependencias de NPM y compilar assets
npm ci
npm run build

# 5. Ejecutar migraciones
php artisan migrate --force

# 6. Limpiar y cachear configuración
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Reiniciar queue workers
php artisan queue:restart

# 8. Desactivar modo mantenimiento
php artisan up

echo "✅ Deployment completed successfully!"
```

**Uso:**
```bash
chmod +x deploy.sh
./deploy.sh
```

---

### 🔄 Script de Deployment con Tests

**Archivo:** `deploy-with-tests.sh` *(Pendiente de crear)*

```bash
#!/bin/bash

echo "🔍 Running tests before deployment..."

# Ejecutar tests
php artisan test --stop-on-failure

if [ $? -ne 0 ]; then
    echo "❌ Tests failed! Aborting deployment."
    exit 1
fi

echo "✅ All tests passed! Proceeding with deployment..."

# Ejecutar deploy normal
./deploy.sh
```

---

### 🎮 Script para Registrar Juegos en Producción

**Archivo:** `register-games.sh` *(Pendiente de crear)*

```bash
#!/bin/bash

echo "🎮 Discovering and registering games..."

# Descubrir y registrar juegos
php artisan games:discover --register

if [ $? -ne 0 ]; then
    echo "❌ Game registration failed!"
    exit 1
fi

echo "✅ Games registered successfully!"

# Validar todos los juegos
php artisan games:validate --all --verbose
```

---

## Entornos

### 🏠 Local (Development)
- **Base de datos:** MySQL local o SQLite
- **Cache:** File cache
- **Queue:** Sync (sin queue)
- **Debug:** Habilitado
- **Tests:** Ejecutar antes de cada commit

### 🧪 Staging
- **Base de datos:** MySQL (réplica de producción)
- **Cache:** Redis
- **Queue:** Redis
- **Debug:** Habilitado con log level DEBUG
- **Tests:** Ejecutar en cada deploy
- **Propósito:** Validar cambios antes de producción

### 🚀 Production
- **Base de datos:** MySQL (servidor dedicado)
- **Cache:** Redis (cluster si es necesario)
- **Queue:** Redis con workers dedicados
- **Debug:** Deshabilitado
- **Tests:** Ejecutados en staging, no en producción
- **Monitoring:** Sentry, New Relic, etc.

---

## Comandos Útiles para Deployment

### Verificar estado de la aplicación
```bash
# Ver versión de Laravel y PHP
php artisan --version
php -v

# Ver configuración actual
php artisan config:show

# Ver rutas registradas
php artisan route:list

# Ver migraciones ejecutadas
php artisan migrate:status

# Ver juegos registrados
php artisan games:discover
```

### Mantenimiento
```bash
# Activar modo mantenimiento
php artisan down

# Modo mantenimiento con mensaje personalizado
php artisan down --message="Actualizando el sistema" --retry=60

# Desactivar modo mantenimiento
php artisan up
```

### Limpieza y caché
```bash
# Limpiar todo el caché
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Recrear caché
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Logs y debugging
```bash
# Ver logs en tiempo real
tail -f storage/logs/laravel.log

# Limpiar logs antiguos
echo "" > storage/logs/laravel.log
```

---

## Monitoreo Post-Deployment

### ✅ Checklist de Verificación

Después de cada deployment, verificar:

1. **Aplicación:**
   - [ ] La home page carga correctamente
   - [ ] El login funciona
   - [ ] El dashboard de admin carga

2. **Juegos:**
   - [ ] `php artisan games:discover` retorna juegos válidos
   - [ ] Se puede crear una sala para un juego

3. **Base de Datos:**
   - [ ] Las migraciones están al día (`php artisan migrate:status`)
   - [ ] Los seeders funcionaron correctamente

4. **Performance:**
   - [ ] Tiempo de respuesta < 200ms en promedio
   - [ ] No hay errores en logs
   - [ ] Queue workers están procesando jobs

5. **Seguridad:**
   - [ ] HTTPS funcionando correctamente
   - [ ] No hay información sensible expuesta
   - [ ] Rate limiting activo

---

## Rollback en Caso de Error

Si algo falla en producción:

```bash
# 1. Activar modo mantenimiento
php artisan down

# 2. Rollback al commit anterior
git revert HEAD
# O si es necesario, hacer hard reset
git reset --hard HEAD~1

# 3. Reinstalar dependencias
composer install --optimize-autoloader --no-dev

# 4. Rollback de migraciones (si es necesario)
php artisan migrate:rollback

# 5. Limpiar caché
php artisan cache:clear
php artisan config:cache

# 6. Desactivar modo mantenimiento
php artisan up
```

---

**Documento creado por:** Claude Code
**Última actualización:** 2025-10-20 (Tarea 2.0 completada)
**Próxima revisión:** Al implementar CI/CD pipeline
