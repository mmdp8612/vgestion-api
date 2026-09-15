<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrokerShowRequest;
use App\Http\Resources\BrokerResource;
use App\Repositories\BrokersRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BrokersController extends Controller
{
    public function show(BrokerShowRequest $request, BrokersRepository $repository): JsonResponse
    {
        $route = $request->validated();
        $authenticatedBrokerId = (string) $request->attributes->get('broker_id');

        $broker = $repository->find($authenticatedBrokerId, $route['idBroker']);

        if ($broker === null) {
            return response()->json([
                'message' => 'La inmobiliaria no existe.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => BrokerResource::make($broker)->resolve($request),
        ]);
    }
}
