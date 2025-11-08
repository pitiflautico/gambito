#!/usr/bin/env node

/**
 * Test completo: Crear sala de Pictionary y probar con múltiples jugadores
 * Ejecutar: node test-create-room-multiplayer.js
 */

import puppeteer from 'puppeteer';

const BASE_URL = 'https://gambito.nebulio.es';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';
const NUM_PLAYERS = 2;

console.log(`🔍 [FULL TEST] Crear sala y test con ${NUM_PLAYERS} jugadores\n`);

// Función para login
async function login(page) {
    console.log('🔐 Login...');
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
    
    console.log('✅ Login completado\n');
}

// Función para crear sala
async function createRoom(page) {
    console.log('🎮 Creando sala de Pictionary...');
    await page.goto(`${BASE_URL}/rooms/create`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('input[type="radio"][name="game_id"]', { timeout: 10000 });
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    // Buscar Pictionary
    const pictionaryId = await page.evaluate(() => {
        const radios = Array.from(document.querySelectorAll('input[type="radio"][name="game_id"]'));
        for (const radio of radios) {
            const label = radio.closest('label');
            if (label && label.textContent.toLowerCase().includes('pictionary')) {
                return radio.value;
            }
        }
        // Si no encuentra Pictionary, usar el primero
        return radios[0]?.value || null;
    });
    
    if (!pictionaryId) {
        throw new Error('No se encontró ningún juego disponible');
    }
    
    console.log(`   Seleccionando juego ID: ${pictionaryId}`);
    await page.click(`input[type="radio"][name="game_id"][value="${pictionaryId}"]`);
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    // Click en crear
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });
    await page.click('button[type="submit"]');
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
    
    // Obtener código de sala
    const currentUrl = page.url();
    const roomCodeMatch = currentUrl.match(/\/rooms\/([A-Z0-9]+)/);
    const roomCode = roomCodeMatch ? roomCodeMatch[1] : null;
    
    if (!roomCode) {
        throw new Error('No se pudo obtener el código de la sala');
    }
    
    console.log(`✅ Sala creada: ${roomCode}\n`);
    return roomCode;
}

// Función para iniciar el juego (master)
async function startGame(page, roomCode) {
    console.log('🚀 Iniciando juego...');
    
    // Ir a la página del lobby
    await page.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    // Buscar y hacer click en el botón de iniciar juego
    try {
        // Esperar a que el botón esté disponible
        await page.waitForSelector('#start-game-button', { timeout: 10000 });
        
        // Verificar si está habilitado
        const isEnabled = await page.evaluate(() => {
            const btn = document.getElementById('start-game-button');
            return btn && !btn.disabled;
        });
        
        if (isEnabled) {
            await page.click('#start-game-button');
            await new Promise(resolve => setTimeout(resolve, 3000));
            console.log('✅ Juego iniciado\n');
        } else {
            console.log('⚠️  Botón deshabilitado, esperando más jugadores...');
            // Esperar a que se habilite
            await page.waitForFunction(
                () => {
                    const btn = document.getElementById('start-game-button');
                    return btn && !btn.disabled;
                },
                { timeout: 30000 }
            );
            await page.click('#start-game-button');
            await new Promise(resolve => setTimeout(resolve, 3000));
            console.log('✅ Juego iniciado\n');
        }
    } catch (e) {
        console.log(`⚠️  Error al iniciar juego: ${e.message}`);
        console.log('   Continuando de todas formas...\n');
    }
}

// Función para crear un jugador invitado (sin login)
async function createGuestPlayer(playerNum, roomCode, goToLobby = false) {
    const browser = await puppeteer.launch({ 
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    await page.setUserAgent(`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36`);
    await page.setViewport({ width: 1920, height: 1080 });
    
    let eventReceived = false;
    
    // Capturar eventos
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('game.countdown') || text.includes('Countdown event')) {
            console.log(`[Guest ${playerNum}] ✅ Evento game.countdown recibido!`);
            eventReceived = true;
        }
    });
    
    // Ir al lobby o directamente a la sala como invitado (sin login)
    if (goToLobby) {
        console.log(`🔄 [Guest ${playerNum}] Uniéndose al lobby ${roomCode} como invitado...`);
        await page.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
        
        // Si hay un formulario de nombre de invitado, completarlo
        await new Promise(resolve => setTimeout(resolve, 2000));
        
        // Verificar si redirigió a la página de nombre de invitado
        const currentUrl = page.url();
        if (currentUrl.includes('/guest-name') || currentUrl.includes('/guest')) {
            console.log(`   [Guest ${playerNum}] Completando formulario de nombre de invitado...`);
            const guestName = `Guest${playerNum}`;
            
            // Esperar y llenar el campo de nombre
            await page.waitForSelector('input[name="player_name"]', { timeout: 10000 });
            await page.type('input[name="player_name"]', guestName, { delay: 50 });
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // Hacer click en el botón de continuar
            await page.click('button[type="submit"]');
            await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
            await new Promise(resolve => setTimeout(resolve, 2000));
            console.log(`   [Guest ${playerNum}] ✅ Nombre ingresado y continuado al lobby`);
        }
    } else {
        console.log(`🔄 [Guest ${playerNum}] Conectando a sala ${roomCode} como invitado...`);
        await page.goto(`${BASE_URL}/rooms/${roomCode}`, { waitUntil: 'networkidle2' });
    }
    
    // Esperar suscripción al canal público
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
    
    console.log(`   [Guest ${playerNum}] Canal público: ${subscribed ? '✅' : '❌'}`);
    
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
    
    return { browser, page, playerNum, eventReceived: () => eventReceived };
}

