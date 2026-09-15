<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BienesraicesRepository
{
    /** @var array<string, array{id: string, name: string}> */
    private const FACETS = [
        'localidad' => ['id' => 'br.idLocalidad', 'name' => 'localidad.Descrip'],
        'partido' => ['id' => 'br.IdPartido', 'name' => 'partido.Descrip'],
        'provincia' => ['id' => 'br.IdProvincia', 'name' => 'provincia.Descrip'],
        'pais' => ['id' => 'br.IdPais', 'name' => 'pais.Descrip'],
        'antiguedad' => ['id' => 'br.Antiguedad', 'name' => 'antiguedad.Descrip'],
        'comercializacion' => ['id' => 'br.IdComercializacion', 'name' => 'comercializacion.Descrip'],
        'vista' => ['id' => 'br.IdVista', 'name' => 'vista.Descrip'],
        'orientacion' => ['id' => 'br.IdOrientacion', 'name' => 'orientacion.Descrip'],
        'tipo' => ['id' => 'br.IdTipologia', 'name' => 'tipologia.Descrip'],
        'cochera' => ['id' => 'br.IdCochera', 'name' => 'cochera.Descrip'],
        'ambientes' => ['id' => 'br.Ambientes', 'name' => 'br.Ambientes'],
        'moneda_venta' => ['id' => 'br.idTipoMonedaVta', 'name' => 'moneda_vta.Descrip'],
        'moneda_alquiler' => ['id' => 'br.idTipoMonedaAlq', 'name' => 'moneda_alq.Descrip'],
    ];

    /** @var array<string, string> */
    private const ID_FILTERS = [
        'idLocalidad' => 'br.idLocalidad',
        'idPartido' => 'br.IdPartido',
        'idProvincia' => 'br.IdProvincia',
        'idPais' => 'br.IdPais',
        'Antiguedad' => 'br.Antiguedad',
        'IdVista' => 'br.IdVista',
        'idOrientacion' => 'br.IdOrientacion',
        'idTipologia' => 'br.IdTipologia',
        'idcochera' => 'br.IdCochera',
        'idTipoMonedaAlq' => 'br.idTipoMonedaAlq',
        'idTipoMonedaVta' => 'br.idTipoMonedaVta',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(string $brokerId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->applyFilters($this->baseQuery($brokerId), $filters)
            ->select($this->propertyColumns())
            ->orderBy('br.IdBroker')
            ->orderBy('br.IdBienes')
            ->paginate($perPage);
    }

    public function find(
        string $authenticatedBrokerId,
        string $propertyBrokerId,
        string $propertyId
    ): ?object {
        $superBroker = (string) config('visualgestion.super_broker');

        if ($authenticatedBrokerId !== $superBroker
            && ! hash_equals($authenticatedBrokerId, $propertyBrokerId)
        ) {
            return null;
        }

        return $this->baseQuery($authenticatedBrokerId)
            ->where('br.IdBroker', $propertyBrokerId)
            ->where('br.IdBienes', $propertyId)
            ->select($this->propertyColumns())
            ->first();
    }

    /**
     * Returns the values still present after applying the current search.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function availableFilters(string $brokerId, array $filters): array
    {
        $query = $this->applyFilters($this->baseQuery($brokerId), $filters);
        $result = [];

        foreach (self::FACETS as $key => $facet) {
            $result[$key] = (clone $query)
                ->whereNotNull($facet['id'])
                ->whereNotNull($facet['name'])
                ->where($facet['name'], '<>', '')
                ->selectRaw("{$facet['id']} as id, TRIM({$facet['name']}) as nombre, COUNT(*) as cantidad")
                ->groupBy($facet['id'], $facet['name'])
                ->orderBy($facet['name'])
                ->get()
                ->map(fn (object $item): array => [
                    'id' => is_numeric($item->id) ? (int) $item->id : trim((string) $item->id),
                    'nombre' => trim((string) $item->nombre),
                    'cantidad' => (int) $item->cantidad,
                ])
                ->all();
        }

        $prices = (clone $query)
            ->where('br.NoPPI', 0)
            ->selectRaw('MIN(CASE WHEN br.ImporteVta > 0 THEN br.ImporteVta END) as venta_min')
            ->selectRaw('MAX(CASE WHEN br.ImporteVta > 0 THEN br.ImporteVta END) as venta_max')
            ->selectRaw('MIN(CASE WHEN br.ImporteAlq > 0 THEN br.ImporteAlq END) as alquiler_min')
            ->selectRaw('MAX(CASE WHEN br.ImporteAlq > 0 THEN br.ImporteAlq END) as alquiler_max')
            ->first();

        $result['precios'] = [
            'venta' => [
                'minimo' => $prices?->venta_min !== null ? (float) $prices->venta_min : null,
                'maximo' => $prices?->venta_max !== null ? (float) $prices->venta_max : null,
            ],
            'alquiler' => [
                'minimo' => $prices?->alquiler_min !== null ? (float) $prices->alquiler_min : null,
                'maximo' => $prices?->alquiler_max !== null ? (float) $prices->alquiler_max : null,
            ],
        ];

        return $result;
    }

    private function baseQuery(string $brokerId): Builder
    {
        $query = DB::table('mae_bienesraices as br')
            ->join('mae_brokers as broker', 'broker.IdBroker', '=', 'br.IdBroker')
            ->leftJoin('tip_localidad as localidad', 'localidad.IdLocalidad', '=', 'br.idLocalidad')
            ->leftJoin('tip_partido as partido', 'partido.IdPartido', '=', 'br.IdPartido')
            ->leftJoin('tip_provincia as provincia', 'provincia.IdProvincia', '=', 'br.IdProvincia')
            ->leftJoin('tip_pais as pais', 'pais.IdPais', '=', 'br.IdPais')
            ->leftJoin('tip_antiguedad as antiguedad', 'antiguedad.IdAntiguedad', '=', 'br.Antiguedad')
            ->leftJoin('tip_comercializacion as comercializacion', 'comercializacion.IdComercializacion', '=', 'br.IdComercializacion')
            ->leftJoin('tip_vista as vista', 'vista.IdVista', '=', 'br.IdVista')
            ->leftJoin('tip_uso as uso', 'uso.IdUso', '=', 'br.IdUso')
            ->leftJoin('tip_orientacion as orientacion', 'orientacion.IdOrientacion', '=', 'br.IdOrientacion')
            ->leftJoin('tip_tipologia as tipologia', 'tipologia.IdTipologia', '=', 'br.IdTipologia')
            ->leftJoin('tip_cochera as cochera', 'cochera.IdCochera', '=', 'br.IdCochera')
            ->leftJoin('tip_tipomoneda as moneda_vta', 'moneda_vta.idTipoMoneda', '=', 'br.idTipoMonedaVta')
            ->leftJoin('tip_tipomoneda as moneda_alq', 'moneda_alq.idTipoMoneda', '=', 'br.idTipoMonedaAlq');

        if ($brokerId !== (string) config('visualgestion.super_broker')) {
            $query->where('br.IdBroker', $brokerId);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (self::FACETS as $parameter => $facet) {
            if (! isset($filters[$parameter])) {
                continue;
            }

            $values = $this->values($filters[$parameter]);
            if ($values === []) {
                continue;
            }

            if ($parameter === 'comercializacion') {
                $this->applyCommercializationNames($query, $facet['name'], $values);
            } elseif (str_starts_with($parameter, 'moneda_')) {
                $symbolColumn = $parameter === 'moneda_venta' ? 'moneda_vta.Simbolo' : 'moneda_alq.Simbolo';
                $query->where(function (Builder $nested) use ($facet, $symbolColumn, $values): void {
                    $nested->whereIn($facet['name'], $values)->orWhereIn($symbolColumn, $values);
                });
            } else {
                $query->whereIn($facet['name'], $values);
            }
        }

        foreach (self::ID_FILTERS as $parameter => $column) {
            if (isset($filters[$parameter])) {
                $values = $this->values($filters[$parameter]);
                if ($values !== []) {
                    $query->whereIn($column, $values);
                }
            }
        }

        if (isset($filters['IdComercializacion'])) {
            $values = $this->values($filters['IdComercializacion']);
            $query->where(function (Builder $nested) use ($values): void {
                $nested->whereIn('br.IdComercializacion', $values);
                if (in_array('VTA', $values, true) || in_array('ALQ', $values, true)) {
                    $nested->orWhere('br.IdComercializacion', 'A-V');
                }
            });
        }

        $exact = [
            'ImporteVta' => 'br.ImporteVta',
            'ImporteAlq' => 'br.ImporteAlq',
        ];
        foreach ($exact as $parameter => $column) {
            if (isset($filters[$parameter])) {
                $query->where($column, (float) $filters[$parameter]);
            }
        }

        $ranges = [
            'precio_venta_desde' => ['br.ImporteVta', '>='],
            'precio_venta_hasta' => ['br.ImporteVta', '<='],
            'precio_alquiler_desde' => ['br.ImporteAlq', '>='],
            'precio_alquiler_hasta' => ['br.ImporteAlq', '<='],
        ];
        foreach ($ranges as $parameter => [$column, $operator]) {
            if (isset($filters[$parameter])) {
                $query->where($column, $operator, (float) $filters[$parameter]);
            }
        }

        return $query;
    }

    /**
     * @param  list<string>  $values
     */
    private function applyCommercializationNames(Builder $query, string $nameColumn, array $values): void
    {
        $normalized = array_map(
            fn (string $value): string => mb_strtolower(trim($value)),
            $values
        );

        $includeBoth = in_array('venta', $normalized, true)
            || in_array('alquiler', $normalized, true);

        $query->where(function (Builder $nested) use ($nameColumn, $values, $includeBoth): void {
            $nested->whereIn($nameColumn, $values);
            if ($includeBoth) {
                $nested->orWhere('br.IdComercializacion', 'A-V');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function values(mixed $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * @return list<string>
     */
    private function propertyColumns(): array
    {
        return [
            'br.IdBroker', 'br.IdBienes', 'br.Calle', 'br.Numero', 'br.Piso', 'br.Torre',
            'br.Caratula', 'br.Barrio', 'br.idLocalidad', 'br.IdPartido', 'br.IdProvincia',
            'br.IdPais', 'br.Antiguedad', 'br.Luminosidad', 'br.Plantas', 'br.Frente',
            'br.Fondo', 'br.MtsFondo', 'br.SupCubiertaPropia', 'br.SupTerreno',
            'br.Ambientes', 'br.Sanitarios', 'br.Suite', 'br.Dormitorios', 'br.LineasTel',
            'br.ImporteVta', 'br.ImporteAlq', 'br.IdComercializacion', 'br.IdVista',
            'br.IdUso', 'br.IdOrientacion', 'br.IdTipologia', 'br.IdCochera',
            'br.idTipoMonedaVta', 'br.idTipoMonedaAlq', 'br.TieneFoto', 'br.TieneVideo',
            'br.Latitud', 'br.Longitud', 'br.UrlVideo', 'br.NoPPI', 'br.Tiene360', 'br.URL360',
            'localidad.Descrip as localidad_nombre', 'partido.Descrip as partido_nombre',
            'provincia.Descrip as provincia_nombre', 'pais.Descrip as pais_nombre',
            'antiguedad.Descrip as antiguedad_nombre',
            'comercializacion.Descrip as comercializacion_nombre',
            'vista.Descrip as vista_nombre', 'uso.Descrip as uso_nombre',
            'orientacion.Descrip as orientacion_nombre', 'tipologia.Descrip as tipologia_nombre',
            'cochera.Descrip as cochera_nombre', 'moneda_vta.Descrip as moneda_vta_nombre',
            'moneda_vta.Simbolo as moneda_vta_simbolo', 'moneda_alq.Descrip as moneda_alq_nombre',
            'moneda_alq.Simbolo as moneda_alq_simbolo',
            'broker.RazonSocial as broker_razon_social', 'broker.Email as broker_email',
            'broker.TelPrincipal as broker_telefono', 'broker.Celular as broker_celular',
            'broker.Direccion as broker_direccion', 'broker.Localidad as broker_localidad',
            'broker.Partido as broker_partido', 'broker.Provincia as broker_provincia',
            'broker.Pais as broker_pais', 'broker.CodigoPostal as broker_codigo_postal',
            'broker.Web as broker_web', 'broker.Matricula as broker_matricula',
        ];
    }
}
