/**
 * TimingModule - Sistema de timing para juegos
 *
 * Responsabilidades:
 * - Procesar timing points de eventos
 * - Mostrar countdowns visuales
 * - Ejecutar delays automáticos
 * - Notificar al backend cuando termina el countdown
 *
 * Protección contra Race Conditions:
 * - Solo el primer cliente en notificar al backend avanzará la ronda
 * - Backend tiene lock mechanism para prevenir duplicados
 * - Otros clientes recibirán RoundStartedEvent y se sincronizarán
 */
class TimingModule {
    constructor() {
        this.activeCountdowns = new Map();
        this.config = {
            countdownWarningThreshold: 3, // Segundos para cambiar a warning
            tickInterval: 1000,            // Intervalo de tick (1 segundo)
            debug: true                     // Logging detallado
        };
    }

    /**
     * Configurar el módulo
     */
    configure(config) {
        this.config = { ...this.config, ...config };
    }

    /**
     * Procesar timing point de un evento
     *
     * @param {Object} timingPoint - Metadata de timing del evento
     * @param {Function} callback - Función a ejecutar al terminar
     * @param {HTMLElement} element - Elemento donde mostrar countdown (opcional)
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

        // Si no hay auto_next, no hacer nada
        if (!auto_next) {
            this.log('Auto-next disabled, skipping timing');
            return;
        }

        // Si delay es 0, ejecutar inmediatamente
        if (delay === 0) {
            this.log('Delay is 0, executing immediately');
            if (callback) callback();
            return;
        }

        // Construir template del mensaje
        const template = message
            ? `${message} en {seconds}s...`
            : 'Continuando en {seconds}s...';

        // Mostrar countdown
        if (element) {
            await this.delayWithCountdown(delay, element, template, action);
        } else {
            await this.delay(delay);
        }

        // Ejecutar callback al terminar
        if (callback) {
            this.log('Timing completed, executing callback');
            callback();
        }
    }

    /**
     * Countdown visual con elemento DOM
     *
     * @param {number} seconds - Duración en segundos
     * @param {HTMLElement} element - Elemento donde mostrar
     * @param {string} template - Template del mensaje
     * @param {string} name - Nombre único del countdown
     * @returns {Promise}
     */
    delayWithCountdown(seconds, element, template = '{seconds}s', name = 'default') {
        return new Promise((resolve) => {
            let remaining = seconds;

            const updateElement = () => {
                if (element) {
                    // Reemplazar {seconds} con valor actual
                    const text = template.replace('{seconds}', remaining);
                    element.textContent = text;

                    // Añadir clase warning si quedan pocos segundos
                    if (remaining <= this.config.countdownWarningThreshold) {
                        element.classList.add('countdown-warning');
                    } else {
                        element.classList.remove('countdown-warning');
                    }

                    this.log(`Countdown ${name}: ${remaining}s remaining`);
                }
            };

            // Actualizar inmediatamente
            updateElement();

            // Crear interval
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
            }, this.config.tickInterval);

            // Guardar countdown activo
            this.activeCountdowns.set(name, {
                interval,
                remaining,
                element,
                template
            });
        });
    }

    /**
     * Delay simple sin UI
     *
     * @param {number} seconds - Duración en segundos
     * @returns {Promise}
     */
    delay(seconds) {
        this.log(`Simple delay: ${seconds}s`);
        return new Promise(resolve => {
            setTimeout(resolve, seconds * 1000);
        });
    }

    /**
     * Cancelar countdown activo
     *
     * @param {string} name - Nombre del countdown
     */
    cancelCountdown(name) {
        const countdown = this.activeCountdowns.get(name);
        if (!countdown) {
            this.log(`Countdown ${name} not found`);
            return;
        }

        clearInterval(countdown.interval);
        this.activeCountdowns.delete(name);
        this.log(`Countdown ${name} cancelled`);
    }

    /**
     * Obtener countdown activo
     *
     * @param {string} name - Nombre del countdown
     * @returns {Object|null}
     */
    getCountdown(name) {
        return this.activeCountdowns.get(name) || null;
    }

    /**
     * Verificar si hay countdown activo
     *
     * @param {string} name - Nombre del countdown
     * @returns {boolean}
     */
    hasActiveCountdown(name) {
        return this.activeCountdowns.has(name);
    }

    /**
     * Obtener tiempo restante de countdown
     *
     * @param {string} name - Nombre del countdown
     * @returns {number|null}
     */
    getRemainingTime(name) {
        const countdown = this.activeCountdowns.get(name);
        return countdown ? countdown.remaining : null;
    }

    /**
     * Limpiar todos los countdowns activos
     */
    clearAll() {
        this.log('Clearing all active countdowns');
        for (const [name, countdown] of this.activeCountdowns.entries()) {
            clearInterval(countdown.interval);
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
