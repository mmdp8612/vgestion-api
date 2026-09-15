<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva consulta de propiedad</title>
</head>
<body style="margin: 0; padding: 24px; background: #f3f4f6; color: #111827; font-family: Arial, sans-serif;">
    <div style="max-width: 640px; margin: 0 auto; padding: 28px; background: #ffffff; border-radius: 8px;">
        <h1 style="margin: 0 0 20px; font-size: 22px;">Nueva consulta de propiedad</h1>

        <p>Recibiste una consulta desde el portal por la propiedad <strong>{{ $propiedad['referencia'] }}</strong>.</p>

        <table style="width: 100%; margin: 20px 0; border-collapse: collapse;">
            <tr><td style="padding: 6px 0; font-weight: bold;">Inmobiliaria</td><td>{{ $propiedad['broker'] ?? '-' }}</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">Dirección</td><td>{{ $propiedad['direccion'] ?? '-' }}</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">Tipo</td><td>{{ $propiedad['tipologia'] ?? '-' }}</td></tr>
            <tr><td style="padding: 6px 0; font-weight: bold;">Operación</td><td>{{ $propiedad['operacion'] ?? '-' }}</td></tr>
        </table>

        <h2 style="margin: 24px 0 12px; font-size: 18px;">Datos del interesado</h2>
        <p style="margin: 6px 0;"><strong>Nombre:</strong> {{ $consulta['nombre'] }}</p>
        <p style="margin: 6px 0;"><strong>Email:</strong> <a href="mailto:{{ $consulta['email'] }}">{{ $consulta['email'] }}</a></p>
        <p style="margin: 6px 0;"><strong>Teléfono:</strong> {{ $consulta['telefono'] ?? 'No informado' }}</p>

        <h2 style="margin: 24px 0 12px; font-size: 18px;">Mensaje</h2>
        <div style="padding: 16px; background: #f9fafb; border-left: 4px solid #2563eb; white-space: pre-wrap;">{{ $consulta['mensaje'] }}</div>

        <p style="margin-top: 24px; color: #4b5563; font-size: 13px;">Al responder este correo, la respuesta se enviará directamente al interesado.</p>
    </div>
</body>
</html>
