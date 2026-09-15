<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id_broker' => mb_strtoupper(trim((string) ($this->input('id_broker') ?? $this->input('idBroker')))),
            'id_bienes' => $this->input('id_bienes') ?? $this->input('idBienes'),
            'nombre' => trim((string) $this->input('nombre')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'telefono' => $this->filled('telefono') ? trim((string) $this->input('telefono')) : null,
            'mensaje' => trim((string) $this->input('mensaje')),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id_broker' => ['required', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/'],
            'id_bienes' => ['required', 'numeric', 'min:0'],
            'nombre' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'telefono' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-\.\s]+$/'],
            'mensaje' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'telefono.regex' => 'El teléfono solamente puede contener números, espacios y los caracteres + ( ) - .',
        ];
    }
}
