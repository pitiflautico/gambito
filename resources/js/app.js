console.log('🎯 APP.JS LOADED - VERSION: 2024-10-22');

import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Game-specific JavaScript is loaded separately in each game view
// See vite.config.js for game entry points
