#!/usr/bin/env node

/**
 * Test con múltiples jugadores simulados
 * Ejecutar: node test-multiple-players.js [ROOM_CODE]
 */

import puppeteer from 'puppeteer';

const BASE_URL = 'https://gambito.nebulio.es';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';
const ROOM_CODE = process.argv[2] || 'N3MMN6';
const NUM_PLAYERS = 2; // Número de jugadores a simular

console.log(`🔍 [MULTI-PLAYER TEST] Test con ${NUM_PLAYERS} jugadores en sala: ${ROOM_CODE}\n`);

const browsers = [];
const pages = [];
let eventsReceived = 0;

// Función para crear un jugador
async function createPlayer(playerNum) {
    const browser = await puppeteer.launch({ 
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    await page.setUserAgent(`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36`);
    await page.setViewport({ width: 1920, height: 1080 });
    
    let eventReceived = false;
    
    // Capturar logs
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('game.countdown') || text.includes('Countdown')) {
            console.log(`[Player ${playerNum}] ✅ Evento recibido!`);
            eventReceived = true;
            eventsReceived++;
        }
    });
    
    // Login
    console.log(`🔐 [Player ${playerNum}] Login...`);
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('input[name="email"]', { timeout: 10000 });
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    await page.evaluate(() => {
        document.querySelector('input[name="email"]').value = '';
        document.querySelector('input[name="password"]').value = '';
    });
    
    await page.type('input[name="email"]', EMAIL, { delay: 50 });
    await page.type('input[name="password"]', PASSWORD, { delay: 50 });
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => null),
        page.click('button[type="submit"]')
    ]);
    
    console.log(`✅ [Player ${playerNum}] Login completado`);
    
    // Ir a transition page
    console.log(`🔄 [Player ${playerNum}] Navegando a transition page...`);
    await page.goto(`${BASE_URL}/rooms/${ROOM_CODE}`, { waitUntil: 'networkidle2' });
    
    // Esperar a que el canal público se suscriba
    let subscribed = false;
    for (let i = 0; i < 10; i++) {
        await new Promise(resolve => setTimeout(resolve, 1000));
        const status = await page.evaluate(() => {
            const pusher = window.Echo?.connector?.pusher;
            const channels = pusher ? pusher.allChannels() : [];
            const publicChannel = channels.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
            return publicChannel?.subscribed || false;
        });
        
        if (status) {
            subscribed = true;
            break;
        }
    }
    
    if (!subscribed) {
        console.log(`   ⚠️ [Player ${playerNum}] Canal público no se suscribió después de 10 segundos`);
    }
    
    // Configurar listeners
    await page.evaluate(() => {
        window.testEventReceived = false;
        window.testEventData = null;
        
        if (window.Echo) {
            const pusher = window.Echo.connector.pusher;
            
            pusher.bind_global((eventName, data) => {
                if (eventName.includes('game.countdown')) {
                    window.testEventReceived = true;
                    window.testEventData = data;
                }
            });
            
            const roomCode = window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1];
            if (roomCode) {
                const channel = window.Echo.channel(`room.${roomCode}`);
                channel.listen('.game.countdown', (data) => {
                    window.testEventReceived = true;
                    window.testEventData = data;
                });
            }
        }
    });
    
    // Verificar estado WebSocket
    const wsStatus = await page.evaluate(() => {
        const pusher = window.Echo?.connector?.pusher;
        const channels = pusher ? pusher.allChannels() : [];
        const publicChannel = channels.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
        
        return {
            connected: pusher?.connection?.state === 'connected',
            publicChannelSubscribed: publicChannel?.subscribed || false
        };
    });
    
    console.log(`   [Player ${playerNum}] Conexión: ${wsStatus.connected ? '✅' : '❌'}, Canal: ${wsStatus.publicChannelSubscribed ? '✅' : '❌'}`);
    
    return { browser, page, playerNum, eventReceived: () => eventReceived };
}

