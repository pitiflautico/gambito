
import './bootstrap';

import Alpine from 'alpinejs';
import EventManager from './modules/EventManager.js';
import { BaseGameClient } from './core/BaseGameClient.js';

// Global libraries available for all games
window.Alpine = Alpine;
window.EventManager = EventManager;
window.BaseGameClient = BaseGameClient;

Alpine.start();

// Game-specific JavaScript is loaded separately in each game view
// See vite.config.js for game entry points
