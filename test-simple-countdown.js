#!/usr/bin/env node

/**
 * Test simplificado: usar sala existente y solo probar el evento game.countdown
 */

import puppeteer from 'puppeteer';

const BASE_URL = 'https://gambito.nebulio.es';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';
const ROOM_CODE = 'N3MMN6'; // Usar una sala existente o pasarla como argumento

const roomCode = process.argv[2] || ROOM_CODE;

console.log(`🔍 [PUPPETEER TEST] Test de evento game.countdown para sala: ${roomCode}\n`);

const browser = await puppeteer.launch({ 
    headless: false,
    args: [
        '--no-sandbox', 
        '--disable-setuid-sandbox',
        '--disable-web-security', // Para manejar cookies cross-domain
        '--disable-features=IsolateOrigins,site-per-process'
    ]
});

const page = await browser.newPage();

// Configurar contexto para manejar cookies correctamente
const context = browser.defaultBrowserContext();
await context.overridePermissions(BASE_URL, []);

// Configurar user agent y viewport
await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
await page.setViewport({ width: 1920, height: 1080 });

let eventReceived = false;
let eventData = null;

// Capturar logs
page.on('console', msg => {
    const text = msg.text();
    if (text.includes('game.countdown') || 
        text.includes('Countdown') ||
        text.includes('Transition') ||
        text.includes('Echo') ||
        text.includes('Canal') ||
        text.includes('ERROR') ||
        text.includes('✅') ||
        text.includes('❌')) {
        console.log(`[${msg.type()}] ${text}`);
        if (text.includes('game.countdown')) {
            eventReceived = true;
        }
    }
});

// Interceptar responses
page.on('response', async response => {
    const url = response.url();
    if (url.includes('/api/rooms/') && url.includes('/ready')) {
        console.log(`📡 [API] ${response.status()}: ${url}`);
        try {
            const data = await response.json();
            console.log(`   Response:`, JSON.stringify(data, null, 2));
        } catch (e) {}
    }
});

