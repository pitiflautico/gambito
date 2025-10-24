import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Core game client base
                'resources/js/core/BaseGameClient.js',
                // Game-specific JavaScript (loaded only when needed)
                'resources/js/pictionary-canvas.js',
                'resources/js/trivia-game.js',
                'resources/js/trivia-game-new.js',
            ],
            refresh: true,
        }),
    ],
});
