#!/usr/bin/env node

/**
 * Test completo del flujo WebSocket con Puppeteer
 * Ejecuta todo el flujo: login -> crear sala -> transition -> ready -> verificar evento
 */

import puppeteer from 'puppeteer';

const BASE_URL = 'https://gambito.nebulio.es';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';

console.log('🔍 [PUPPETEER TEST] Iniciando test completo del flujo WebSocket\n');

const browser = await puppeteer.launch({ 
    headless: false, // Mostrar navegador
    args: ['--no-sandbox', '--disable-setuid-sandbox']
});

const page = await browser.newPage();

let eventReceived = false;
let eventData = null;
let roomCode = null;

// Capturar todos los logs relevantes
page.on('console', msg => {
    const text = msg.text();
    const type = msg.type();
    
    if (text.includes('game.countdown') || 
        text.includes('Countdown event') ||
        text.includes('Transition') ||
        text.includes('Echo') ||
        text.includes('Canal') ||
        text.includes('pusher') ||
        text.includes('subscription') ||
        text.includes('ERROR') ||
        text.includes('✅') ||
        text.includes('❌')) {
        console.log(`[${type.toUpperCase()}] ${text}`);
        
        if (text.includes('game.countdown')) {
            eventReceived = true;
        }
    }
});

// Interceptar responses de API
page.on('response', async response => {
    const url = response.url();
    if (url.includes('/api/rooms/') && url.includes('/ready')) {
        console.log(`📡 [API] Response ${response.status()}: ${url}`);
        try {
            const data = await response.json();
            console.log(`   Data:`, JSON.stringify(data, null, 2));
        } catch (e) {}
    }
});

