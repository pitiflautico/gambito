/**
 * Script de debugging para WebSocket en el cliente
 * Ejecutar en la consola del navegador cuando estés en la transition page
 * 
 * Copia y pega este código en la consola del navegador
 */

(function() {
    console.log('🔍 [DEBUG CLIENT] Iniciando debugging de WebSocket...');
    
    const roomCode = window.location.pathname.match(/\/rooms\/([A-Z0-9]+)/)?.[1];
    if (!roomCode) {
        console.error('❌ No se pudo detectar el roomCode de la URL');
        return;
    }
    
    console.log('📍 Room Code:', roomCode);
    
    // Verificar Echo
    if (typeof window.Echo === 'undefined') {
        console.error('❌ Echo no está disponible');
        return;
    }
    
    const pusher = window.Echo.connector.pusher;
    console.log('🔌 Estado de conexión:', pusher.connection.state);
    console.log('🔌 Último error:', pusher.connection.last_error);
    
    // Listar canales suscritos
    const channels = pusher.allChannels();
    console.log('📡 Canales suscritos:', channels.map(c => c.name));
    
    // Verificar si el canal público está suscrito
    const publicChannelName = `room.${roomCode}`;
    const publicChannel = channels.find(c => c.name === publicChannelName);
    
    if (publicChannel) {
        console.log('✅ Canal público suscrito:', publicChannelName);
        console.log('   Estado:', publicChannel.subscribed);
    } else {
        console.error('❌ Canal público NO está suscrito:', publicChannelName);
    }
    
    // Verificar Presence Channel
    const presenceChannelName = `presence-room.${roomCode}`;
    const presenceChannel = channels.find(c => c.name === presenceChannelName);
    
    if (presenceChannel) {
        console.log('✅ Presence Channel suscrito:', presenceChannelName);
        console.log('   Estado:', presenceChannel.subscribed);
    } else {
        console.warn('⚠️ Presence Channel NO está suscrito:', presenceChannelName);
    }
    
    // Listener global para capturar TODOS los eventos
    console.log('🎧 Configurando listener global de debugging...');
    pusher.bind_global((eventName, data) => {
        if (eventName.includes('game.countdown') || eventName.includes('game.initialized')) {
            console.log('🔍 [GLOBAL LISTENER] Evento detectado:', {
                eventName,
                channel: data?.channel || 'unknown',
                roomCode: data?.room_code || data?.roomCode || 'unknown',
                data: data
            });
        }
    });
    
    // Verificar listeners registrados
    console.log('📋 Listeners registrados en canal público:');
    if (publicChannel && publicChannel._callbacks) {
        Object.keys(publicChannel._callbacks).forEach(eventName => {
            console.log('   -', eventName, ':', publicChannel._callbacks[eventName].length, 'listeners');
        });
    }
    
    console.log('✅ [DEBUG CLIENT] Debugging configurado');
    console.log('💡 Monitorea los eventos en tiempo real arriba');
    
    // Función helper para forzar suscripción al canal público
    window.debugSubscribePublicChannel = function() {
        console.log('🔌 [DEBUG] Forzando suscripción al canal público...');
        const channel = window.Echo.channel(publicChannelName);
        channel.listen('.game.countdown', (data) => {
            console.log('⏰ [DEBUG] Countdown recibido:', data);
        });
        channel.listen('.game.initialized', (data) => {
            console.log('🎮 [DEBUG] Game initialized recibido:', data);
        });
        console.log('✅ Canal público configurado manualmente');
    };
    
    console.log('💡 Ejecuta debugSubscribePublicChannel() para forzar suscripción al canal público');
})();

