# Test de Presence Channel

Script de Node.js para testear conexiones simultáneas al Presence Channel de Laravel Reverb.

## Instalación

```bash
# Instalar dependencias del script
npm install --prefix . --package-lock=false laravel-echo pusher-js node-fetch
```

O usando el package.json incluido:

```bash
npm install --package-lock=false --no-save laravel-echo pusher-js node-fetch
```

## Uso

### 1. Asegúrate de que Reverb esté corriendo

```bash
php artisan reverb:start --host=0.0.0.0 --port=8086 --debug
```

### 2. Ejecuta el script de test

```bash
# Testear con 10 conexiones simultáneas en la sala DEBUG1
node test-presence-connections.js DEBUG1 10

# Testear con 20 conexiones en otra sala
node test-presence-connections.js ROOM123 20

# Testear con valores por defecto (DEBUG1, 10 conexiones)
node test-presence-connections.js
```

## Qué testea

El script verifica:

1. **Race Conditions**: Todas las conexiones se crean simultáneamente usando `Promise.all()`
2. **`.here()` callbacks**: Cada conexión debe recibir la lista de usuarios actuales
3. **`.joining()` events**: Cada conexión debe ver cuando otros se conectan
4. **`.leaving()` events**: Cada conexión debe ver cuando otros se desconectan
5. **Estadísticas**: Al final muestra totales para verificar que no se perdieron eventos

## Resultados Esperados

Para N conexiones simultáneas:

- **Total `.here()` callbacks**: N (uno por cada conexión)
- **Total `.joining()` events**: N × (N-1) (cada conexión ve a las demás unirse)
- **Total `.leaving()` events**: N × (N-1) (al desconectar, cada uno ve a los demás irse)

Ejemplo con 3 conexiones:
- `.here()`: 3 callbacks (uno por conexión)
- `.joining()`: 6 eventos (conexión 1 ve a 2 y 3, conexión 2 ve a 1 y 3, etc.)
- `.leaving()`: 6 eventos (cuando se desconectan todos)

## Configuración

El script usa variables de entorno o valores por defecto:

```bash
REVERB_APP_KEY=oqz0oagpozlfhykk4jho
REVERB_APP_ID=616729
REVERB_HOST=localhost
REVERB_PORT=8086
APP_URL=http://localhost
```

## Notas

- El script mantiene las conexiones activas por 30 segundos
- Presiona `Ctrl+C` para desconectar manualmente antes de los 30 segundos
- Cada conexión simula un jugador con ID y nombre únicos
- La autorización está simulada (fake signature) para testing

## Troubleshooting

### Error: Cannot find package 'laravel-echo'
```bash
npm install laravel-echo pusher-js node-fetch
```

### Error: Reverb connection refused
Verifica que Reverb esté corriendo en el puerto correcto:
```bash
php artisan reverb:start --host=0.0.0.0 --port=8086 --debug
```

### Las conexiones no se autorizan
El script usa autorización simulada. Para testing real con autorización de Laravel, necesitas modificar el `authorizer` para hacer peticiones HTTP reales a `/broadcasting/auth`.