try {
    // Crear todos los jugadores
    console.log(`👥 Creando ${NUM_PLAYERS} jugadores...\n`);
    
    for (let i = 1; i <= NUM_PLAYERS; i++) {
        const player = await createPlayer(i);
        browsers.push(player.browser);
        pages.push(player);
        await new Promise(resolve => setTimeout(resolve, 2000)); // Espaciar conexiones
    }
    
    console.log(`\n✅ Todos los jugadores conectados\n`);
    console.log(`⏳ Esperando a que todos se conecten al Presence Channel...\n`);
    await new Promise(resolve => setTimeout(resolve, 8000)); // Más tiempo para Presence Channel
    
    // Verificar estado de todos los jugadores
    let totalMembers = 0;
    for (const player of pages) {
        const status = await player.page.evaluate(() => {
            // Verificar miembros en Presence Channel
            const pusher = window.Echo?.connector?.pusher;
            const channels = pusher ? pusher.allChannels() : [];
            const presenceChannel = channels.find(c => c.name.startsWith('presence-'));
            
            if (presenceChannel && presenceChannel.members) {
                return Object.keys(presenceChannel.members.members || {}).length;
            }
            return 0;
        }).catch(() => 0);
        
        console.log(`   [Player ${player.playerNum}] Miembros en Presence: ${status}`);
        totalMembers = Math.max(totalMembers, status);
    }
    
    console.log(`\n   Total de miembros detectados: ${totalMembers}`);
    
    // Esperar a que todos estén conectados según el servidor
    if (totalMembers < NUM_PLAYERS) {
        console.log(`   ⚠️  Esperando más tiempo para que todos se conecten...`);
        await new Promise(resolve => setTimeout(resolve, 5000));
    }
    
    // El último jugador llama a /ready cuando todos estén conectados
    console.log(`\n📞 [Player ${NUM_PLAYERS}] Llamando a /api/rooms/{code}/ready...`);
    
    const lastPlayer = pages[NUM_PLAYERS - 1];
    const response = await lastPlayer.page.evaluate(async (code) => {
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
    }, ROOM_CODE);
    
    console.log(`   Status: ${response.status || 'ERROR'}`);
    console.log(`   Response:`, JSON.stringify(response.data || response.error, null, 2));
    
    if (response.data?.success) {
        console.log(`\n✅ Servidor confirmó countdown\n`);
        console.log(`⏳ Esperando evento game.countdown en todos los jugadores (15 segundos)...\n`);
        
        // Esperar eventos en todos los jugadores
        for (let i = 0; i < 15; i++) {
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            let allReceived = true;
            for (const player of pages) {
                try {
                    const status = await player.page.evaluate(() => ({
                        received: window.testEventReceived || false,
                        data: window.testEventData || null
                    }));
                    
                    if (!status.received && !player.eventReceived()) {
                        allReceived = false;
                    }
                } catch (e) {
                    // Página puede haber navegado
                }
            }
            
            if (eventsReceived >= NUM_PLAYERS || allReceived) {
                console.log(`✅ ✅ ✅ Todos los eventos recibidos! ✅ ✅ ✅`);
                break;
            }
            
            if (i % 3 === 0) {
                console.log(`   [${i}s] Eventos recibidos: ${eventsReceived}/${NUM_PLAYERS}`);
            }
        }
    }
    
    // Resultado final
    console.log('\n' + '='.repeat(60));
    console.log('📊 RESULTADO FINAL');
    console.log('='.repeat(60));
    console.log(`   Sala: ${ROOM_CODE}`);
    console.log(`   Jugadores: ${NUM_PLAYERS}`);
    console.log(`   Eventos recibidos: ${eventsReceived}/${NUM_PLAYERS}`);
    console.log(`   Éxito: ${eventsReceived >= NUM_PLAYERS ? '✅ ✅ ✅' : '❌ ❌ ❌'}`);
    
    if (eventsReceived >= NUM_PLAYERS) {
        console.log('\n✅ ✅ ✅ TEST EXITOSO CON MÚLTIPLES JUGADORES! ✅ ✅ ✅');
    } else {
        console.log('\n⚠️  Algunos jugadores no recibieron el evento');
    }
    
} catch (error) {
    console.error('\n❌ Error:', error.message);
} finally {
    console.log('\n⏳ Cerrando navegadores en 5 segundos...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    for (const browser of browsers) {
        try {
            await browser.close();
        } catch (e) {}
    }
    
    process.exit(eventsReceived >= NUM_PLAYERS ? 0 : 1);
}

