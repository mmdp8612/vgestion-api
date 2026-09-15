<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultaStoreRequest;
use App\Mail\ConsultaPropiedadMail;
use App\Repositories\BienesraicesRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ConsultasController extends Controller
{
    public function store(ConsultaStoreRequest $request, BienesraicesRepository $repository): JsonResponse
    {
        $data = $request->validated();
        $authenticatedBrokerId = (string) $request->attributes->get('broker_id');

        $property = $repository->find(
            $authenticatedBrokerId,
            $data['id_broker'],
            (string) $data['id_bienes'],
        );

        if ($property === null) {
            return response()->json([
                'message' => 'La propiedad no existe.',
            ], Response::HTTP_NOT_FOUND);
        }

        $recipientEmail = filter_var(trim((string) $property->broker_email), FILTER_VALIDATE_EMAIL);

        if ($recipientEmail === false) {
            return response()->json([
                'message' => 'La inmobiliaria no tiene un email válido configurado para recibir consultas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $propertyData = $this->propertyData($property);

        try {
            Mail::to($recipientEmail)->send(new ConsultaPropiedadMail($data, $propertyData));
        } catch (Throwable $exception) {
            Log::error('No se pudo enviar una consulta de propiedad.', [
                'id_broker' => $data['id_broker'],
                'id_bienes' => (string) $data['id_bienes'],
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'No fue posible enviar la consulta. Intente nuevamente más tarde.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'message' => 'La consulta fue enviada correctamente.',
            'data' => [
                'id_broker' => trim((string) $property->IdBroker),
                'id_bienes' => $this->number($property->IdBienes),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /** @return array<string, string|null> */
    private function propertyData(object $property): array
    {
        $address = array_filter([
            $this->nullableText($property->Calle),
            (int) $property->Numero > 0 ? (string) (int) $property->Numero : null,
            $this->nullableText($property->localidad_nombre),
            $this->nullableText($property->provincia_nombre),
        ]);

        return [
            'referencia' => trim((string) $property->IdBroker).' #'.$this->number($property->IdBienes),
            'direccion' => $address !== [] ? implode(', ', $address) : null,
            'tipologia' => $this->nullableText($property->tipologia_nombre),
            'operacion' => $this->nullableText($property->comercializacion_nombre),
            'broker' => $this->nullableText($property->broker_razon_social),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
    }
}
