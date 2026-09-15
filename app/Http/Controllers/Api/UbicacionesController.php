<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UbicacionesIndexRequest;
use App\Http\Resources\UbicacionResource;
use App\Repositories\UbicacionesRepository;
use Illuminate\Http\JsonResponse;

class UbicacionesController extends Controller
{
    public function index(UbicacionesIndexRequest $request, UbicacionesRepository $repository): JsonResponse
    {
        $validated = $request->validated();
        $search = $validated['q'] ?? null;
        $limit = isset($validated['limit'])
            ? (int) $validated['limit']
            : ($search !== null ? 10 : null);

        $result = $repository->search(
            (string) $request->attributes->get('broker_id'),
            $search,
            $limit,
        );

        return response()->json([
            'data' => UbicacionResource::collection($result['items'])->resolve($request),
            'meta' => [
                'busqueda' => $search,
                'cantidad' => $result['items']->count(),
                'limite' => $limit,
                'hay_mas' => $result['has_more'],
            ],
        ]);
    }
}
