#!/usr/bin/env node

/**
 * Test completo del flujo hasta el juego funcionando
 * Verifica que después del countdown se muestre el panel de dibujo
 */

import puppeteer from 'puppeteer';

const BASE_URL = process.env.TEST_URL || 'http://gambito.test';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';
const parsedGuests = parseInt(process.env.NUM_GUESTS ?? '1', 10);
const NUM_PLAYERS = Number.isNaN(parsedGuests) || parsedGuests < 1 ? 1 : parsedGuests;

console.log(`🔍 [FULL FLOW TEST] Test completo hasta panel de dibujo\n`);

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
    
    console.log(`✅ Login completado\n   URL tras login: ${page.url()}\n`);
}

// Función para crear sala
async function createRoom(page) {
    console.log('🎮 Creando sala de Pictionary...');
    await page.goto(`${BASE_URL}/rooms/create`, { waitUntil: 'networkidle2' });
    console.log(`   URL creación sala: ${page.url()}`);
    await new Promise(resolve => setTimeout(resolve, 4000));
    await page.waitForSelector('form', { timeout: 10000 });
    await page.waitForFunction(() => {
        return document.querySelectorAll('input[type="radio"][name="game_id"]').length > 0 ||
            document.querySelector('select[name="game_id"]');
    }, { timeout: 15000 }).catch(() => null);
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    const optionSnapshot = await page.evaluate(() => {
        const radios = Array.from(document.querySelectorAll('input[type="radio"][name="game_id"]')).map(radio => {
            const label = radio.closest('label');
            return {
                value: radio.value,
                text: label ? label.textContent.trim() : radio.value
            };
        });
        const select = document.querySelector('select[name="game_id"]');
        const selectOptions = select ? Array.from(select.options).map(option => ({
            value: option.value,
            text: option.textContent.trim()
        })) : [];
        const cards = Array.from(document.querySelectorAll('[data-game-id]')).map(card => ({
            value: card.getAttribute('data-game-id'),
            text: card.textContent.trim()
        }));
        return { radios, selectOptions, cards };
    });
    
    console.log('   Opciones detectadas:', JSON.stringify(optionSnapshot, null, 2));
    
    if (!optionSnapshot.radios.length && !optionSnapshot.selectOptions.length && !optionSnapshot.cards.length) {
        const bodyPreview = await page.evaluate(() => document.body.innerText.slice(0, 400));
        console.log('   ⚠️  No se detectaron opciones. Preview body:\n', bodyPreview);
    }
    
    const pictionaryId = await page.evaluate(() => {
        const radios = Array.from(document.querySelectorAll('input[type="radio"][name="game_id"]'));
        if (radios.length > 0) {
            for (const radio of radios) {
                const label = radio.closest('label');
                if (label && label.textContent.toLowerCase().includes('pictionary')) {
                    return { value: radio.value, type: 'radio' };
                }
            }
            return { value: radios[0]?.value || null, type: 'radio' };
        }
        
        const select = document.querySelector('select[name="game_id"]');
        if (select) {
            for (const option of Array.from(select.options)) {
                if (option.textContent.toLowerCase().includes('pictionary')) {
                    return { value: option.value, type: 'select' };
                }
            }
            return { value: select.options[1]?.value || select.options[0]?.value || null, type: 'select' };
        }
        
        return { value: null, type: 'none' };
    });
    
    if (!pictionaryId) {
        throw new Error('No se encontró ningún juego disponible');
    }
    
    if (!pictionaryId.value) {
        throw new Error('No se pudo determinar el ID del juego');
    }
    
    console.log(`   Seleccionando juego ID: ${pictionaryId.value}`);
    
    if (pictionaryId.type === 'radio') {
        await page.click(`input[type="radio"][name="game_id"][value="${pictionaryId.value}"]`);
    } else if (pictionaryId.type === 'select') {
        await page.select('select[name="game_id"]', pictionaryId.value);
    } else {
        throw new Error('No se encontró control para seleccionar el juego');
    }
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    await page.waitForSelector('button[type="submit"]', { timeout: 5000 });
    await page.click('button[type="submit"]');
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
    
    const currentUrl = page.url();
    const roomCodeMatch = currentUrl.match(/\/rooms\/([A-Z0-9]+)/);
    const roomCode = roomCodeMatch ? roomCodeMatch[1] : null;
    
    if (!roomCode) {
        throw new Error('No se pudo obtener el código de la sala');
    }
    
    console.log(`✅ Sala creada: ${roomCode}\n`);
    return roomCode;
}

