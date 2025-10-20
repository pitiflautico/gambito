<?php

namespace App\Contracts;

/**
 * Contrato para validar la configuración de un juego.
 *
 * Cada juego debe tener archivos config.json y capabilities.json
 * que cumplan con esta estructura.
 */
interface GameConfigInterface
{
    /**
     * Validar el archivo config.json de un juego.
     *
     * Verifica que el archivo tenga todos los campos requeridos
     * y que los valores sean del tipo correcto.
     *
     * @param array $config Contenido del config.json parseado
     * @return array Array con ['valid' => bool, 'errors' => array]
     */
    public function validateConfig(array $config): array;

    /**
     * Validar el archivo capabilities.json de un juego.
     *
     * Verifica que el archivo declare correctamente qué servicios
     * compartidos necesita el juego.
     *
     * @param array $capabilities Contenido del capabilities.json parseado
     * @return array Array con ['valid' => bool, 'errors' => array]
     */
    public function validateCapabilities(array $capabilities): array;

    /**
     * Obtener los campos requeridos en config.json.
     *
     * @return array Lista de campos obligatorios
     */
    public function getRequiredConfigFields(): array;

    /**
     * Obtener los servicios compartidos disponibles.
     *
     * @return array Lista de servicios que un juego puede requerir
     */
    public function getAvailableCapabilities(): array;
}
