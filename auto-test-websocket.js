#!/usr/bin/env node

/**
 * Script automatizado para crear sala y testear WebSocket
 * Ejecutar: node auto-test-websocket.js
 * 
 * Este script:
 * 1. Hace login en la aplicación
 * 2. Crea una sala de prueba
 * 3. Se conecta a la transition page
 * 4. Simula el flujo completo
 * 5. Verifica si el evento game.countdown llega
 */

import puppeteer from 'puppeteer';

const BASE_URL = 'https://gambito.nebulio.es';
const EMAIL = 'admin@gambito.com';
const PASSWORD = 'password';

console.log('🔍 [AUTO-TEST] Iniciando test automatizado completo...\n');

const browser = await puppeteer.launch({ 
    headless: false, // Mostrar navegador para ver qué pasa
    args: ['--no-sandbox', '--disable-setuid-sandbox']
});

const page = await browser.newPage();

// Capturar todos los logs
page.on('console', msg => {
    const text = msg.text();
    if (text.includes('Transition') || 
        text.includes('game.countdown') || 
        text.includes('Echo') || 
        text.includes('Canal') ||
        text.includes('pusher') ||
        text.includes('subscription') ||
        text.includes('ERROR') ||
        text.includes('✅') ||
        text.includes('❌')) {
        console.log(`[${msg.type().toUpperCase()}] ${text}`);
    }
});

try {
    // 1. Login
    console.log('🔐 [1/5] Haciendo login...');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle2' });
    
    await page.type('input[name="email"]', EMAIL);
    await page.type('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    
    await page.waitForNavigation({ waitUntil: 'networkidle2' });
    console.log('✅ Login exitoso\n');
    
    // 2. Crear sala
    console.log('🎮 [2/5] Creando sala de prueba...');
    await page.goto(`${BASE_URL}/rooms/create`, { waitUntil: 'networkidle2' });
    
    // Seleccionar primer juego disponible
    await page.waitForSelector('select[name="game_id"]', { timeout: 5000 });
    await page.select('select[name="game_id"]', await page.evaluate(() => {
        const select = document.querySelector('select[name="game_id"]');
        return select.options[1]?.value || select.options[0]?.value;
    }));
    
    await page.waitForTimeout(1000);
    
    // Click en crear
    await page.click('button[type="submit"]');
    await page.waitForNavigation({ waitUntil: 'networkidle2' });
    
    // Obtener código de sala de la URL
    const currentUrl = page.url();
    const roomCodeMatch = currentUrl.match(/\/rooms\/([A-Z0-9]+)/);
    const roomCode = roomCodeMatch ? roomCodeMatch[1] : null;
    
    if (!roomCode) {
        throw new Error('No se pudo obtener el código de la sala');
    }
    
    console.log(`✅ Sala creada: ${roomCode}\n`);
    
    // 3. Ir a transition page (simular que el juego inició)
    console.log('🔄 [3/5] Navegando a transition page...');
    const transitionUrl = `${BASE_URL}/rooms/${roomCode}`;
    await page.goto(transitionUrl, { waitUntil: 'networkidle2' });
    console.log('✅ En transition page\n');
    
    // 4. Esperar a que se configuren los listeners
    console.log('⏳ [4/5] Esperando configuración de WebSocket...');
    await page.waitForTimeout(5000);
    
    // Verificar estado
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
    
    // 5. Simular llamada a /api/rooms/{code}/ready
    console.log('📡 [5/5] Simulando llamada a /api/rooms/{code}/ready...');
    
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
    
    if (response.data?.success) {
        console.log('\n✅ Servidor confirmó que el countdown comenzará');
        console.log('⏳ Esperando 10 segundos para capturar evento game.countdown...\n');
        
        // Esperar evento
        let eventReceived = false;
        for (let i = 0; i < 10; i++) {
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            const eventStatus = await page.evaluate(() => {
                return window.testEventReceived || false;
            });
            
            if (eventStatus) {
                console.log('✅ EVENTO game.countdown RECIBIDO!');
                eventReceived = true;
                break;
            }
            
            if (i % 3 === 0) {
                console.log(`   [${i}s] Esperando evento...`);
            }
        }
        
        if (!eventReceived) {
            console.log('\n❌ NO se recibió el evento game.countdown');
            console.log('\n💡 Posibles causas:');
            console.log('   1. QUEUE_CONNECTION no está en "sync"');
            console.log('   2. Reverb no está transmitiendo el evento');
            console.log('   3. El evento se emite pero no llega al cliente');
        }
    }
    
    console.log('\n✅ Test completado');
    
} catch (error) {
    console.error('\n❌ Error durante el test:', error.message);
    console.error('Stack:', error.stack);
} finally {
    console.log('\n⏳ Cerrando navegador en 5 segundos...');
    await new Promise(resolve => setTimeout(resolve, 5000));
    await browser.close();
}

