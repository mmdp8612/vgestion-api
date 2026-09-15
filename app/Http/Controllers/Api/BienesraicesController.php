<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BienesraicesIndexRequest;
use App\Http\Requests\BienesraicesShowRequest;
use App\Http\Resources\BienesraicesDetalleResource;
use App\Http\Resources\BienesraicesResource;
use App\Repositories\BienesraicesRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BienesraicesController extends Controller
{
    public function index(BienesraicesIndexRequest $request, BienesraicesRepository $repository): JsonResponse
    {
        $filters = $request->validated();
        $brokerId = (string) $request->attributes->get('broker_id');
        $perPage = (int) ($filters['per_page'] ?? 20);

        $paginator = $repository->paginate($brokerId, $filters, $perPage);
        $paginator->appends($request->query());

        return response()->json([
            'data' => BienesraicesResource::collection($paginator->items())->resolve($request),
            'meta' => [
                'pagina_actual' => $paginator->currentPage(),
                'por_pagina' => $paginator->perPage(),
                'total' => $paginator->total(),
                'ultima_pagina' => $paginator->lastPage(),
            ],
            'links' => [
                'primera' => $paginator->url(1),
                'ultima' => $paginator->url($paginator->lastPage()),
                'anterior' => $paginator->previousPageUrl(),
                'siguiente' => $paginator->nextPageUrl(),
            ],
            'filtros_aplicados' => (object) $this->appliedFilters($filters),
            'filtros_disponibles' => $repository->availableFilters($brokerId, $filters),
        ]);
    }

    public function show(BienesraicesShowRequest $request, BienesraicesRepository $repository): JsonResponse
    {
        $route = $request->validated();
        $authenticatedBrokerId = (string) $request->attributes->get('broker_id');

        $property = $repository->find(
            $authenticatedBrokerId,
            $route['idBroker'],
            (string) $route['idBienes'],
        );

        if ($property === null) {
            return response()->json([
                'message' => 'La propiedad no existe.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => BienesraicesDetalleResource::make($property)->resolve($request),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function appliedFilters(array $filters): array
    {
        $numericFilters = [
            'ImporteVta',
            'ImporteAlq',
            'precio_venta_desde',
            'precio_venta_hasta',
            'precio_alquiler_desde',
            'precio_alquiler_hasta',
        ];

        $applied = [];

        foreach ($filters as $parameter => $value) {
            if (in_array($parameter, ['page', 'per_page'], true)) {
                continue;
            }

            if (in_array($parameter, $numericFilters, true)) {
                $applied[$parameter] = (float) $value;

                continue;
            }

            $values = array_values(array_filter(
                array_map('trim', explode(',', (string) $value)),
                fn (string $item): bool => $item !== ''
            ));

            if ($values === []) {
                continue;
            }

            $applied[$parameter] = $parameter === 'ambientes'
                ? array_map('intval', $values)
                : $values;
        }

        return $applied;
    }
}
