<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BienesraicesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $priceVisible = (int) $this->NoPPI !== 1;

        return [
            'id' => $this->number($this->IdBienes),
            'id_broker' => trim((string) $this->IdBroker),
            'direccion' => [
                'calle' => $this->nullableText($this->Calle),
                'numero' => $this->Numero !== null ? (int) $this->Numero : null,
                'piso' => $this->nullableText($this->Piso),
                'torre' => $this->nullableText($this->Torre),
                'barrio' => $this->nullableText($this->Barrio),
            ],
            'descripcion' => $this->nullableText($this->Caratula),
            'ubicacion' => [
                'localidad' => $this->catalog($this->idLocalidad, $this->localidad_nombre),
                'partido' => $this->catalog($this->IdPartido, $this->partido_nombre),
                'provincia' => $this->catalog($this->IdProvincia, $this->provincia_nombre),
                'pais' => $this->catalog($this->IdPais, $this->pais_nombre),
                'latitud' => (float) $this->Latitud,
                'longitud' => (float) $this->Longitud,
            ],
            'caracteristicas' => [
                'antiguedad' => $this->catalog($this->Antiguedad, $this->antiguedad_nombre),
                'luminosidad' => $this->nullableText($this->Luminosidad),
                'plantas' => (int) $this->Plantas,
                'frente_m' => (float) $this->Frente,
                'fondo_m' => (float) $this->Fondo,
                'fondo_libre_m' => (float) $this->MtsFondo,
                'superficie_cubierta_m2' => (float) $this->SupCubiertaPropia,
                'superficie_terreno_m2' => (float) $this->SupTerreno,
                'ambientes' => (int) $this->Ambientes,
                'banos' => (int) $this->Sanitarios,
                'suites' => (int) $this->Suite,
                'dormitorios' => (int) $this->Dormitorios,
                'lineas_telefonicas' => (int) $this->LineasTel,
                'vista' => $this->catalog($this->IdVista, $this->vista_nombre),
                'uso' => $this->catalog($this->IdUso, $this->uso_nombre),
                'orientacion' => $this->catalog($this->IdOrientacion, $this->orientacion_nombre),
                'tipologia' => $this->catalog($this->IdTipologia, $this->tipologia_nombre),
                'cochera' => $this->catalog($this->IdCochera, $this->cochera_nombre),
            ],
            'comercializacion' => [
                ...$this->catalog($this->IdComercializacion, $this->comercializacion_nombre),
                'permite_venta' => in_array(trim((string) $this->IdComercializacion), ['VTA', 'A-V'], true),
                'permite_alquiler' => in_array(trim((string) $this->IdComercializacion), ['ALQ', 'ATE', 'A-V'], true),
            ],
            'precios' => [
                'visible' => $priceVisible,
                'texto' => $priceVisible ? null : 'Consultar',
                'venta' => $this->price(
                    $priceVisible,
                    $this->ImporteVta,
                    $this->idTipoMonedaVta,
                    $this->moneda_vta_nombre,
                    $this->moneda_vta_simbolo,
                ),
                'alquiler' => $this->price(
                    $priceVisible,
                    $this->ImporteAlq,
                    $this->idTipoMonedaAlq,
                    $this->moneda_alq_nombre,
                    $this->moneda_alq_simbolo,
                ),
            ],
            'multimedia' => [
                'imagenes' => $this->images(),
                'video' => (int) $this->TieneVideo === 1 ? $this->nullableText($this->UrlVideo) : null,
                'tour_360' => (int) $this->Tiene360 === 1 ? $this->nullableText($this->URL360) : null,
            ],
            'broker' => [
                'id' => trim((string) $this->IdBroker),
                'razon_social' => $this->nullableText($this->broker_razon_social),
                'email' => $this->nullableText($this->broker_email),
                'telefono' => $this->nullableText($this->broker_telefono),
                'celular' => $this->nullableText($this->broker_celular),
                'direccion' => $this->nullableText($this->broker_direccion),
                'localidad' => $this->nullableText($this->broker_localidad),
                'partido' => $this->nullableText($this->broker_partido),
                'provincia' => $this->nullableText($this->broker_provincia),
                'pais' => $this->nullableText($this->broker_pais),
                'codigo_postal' => $this->nullableText($this->broker_codigo_postal),
                'web' => $this->nullableText($this->broker_web),
                'matricula' => $this->nullableText($this->broker_matricula),
            ],
        ];
    }

    /** @return array{id: int|float|string|null, nombre: ?string} */
    private function catalog(mixed $id, mixed $name): array
    {
        return [
            'id' => is_numeric($id) ? $this->number($id) : $this->nullableText($id),
            'nombre' => $this->nullableText($name),
        ];
    }

    /** @return array<string, mixed> */
    private function price(bool $visible, mixed $amount, mixed $currencyId, mixed $currencyName, mixed $symbol): array
    {
        return [
            'importe' => $visible ? (float) $amount : null,
            'moneda' => $this->catalog($currencyId, $currencyName),
            'simbolo' => $this->nullableText($symbol),
        ];
    }

    /** @return list<string> */
    private function images(): array
    {
        if ((int) $this->TieneFoto !== 1) {
            return [];
        }

        $baseUrl = rtrim((string) config('visualgestion.image_base_url'), '/');
        $brokerId = rawurlencode(trim((string) $this->IdBroker));
        $propertyId = rawurlencode((string) $this->number($this->IdBienes));

        return array_map(
            fn (int $number): string => "{$baseUrl}/{$brokerId}/fot/{$brokerId}_{$propertyId}_{$number}.jpg",
            range(1, max(1, (int) config('visualgestion.image_limit')))
        );
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
