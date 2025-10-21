<?php

use App\Models\Room;
use App\Models\Player;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Aquí se registran los canales de broadcasting para WebSockets.
| Cada canal debe retornar true si el usuario tiene autorización.
|
*/

// Canal de usuario (autenticado)
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal privado de sala de juego (por código de sala)
Broadcast::channel('room.{code}', function ($user, string $code) {
    // MODO DEMO: Permitir acceso a sala DEMO123 sin autenticación
    if ($code === 'DEMO123') {
        return [
            'id' => request()->session()->getId(),
            'name' => 'Demo User',
            'role' => 'demo',
        ];
    }

    // Verificar que la sala existe
    $room = Room::where('code', $code)->first();

    if (!$room) {
        return false;
    }

    // Verificar que el usuario es el master de la sala
    if ($user && $room->master_id === $user->id) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => 'master',
        ];
    }

    // Verificar si es un jugador invitado (guest) en esta sala
    // Los guests se identifican por session_id, no por user_id
    $sessionId = request()->session()->getId();

    $player = Player::whereHas('match', function ($query) use ($room) {
        $query->where('room_id', $room->id);
    })
    ->where(function ($query) use ($user, $sessionId) {
        $query->where('user_id', $user?->id)
              ->orWhere('session_id', $sessionId);
    })
    ->where('is_active', true)
    ->first();

    if ($player) {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'role' => 'player',
        ];
    }

    // No autorizado
    return false;
});
