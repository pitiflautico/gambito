
import './bootstrap';

import Alpine from 'alpinejs';
import EventManager from './modules/EventManager.js';
import TimingModule from './modules/TimingModule.js';
import { BaseGameClient } from './core/BaseGameClient.js';
import { PresenceChannelManager } from './core/PresenceChannelManager.js';
import { LobbyManager } from './core/LobbyManager.js';
import { TeamManager } from './core/TeamManager.js';

// Global libraries available for all games
window.Alpine = Alpine;
window.EventManager = EventManager;
window.TimingModule = TimingModule;
window.BaseGameClient = BaseGameClient;
window.PresenceChannelManager = PresenceChannelManager;
window.LobbyManager = LobbyManager;
window.TeamManager = TeamManager;

Alpine.start();

// Game-specific JavaScript is loaded separately in each game view
// See vite.config.js for game entry points
