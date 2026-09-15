<?php

return [
    'token_ttl' => (int) env('API_TOKEN_TTL', 86400),
    'super_broker' => env('API_SUPER_BROKER', 'Z999'),
    'image_base_url' => env(
        'PROPERTY_IMAGE_BASE_URL',
        'https://www.pfdatos.com.ar/visualgestion/usuario/zsistema/imagenes'
    ),
    'image_limit' => (int) env('PROPERTY_IMAGE_LIMIT', 5),
];