try {
    // Crear navegador principal (master)
    const mainBrowser = await puppeteer.launch({ 
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const mainPage = await mainBrowser.newPage();
    await mainPage.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    await mainPage.setViewport({ width: 1920, height: 1080 });
    
    // 1. Login y crear sala
    await login(mainPage);
    const roomCode = await createRoom(mainPage);
    
    // 2. Ir al lobby (master)
    console.log('🏠 Yendo al lobby...');
    await mainPage.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
    await new Promise(resolve => setTimeout(resolve, 3000));
    console.log('✅ Master en el lobby\n');
    
    // 3. Crear jugadores invitados que se unan al lobby (sin login)
    console.log(`👥 Creando ${NUM_PLAYERS} jugadores invitados para el lobby...\n`);
    const players = [];
    
    for (let i = 1; i <= NUM_PLAYERS; i++) {
        const player = await createGuestPlayer(i, roomCode, true); // true = ir al lobby
        players.push(player);
        await new Promise(resolve => setTimeout(resolve, 2000));
    }
    
    console.log(`\n✅ Todos los jugadores en el lobby\n`);
    console.log(`⏳ Esperando a que todos se conecten...\n`);
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    // 4. Master inicia el juego desde el lobby
    await startGame(mainPage, roomCode);
    
    // 5. Esperar a que todos vayan a transition page
    console.log(`⏳ Esperando a que todos vayan a transition page...\n`);
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    console.log(`\n✅ Todos los jugadores conectados\n`);
    console.log(`⏳ Esperando a que todos se conecten al Presence Channel...\n`);
    await new Promise(resolve => setTimeout(resolve, 10000));
    
    // 4. Verificar miembros conectados
    let totalMembers = 0;
    for (const player of players) {
        const count = await player.page.evaluate(() => {
            const pusher = window.Echo?.connector?.pusher;
            const channels = pusher ? pusher.allChannels() : [];
            const presenceChannel = channels.find(c => c.name.startsWith('presence-'));
            
            if (presenceChannel && presenceChannel.members) {
                return Object.keys(presenceChannel.members.members || {}).length;
            }
            return 0;
        }).catch(() => 0);
        
        console.log(`   [Guest ${player.playerNum}] Miembros: ${count}`);
        totalMembers = Math.max(totalMembers, count);
    }
    
    console.log(`\n   Total de miembros: ${totalMembers}`);
    
    // 5. Llamar a /ready desde el último jugador
    console.log(`\n📞 Llamando a /api/rooms/{code}/ready...`);
    
    const lastPlayer = players[players.length - 1];
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
    }, roomCode);
    
    console.log(`   Status: ${response.status || 'ERROR'}`);
    console.log(`   Response:`, JSON.stringify(response.data || response.error, null, 2));
    
    if (response.data?.success) {
        console.log(`\n✅ Servidor confirmó countdown\n`);
        console.log(`⏳ Esperando evento game.countdown (15 segundos)...\n`);
        
        // Esperar eventos
        let eventsReceived = 0;
        for (let i = 0; i < 15; i++) {
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            for (const player of players) {
                try {
                    const status = await player.page.evaluate(() => ({
                        received: window.testEventReceived || false
                    }));
                    
                    if (status.received && !player.eventReceived()) {
                        eventsReceived++;
                    }
                } catch (e) {
                    // Página navegó
                }
            }
            
            if (eventsReceived >= NUM_PLAYERS) {
                console.log(`✅ ✅ ✅ Todos los eventos recibidos! ✅ ✅ ✅`);
                break;
            }
            
            if (i % 3 === 0) {
                console.log(`   [${i}s] Eventos: ${eventsReceived}/${NUM_PLAYERS}`);
            }
        }
        
        // Resultado
        console.log('\n' + '='.repeat(60));
        console.log('📊 RESULTADO FINAL');
        console.log('='.repeat(60));
        console.log(`   Sala: ${roomCode}`);
        console.log(`   Jugadores: ${NUM_PLAYERS}`);
        console.log(`   Eventos recibidos: ${eventsReceived}/${NUM_PLAYERS}`);
        console.log(`   Éxito: ${eventsReceived >= NUM_PLAYERS ? '✅ ✅ ✅' : '⚠️'}`);
    }
    
    // Cerrar navegadores
    await new Promise(resolve => setTimeout(resolve, 5000));
    await mainBrowser.close();
    for (const player of players) {
        await player.browser.close();
    }
    
} catch (error) {
    console.error('\n❌ Error:', error.message);
    process.exit(1);
}

