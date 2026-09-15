<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BienesraicesIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $textFilter = ['sometimes', 'string', 'max:500'];

        return [
            'localidad' => $textFilter,
            'partido' => $textFilter,
            'provincia' => $textFilter,
            'pais' => $textFilter,
            'antiguedad' => $textFilter,
            'comercializacion' => $textFilter,
            'vista' => $textFilter,
            'orientacion' => $textFilter,
            'tipo' => $textFilter,
            'cochera' => $textFilter,
            'ambientes' => $textFilter,
            'moneda_venta' => $textFilter,
            'moneda_alquiler' => $textFilter,

            'idLocalidad' => $textFilter,
            'idPartido' => $textFilter,
            'idProvincia' => $textFilter,
            'idPais' => $textFilter,
            'Antiguedad' => $textFilter,
            'IdComercializacion' => $textFilter,
            'IdVista' => $textFilter,
            'idOrientacion' => $textFilter,
            'idTipologia' => $textFilter,
            'idcochera' => $textFilter,
            'idTipoMonedaAlq' => $textFilter,
            'idTipoMonedaVta' => $textFilter,

            'ImporteVta' => ['sometimes', 'numeric', 'min:0'],
            'ImporteAlq' => ['sometimes', 'numeric', 'min:0'],
            'precio_venta_desde' => ['sometimes', 'numeric', 'min:0'],
            'precio_venta_hasta' => ['sometimes', 'numeric', 'min:0'],
            'precio_alquiler_desde' => ['sometimes', 'numeric', 'min:0'],
            'precio_alquiler_hasta' => ['sometimes', 'numeric', 'min:0'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateRange($validator, 'precio_venta_desde', 'precio_venta_hasta');
            $this->validateRange($validator, 'precio_alquiler_desde', 'precio_alquiler_hasta');
            $this->validateIntegerList($validator, 'ambientes');
        }];
    }

    private function validateRange(Validator $validator, string $minimum, string $maximum): void
    {
        if ($this->filled($minimum)
            && $this->filled($maximum)
            && (float) $this->input($maximum) < (float) $this->input($minimum)
        ) {
            $validator->errors()->add($maximum, "El campo {$maximum} debe ser mayor o igual que {$minimum}.");
        }
    }

    private function validateIntegerList(Validator $validator, string $field): void
    {
        if (! $this->filled($field)) {
            return;
        }

        $values = array_map('trim', explode(',', (string) $this->input($field)));

        foreach ($values as $value) {
            if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
                $validator->errors()->add($field, "El campo {$field} debe contener números enteros positivos separados por coma.");

                return;
            }
        }
    }
}
