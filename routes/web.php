<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\PictionaryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Rutas de Juegos
Route::get('/games', [GameController::class, 'index'])->name('games.index');
Route::get('/games/{slug}', [GameController::class, 'show'])->name('games.show');

// Ruta de demo de Pictionary (solo desarrollo)
Route::get('/pictionary/demo', [PictionaryController::class, 'demo'])->name('pictionary.demo');

// Rutas de Salas
Route::prefix('rooms')->name('rooms.')->group(function () {
    // Crear sala (requiere autenticación)
    Route::get('/create', [RoomController::class, 'create'])->name('create')->middleware('auth');
    Route::post('/', [RoomController::class, 'store'])->name('store')->middleware('auth');

    // Unirse a sala (público)
    Route::get('/join', [RoomController::class, 'join'])->name('join');
    Route::post('/join', [RoomController::class, 'joinByCode'])->name('joinByCode');

    // Nombre de invitado (público)
    Route::get('/{code}/guest-name', [RoomController::class, 'guestName'])->name('guestName');
    Route::post('/{code}/guest-name', [RoomController::class, 'storeGuestName'])->name('storeGuestName');

    // Vistas de sala (público con código)
    Route::get('/{code}/lobby', [RoomController::class, 'lobby'])->name('lobby');
    Route::get('/{code}', [RoomController::class, 'show'])->name('show');
    Route::get('/{code}/results', [RoomController::class, 'results'])->name('results');
});

require __DIR__.'/auth.php';
