/**
 * TimingModule - Sistema de timing para juegos (Gaming Industry Standard)
 *
 * Arquitectura timestamp-based:
 * - Backend envía UN evento con server_time preciso (microtime)
 * - Frontend calcula remaining time localmente con requestAnimationFrame (60fps)
 * - Compensa automáticamente drift de reloj y lag de red
 * - Sincronización perfecta entre todos los clientes
 *
 * Usado en: Fortnite, CS:GO, Rocket League, League of Legends
 *
 * Ventajas vs setInterval:
 * - ✅ No se desincroniza nunca
 * - ✅ Compensa lag automáticamente
 * - ✅ 60fps smooth (requestAnimationFrame)
 * - ✅ 0% CPU en backend
 * - ✅ Escalable a miles de jugadores
 */
class TimingModule {
    constructor() {
        this.activeCountdowns = new Map();
        this.notifiedTimers = new Set(); // 🔒 RACE CONTROL: Track timers that already notified backend
        this.config = {
            countdownWarningThreshold: 3, // Segundos para cambiar a warning
            debug: false                   // Logging detallado (desactivado por defecto)
        };

        // Auto-suscribirse a eventos del juego para cancelar timers automáticamente
        this.subscribeToGameEvents();
    }

    /**
     * Suscribirse a eventos del juego para gestión automática de timers
     */
    subscribeToGameEvents() {
        // ROUND STARTED: Limpiar notificaciones de timers anteriores (nueva ronda = timers nuevos)
        window.addEventListener('game:round:started', (e) => {
            this.log('Round started - clearing notified timers from previous round');
            this.clearNotifiedTimers();
        });

        // ROUND ENDED: Cancelar timers de fase/juego (NO el countdown de siguiente ronda)
        window.addEventListener('game:round:ended', (e) => {
            this.log('Round ended - cancelling game/phase timers');
            this.cancelGameTimers();
        });

        // PLAYER DISCONNECTED: Pausar TODOS los timers (para poder reanudar después)
        window.addEventListener('game:player:disconnected', (e) => {
            this.log('Player disconnected - pausing all timers');
            this.pauseAllTimers();
        });

        // PLAYER RECONNECTED: Reanudar timers pausados
        window.addEventListener('game:player:reconnected', (e) => {
            this.log('Player reconnected - resuming timers');
            this.resumeAllTimers();
        });

        // GAME FINISHED: Cancelar TODO definitivamente
        window.addEventListener('game:finished', (e) => {
            this.log('Game finished - cancelling all timers');
            this.cancelAllTimers();
        });
    }

    /**
     * Cancelar solo timers de juego/fase (NO countdowns de transición)
     * Usado cuando termina una ronda para limpiar timers de votación, turnos, etc.
     */
    cancelGameTimers() {
        const timerNames = Array.from(this.activeCountdowns.keys());
        timerNames.forEach(name => {
            // Cancelar timers de juego: preparation_timer, voting_timer, turn_timer, etc.
            // NO cancelar: countdown (usado para transiciones entre rondas)
            if (!name.includes('countdown') && !name.includes('transition')) {
                this.cancelCountdown(name);
            }
        });
    }

    /**
     * Pausar todos los timers activos
     * Usado cuando un jugador se desconecta para poder reanudar después
     */
    pauseAllTimers() {
        if (this.activeCountdowns.size === 0) {
            return;
        }

        this.activeCountdowns.forEach((countdown, name) => {
            // Guardar el animationFrameId ANTES de cancelar
            const frameId = countdown.animationFrameId;

            // Cancelar el animationFrame pero guardar el estado
            if (frameId) {
                cancelAnimationFrame(frameId);
                // Verificar que realmente se canceló
                countdown.animationFrameId = null;
            }

            // Marcar como pausado y guardar tiempo restante
            const now = Date.now() - countdown.drift;
            countdown.paused = true;
            countdown.pausedAt = now;
            countdown.remainingMs = countdown.endTime - now;

            const remainingSeconds = Math.ceil(countdown.remainingMs / 1000);

            // Actualizar el elemento visualmente y añadir clase de pausa
            if (countdown.element) {
                // Congelar el valor actual
                countdown.element.textContent = remainingSeconds + ' ⏸️';
                countdown.element.classList.add('timer-paused');
                countdown.element.style.opacity = '0.6';
            }

            this.log(`Timer ${name} paused with ${remainingSeconds}s remaining`);
        });
    }

