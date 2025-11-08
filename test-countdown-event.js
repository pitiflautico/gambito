/**
 * Script simple para verificar si el evento game.countdown se emite y llega
 * Ejecutar: node test-countdown-event.js [ROOM_CODE]
 */

import puppeteer from 'puppeteer';

const roomCode = process.argv[2] || 'N3MMN6';
const url = `https://gambito.nebulio.es/rooms/${roomCode}`;

console.log(`🔍 Testing game.countdown event for room: ${roomCode}`);
console.log(`🌐 URL: ${url}\n`);

const browser = await puppeteer.launch({ headless: true });
const page = await browser.newPage();

let eventReceived = false;
let eventData = null;

// Capturar eventos de consola relevantes
page.on('console', msg => {
    const text = msg.text();
    if (text.includes('game.countdown') || text.includes('Countdown event')) {
        console.log(`📨 [CONSOLE] ${text}`);
        eventReceived = true;
    }
});

// Inyectar listener directo
await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });

await page.evaluate(() => {
    if (window.Echo) {
        const pusher = window.Echo.connector.pusher;
        
        // Listener global directo
        pusher.bind_global((eventName, data) => {
            if (eventName.includes('game.countdown')) {
                console.log('[TEST] Evento game.countdown recibido:', eventName, data);
                window.testEventReceived = true;
                window.testEventData = data;
            }
        });
        
        // También en el canal público
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

console.log('⏳ Esperando 15 segundos para capturar eventos...\n');

// Esperar y verificar periódicamente
for (let i = 0; i < 15; i++) {
    await new Promise(resolve => setTimeout(resolve, 1000));
    
    const status = await page.evaluate(() => {
        const pusher = window.Echo?.connector?.pusher;
        const channels = pusher ? pusher.allChannels() : [];
        const publicChannel = channels.find(c => c.name.startsWith('room.') && !c.name.startsWith('presence-'));
        
        return {
            connected: pusher?.connection?.state === 'connected',
            publicChannelSubscribed: publicChannel?.subscribed || false,
            eventReceived: window.testEventReceived || false,
            eventData: window.testEventData || null
        };
    });
    
    if (status.eventReceived) {
        console.log('✅ EVENTO RECIBIDO!');
        console.log('   Data:', JSON.stringify(status.eventData, null, 2));
        eventReceived = true;
        eventData = status.eventData;
        break;
    }
    
    if (i % 5 === 0) {
        console.log(`[${i}s] Conexión: ${status.connected ? '✅' : '❌'}, Canal público: ${status.publicChannelSubscribed ? '✅' : '❌'}`);
    }
}

if (!eventReceived) {
    console.log('\n❌ NO se recibió el evento game.countdown');
    console.log('\n💡 Posibles causas:');
    console.log('   1. El evento no se está emitiendo desde el servidor');
    console.log('   2. QUEUE_CONNECTION no está en "sync"');
    console.log('   3. Reverb no está transmitiendo el evento');
    console.log('   4. El canal no está completamente suscrito cuando se emite');
}

await browser.close();
process.exit(eventReceived ? 0 : 1);

