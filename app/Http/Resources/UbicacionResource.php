<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UbicacionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'etiqueta' => $this->label(),
            'pais' => $this->catalog($this->pais_id, $this->pais_nombre),
            'provincia' => $this->catalog($this->provincia_id, $this->provincia_nombre),
            'partido' => $this->catalog($this->partido_id, $this->partido_nombre),
            'localidad' => $this->catalog($this->localidad_id, $this->localidad_nombre),
            'cantidad_propiedades' => (int) $this->cantidad_propiedades,
            'filtro' => [
                'parametro' => 'idLocalidad',
                'valor' => is_numeric($this->localidad_id)
                    ? (int) $this->localidad_id
                    : trim((string) $this->localidad_id),
            ],
        ];
    }

    /** @return array{id: int|string|null, nombre: ?string} */
    private function catalog(mixed $id, mixed $name): array
    {
        return [
            'id' => $id === null
                ? null
                : (is_numeric($id) ? (int) $id : trim((string) $id)),
            'nombre' => $this->text($name),
        ];
    }

    private function label(): string
    {
        return implode(', ', array_values(array_filter([
            $this->text($this->provincia_nombre),
            $this->text($this->partido_nombre),
            $this->text($this->localidad_nombre),
        ])));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
