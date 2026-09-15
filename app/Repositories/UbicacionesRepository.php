<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UbicacionesRepository
{
    /** @var list<string> */
    private const SEARCH_COLUMNS = [
        'localidad.Descrip',
        'partido.Descrip',
        'provincia.Descrip',
        'pais.Descrip',
    ];

    /**
     * @return array{items: Collection<int, object>, has_more: bool}
     */
    public function search(string $brokerId, ?string $search, ?int $limit): array
    {
        $query = $this->baseQuery($brokerId);

        if ($search !== null) {
            $this->applySearch($query, $search);
        }

        $query
            ->select([
                'br.idLocalidad as localidad_id',
                'localidad.Descrip as localidad_nombre',
                'br.IdPartido as partido_id',
                'partido.Descrip as partido_nombre',
                'br.IdProvincia as provincia_id',
                'provincia.Descrip as provincia_nombre',
                'br.IdPais as pais_id',
                'pais.Descrip as pais_nombre',
            ])
            ->selectRaw('COUNT(*) as cantidad_propiedades')
            ->groupBy([
                'br.idLocalidad',
                'localidad.Descrip',
                'br.IdPartido',
                'partido.Descrip',
                'br.IdProvincia',
                'provincia.Descrip',
                'br.IdPais',
                'pais.Descrip',
            ])
            ->orderBy('provincia.Descrip')
            ->orderBy('partido.Descrip')
            ->orderBy('localidad.Descrip');

        if ($limit === null) {
            return [
                'items' => $query->get(),
                'has_more' => false,
            ];
        }

        $items = $query->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;

        return [
            'items' => $items->take($limit)->values(),
            'has_more' => $hasMore,
        ];
    }

    private function baseQuery(string $brokerId): Builder
    {
        $query = DB::table('mae_bienesraices as br')
            ->join('tip_localidad as localidad', 'localidad.IdLocalidad', '=', 'br.idLocalidad')
            ->leftJoin('tip_partido as partido', 'partido.IdPartido', '=', 'br.IdPartido')
            ->leftJoin('tip_provincia as provincia', 'provincia.IdProvincia', '=', 'br.IdProvincia')
            ->leftJoin('tip_pais as pais', 'pais.IdPais', '=', 'br.IdPais')
            ->whereNotNull('localidad.Descrip')
            ->where('localidad.Descrip', '<>', '');

        if ($brokerId !== (string) config('visualgestion.super_broker')) {
            $query->where('br.IdBroker', $brokerId);
        }

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($search)) ?: [],
            fn (string $token): bool => $token !== ''
        ));

        foreach ($tokens as $token) {
            $pattern = '%'.$this->escapeLike($token).'%';

            $query->where(function (Builder $nested) use ($pattern): void {
                foreach (self::SEARCH_COLUMNS as $index => $column) {
                    $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                    $nested->{$method}("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
