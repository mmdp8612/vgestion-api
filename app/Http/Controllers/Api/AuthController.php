<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\BrokerResource;
use App\Services\BrokerTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, BrokerTokenService $tokens): JsonResponse
    {
        $credentials = $request->validated();

        $broker = DB::table('mae_brokers')
            ->where('IdBroker', $credentials['id_broker'])
            ->where('Hab', '1')
            ->first();

        if ($broker === null
            || ! is_string($broker->PwdWS)
            || $broker->PwdWS === ''
            || ! hash_equals($broker->PwdWS, $credentials['password'])
        ) {
            return response()->json([
                'message' => 'Las credenciales son incorrectas o el broker está deshabilitado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $issued = $tokens->issue($broker->IdBroker);

        return response()->json([
            'data' => [
                'token' => $issued['token'],
                'token_type' => 'Bearer',
                'expires_in' => $issued['expires_in'],
                'expires_at' => $issued['expires_at'],
                'broker' => BrokerResource::make($broker)->resolve($request),
            ],
        ]);
    }
}
