/**
 * Script de debugging con Puppeteer para WebSockets
 * Ejecutar: node debug-websocket-puppeteer.js [URL]
 */

import puppeteer from 'puppeteer';

async function debugWebSocket() {
    console.log('🔍 [DEBUG] Iniciando debugging con Puppeteer...');
    
    const browser = await puppeteer.launch({
        headless: false, // Mostrar navegador para debugging
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    
    // Capturar todos los logs de consola
    page.on('console', msg => {
        const text = msg.text();
        const type = msg.type();
        
        // Filtrar solo logs relevantes
        if (text.includes('Transition') || 
            text.includes('game.countdown') || 
            text.includes('Echo') || 
            text.includes('Canal') ||
            text.includes('pusher') ||
            text.includes('subscription')) {
            console.log(`[${type.toUpperCase()}] ${text}`);
        }
    });
    
    // Capturar errores de página
    page.on('pageerror', error => {
        console.error('❌ [PAGE ERROR]', error.message);
    });
    
    // Interceptar requests de WebSocket
    page.on('request', request => {
        const url = request.url();
        if (url.includes('wss://') || url.includes('ws://')) {
            console.log('🔌 [WEBSOCKET REQUEST]', url);
        }
    });
    
    // Interceptar responses
    page.on('response', response => {
        const url = response.url();
        if (url.includes('/api/rooms/') && url.includes('/ready')) {
            console.log('📡 [API RESPONSE]', url, response.status());
            response.json().then(data => {
                console.log('   Data:', JSON.stringify(data, null, 2));
            }).catch(() => {});
        }
    });
    
    // Navegar a la transition page
    // Puedes pasar la URL como argumento: node debug-websocket-puppeteer.js https://gambito.nebulio.es/rooms/N3MMN6
    const transitionUrl = process.argv[2] || process.env.TRANSITION_URL || 'https://gambito.nebulio.es/rooms/N3MMN6';
    console.log(`🌐 Navegando a: ${transitionUrl}`);
    
    await page.goto(transitionUrl, {
        waitUntil: 'networkidle2',
        timeout: 30000
    });
    
    console.log('✅ Página cargada');
    
    // Inyectar código de debugging
    await page.evaluate(() => {
        // Guardar referencia a funciones originales
        const originalLog = console.log;
        const originalError = console.error;
        
        // Interceptar logs de WebSocket
        window.debugWebSocket = {
            events: [],
            channels: [],
            connectionState: null
        };
        
        // Monitorear Echo
        if (window.Echo) {
            const pusher = window.Echo.connector.pusher;
            
            // Guardar estado de conexión
            window.debugWebSocket.connectionState = pusher.connection.state;
            
            // Listener para cambios de estado
            pusher.connection.bind('state_change', (states) => {
                window.debugWebSocket.connectionState = states.current;
                originalLog('[DEBUG] Estado cambiado:', states.previous, '->', states.current);
            });
            
            // Listener global para TODOS los eventos
            pusher.bind_global((eventName, data) => {
                window.debugWebSocket.events.push({
                    eventName,
                    data,
                    timestamp: Date.now()
                });
                
                if (eventName.includes('game.countdown') || 
                    eventName.includes('game.initialized') ||
                    eventName.includes('subscription')) {
                    originalLog('[DEBUG] Evento global:', eventName, data);
                }
            });
            
            // Monitorear suscripciones de canales
            const originalSubscribe = pusher.subscribe;
            pusher.subscribe = function(channelName) {
                const channel = originalSubscribe.call(this, channelName);
                window.debugWebSocket.channels.push({
                    name: channelName,
                    subscribed: false,
                    timestamp: Date.now()
                });
                
                // Monitorear cuando se suscribe
                channel.bind('pusher:subscription_succeeded', () => {
                    const ch = window.debugWebSocket.channels.find(c => c.name === channelName);
                    if (ch) ch.subscribed = true;
                    originalLog('[DEBUG] Canal suscrito:', channelName);
                });
                
                return channel;
            };
        }
    });
    
    console.log('⏳ Esperando eventos... (presiona Ctrl+C para detener)');
    
    // Esperar y mostrar estado periódicamente
    setInterval(async () => {
        const debugInfo = await page.evaluate(() => {
            if (!window.debugWebSocket) return null;
            
            const pusher = window.Echo?.connector?.pusher;
            const channels = pusher ? pusher.allChannels() : [];
            
            return {
                connectionState: window.debugWebSocket.connectionState,
                channels: channels.map(c => ({
                    name: c.name,
                    subscribed: c.subscribed
                })),
                eventsCount: window.debugWebSocket.events.length,
                recentEvents: window.debugWebSocket.events.slice(-5)
            };
        });
        
        if (debugInfo) {
            console.log('\n📊 [ESTADO ACTUAL]');
            console.log('   Conexión:', debugInfo.connectionState);
            console.log('   Canales:', debugInfo.channels.length);
            debugInfo.channels.forEach(ch => {
                console.log(`     - ${ch.name}: ${ch.subscribed ? '✅' : '❌'}`);
            });
            console.log('   Eventos capturados:', debugInfo.eventsCount);
            if (debugInfo.recentEvents.length > 0) {
                console.log('   Últimos eventos:');
                debugInfo.recentEvents.forEach(ev => {
                    console.log(`     - ${ev.eventName} (${new Date(ev.timestamp).toLocaleTimeString()})`);
                });
            }
        }
    }, 5000);
    
    // Esperar a que la página esté completamente cargada
    await page.waitForTimeout(5000);
    
    // Simular el flujo: esperar a que todos estén conectados y luego notificar al servidor
    console.log('⏳ Esperando a que todos los jugadores estén conectados...');
    
    // Inyectar código para simular el flujo completo
    await page.evaluate(async () => {
        // Esperar a que todos estén conectados
        return new Promise((resolve) => {
            const checkInterval = setInterval(() => {
                const pusher = window.Echo?.connector?.pusher;
                const publicChannel = pusher?.allChannels()?.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
                
                if (publicChannel && publicChannel.subscribed) {
                    console.log('[DEBUG] Canal público listo, simulando notificación al servidor...');
                    
                    // Simular la llamada a /api/rooms/{code}/ready
                    const roomCode = window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1];
                    fetch(`/api/rooms/${roomCode}/ready`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('[DEBUG] Servidor respondió:', data);
                        clearInterval(checkInterval);
                        resolve(data);
                    })
                    .catch(error => {
                        console.error('[DEBUG] Error:', error);
                        clearInterval(checkInterval);
                        resolve(null);
                    });
                }
            }, 1000);
            
            // Timeout de seguridad
            setTimeout(() => {
                clearInterval(checkInterval);
                resolve(null);
            }, 20000);
        });
    });
    
    console.log('⏳ Esperando 10 segundos más para capturar el evento game.countdown...');
    await new Promise(resolve => setTimeout(resolve, 10000));
    
    // Obtener información final
    const finalInfo = await page.evaluate(() => {
        if (!window.debugWebSocket) return null;
        
        const pusher = window.Echo?.connector?.pusher;
        const channels = pusher ? pusher.allChannels() : [];
        
        return {
            connectionState: window.debugWebSocket.connectionState,
            channels: channels.map(c => ({
                name: c.name,
                subscribed: c.subscribed
            })),
            events: window.debugWebSocket.events,
            roomCode: window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1]
        };
    });
    
    console.log('\n📊 [RESUMEN FINAL]');
    console.log('   Room Code:', finalInfo?.roomCode);
    console.log('   Estado de conexión:', finalInfo?.connectionState);
    console.log('   Canales suscritos:');
    finalInfo?.channels.forEach(ch => {
        console.log(`     - ${ch.name}: ${ch.subscribed ? '✅ Suscrito' : '❌ No suscrito'}`);
    });
    console.log(`\n   Total de eventos capturados: ${finalInfo?.events.length || 0}`);
    
    if (finalInfo?.events.length > 0) {
        console.log('\n   Eventos capturados:');
        finalInfo.events.forEach((ev, idx) => {
            console.log(`     ${idx + 1}. ${ev.eventName} (${new Date(ev.timestamp).toLocaleTimeString()})`);
            if (ev.eventName.includes('game.countdown')) {
                console.log(`        Data:`, JSON.stringify(ev.data, null, 8));
            }
        });
    }
    
    try {
        await browser.close();
    } catch (e) {
        // Ignorar errores al cerrar
    }
    console.log('\n✅ Debugging completado');
}

// Ejecutar
debugWebSocket().catch(error => {
    console.error('❌ Error:', error.message);
    console.error('Stack:', error.stack);
    process.exit(1);
});

