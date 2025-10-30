<?php

namespace App\Http\Requests\Game;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request para validar acciones de juego.
 *
 * Valida que las acciones enviadas por el frontend tengan la estructura correcta
 * antes de ser procesadas por el GameController.
 */
class PerformActionRequest extends FormRequest
{
    /**
     * Determinar si el usuario está autorizado para hacer esta petición.
     *
     * La autenticación ya está manejada en el middleware,
     * por lo que siempre retornamos true aquí.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Obtener las reglas de validación que se aplican a la petición.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:255'],
            'data' => ['sometimes', 'array'],
        ];
    }

    /**
     * Obtener los mensajes de error personalizados para el validador.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'El tipo de acción es obligatorio.',
            'action.string' => 'El tipo de acción debe ser un texto.',
            'action.max' => 'El tipo de acción no puede exceder :max caracteres.',
            'data.array' => 'Los datos de la acción deben ser un array.',
        ];
    }

    /**
     * Obtener nombres personalizados de atributos para mensajes de validación.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'tipo de acción',
            'data' => 'datos de la acción',
        ];
    }
}
