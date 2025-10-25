
import './bootstrap';

import Alpine from 'alpinejs';
import EventManager from './modules/EventManager.js';
import { BaseGameClient } from './core/BaseGameClient.js';
import { PresenceChannelManager } from './core/PresenceChannelManager.js';
import { LobbyManager } from './core/LobbyManager.js';

// Global libraries available for all games
window.Alpine = Alpine;
window.EventManager = EventManager;
window.BaseGameClient = BaseGameClient;
window.PresenceChannelManager = PresenceChannelManager;
window.LobbyManager = LobbyManager;

Alpine.start();

// Game-specific JavaScript is loaded separately in each game view
// See vite.config.js for game entry points