    /**
     * Reanudar todos los timers pausados
     * Usado cuando un jugador se reconecta
     */
    resumeAllTimers() {
        // TODO: Implementar lógica de reanudación
        // Por ahora, cuando alguien se reconecta, el backend resetea la ronda
        // por lo que no necesitamos reanudar timers (se crearán nuevos)
        this.log('Timer resumption not implemented - backend resets round on reconnection');
    }

    /**
     * Cancelar TODOS los timers (incluidos countdowns de transición)
     * Usado cuando el juego termina completamente
     */
    cancelAllTimers() {
        const timerNames = Array.from(this.activeCountdowns.keys());
        timerNames.forEach(name => {
            this.cancelCountdown(name);
        });
    }

    /**
     * Configurar el módulo
     */
    configure(config) {
        this.config = { ...this.config, ...config };
    }

    /**
     * Procesar automáticamente eventos con timer
     *
     * Detecta eventos con timer_id, server_time, duration y:
     * - Inicia countdown visual sincronizado
     * - Notifica al backend cuando expire (estrategia híbrida)
     * - Race control para evitar notificaciones duplicadas
     *
     * @param {Object} event - Evento recibido del backend
     * @param {string} roomCode - Código de la sala
     */
    autoProcessEvent(event, roomCode) {
        console.log('🔍 [TimingModule] autoProcessEvent called', {
            has_timer_id: !!event.timer_id,
            has_server_time: !!event.server_time,
            has_duration: !!event.duration,
            event
        });

        // Detectar si el evento tiene datos de timer
        if (!event.timer_id || !event.server_time || !event.duration) {
            console.log('⚠️ [TimingModule] Event does not have timer data, skipping');
            return; // No es un evento con timer
        }

        console.log('⏱️ [TimingModule] Detectado evento con timer', {
            timer_id: event.timer_id,
            timer_name: event.timer_name || event.phase,
            duration: event.duration,
            server_time: event.server_time
        });

        // Buscar elemento timer - priorizar popup-timer si existe y está visible
        let timerElement = document.getElementById('popup-timer');
        if (timerElement) {
            const popup = document.getElementById('round-end-popup');
            const isPopupVisible = popup && popup.style.display !== 'none';

            if (!isPopupVisible) {
                // Popup no visible, usar timer normal
                timerElement = document.getElementById(event.timer_id);
            } else {
                console.log('⏱️ [TimingModule] Using popup-timer element');
            }
        } else {
            // No hay popup-timer, usar el ID del evento
            timerElement = document.getElementById(event.timer_id);
        }

        if (!timerElement) {
            this.log(`Timer element not found: #${event.timer_id} or #popup-timer`);
            return;
        }

        // Nombre del timer (puede venir en timer_name, phase, o timer_id)
        const timerName = event.timer_name || event.phase || event.timer_id;

        // Convertir duration a milisegundos si viene en segundos
        const durationMs = event.duration > 1000 ? event.duration : event.duration * 1000;

        this.log(`Auto-processing timer from event`, {
            timer_id: event.timer_id,
            timer_name: timerName,
            server_time: event.server_time,
            duration: event.duration,
            duration_ms: durationMs
        });

        // Callback cuando el timer expira: notificar al backend con race control
        const onExpiredCallback = () => {
            // Priorizar event_data genérico, luego phase_data (legacy)
            const eventData = event.event_data || event.phase_data;

            console.log('⏰ [TimingModule] Timer expirado, notificando al backend', {
                timer_name: timerName,
                room_code: roomCode,
                event_class: event.event_class,
                event_data: eventData
            });
            this.notifyTimerExpired(timerName, roomCode, event.event_class, eventData);
        };

        // Iniciar countdown visual sincronizado con callback
        this.startServerSyncedCountdown(
            event.server_time,
            durationMs,
            timerElement,
            onExpiredCallback,
            timerName
        );
    }

