
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

// Lazy load game-specific clients (loaded on demand)
// TriviaGameClient will be loaded when needed
window.loadTriviaGameClient = async () => {
    if (window.TriviaGameClient) return; // Ya cargado
    const module = await import('../../games/trivia/js/TriviaGameClient.js');
    // El módulo ya expone window.TriviaGameClient internamente
    // Esperar un tick para asegurar que window.TriviaGameClient esté disponible
    if (!window.TriviaGameClient && module.TriviaGameClient) {
        window.TriviaGameClient = module.TriviaGameClient;
    }
    // Verificar que se cargó correctamente
    if (!window.TriviaGameClient) {
        console.error('[app.js] TriviaGameClient not available after import');
    }
};

// PictionaryGameClient will be loaded when needed
window.loadPictionaryGameClient = async () => {
    if (window.PictionaryGameClient) return; // Ya cargado
    await import('../../games/pictionary/js/PictionaryGameClient.js');
    // El módulo ya expone window.PictionaryGameClient internamente
};
