<?php

/**
 * Configuración de eventos base para todos los juegos
 *
 * Estos son los eventos genéricos que todos los juegos usan por defecto.
 * Cada juego puede añadir sus propios eventos específicos en su capabilities.json
 */

return [
    'base_events' => [
        'channel' => 'room.{roomCode}',
        'events' => [
            'GameStartedEvent' => [
                'name' => 'game.started',
                'handler' => 'handleGameStarted'
            ],
            'GameCountdownEvent' => [
                'name' => 'game.countdown',
                'handler' => 'handleGameCountdown'
            ],
            'GameInitializedEvent' => [
                'name' => 'game.initialized',
                'handler' => 'handleGameInitialized'
            ],
            'PlayerConnectedToGameEvent' => [
                'name' => 'player.connected',
                'handler' => 'handlePlayerConnected'
            ],
            'RoundStartedEvent' => [
                'name' => 'game.round.started',
                'handler' => 'handleRoundStarted'
            ],
            'RoundEndedEvent' => [
                'name' => 'game.round.ended',
                'handler' => 'handleRoundEnded'
            ],
            'PlayerActionEvent' => [
                'name' => 'game.player.action',
                'handler' => 'handlePlayerAction'
            ],
            'PhaseChangedEvent' => [
                'name' => 'game.phase.changed',
                'handler' => 'handlePhaseChanged'
            ],
            'TurnChangedEvent' => [
                'name' => 'game.turn.changed',
                'handler' => 'handleTurnChanged'
            ],
            'GameFinishedEvent' => [
                'name' => 'game.finished',
                'handler' => 'handleGameFinished'
            ],
            'PlayerDisconnectedEvent' => [
                'name' => 'game.player.disconnected',
                'handler' => 'handlePlayerDisconnected'
            ],
            'PlayerReconnectedEvent' => [
                'name' => 'game.player.reconnected',
                'handler' => 'handlePlayerReconnected'
            ],
        ]
    ]
];