// Función para iniciar juego
async function startGame(page, roomCode) {
    console.log('🚀 Iniciando juego...');
    await page.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    try {
        await page.waitForSelector('#start-game-button', { timeout: 10000 });
        
        const isEnabled = await page.evaluate(() => {
            const btn = document.getElementById('start-game-button');
            return btn && !btn.disabled;
        });
        
        if (isEnabled) {
            await page.click('#start-game-button');
            await new Promise(resolve => setTimeout(resolve, 3000));
            console.log('✅ Juego iniciado\n');
        } else {
            console.log('⚠️  Botón deshabilitado, esperando...');
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
        console.log(`⚠️  Error: ${e.message}\n`);
    }
}

// Función para crear invitado
async function createGuest(playerNum, roomCode) {
    const browser = await puppeteer.launch({ 
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    await page.setUserAgent(`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36`);
    await page.setViewport({ width: 1920, height: 1080 });
    
    // Capturar todos los logs
    page.on('console', msg => {
        const text = msg.text();
        if (text.includes('Transition') || 
            text.includes('game.countdown') || 
            text.includes('game.initialized') ||
            text.includes('game.started') ||
            text.includes('round.started') ||
            text.includes('RoundStarted') ||
            text.includes('DomLoaded') ||
            text.includes('Esperando') ||
            text.includes('ronda') ||
            text.includes('Pictionary') ||
            text.includes('ERROR') ||
            text.includes('✅') ||
            text.includes('❌') ||
            text.includes('[Echo]')) {
            console.log(`[Guest ${playerNum}] ${text}`);
        }
    });
    
    console.log(`🔄 [Guest ${playerNum}] Uniéndose al lobby...`);
    await page.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
    await new Promise(resolve => setTimeout(resolve, 2000));
    
    const currentUrl = page.url();
    if (currentUrl.includes('/guest-name')) {
        console.log(`   [Guest ${playerNum}] Completando formulario...`);
        const guestName = `Guest${playerNum}`;
        await page.waitForSelector('input[name="player_name"]', { timeout: 10000 });
        await page.type('input[name="player_name"]', guestName, { delay: 50 });
        await page.click('button[type="submit"]');
        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
        await new Promise(resolve => setTimeout(resolve, 2000));
    }
    
    return { browser, page, playerNum };
}

let mainBrowser;
const guests = [];

try {
    // Master
    mainBrowser = await puppeteer.launch({ 
        headless: false,
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    const mainPage = await mainBrowser.newPage();
    await mainPage.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    await mainPage.setViewport({ width: 1920, height: 1080 });
    
    // Capturar logs del master
    mainPage.on('console', msg => {
        const text = msg.text();
        if (text.includes('Transition') || 
            text.includes('game.countdown') || 
            text.includes('game.initialized') ||
            text.includes('game.started') ||
            text.includes('round.started') ||
            text.includes('RoundStarted') ||
            text.includes('DomLoaded') ||
            text.includes('Esperando') ||
            text.includes('ronda') ||
            text.includes('play') ||
            text.includes('Pictionary') ||
            text.includes('ERROR') ||
            text.includes('✅') ||
            text.includes('❌') ||
            text.includes('[Echo]')) {
            console.log(`[Master] ${text}`);
        }
    });
    
    await login(mainPage);
    const roomCode = await createRoom(mainPage);
    
    console.log('🏠 Yendo al lobby...');
    await mainPage.goto(`${BASE_URL}/rooms/${roomCode}/lobby`, { waitUntil: 'networkidle2' });
    await new Promise(resolve => setTimeout(resolve, 3000));
    
    // Crear invitados
    for (let i = 1; i <= NUM_PLAYERS; i++) {
        const guest = await createGuest(i, roomCode);
        guests.push(guest);
        await new Promise(resolve => setTimeout(resolve, 2000));
    }
    
    console.log(`\n✅ Todos en el lobby\n`);
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    // Iniciar juego
    await startGame(mainPage, roomCode);
    
    // Esperar a que todos vayan a transition
    console.log(`⏳ Esperando a que todos vayan a transition page...\n`);
    await new Promise(resolve => setTimeout(resolve, 5000));
    
    // Esperar countdown y verificar que todos lleguen a la página del juego
    console.log(`⏳ Esperando countdown y redirección (60 segundos)...\n`);
    
    let masterInGame = false;
    let guestsInGame = 0;
    let masterReceivedRoundStarted = false;
    let masterReceivedGameStarted = false;
    
    // Interceptar eventos WebSocket del master
    await mainPage.evaluateOnNewDocument(() => {
        const originalLog = console.log;
        window._testEvents = {
            roundStarted: false,
            gameStarted: false,
            domLoaded: false
        };
        
        // Interceptar eventos de Echo
        if (window.Echo) {
            const originalListen = window.Echo.channel.prototype.listen;
            window.Echo.channel.prototype.listen = function(event, callback) {
                if (event.includes('round.started') || event.includes('game.round.started')) {
                    window._testEvents.roundStarted = true;
                    originalLog('[TEST] RoundStartedEvent detected');
                }
                if (event.includes('game.started')) {
                    window._testEvents.gameStarted = true;
                    originalLog('[TEST] GameStartedEvent detected');
                }
                return originalListen.call(this, event, callback);
            };
        }
    });
    
    for (let i = 0; i < 60; i++) {
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // Verificar master
        const masterUrl = mainPage.url();
        if (masterUrl.includes(`/rooms/${roomCode}`) && !masterUrl.includes('/lobby') && !masterUrl.includes('/transition')) {
            if (!masterInGame) {
                console.log(`✅ [Master] En página del juego`);
                masterInGame = true;
            }
            
            // Verificar eventos recibidos
            const events = await mainPage.evaluate(() => {
                return window._testEvents || {};
            });
            if (events.roundStarted && !masterReceivedRoundStarted) {
                console.log(`✅ [Master] RoundStartedEvent recibido`);
                masterReceivedRoundStarted = true;
            }
            if (events.gameStarted && !masterReceivedGameStarted) {
                console.log(`✅ [Master] GameStartedEvent recibido`);
                masterReceivedGameStarted = true;
            }
            
            // Verificar si hay panel de dibujo o botones del juego
            const hasGameUI = await mainPage.evaluate(() => {
                const loadingState = document.getElementById('loading-state');
                const playingState = document.getElementById('playing-state');
                const bodyText = document.body.innerText.toLowerCase();
                return {
                    hasLoading: loadingState && !loadingState.classList.contains('hidden'),
                    hasPlaying: playingState && !playingState.classList.contains('hidden'),
                    hasCanvas: !!document.querySelector('canvas'),
                    hasDrawingPanel: bodyText.includes('dibuj') || bodyText.includes('lo sé'),
                    text: document.body.innerText.substring(0, 300)
                };
            });
            
            if (hasGameUI.hasLoading && !hasGameUI.hasPlaying) {
                console.log(`⏳ [Master] Aún en loading state: "${hasGameUI.text.substring(0, 100)}"`);
            } else if (hasGameUI.hasPlaying) {
                console.log(`🎨 [Master] Playing state visible`);
                if (hasGameUI.hasCanvas) {
                    console.log(`   ✅ Canvas detectado`);
                }
            }
        }
        
        // Verificar invitados
        for (const guest of guests) {
            try {
                const guestUrl = guest.page.url();
                if (guestUrl.includes(`/rooms/${roomCode}`) && !guestUrl.includes('/lobby') && !guestUrl.includes('/transition')) {
                    guestsInGame++;
                }
            } catch (e) {
                // Página puede haber navegado
            }
        }
        
        if (masterInGame && guestsInGame >= NUM_PLAYERS && masterReceivedRoundStarted) {
            console.log(`\n✅ Todos en la página del juego y RoundStarted recibido\n`);
            break;
        }
        
        if (i % 5 === 0) {
            console.log(`   [${i}s] Master: ${masterInGame ? '✅' : '⏳'}, Guests: ${guestsInGame}/${NUM_PLAYERS}, RoundStarted: ${masterReceivedRoundStarted ? '✅' : '⏳'}`);
        }
    }
    
    // Verificar estado final
    console.log('\n' + '='.repeat(60));
    console.log('📊 ESTADO FINAL');
    console.log('='.repeat(60));
    console.log(`   Master en juego: ${masterInGame ? '✅' : '❌'}`);
    console.log(`   Invitados en juego: ${guestsInGame}/${NUM_PLAYERS}`);
    
    const finalUrl = mainPage.url();
    console.log(`   URL final master: ${finalUrl}`);
    
    const pageContent = await mainPage.evaluate(() => {
        return {
            title: document.title,
            bodyText: document.body.innerText.substring(0, 500),
            hasCanvas: !!document.querySelector('canvas'),
            hasDrawingPanel: document.body.innerText.toLowerCase().includes('dibuj'),
            hasButtons: document.body.innerText.toLowerCase().includes('lo sé')
        };
    });
    
    console.log(`\n   Contenido de la página:`);
    console.log(`   - Título: ${pageContent.title}`);
    console.log(`   - Tiene canvas: ${pageContent.hasCanvas ? '✅' : '❌'}`);
    console.log(`   - Tiene panel de dibujo: ${pageContent.hasDrawingPanel ? '✅' : '❌'}`);
    console.log(`   - Tiene botones: ${pageContent.hasButtons ? '✅' : '❌'}`);
    console.log(`   - Texto: ${pageContent.bodyText.substring(0, 200)}...`);
    
    if (pageContent.bodyText.includes('Esperando primera ronda')) {
        console.log(`\n❌ PROBLEMA: Se queda en "Esperando primera ronda..."`);
        console.log(`💡 El juego no está iniciando correctamente después del countdown`);
    }
    
} catch (error) {
    console.error('\n❌ Error:', error.message);
    process.exit(1);
} finally {
    if (mainBrowser) {
        try {
            await mainBrowser.close();
        } catch (e) {
            console.error('⚠️  No se pudo cerrar el navegador principal:', e.message);
        }
    }

    for (const guest of guests) {
        if (guest?.browser) {
            try {
                await guest.browser.close();
            } catch (e) {
                console.error(`⚠️  No se pudo cerrar el navegador de Guest ${guest.playerNum}:`, e.message);
            }
        }
    }
}

