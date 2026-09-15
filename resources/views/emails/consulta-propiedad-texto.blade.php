Nueva consulta de propiedad

Propiedad: {{ $propiedad['referencia'] }}
Inmobiliaria: {{ $propiedad['broker'] ?? '-' }}
Dirección: {{ $propiedad['direccion'] ?? '-' }}
Tipo: {{ $propiedad['tipologia'] ?? '-' }}
Operación: {{ $propiedad['operacion'] ?? '-' }}

DATOS DEL INTERESADO
Nombre: {{ $consulta['nombre'] }}
Email: {{ $consulta['email'] }}
Teléfono: {{ $consulta['telefono'] ?? 'No informado' }}

MENSAJE
{{ $consulta['mensaje'] }}

Al responder este correo, la respuesta se enviará directamente al interesado.
