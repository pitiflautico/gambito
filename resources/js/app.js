console.log('🎯 APP.JS LOADED - VERSION: 2024-10-22');

import './bootstrap';

import Alpine from 'alpinejs';
import EventManager from './modules/EventManager.js';

// Global libraries available for all games
window.Alpine = Alpine;
window.EventManager = EventManager;

Alpine.start();

console.log('✅ Global libraries loaded:', {
    Alpine: !!window.Alpine,
    Echo: !!window.Echo,
    EventManager: !!window.EventManager
});

// Game-specific JavaScript is loaded separately in each game view
// See vite.config.js for game entry points