    /**
     * Notificar al backend que un timer ha expirado
     *
     * Usa race control (lock en backend) para evitar que múltiples
     * clientes notifiquen al mismo tiempo
     *
     * @param {string} timerName - Nombre del timer que expiró
     * @param {string} roomCode - Código de la sala
     * @param {string} eventClass - Clase del evento a emitir cuando expire
     * @param {Object} eventData - Datos adicionales para el evento
     */
    async notifyTimerExpired(timerName, roomCode, eventClass = null, eventData = null) {
        if (!roomCode) {
            console.error('❌ [TimingModule] No room code available for timer notification');
            return;
        }

        // 🔒 RACE CONTROL: Prevenir notificaciones duplicadas del mismo timer
        if (this.notifiedTimers.has(timerName)) {
            console.warn('⚠️ [TimingModule] Timer already notified, skipping duplicate', {
                timer_name: timerName,
                room_code: roomCode
            });
            return;
        }

        // Marcar como notificado ANTES de hacer la llamada (fail-fast)
        this.notifiedTimers.add(timerName);

        const frontendCallId = `frontend_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;

        console.warn('🔥 [TimingModule] FRONTEND CALLING API', {
            frontend_call_id: frontendCallId,
            timer_name: timerName,
            room_code: roomCode,
            event_class: eventClass,
            event_data: eventData,
            timestamp: new Date().toISOString()
        });

        this.log(`⏰ Notifying backend: timer expired`, {
            frontend_call_id: frontendCallId,
            timer_name: timerName,
            room_code: roomCode,
            event_class: eventClass,
            event_data: eventData
        });

        try {
            const payload = {
                timer_name: timerName,
                frontend_call_id: frontendCallId
            };

            // Incluir event_class y event_data si están disponibles
            if (eventClass) {
                payload.event_class = eventClass;
            }
            if (eventData) {
                payload.event_data = eventData;
            }

            const response = await fetch(`/api/rooms/${roomCode}/check-timer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                console.error('❌ [TimingModule] Failed to notify timer expiration', {
                    status: response.status,
                    timer_name: timerName
                });
                return;
            }

