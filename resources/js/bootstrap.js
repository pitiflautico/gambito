import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Laravel Echo + Reverb para WebSockets
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Configuración de WebSocket
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const useTLS = scheme === 'https';
const wsHost = import.meta.env.VITE_REVERB_HOST;
// Convertir puerto a número (asegurar que siempre sea número, no string)
const wsPortRaw = import.meta.env.VITE_REVERB_PORT;
const wsPort = wsPortRaw ? (typeof wsPortRaw === 'string' ? parseInt(wsPortRaw, 10) : Number(wsPortRaw)) : (useTLS ? 443 : 80);
const wssPort = wsPortRaw ? (typeof wsPortRaw === 'string' ? parseInt(wsPortRaw, 10) : Number(wsPortRaw)) : 443;
const appKey = import.meta.env.VITE_REVERB_APP_KEY;
// Path opcional para proxy Nginx
// NOTA: Pusher.js ya agrega automáticamente '/app' para Reverb, así que NO uses wsPath
// a menos que tu configuración de Nginx sea diferente
const wsPathRaw = import.meta.env.VITE_REVERB_PATH;
// Solo usar wsPath si está explícitamente configurado y NO es '/app' (para evitar duplicación)
const wsPath = wsPathRaw && wsPathRaw !== '/app' ? wsPathRaw : undefined;

// Validar configuración crítica
const errors = [];
if (!wsHost) {
    errors.push('VITE_REVERB_HOST no está definido');
    console.error('[Echo] ❌ VITE_REVERB_HOST no está definido');
}
if (!appKey || appKey === 'undefined' || appKey === '') {
    errors.push('VITE_REVERB_APP_KEY no está definido o es inválido');
    console.error('[Echo] ❌ VITE_REVERB_APP_KEY no está definido o es inválido');
    console.error('[Echo] Valor recibido:', appKey);
    console.error('[Echo] ⚠️ IMPORTANTE: Agrega VITE_REVERB_APP_KEY en tu .env y ejecuta npm run build');
}

if (errors.length > 0) {
    console.error('[Echo] ⚠️ ERRORES DE CONFIGURACIÓN DETECTADOS:');
    errors.forEach(err => console.error('[Echo]   -', err));
    console.error('[Echo] La conexión WebSocket fallará hasta que estos errores se corrijan.');
}

const echoConfig = {
    broadcaster: 'reverb',
    key: appKey || 'undefined-key-will-fail', // Usar placeholder si no está definido para que falle claramente
    wsHost: wsHost || 'localhost',
    wsPort: wsPort,
    wssPort: wssPort,
    wsPath: wsPath, // Path para proxy Nginx (ej: '/app')
    forceTLS: useTLS,
    enabledTransports: useTLS ? ['wss', 'ws'] : ['ws', 'wss'], // Fallback a ws si wss falla
    disableStats: true,
    // Configuración adicional para mejor manejo de conexiones
    cluster: undefined, // No usar cluster para Reverb
    encrypted: useTLS,
    authorizer: (channel, options) => {
        return {
            authorize: (socketId, callback) => {
                console.log('[Echo] Intentando autorizar canal:', channel.name, 'socketId:', socketId);
                axios.post('/broadcasting/auth', {
                    socket_id: socketId,
                    channel_name: channel.name
                })
                .then(response => {
                    console.log('[Echo] ✅ Autorización exitosa para:', channel.name);
                    callback(null, response.data);
                })
                .catch(error => {
                    console.error('[Echo] ❌ Error en autorización de canal:', channel.name);
                    console.error('[Echo] Error response:', error.response);
                    console.error('[Echo] Status:', error.response?.status);
                    console.error('[Echo] Data:', error.response?.data);
                    callback(error);
                });
            }
        };
    },
};

