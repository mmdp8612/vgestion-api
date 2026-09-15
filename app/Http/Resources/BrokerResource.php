<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BrokerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => trim((string) $this->IdBroker),
            'razon_social' => $this->nullableText($this->RazonSocial),
            'email' => $this->nullableText($this->Email),
            'telefono' => $this->nullableText($this->TelPrincipal),
            'telefonos' => $this->nullableText($this->Telefonos),
            'celular' => $this->nullableText($this->Celular),
            'direccion' => $this->nullableText($this->Direccion),
            'localidad' => $this->nullableText($this->Localidad),
            'partido' => $this->nullableText($this->Partido),
            'provincia' => $this->nullableText($this->Provincia),
            'pais' => $this->nullableText($this->Pais),
            'codigo_postal' => $this->nullableText($this->CodigoPostal),
            'latitud' => $this->coordinate($this->Latitud),
            'longitud' => $this->coordinate($this->Longitud),
            'web' => $this->nullableText($this->Web),
            'matricula' => $this->nullableText($this->Matricula),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function coordinate(mixed $value): ?float
    {
        $text = trim((string) $value);

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }
}
