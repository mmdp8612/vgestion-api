<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BienesraicesShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idBroker' => mb_strtoupper((string) $this->route('idBroker')),
            'idBienes' => $this->route('idBienes'),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'idBroker' => ['required', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/'],
            'idBienes' => ['required', 'numeric', 'min:0'],
        ];
    }
}
