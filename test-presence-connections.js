/**
 * Script para testear conexiones simultáneas al Presence Channel
 *
 * Instalación:
 * npm install laravel-echo pusher-js node-fetch
 *
 * Uso:
 * node test-presence-connections.js DEBUG1 10
 *
 * Simula 10 jugadores conectándose simultáneamente a la sala DEBUG1
 */

import LaravelEchoModule from 'laravel-echo';
import Pusher from 'pusher-js';
import fetch from 'node-fetch';

// Hacer Pusher disponible globalmente (requerido por Laravel Echo)
global.Pusher = Pusher;

// Echo es el export default
const Echo = LaravelEchoModule.default || LaravelEchoModule;

// Configuración desde .env o argumentos
const roomCode = process.argv[2] || 'DEBUG1';
const numConnections = parseInt(process.argv[3]) || 10;
const appKey = process.env.REVERB_APP_KEY || 'oqz0oagpozlfhykk4jho';
const appId = process.env.REVERB_APP_ID || '616729';
const wsHost = process.env.REVERB_HOST || 'localhost';
const wsPort = process.env.REVERB_PORT || '8086';
const appUrl = process.env.APP_URL || 'http://localhost';

console.log(`\n🧪 Testing ${numConnections} simultaneous connections to presence channel`);
console.log(`📍 Room: ${roomCode}`);
console.log(`🔌 WebSocket: ${wsHost}:${wsPort}`);
console.log(`🔑 App Key: ${appKey}`);
console.log(`\n⏳ Connecting...\n`);

const connections = [];
let totalHereCallbacks = 0;
let totalJoiningEvents = 0;
let totalLeavingEvents = 0;

// Función para crear una conexión
async function createConnection(index) {
    return new Promise((resolve, reject) => {
        const connectionId = `conn-${index}`;

        // Configurar Echo para este cliente
        const echo = new Echo({
            broadcaster: 'reverb',
            key: appKey,
            wsHost: wsHost,
            wsPort: wsPort,
            wssPort: wsPort,
            forceTLS: false,
            enabledTransports: ['ws'],
            authorizer: (channel, options) => {
                return {
                    authorize: (socketId, callback) => {
                        // Simular autorización (en producción esto requeriría sesión válida)
                        // Para testing, devolvemos datos simulados
                        callback(null, {
                            auth: `${appKey}:fake-signature`,
                            channel_data: JSON.stringify({
                                user_id: index,
                                user_info: {
                                    id: index,
                                    name: `Test Player ${index}`,
                                    role: 'player'
                                }
                            })
                        });
                    }
                };
            }
        });

        const channelName = `presence-room.${roomCode}`;

        try {
            const channel = echo.join(channelName)
                .here((users) => {
                    totalHereCallbacks++;
                    console.log(`[${connectionId}] 👥 .here() → ${users.length} users currently in room`);
                    if (index === 1) {
                        console.log(`[${connectionId}] Users:`, users.map(u => `${u.name} (ID: ${u.id})`));
                    }
                    resolve({ echo, channel, connectionId });
                })
                .joining((user) => {
                    totalJoiningEvents++;
                    console.log(`[${connectionId}] ✅ .joining() → ${user.name} joined`);
                })
                .leaving((user) => {
                    totalLeavingEvents++;
                    console.log(`[${connectionId}] ❌ .leaving() → ${user.name} left`);
                })
                .error((error) => {
                    console.error(`[${connectionId}] 🔴 Error:`, error);
                    reject(error);
                });

            connections.push({ echo, channel, connectionId });
        } catch (error) {
            console.error(`[${connectionId}] ❌ Failed to join channel:`, error);
            reject(error);
        }
    });
}

// Conectar todos los clientes simultáneamente
async function connectAll() {
    const promises = [];

    for (let i = 1; i <= numConnections; i++) {
        promises.push(createConnection(i));
    }

    try {
        await Promise.all(promises);

        console.log(`\n✅ All ${numConnections} connections established`);
        console.log(`\n📊 Statistics:`);
        console.log(`   • Total .here() callbacks: ${totalHereCallbacks}`);
        console.log(`   • Total .joining() events: ${totalJoiningEvents}`);
        console.log(`   • Total .leaving() events: ${totalLeavingEvents}`);
        console.log(`\n⏱️  Keeping connections alive for 30 seconds...`);
        console.log(`   Press Ctrl+C to disconnect manually\n`);

    } catch (error) {
        console.error('\n❌ Error connecting:', error);
        disconnectAll();
        process.exit(1);
    }
}

function disconnectAll() {
    console.log('\n🛑 Disconnecting all connections...');
    connections.forEach(({ echo, connectionId }) => {
        try {
            echo.disconnect();
            console.log(`[${connectionId}] Disconnected`);
        } catch (error) {
            console.error(`[${connectionId}] Error disconnecting:`, error);
        }
    });
}

function printFinalStats() {
    console.log(`\n📊 Final Statistics:`);
    console.log(`   • Connections created: ${connections.length}`);
    console.log(`   • Total .here() callbacks: ${totalHereCallbacks}`);
    console.log(`   • Total .joining() events: ${totalJoiningEvents}`);
    console.log(`   • Total .leaving() events: ${totalLeavingEvents}`);
    console.log(`\n✅ Test completed\n`);
}

// Handlers
process.on('SIGINT', () => {
    disconnectAll();
    printFinalStats();
    process.exit(0);
});

// Auto-desconectar después de 30 segundos
setTimeout(() => {
    console.log('\n⏱️  30 seconds elapsed');
    disconnectAll();
    printFinalStats();
    process.exit(0);
}, 30000);

// Iniciar test
connectAll();