try {
    // 1. Login
    console.log('🔐 [1/6] Haciendo login...');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2', timeout: 30000 });
    
    await page.waitForSelector('input[name="email"]', { timeout: 5000 });
    await page.type('input[name="email"]', EMAIL);
    await page.type('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
    console.log('✅ Login exitoso\n');
    
    // 2. Crear sala
    console.log('🎮 [2/6] Creando sala de prueba...');
    await page.goto(`${BASE_URL}/rooms/create`, { waitUntil: 'networkidle2', timeout: 30000 });
    
    // Esperar a que la página cargue completamente
    await page.waitForTimeout(2000);
    
    // Verificar si hay juegos disponibles
    const hasGames = await page.evaluate(() => {
        const radios = document.querySelectorAll('input[type="radio"][name="game_id"]');
        return radios.length > 0;
    });
    
    if (!hasGames) {
        throw new Error('No hay juegos disponibles');
    }
    
    // Obtener el primer game_id disponible
    const firstGameId = await page.evaluate(() => {
        const firstRadio = document.querySelector('input[type="radio"][name="game_id"]');
        return firstRadio ? firstRadio.value : null;
    });
    
    if (!firstGameId) {
        throw new Error('No se pudo obtener el game_id');
    }
    
    console.log(`   Seleccionando juego ID: ${firstGameId}`);
    
    // Click en el primer radio button
    await page.click(`input[type="radio"][name="game_id"][value="${firstGameId}"]`);
    await page.waitForTimeout(2000); // Esperar a que se cargue la configuración
    
    // Obtener CSRF token fresco
    const csrfToken = await page.evaluate(() => {
        return document.querySelector('meta[name="csrf-token"]')?.content || 
               document.querySelector('input[name="_token"]')?.value || '';
    });
    
    console.log(`   CSRF Token obtenido: ${csrfToken ? '✅' : '❌'}`);
    
    // Click en el botón de crear usando el formulario directamente
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });
    
    // Interceptar la respuesta para ver si hay error 419
    const navigationPromise = page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
    await page.click('button[type="submit"]');
    
    try {
        await navigationPromise;
    } catch (e) {
        // Si falla, verificar si hay error 419
        const errorText = await page.evaluate(() => {
            return document.body.innerText;
        });
        
        if (errorText.includes('419') || errorText.includes('CSRF')) {
            console.log('   ⚠️  Error 419 detectado, refrescando página...');
            await page.reload({ waitUntil: 'networkidle2' });
            await page.waitForTimeout(2000);
            
            // Intentar de nuevo
            await page.click(`input[type="radio"][name="game_id"][value="${firstGameId}"]`);
            await page.waitForTimeout(2000);
            await page.click('button[type="submit"]');
            await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
        } else {
            throw e;
        }
    }
    
    // Obtener código de sala
    const currentUrl = page.url();
    const roomCodeMatch = currentUrl.match(/\/rooms\/([A-Z0-9]+)/);
    roomCode = roomCodeMatch ? roomCodeMatch[1] : null;
    
    if (!roomCode) {
        throw new Error('No se pudo obtener el código de la sala');
    }
    
    console.log(`✅ Sala creada: ${roomCode}\n`);
    
    // 3. Ir a transition page (simular que el juego inició)
    console.log('🔄 [3/6] Navegando a transition page...');
    const transitionUrl = `${BASE_URL}/rooms/${roomCode}`;
    await page.goto(transitionUrl, { waitUntil: 'networkidle2', timeout: 30000 });
    console.log('✅ En transition page\n');
    
    // 4. Configurar listener para eventos
    console.log('📡 [4/6] Configurando listeners de WebSocket...');
    await page.evaluate(() => {
        window.testEventReceived = false;
        window.testEventData = null;
        
        if (window.Echo) {
            const pusher = window.Echo.connector.pusher;
            
            // Listener global
            pusher.bind_global((eventName, data) => {
                if (eventName.includes('game.countdown')) {
                    console.log('[TEST] Evento game.countdown recibido:', eventName, data);
                    window.testEventReceived = true;
                    window.testEventData = data;
                }
            });
            
            // También en canal público
            const roomCode = window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1];
            if (roomCode) {
                const channel = window.Echo.channel(`room.${roomCode}`);
                channel.listen('.game.countdown', (data) => {
                    console.log('[TEST] Countdown recibido en canal público:', data);
                    window.testEventReceived = true;
                    window.testEventData = data;
                });
            }
        }
    });
    
    // Esperar a que WebSocket se conecte
    await page.waitForTimeout(5000);
    
    // Verificar estado de conexión
    const wsStatus = await page.evaluate(() => {
        const pusher = window.Echo?.connector?.pusher;
        const channels = pusher ? pusher.allChannels() : [];
        const publicChannel = channels.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
        
        return {
            connected: pusher?.connection?.state === 'connected',
            publicChannelSubscribed: publicChannel?.subscribed || false,
            channelName: publicChannel?.name || 'none'
        };
    });
    
    console.log(`   Conexión: ${wsStatus.connected ? '✅' : '❌'}`);
    console.log(`   Canal público: ${wsStatus.publicChannelSubscribed ? '✅' : '❌'} (${wsStatus.channelName})\n`);
    
    if (!wsStatus.connected || !wsStatus.publicChannelSubscribed) {
        console.log('⚠️  WebSocket no está completamente conectado, esperando más tiempo...');
        await page.waitForTimeout(3000);
    }
    
    // 5. Simular que todos están conectados y llamar a /ready
    console.log('📞 [5/6] Llamando a /api/rooms/{code}/ready...');
    
    const response = await page.evaluate(async (code) => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        
        try {
            const res = await fetch(`/api/rooms/${code}/ready`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            
            return {
                status: res.status,
                data: await res.json()
            };
        } catch (error) {
            return {
                error: error.message
            };
        }
    }, roomCode);
    
    console.log(`   Status: ${response.status || 'ERROR'}`);
    console.log(`   Response:`, JSON.stringify(response.data || response.error, null, 2));
    
    if (!response.data?.success) {
        throw new Error('La llamada a /ready falló');
    }
    
    console.log('✅ Servidor confirmó que el countdown comenzará\n');
    
    // 6. Esperar evento game.countdown
    console.log('⏳ [6/6] Esperando evento game.countdown (15 segundos)...\n');
    
    for (let i = 0; i < 15; i++) {
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        const eventStatus = await page.evaluate(() => {
            return {
                received: window.testEventReceived || false,
                data: window.testEventData || null
            };
        });
        
        if (eventStatus.received) {
            console.log('✅ ✅ ✅ EVENTO game.countdown RECIBIDO! ✅ ✅ ✅');
            console.log('   Data:', JSON.stringify(eventStatus.data, null, 2));
            eventReceived = true;
            eventData = eventStatus.data;
            break;
        }
        
        if (i % 3 === 0) {
            console.log(`   [${i}s] Esperando evento...`);
        }
    }
    
    // Resultado final
    console.log('\n' + '='.repeat(60));
    console.log('📊 RESULTADO FINAL');
    console.log('='.repeat(60));
    console.log(`   Room Code: ${roomCode}`);
    console.log(`   Conexión WebSocket: ${wsStatus.connected ? '✅' : '❌'}`);
    console.log(`   Canal público suscrito: ${wsStatus.publicChannelSubscribed ? '✅' : '❌'}`);
    console.log(`   Evento game.countdown recibido: ${eventReceived ? '✅ ✅ ✅' : '❌ ❌ ❌'}`);
    
    if (eventReceived && eventData) {
        console.log(`\n   Datos del evento:`);
        console.log(`   - Seconds: ${eventData.seconds || eventData.durationMs / 1000 || 'N/A'}`);
        console.log(`   - Server Timestamp: ${eventData.serverTimestamp || 'N/A'}`);
        console.log(`   - Room Code: ${eventData.room_code || eventData.roomCode || 'N/A'}`);
    }
    
    if (!eventReceived) {
        console.log('\n❌ PROBLEMA: El evento NO llegó');
        console.log('\n💡 Posibles causas:');
        console.log('   1. QUEUE_CONNECTION aún no está en sync (verificar en servidor)');
        console.log('   2. Reverb no está transmitiendo el evento');
        console.log('   3. El evento se emite pero hay un problema de timing');
        console.log('\n💡 Verificar en servidor:');
        console.log(`   myforge-exec gambito "tail -50 storage/logs/laravel.log | grep GameCountdownEvent"`);
    } else {
        console.log('\n✅ ✅ ✅ TEST EXITOSO - El evento llegó correctamente! ✅ ✅ ✅');
    }
    
} catch (error) {
    console.error('\n❌ Error durante el test:', error.message);
    console.error('Stack:', error.stack);
} finally {
    console.log('\n⏳ Cerrando navegador en 5 segundos...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    await browser.close();
    
    process.exit(eventReceived ? 0 : 1);
}

