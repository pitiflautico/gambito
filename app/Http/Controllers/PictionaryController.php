<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PictionaryController extends Controller
{
    /**
     * Mostrar demo del canvas (solo para desarrollo)
     */
    public function demo()
    {
        // Datos de prueba para visualizar el diseño
        $room = (object) [
            'id' => 1,
            'name' => 'Sala de Prueba',
            'code' => 'DEMO123',
        ];

        $match = (object) [
            'id' => 1,
        ];

        // Por ahora cargamos la vista directamente desde el archivo
        // TODO Task 6.0: Registrar el namespace de vistas del juego
        return view('games.pictionary.canvas', compact('room', 'match'));
    }
}
