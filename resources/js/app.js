console.log('🎯 APP.JS LOADED - VERSION: 2024-10-21-17:00');

import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Importar módulos de juegos
import './pictionary-canvas.js';
import './trivia-game.js';