try {
    console.log('[Echo] Configuración:', {
        broadcaster: echoConfig.broadcaster,
        host: echoConfig.wsHost,
        wsPort: echoConfig.wsPort,
        wssPort: echoConfig.wssPort,
        wsPath: echoConfig.wsPath || '(no path)',
        scheme: scheme,
        forceTLS: echoConfig.forceTLS,
        enabledTransports: echoConfig.enabledTransports,
        key: echoConfig.key && echoConfig.key !== 'undefined-key-will-fail' 
            ? `${echoConfig.key.substring(0, 10)}...` 
            : '❌ UNDEFINED - La conexión fallará'
    });
    
    // Advertencia si la key no está definida
    if (!appKey || appKey === 'undefined' || appKey === '') {
        console.error('[Echo] ⚠️⚠️⚠️ ADVERTENCIA CRÍTICA ⚠️⚠️⚠️');
        console.error('[Echo] VITE_REVERB_APP_KEY no está definida en el bundle compilado.');
        console.error('[Echo] Pasos para solucionar:');
        console.error('[Echo] 1. Agrega VITE_REVERB_APP_KEY=tu-app-key en tu archivo .env');
        console.error('[Echo] 2. Ejecuta: npm run build');
        console.error('[Echo] 3. Recarga la página');
    }

    window.Echo = new Echo(echoConfig);
    
    // Listeners de eventos de conexión para diagnóstico
    const pusher = window.Echo.connector.pusher;
    
    // Verificar URL de conexión que se está intentando usar
    const socket = pusher.connection.socket;
    if (socket && socket.url) {
        console.log('[Echo] URL de conexión:', socket.url);
    }
    
    // Listeners para errores de canales (pueden ocurrir después de la conexión)
    pusher.bind_global((eventName, data) => {
        if (eventName === 'pusher:error' || eventName.includes('error')) {
            console.error('[Echo] Error en evento global:', eventName, data);
        }
    });
    
    // Capturar error inmediatamente si ya falló
    if (pusher.connection.state === 'failed' || pusher.connection.state === 'unavailable') {
        console.error('[Echo] ⚠️ Conexión ya falló al inicializar');
        console.error('[Echo] Estado:', pusher.connection.state);
        console.error('[Echo] Último error:', pusher.connection.last_error);
        
        // Intentar obtener más información del socket
        if (socket) {
            console.error('[Echo] Socket state:', socket.readyState);
            console.error('[Echo] Socket URL:', socket.url);
        }
    }
    
    pusher.connection.bind('connected', () => {
        console.log('[Echo] ✅ Conexión WebSocket establecida exitosamente');
    });
    
    pusher.connection.bind('disconnected', () => {
        console.warn('[Echo] ⚠️ Conexión WebSocket desconectada');
        console.warn('[Echo] Razón de desconexión:', pusher.connection.last_error);
        
        // Si el error es 1006, es un cierre anormal
        if (pusher.connection.last_error?.code === 1006) {
            console.error('[Echo] ⚠️ Error 1006: WebSocket cerrado anormalmente');
            console.error('[Echo] Posibles causas:');
            console.error('[Echo] 1. Servidor Reverb cerró la conexión');
            console.error('[Echo] 2. Problema con proxy Nginx (timeout, límite de conexiones)');
            console.error('[Echo] 3. Problema de red o firewall');
            console.error('[Echo] 4. Servidor Reverb sobrecargado');
        }
    });
    
    pusher.connection.bind('error', (error) => {
        console.error('[Echo] ❌ Error de conexión WebSocket:', error);
        console.error('[Echo] Tipo de error:', error?.type);
        console.error('[Echo] Estado de conexión:', pusher.connection.state);
        console.error('[Echo] Último error:', pusher.connection.last_error);
        
        // Información adicional para debugging
        if (error) {
            console.error('[Echo] Error completo:', {
                type: error.type,
                error: error.error,
                data: error.data,
                message: error.message,
                code: error.code
            });
            
            // Si es PusherError, mostrar detalles
            if (error.type === 'PusherError' && error.data) {
                console.error('[Echo] Detalles PusherError:', {
                    code: error.data.code,
                    message: error.data.message,
                    data: error.data
                });
            }
        }
        
        // Verificar socket
        if (socket) {
            console.error('[Echo] Socket readyState:', socket.readyState);
            console.error('[Echo] Socket URL:', socket.url);
        }
    });
    
    pusher.connection.bind('state_change', (states) => {
        console.log('[Echo] Cambio de estado:', states.previous, '->', states.current);
    });
    
    pusher.connection.bind('unavailable', () => {
        console.error('[Echo] ❌ Servidor WebSocket no disponible');
        console.error('[Echo] Verifica que:');
        console.error('[Echo] 1. El servidor Reverb esté corriendo');
        console.error('[Echo] 2. El puerto esté abierto y accesible');
        console.error('[Echo] 3. Nginx esté configurado correctamente para WebSocket proxy');
        console.error('[Echo] 4. Las variables VITE_REVERB_* coincidan con REVERB_* en el servidor');
    });
    
    // Verificar estado después de un breve delay
    setTimeout(() => {
        console.log('[Echo] Estado después de 1 segundo:', pusher.connection.state);
        if (pusher.connection.state === 'failed') {
            console.error('[Echo] ⚠️ La conexión falló. Verifica la configuración del servidor.');
        }
    }, 1000);
    
    console.log('[Echo] ✅ Echo inicializado correctamente');
    console.log('[Echo] Estado inicial de conexión:', pusher.connection.state);
    
} catch (error) {
    console.error('[Echo] ❌ Error al inicializar Echo:', error);
    console.error('[Echo] Configuración utilizada:', echoConfig);
    console.error('[Echo] Stack trace:', error.stack);
}
