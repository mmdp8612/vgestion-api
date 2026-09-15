<?php

namespace App\Http\Middleware;

use App\Services\BrokerTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrokerToken
{
    public function __construct(private readonly BrokerTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return $this->unauthorized('Debe enviar el token mediante Authorization: Bearer <token>.');
        }

        try {
            $payload = $this->tokens->parse($token);
        } catch (RuntimeException $exception) {
            return $this->unauthorized($exception->getMessage());
        }

        $broker = DB::table('mae_brokers')
            ->where('IdBroker', $payload['broker_id'])
            ->where('Hab', '1')
            ->first();

        if ($broker === null) {
            return $this->unauthorized('El broker del token no existe o se encuentra deshabilitado.');
        }

        $request->attributes->set('broker_id', $payload['broker_id']);
        $request->attributes->set('broker', $broker);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
