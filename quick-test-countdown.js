#!/usr/bin/env node

/**
 * Script rápido para testear si el evento game.countdown llega
 * Ejecutar: node quick-test-countdown.js [ROOM_CODE]
 */

import puppeteer from 'puppeteer';

const roomCode = process.argv[2] || 'N3MMN6';
const url = `https://gambito.nebulio.es/rooms/${roomCode}`;

console.log(`🔍 Quick test: ${roomCode}\n`);

const browser = await puppeteer.launch({ headless: true });
const page = await browser.newPage();

let eventReceived = false;

page.on('console', msg => {
    const text = msg.text();
    if (text.includes('game.countdown')) {
        console.log(`✅ EVENTO: ${text}`);
        eventReceived = true;
    }
});

await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

// Inyectar listener
await page.evaluate(() => {
    if (window.Echo) {
        const pusher = window.Echo.connector.pusher;
        pusher.bind_global((eventName, data) => {
            if (eventName.includes('game.countdown')) {
                console.log('[QUICK-TEST] Evento recibido:', eventName, data);
                window.quickTestEventReceived = true;
            }
        });
    }
});

console.log('⏳ Esperando 15 segundos...\n');

// Simular llamada a /ready después de 5 segundos
setTimeout(async () => {
    await page.evaluate(async (code) => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const res = await fetch(`/api/rooms/${code}/ready`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            const data = await res.json();
            console.log('[QUICK-TEST] Server response:', data);
        } catch (e) {
            console.error('[QUICK-TEST] Error:', e);
        }
    }, roomCode);
}, 5000);

await new Promise(resolve => setTimeout(resolve, 15000));

const finalStatus = await page.evaluate(() => {
    const pusher = window.Echo?.connector?.pusher;
    const channels = pusher ? pusher.allChannels() : [];
    const publicChannel = channels.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
    
    return {
        connected: pusher?.connection?.state === 'connected',
        publicChannelSubscribed: publicChannel?.subscribed || false,
        eventReceived: window.quickTestEventReceived || false
    };
});

console.log('\n📊 RESULTADO:');
console.log(`   Conexión: ${finalStatus.connected ? '✅' : '❌'}`);
console.log(`   Canal público: ${finalStatus.publicChannelSubscribed ? '✅' : '❌'}`);
console.log(`   Evento recibido: ${finalStatus.eventReceived ? '✅' : '❌'}`);

if (!finalStatus.eventReceived) {
    console.log('\n❌ PROBLEMA: El evento no llegó aunque el canal está suscrito');
    console.log('💡 Verifica en el servidor:');
    console.log('   1. QUEUE_CONNECTION debe ser "sync"');
    console.log('   2. Reverb debe estar corriendo');
    console.log('   3. Ejecuta: php test-emit-countdown.php ' + roomCode);
}

await browser.close();
process.exit(finalStatus.eventReceived ? 0 : 1);