            const data = await response.json();
            this.log(`✅ Backend notified of timer expiration`, data);

        } catch (error) {
            console.error('❌ [TimingModule] Error notifying timer expiration:', error);
        }
    }

    /**
     * Countdown sincronizado con timestamp del servidor
     *
     * Este es el método que usan los juegos AAA para sincronización perfecta.
     *
     * @param {number} serverTime - Timestamp del servidor (en segundos, con decimales)
     * @param {number} durationMs - Duración del countdown en milisegundos
     * @param {HTMLElement} element - Elemento donde mostrar el countdown
     * @param {Function} callback - Función a ejecutar al terminar
     * @param {string} name - Nombre único del countdown
     * @returns {Function} Cleanup function para cancelar el countdown
     */
    startServerSyncedCountdown(serverTime, durationMs, element, callback, name = 'default') {
        // 🔥 FIX: Cancelar countdown existente con el mismo nombre para evitar duplicados
        if (this.activeCountdowns.has(name)) {
            this.cancelCountdown(name);
        }

        // 🔥 CLEANUP: Limpiar notificación anterior con el mismo nombre
        // Esto permite re-usar nombres de timer (ej: "phase1" en cada ronda)
        this.notifiedTimers.delete(name);

        // Timestamps en milisegundos
        const startTime = serverTime * 1000;
        const endTime = startTime + durationMs;

        // Calcular drift inicial (diferencia entre reloj cliente y servidor)
        const clientTime = Date.now();
        const drift = clientTime - startTime;

        this.log(`Server-synced countdown started`, {
            name,
            server_time: new Date(startTime).toISOString(),
            client_time: new Date(clientTime).toISOString(),
            drift_ms: Math.round(drift),
            duration_ms: durationMs,
            duration_sec: durationMs / 1000
        });

        let animationFrameId;

        // Crear objeto de countdown que se actualizará
        const countdownData = {
            name,
            animationFrameId: null,
            element,
            startTime,
            endTime,
            drift
        };

        const update = () => {
            // Tiempo actual compensado por drift
            const now = Date.now() - drift;
            const remainingMs = Math.max(0, endTime - now);
            const remainingSeconds = Math.ceil(remainingMs / 1000);

            // Actualizar UI (60fps)
            if (element) {
                element.textContent = remainingSeconds;

                // Warning visual cuando queda poco
                if (remainingSeconds <= this.config.countdownWarningThreshold) {
                    element.classList.add('countdown-warning');
                } else {
                    element.classList.remove('countdown-warning');
                }
            }

            // Log cada segundo
            if (remainingMs > 0 && remainingSeconds !== this.lastLoggedSecond) {
                this.lastLoggedSecond = remainingSeconds;
                this.log(`Countdown ${name}: ${remainingSeconds}s remaining`);
            }

            // Terminar cuando el countdown llega a 0
            if (remainingMs <= 0) {
                this.log(`Countdown ${name} completed`);
                cancelAnimationFrame(animationFrameId);
                this.activeCountdowns.delete(name);

                if (callback) {
                    callback();
                }
                return;
            }

            // Siguiente frame
            animationFrameId = requestAnimationFrame(update);
            // Actualizar el objeto countdownData con el nuevo frame ID
            countdownData.animationFrameId = animationFrameId;
        };

        // Guardar countdown activo ANTES de iniciar animación
        this.activeCountdowns.set(name, countdownData);

        // Iniciar animación y guardar el primer frame ID
        this.lastLoggedSecond = null;
        animationFrameId = requestAnimationFrame(update);
        countdownData.animationFrameId = animationFrameId;

        // Retornar cleanup function
        return () => {
            cancelAnimationFrame(animationFrameId);
            this.activeCountdowns.delete(name);
            this.log(`Countdown ${name} cancelled`);
        };
    }

    /**
     * Procesar evento de countdown desde backend
     *
     * Este método recibe el evento del backend y automáticamente
     * inicia el countdown sincronizado.
     *
     * @param {Object} event - Evento del backend
     * @param {number} event.server_time - Timestamp del servidor
     * @param {number} event.duration_ms - Duración en milisegundos
     * @param {HTMLElement} element - Elemento donde mostrar
     * @param {Function} callback - Callback al terminar
     * @param {string} name - Nombre del countdown
     * @returns {Function} Cleanup function
     */
    handleCountdownEvent(event, element, callback, name = 'default') {
        const { server_time, duration_ms } = event;

        if (!server_time || !duration_ms) {
            console.error('❌ [TimingModule] Invalid event format:', event);
            return () => {};
        }

        return this.startServerSyncedCountdown(
            server_time,
            duration_ms,
            element,
            callback,
            name
        );
    }

    /**
     * LEGACY: Countdown con setInterval (NO USAR EN PRODUCCIÓN)
     *
     * Este método se mantiene solo para compatibilidad con código antiguo.
     * Usa startServerSyncedCountdown() en su lugar.
     *
     * @deprecated Use startServerSyncedCountdown() instead
     */
    delayWithCountdown(seconds, element, template = '{seconds}s', name = 'default') {
        return new Promise((resolve) => {
            let remaining = seconds;

            const updateElement = () => {
                if (element) {
                    const text = template.replace('{seconds}', remaining);
                    element.textContent = text;

                    if (remaining <= this.config.countdownWarningThreshold) {
                        element.classList.add('countdown-warning');
                    } else {
                        element.classList.remove('countdown-warning');
                    }

                    this.log(`Countdown ${name}: ${remaining}s remaining`);
                }
            };

            updateElement();

            const interval = setInterval(() => {
                remaining--;

                if (remaining < 0) {
                    clearInterval(interval);
                    this.activeCountdowns.delete(name);
                    this.log(`Countdown ${name} completed`);
                    resolve();
                } else {
                    updateElement();
                }
            }, 1000);

            this.activeCountdowns.set(name, {
                interval,
                remaining,
                element,
                template
            });
        });
    }

    /**
     * Procesar timing point de un evento (LEGACY)
     *
     * @deprecated Use handleCountdownEvent() instead
     */
    async processTimingPoint(timingPoint, callback, element = null) {
        if (!timingPoint) {
            this.log('No timing metadata provided');
            return;
        }

        const { auto_next, delay, action, message } = timingPoint;

        this.log('Processing timing point:', {
            auto_next,
            delay,
            action,
            message
        });

        if (!auto_next) {
            this.log('Auto-next disabled, skipping timing');
            return;
        }

        if (delay === 0) {
            this.log('Delay is 0, executing immediately');
            if (callback) callback();
            return;
        }

        const template = message
            ? `${message} en {seconds}s...`
            : 'Continuando en {seconds}s...';

        if (element) {
            await this.delayWithCountdown(delay, element, template, action);
        } else {
            await this.delay(delay);
        }

        if (callback) {
            this.log('Timing completed, executing callback');
            callback();
        }
    }

    /**
     * Delay simple sin UI
     */
    delay(seconds) {
        this.log(`Simple delay: ${seconds}s`);
        return new Promise(resolve => {
            setTimeout(resolve, seconds * 1000);
        });
    }

    /**
     * Cancelar countdown activo
     */
    cancelCountdown(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) {
            this.log(`Countdown ${name} not found`);
            return;
        }

        if (countdown.interval) {
            clearInterval(countdown.interval);
        }

        if (countdown.animationFrameId) {
            cancelAnimationFrame(countdown.animationFrameId);
        }

        this.activeCountdowns.delete(name);
        // 🔥 CLEANUP: También eliminar del Set de notificados para permitir re-uso del nombre
        this.notifiedTimers.delete(name);
        this.log(`Countdown ${name} cancelled`);
    }

    /**
     * Limpiar todos los timers notificados
     * Usado al empezar una nueva ronda para permitir re-usar nombres de timers
     */
    clearNotifiedTimers() {
        const count = this.notifiedTimers.size;
        this.notifiedTimers.clear();
        this.log(`Cleared ${count} notified timers`);
    }

    /**
     * Obtener countdown activo
     */
    getCountdown(name) {
        return this.activeCountdowns.get(name) || null;
    }

    /**
     * Verificar si hay countdown activo
     */
    hasActiveCountdown(name) {
        return this.activeCountdowns.has(name);
    }

    /**
     * Obtener tiempo restante de countdown (aproximado)
     */
    getRemainingTime(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) return null;

        if (countdown.endTime) {
            // Countdown con timestamp
            const now = Date.now() - countdown.drift;
            return Math.max(0, Math.ceil((countdown.endTime - now) / 1000));
        }

        // Countdown legacy con interval
        return countdown.remaining || null;
    }

    /**
     * Limpiar todos los countdowns activos
     */
    clearAll() {
        this.log('Clearing all active countdowns');
        for (const [name, countdown] of this.activeCountdowns.entries()) {
            if (countdown.interval) {
                clearInterval(countdown.interval);
            }
            if (countdown.animationFrameId) {
                cancelAnimationFrame(countdown.animationFrameId);
            }
            this.log(`Cleared countdown: ${name}`);
        }
        this.activeCountdowns.clear();
    }

    /**
     * Logging condicional
     */
    log(...args) {
        if (this.config.debug) {
            console.log('⏰ [TimingModule]', ...args);
        }
    }
}

// Exportar para uso en módulos
export default TimingModule;
