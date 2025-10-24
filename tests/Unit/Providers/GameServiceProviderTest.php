<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GameServiceProviderTest extends TestCase
{
    /** @test */
    public function test_pictionary_routes_are_loaded()
    {
        // Verificar que las rutas de Pictionary están registradas
        $routeNames = collect(Route::getRoutes())->map(fn($route) => $route->getName())->filter();

        // Rutas API de Pictionary
        $this->assertTrue($routeNames->contains('api.pictionary.draw'));
        $this->assertTrue($routeNames->contains('api.pictionary.clear'));
        $this->assertTrue($routeNames->contains('api.pictionary.player-answered'));
        $this->assertTrue($routeNames->contains('api.pictionary.confirm-answer'));
        $this->assertTrue($routeNames->contains('api.pictionary.advance-phase'));
        $this->assertTrue($routeNames->contains('api.pictionary.get-word'));

        // Rutas Web de Pictionary
        $this->assertTrue($routeNames->contains('pictionary.demo'));
    }

    /** @test */
    public function test_pictionary_routes_have_correct_prefixes()
    {
        $routes = Route::getRoutes();

        // Buscar ruta de draw
        $drawRoute = collect($routes)->first(fn($route) => $route->getName() === 'api.pictionary.draw');
        $this->assertNotNull($drawRoute);
        $this->assertEquals('api/pictionary/draw', $drawRoute->uri());

        // Buscar ruta de demo
        $demoRoute = collect($routes)->first(fn($route) => $route->getName() === 'pictionary.demo');
        $this->assertNotNull($demoRoute);
        $this->assertEquals('pictionary/demo', $demoRoute->uri());
    }

    /** @test */
    public function test_pictionary_routes_have_correct_methods()
    {
        $routes = Route::getRoutes();

        // Rutas API deben ser POST
        $drawRoute = collect($routes)->first(fn($route) => $route->getName() === 'api.pictionary.draw');
        $this->assertContains('POST', $drawRoute->methods());

        // Ruta demo debe ser GET
        $demoRoute = collect($routes)->first(fn($route) => $route->getName() === 'pictionary.demo');
        $this->assertContains('GET', $demoRoute->methods());
    }

    /** @test */
    public function test_core_routes_still_exist()
    {
        // Verificar que las rutas core no se eliminaron
        $routeNames = collect(Route::getRoutes())->map(fn($route) => $route->getName())->filter();

        // Rutas de juegos
        $this->assertTrue($routeNames->contains('games.index'));
        $this->assertTrue($routeNames->contains('games.show'));

        // Rutas de salas
        $this->assertTrue($routeNames->contains('rooms.create'));
        $this->assertTrue($routeNames->contains('rooms.lobby'));
        $this->assertTrue($routeNames->contains('rooms.start'));
    }

    /** @test */
    public function test_game_views_are_registered()
    {
        // Verificar que el namespace de vistas de Pictionary está registrado
        $this->assertTrue(view()->exists('pictionary::game'));
    }
}