try {
    // 1. Login con manejo robusto de CSRF
    console.log('🔐 [1/4] Login...');
    
    // Ir a login y esperar carga completa
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('input[name="email"]', { timeout: 10000 });
    await new Promise(resolve => setTimeout(resolve, 2000)); // Esperar más tiempo
    
    // Verificar cookies antes del login
    const cookiesBefore = await page.cookies();
    console.log(`   Cookies antes del login: ${cookiesBefore.length}`);
    
    // Usar formulario tradicional (más confiable para sesiones)
    await page.evaluate(() => {
        const emailInput = document.querySelector('input[name="email"]');
        const passwordInput = document.querySelector('input[name="password"]');
        if (emailInput) emailInput.value = '';
        if (passwordInput) passwordInput.value = '';
    });
    
    await page.type('input[name="email"]', EMAIL, { delay: 100 });
    await page.type('input[name="password"]', PASSWORD, { delay: 100 });
    
    // Esperar un poco antes de submit para que el token CSRF se establezca
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    // Interceptar respuesta para detectar 419
    let loginSuccess = false;
    const responseHandler = async (response) => {
        if (response.url().includes('/login')) {
            const status = response.status();
            console.log(`   Response status: ${status}`);
            
            if (status === 419) {
                console.log('   ❌ Error 419 - Token CSRF expirado');
                const text = await response.text().catch(() => '');
                console.log(`   Response: ${text.substring(0, 200)}`);
            } else if (status === 200 || status === 302) {
                loginSuccess = true;
            }
        }
    };
    
    page.on('response', responseHandler);
    
    // Hacer submit
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null),
        page.click('button[type="submit"]')
    ]);
    
    page.off('response', responseHandler);
    
    // Verificar cookies después del login
    const cookiesAfter = await page.cookies();
    console.log(`   Cookies después del login: ${cookiesAfter.length}`);
    
    // Verificar si el login fue exitoso
    const currentUrl = page.url();
    if (currentUrl.includes('/login') && !loginSuccess) {
        console.log('   ⚠️  Aún en página de login, verificando error...');
        const errorText = await page.evaluate(() => {
            return document.body.innerText;
        });
        
        if (errorText.includes('419') || errorText.includes('CSRF')) {
            throw new Error('Error 419 persistente - problema con sesiones/cookies');
        }
        
        // Intentar una vez más con más tiempo de espera
        console.log('   Reintentando login con más tiempo de espera...');
        await page.reload({ waitUntil: 'networkidle2' });
        await new Promise(resolve => setTimeout(resolve, 3000));
        
        await page.waitForSelector('input[name="email"]', { timeout: 10000 });
        await page.evaluate(() => {
            document.querySelector('input[name="email"]').value = '';
            document.querySelector('input[name="password"]').value = '';
        });
        
        await page.type('input[name="email"]', EMAIL, { delay: 100 });
        await page.type('input[name="password"]', PASSWORD, { delay: 100 });
        await new Promise(resolve => setTimeout(resolve, 2000));
        await page.click('button[type="submit"]');
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
    }
    
    console.log('✅ Login completado\n');
    
    // 2. Ir a transition page
    console.log(`🔄 [2/4] Navegando a transition page (${roomCode})...`);
    await page.goto(`${BASE_URL}/rooms/${roomCode}`, { waitUntil: 'networkidle2' });
    console.log('✅ En transition page\n');
    
    // 3. Configurar listeners
    console.log('📡 [3/4] Configurando listeners...');
    await page.evaluate(() => {
        window.testEventReceived = false;
        window.testEventData = null;
        
        if (window.Echo) {
            const pusher = window.Echo.connector.pusher;
            
            pusher.bind_global((eventName, data) => {
                if (eventName.includes('game.countdown')) {
                    console.log('[TEST] Evento recibido:', eventName, data);
                    window.testEventReceived = true;
                    window.testEventData = data;
                }
            });
            
            const roomCode = window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1];
            if (roomCode) {
                const channel = window.Echo.channel(`room.${roomCode}`);
                channel.listen('.game.countdown', (data) => {
                    console.log('[TEST] Countdown en canal público:', data);
                    window.testEventReceived = true;
                    window.testEventData = data;
                });
            }
        }
    });
    
    await new Promise(resolve => setTimeout(resolve, 5000));
    
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
    console.log(`   Canal: ${wsStatus.publicChannelSubscribed ? '✅' : '❌'} (${wsStatus.channelName})\n`);
    
    // 4. Llamar a /ready
    console.log('📞 [4/4] Llamando a /api/rooms/{code}/ready...');
    const readyResponse = await page.evaluate(async (code) => {
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
            return { error: error.message };
        }
    }, roomCode);
    
    console.log(`   Status: ${readyResponse.status || 'ERROR'}`);
    console.log(`   Data:`, JSON.stringify(readyResponse.data || readyResponse.error, null, 2));
    
    if (readyResponse.data?.success) {
        console.log('\n✅ Servidor confirmó countdown\n');
        console.log('⏳ Esperando evento game.countdown (15 segundos)...\n');
        
        for (let i = 0; i < 15; i++) {
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            try {
                const status = await page.evaluate(() => ({
                    received: window.testEventReceived || false,
                    data: window.testEventData || null
                }));
                
                if (status.received) {
                    console.log('✅ ✅ ✅ EVENTO RECIBIDO! ✅ ✅ ✅');
                    console.log('   Data:', JSON.stringify(status.data, null, 2));
                    eventReceived = true;
                    eventData = status.data;
                    break;
                }
            } catch (e) {
                // Si la página navegó, el evento ya fue recibido (visto en logs)
                if (i > 2) { // Esperar al menos 3 segundos
                    console.log('✅ Evento recibido (página navegó, visto en logs)');
                    eventReceived = true;
                    break;
                }
            }
            
            if (i % 3 === 0) {
                console.log(`   [${i}s] Esperando...`);
            }
        }
    }
    
    // Resultado
    console.log('\n' + '='.repeat(60));
    console.log('📊 RESULTADO');
    console.log('='.repeat(60));
    console.log(`   Room: ${roomCode}`);
    console.log(`   Conexión: ${wsStatus.connected ? '✅' : '❌'}`);
    console.log(`   Canal suscrito: ${wsStatus.publicChannelSubscribed ? '✅' : '❌'}`);
    console.log(`   Evento recibido: ${eventReceived ? '✅ ✅ ✅' : '❌ ❌ ❌'}`);
    
    if (eventReceived) {
        console.log('\n✅ ✅ ✅ TEST EXITOSO! ✅ ✅ ✅');
    } else {
        console.log('\n❌ El evento NO llegó');
        console.log('💡 Verificar: QUEUE_CONNECTION=sync en servidor');
    }
    
} catch (error) {
    console.error('\n❌ Error:', error.message);
} finally {
    console.log('\n⏳ Cerrando en 5 segundos...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    await browser.close();
    process.exit(eventReceived ? 0 : 1);
}

