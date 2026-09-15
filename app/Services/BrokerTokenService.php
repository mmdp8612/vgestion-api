<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class BrokerTokenService
{
    /**
     * @return array{token: string, expires_in: int, expires_at: string}
     */
    public function issue(string $brokerId): array
    {
        $ttl = max(60, (int) config('visualgestion.token_ttl'));
        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addSeconds($ttl);

        $payload = json_encode([
            'version' => 1,
            'broker_id' => $brokerId,
            'issued_at' => $issuedAt->timestamp,
            'expires_at' => $expiresAt->timestamp,
        ], JSON_THROW_ON_ERROR);

        return [
            'token' => Crypt::encryptString($payload),
            'expires_in' => $ttl,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{version: int, broker_id: string, issued_at: int, expires_at: int}
     */
    public function parse(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new RuntimeException('El token es inválido.');
        }

        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['broker_id'] ?? null)
            || ! is_int($payload['issued_at'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)
        ) {
            throw new RuntimeException('El token es inválido.');
        }

        if ($payload['expires_at'] <= now()->timestamp) {
            throw new RuntimeException('El token ha expirado.');
        }

        return $payload;
    }
}
