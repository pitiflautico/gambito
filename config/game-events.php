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
        ]
    ]
];
