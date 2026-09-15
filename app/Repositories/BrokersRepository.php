<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class BrokersRepository
{
    public function find(string $authenticatedBrokerId, string $requestedBrokerId): ?object
    {
        $superBroker = (string) config('visualgestion.super_broker');

        if ($authenticatedBrokerId !== $superBroker
            && ! hash_equals($authenticatedBrokerId, $requestedBrokerId)
        ) {
            return null;
        }

        return DB::table('mae_brokers')
            ->select([
                'IdBroker',
                'RazonSocial',
                'Email',
                'Telefonos',
                'TelPrincipal',
                'Celular',
                'Direccion',
                'Localidad',
                'Partido',
                'Provincia',
                'Pais',
                'CodigoPostal',
                'Latitud',
                'Longitud',
                'Web',
                'Matricula',
            ])
            ->where('IdBroker', $requestedBrokerId)
            ->where('Hab', '1')
            ->first();
    }
}
