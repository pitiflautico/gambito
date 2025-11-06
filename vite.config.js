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
                'games/mockup/js/MockupGameClient.js',
                'games/trivia/js/TriviaGameClient.js',
                'games/pictionary/js/PictionaryGameClient.js',
                'games/mentiroso/js/MentirosoGameClient.js',
            ],
            refresh: [
                'resources/**',
                'games/**/views/**',
                'routes/**',
                'app/Http/Controllers/**',
            ],
        }),
    ],
});
