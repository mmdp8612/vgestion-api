<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id_broker' => $this->input('id_broker', $this->input('IdBroker')),
            'password' => $this->input('password', $this->input('PwdWS')),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id_broker' => ['required', 'string', 'max:6'],
            'password' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'id_broker' => 'IdBroker',
            'password' => 'PwdWS',
        ];
    }
}
