<!DOCTYPE html>
<html>
<head>
    <title>Test WebSocket</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="padding: 20px; font-family: monospace;">
    <h1>WebSocket Connection Test</h1>
    <div id="status">Checking...</div>
    <div id="log" style="margin-top: 20px; padding: 10px; background: #f5f5f5; border: 1px solid #ccc;"></div>

    <script>
        const log = document.getElementById('log');
        const status = document.getElementById('status');

        function addLog(message) {
            const time = new Date().toLocaleTimeString();
            log.innerHTML += `<div>[${time}] ${message}</div>`;
            console.log(message);
        }

        // Esperar a que el DOM esté listo
        setTimeout(() => {
            addLog('✓ Page loaded');

            if (typeof window.Echo !== 'undefined') {
                addLog('✓ Echo is defined');
                status.innerHTML = '<span style="color: green;">✓ Echo loaded</span>';

                try {
                    addLog('Attempting to connect to PUBLIC channel test-channel...');

                    // Usar canal público para testing (no requiere autenticación)
                    const channel = window.Echo.channel('test-channel');

                    addLog('✓ Channel subscription created');

                    // Escuchar evento específico
                    channel.listen('TestEvent', (data) => {
                        addLog('📨 Received TestEvent: ' + JSON.stringify(data));
                    });

                    // Escuchar TODOS los eventos (debugging)
                    channel.listenToAll((eventName, data) => {
                        addLog('📡 ANY EVENT: ' + eventName + ' - ' + JSON.stringify(data));
                    });

                    addLog('✓ Listener registered');
                    addLog('✓ All setup complete!');
                    addLog('ℹ️ Waiting for events... (use "php artisan tinker" to broadcast)');
                    status.innerHTML = '<span style="color: green;">✓ Connected to WebSocket</span>';

                } catch (error) {
                    addLog('❌ Error: ' + error.message);
                    status.innerHTML = '<span style="color: red;">❌ Connection failed</span>';
                }

            } else {
                addLog('❌ Echo is NOT defined');
                status.innerHTML = '<span style="color: red;">❌ Echo not loaded</span>';
            }
        }, 1000);
    </script>
</body>
</html>
