<?php

namespace Tests\Unit\Services\Modules\RolesSystem;

use App\Services\Modules\RolesSystem\RoleManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests para RoleManager.
 *
 * Cobertura:
 * - Asignación de roles únicos y múltiples
 * - Consulta de roles por jugador
 * - Obtener jugadores por rol
 * - Verificación de roles
 * - Rotación de roles
 * - Remover roles
 * - Serialización
 * - Casos edge
 */
class RoleManagerTest extends TestCase
{
    /**
     * Test: Puede crear manager con roles disponibles.
     */
    public function test_can_create_with_available_roles(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $this->assertEquals(['drawer', 'guesser'], $manager->getAvailableRoles());
        $this->assertFalse($manager->allowsMultipleRoles());
    }

    /**
     * Test: Puede asignar rol único a jugador.
     */
    public function test_can_assign_single_role(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);
        $manager->assignRole(1, 'drawer');

        $this->assertEquals('drawer', $manager->getPlayerRole(1));
        $this->assertTrue($manager->hasRole(1, 'drawer'));
        $this->assertFalse($manager->hasRole(1, 'guesser'));
    }

    /**
     * Test: No puede asignar rol no disponible.
     */
    public function test_cannot_assign_unavailable_role(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Role 'detective' is not available");

        $manager->assignRole(1, 'detective');
    }

    /**
     * Test: Reemplaza rol cuando asigna nuevo (modo único).
     */
    public function test_replaces_role_in_single_mode(): void
    {
        $manager = new RoleManager(['drawer', 'guesser', 'spectator']);
        $manager->assignRole(1, 'drawer');
        $manager->assignRole(1, 'guesser'); // Reemplaza

        $this->assertEquals('guesser', $manager->getPlayerRole(1));
        $this->assertFalse($manager->hasRole(1, 'drawer'));
    }

    /**
     * Test: Permite múltiples roles cuando está habilitado.
     */
    public function test_allows_multiple_roles(): void
    {
        $manager = new RoleManager(['detective', 'doctor', 'mafia'], allowMultipleRoles: true);

        $manager->assignRole(1, 'detective');
        $manager->assignRole(1, 'doctor');

        $roles = $manager->getPlayerRole(1);

        $this->assertIsArray($roles);
        $this->assertContains('detective', $roles);
        $this->assertContains('doctor', $roles);
        $this->assertTrue($manager->hasRole(1, 'detective'));
        $this->assertTrue($manager->hasRole(1, 'doctor'));
    }

    /**
     * Test: No duplica roles en modo múltiple.
     */
    public function test_does_not_duplicate_roles_in_multiple_mode(): void
    {
        $manager = new RoleManager(['role1', 'role2'], allowMultipleRoles: true);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(1, 'role1'); // Duplicado

        $roles = $manager->getPlayerRole(1);

        $this->assertCount(1, $roles);
        $this->assertEquals(['role1'], $roles);
    }

    /**
     * Test: Obtiene jugadores con rol específico.
     */
    public function test_gets_players_with_role(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $manager->assignRole(1, 'drawer');
        $manager->assignRole(2, 'guesser');
        $manager->assignRole(3, 'guesser');

        $drawers = $manager->getPlayersWithRole('drawer');
        $guessers = $manager->getPlayersWithRole('guesser');

        $this->assertEquals([1], $drawers);
        $this->assertEquals([2, 3], $guessers);
    }

    /**
     * Test: Obtiene jugadores con rol en modo múltiple.
     */
    public function test_gets_players_with_role_in_multiple_mode(): void
    {
        $manager = new RoleManager(['detective', 'doctor'], allowMultipleRoles: true);

        $manager->assignRole(1, 'detective');
        $manager->assignRole(1, 'doctor');
        $manager->assignRole(2, 'detective');

        $detectives = $manager->getPlayersWithRole('detective');
        $doctors = $manager->getPlayersWithRole('doctor');

        $this->assertEquals([1, 2], $detectives);
        $this->assertEquals([1], $doctors);
    }

    /**
     * Test: Retorna array vacío si no hay jugadores con ese rol.
     */
    public function test_returns_empty_array_if_no_players_have_role(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $this->assertEquals([], $manager->getPlayersWithRole('drawer'));
    }

    /**
     * Test: Retorna null si jugador no tiene rol.
     */
    public function test_returns_null_if_player_has_no_role(): void
    {
        $manager = new RoleManager(['drawer']);

        $this->assertNull($manager->getPlayerRole(999));
        $this->assertFalse($manager->hasRole(999, 'drawer'));
    }

    /**
     * Test: Obtiene todos los roles de jugadores.
     */
    public function test_gets_all_player_roles(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $manager->assignRole(1, 'drawer');
        $manager->assignRole(2, 'guesser');
        $manager->assignRole(3, 'guesser');

        $allRoles = $manager->getAllPlayerRoles();

        $this->assertEquals([
            1 => 'drawer',
            2 => 'guesser',
            3 => 'guesser',
        ], $allRoles);
    }

    /**
     * Test: Obtiene jugadores sin rol.
     */
    public function test_gets_players_without_role(): void
    {
        $manager = new RoleManager(['drawer']);

        $manager->assignRole(1, 'drawer');
        $manager->assignRole(2, 'drawer');

        $allPlayers = [1, 2, 3, 4, 5];
        $withoutRole = $manager->getPlayersWithoutRole($allPlayers);

        $this->assertEquals([3, 4, 5], $withoutRole);
    }

    /**
     * Test: Puede remover rol de jugador (modo único).
     */
    public function test_can_remove_role_in_single_mode(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $manager->assignRole(1, 'drawer');
        $this->assertTrue($manager->hasRole(1, 'drawer'));

        $manager->removeRole(1, 'drawer');
        $this->assertNull($manager->getPlayerRole(1));
        $this->assertFalse($manager->hasRole(1, 'drawer'));
    }

    /**
     * Test: Puede remover rol específico en modo múltiple.
     */
    public function test_can_remove_specific_role_in_multiple_mode(): void
    {
        $manager = new RoleManager(['role1', 'role2', 'role3'], allowMultipleRoles: true);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(1, 'role2');
        $manager->assignRole(1, 'role3');

        $manager->removeRole(1, 'role2');

        $roles = $manager->getPlayerRole(1);

        $this->assertCount(2, $roles);
        $this->assertContains('role1', $roles);
        $this->assertContains('role3', $roles);
        $this->assertNotContains('role2', $roles);
    }

    /**
     * Test: Puede remover todos los roles de jugador.
     */
    public function test_can_remove_all_roles_from_player(): void
    {
        $manager = new RoleManager(['role1', 'role2'], allowMultipleRoles: true);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(1, 'role2');

        $manager->removeRole(1); // null = remover todos

        $this->assertNull($manager->getPlayerRole(1));
    }

    /**
     * Test: Puede rotar rol al siguiente jugador.
     */
    public function test_can_rotate_role(): void
    {
        $manager = new RoleManager(['drawer']);

        $playerOrder = [1, 2, 3, 4];

        // Nadie tiene el rol, asignar al primero
        $newPlayerId = $manager->rotateRole('drawer', $playerOrder);
        $this->assertEquals(1, $newPlayerId);
        $this->assertTrue($manager->hasRole(1, 'drawer'));

        // Rotar al siguiente
        $newPlayerId = $manager->rotateRole('drawer', $playerOrder);
        $this->assertEquals(2, $newPlayerId);
        $this->assertFalse($manager->hasRole(1, 'drawer'));
        $this->assertTrue($manager->hasRole(2, 'drawer'));

        // Rotar al siguiente
        $newPlayerId = $manager->rotateRole('drawer', $playerOrder);
        $this->assertEquals(3, $newPlayerId);
        $this->assertTrue($manager->hasRole(3, 'drawer'));

        // Rotar al siguiente
        $newPlayerId = $manager->rotateRole('drawer', $playerOrder);
        $this->assertEquals(4, $newPlayerId);

        // Rotar de nuevo (circular, vuelve al primero)
        $newPlayerId = $manager->rotateRole('drawer', $playerOrder);
        $this->assertEquals(1, $newPlayerId);
        $this->assertTrue($manager->hasRole(1, 'drawer'));
    }

    /**
     * Test: Rotación retorna null si lista está vacía.
     */
    public function test_rotation_returns_null_if_empty_list(): void
    {
        $manager = new RoleManager(['drawer']);

        $newPlayerId = $manager->rotateRole('drawer', []);

        $this->assertNull($newPlayerId);
    }

    /**
     * Test: Puede limpiar todos los roles.
     */
    public function test_can_clear_all_roles(): void
    {
        $manager = new RoleManager(['role1', 'role2']);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(2, 'role2');

        $this->assertCount(2, $manager->getAllPlayerRoles());

        $manager->clearAllRoles();

        $this->assertCount(0, $manager->getAllPlayerRoles());
        $this->assertNull($manager->getPlayerRole(1));
    }

    /**
     * Test: Serializa a array correctamente (modo único).
     */
    public function test_serializes_to_array_single_mode(): void
    {
        $manager = new RoleManager(['drawer', 'guesser']);

        $manager->assignRole(1, 'drawer');
        $manager->assignRole(2, 'guesser');

        $data = $manager->toArray();

        $this->assertEquals([
            'player_roles' => [
                1 => 'drawer',
                2 => 'guesser',
            ],
            'available_roles' => ['drawer', 'guesser'],
            'allow_multiple_roles' => false,
        ], $data);
    }

    /**
     * Test: Serializa a array correctamente (modo múltiple).
     */
    public function test_serializes_to_array_multiple_mode(): void
    {
        $manager = new RoleManager(['role1', 'role2'], allowMultipleRoles: true);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(1, 'role2');

        $data = $manager->toArray();

        $this->assertEquals([
            'player_roles' => [
                1 => ['role1', 'role2'],
            ],
            'available_roles' => ['role1', 'role2'],
            'allow_multiple_roles' => true,
        ], $data);
    }

    /**
     * Test: Restaura desde array correctamente.
     */
    public function test_restores_from_array(): void
    {
        $data = [
            'player_roles' => [
                1 => 'drawer',
                2 => 'guesser',
            ],
            'available_roles' => ['drawer', 'guesser'],
            'allow_multiple_roles' => false,
        ];

        $manager = RoleManager::fromArray($data);

        $this->assertEquals('drawer', $manager->getPlayerRole(1));
        $this->assertEquals('guesser', $manager->getPlayerRole(2));
        $this->assertEquals(['drawer', 'guesser'], $manager->getAvailableRoles());
        $this->assertFalse($manager->allowsMultipleRoles());
    }

    /**
     * Test: Serialización round-trip mantiene estado.
     */
    public function test_serialization_roundtrip(): void
    {
        $manager = new RoleManager(['role1', 'role2', 'role3'], allowMultipleRoles: true);

        $manager->assignRole(1, 'role1');
        $manager->assignRole(1, 'role2');
        $manager->assignRole(2, 'role3');

        $data = $manager->toArray();
        $restored = RoleManager::fromArray($data);

        $this->assertEquals($manager->getAllPlayerRoles(), $restored->getAllPlayerRoles());
        $this->assertEquals($manager->getAvailableRoles(), $restored->getAvailableRoles());
        $this->assertEquals($manager->allowsMultipleRoles(), $restored->allowsMultipleRoles());
    }
}
