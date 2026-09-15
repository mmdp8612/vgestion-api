# Guía de implementación de un portal inmobiliario

Este documento describe cómo construir un portal inmobiliario que consuma la API REST de VisualGestion. Está pensado como material de traspaso para otra sesión de desarrollo, por lo que incluye el contrato actual, decisiones importantes, ejemplos y una estrategia recomendada de implementación.

## 1. Objetivo del portal

El portal debe permitir:

- Autenticarse con la API como una inmobiliaria concreta o como el broker global `Z999`.
- Mostrar un listado paginado de propiedades.
- Aplicar y eliminar filtros.
- Construir los filtros dinámicamente con `filtros_disponibles`.
- Mostrar los filtros activos usando `filtros_aplicados`.
- Abrir la ficha individual de una propiedad.
- Enviar consultas de interesados a la inmobiliaria responsable.
- Respetar la visibilidad de precios indicada por `NoPPI`.
- Mostrar imágenes, video y tour 360 cuando estén disponibles.
- Mostrar los datos de la inmobiliaria responsable de cada propiedad.

La API permite leer propiedades y enviar consultas por email. El portal no debe intentar crear, editar ni eliminar propiedades.

## 2. Información general de la API

Durante el desarrollo local, la URL base habitual es:

~~~text
http://127.0.0.1:8000
~~~

Debe configurarse mediante una variable de entorno del portal:

~~~dotenv
VISUALGESTION_API_URL=http://127.0.0.1:8000
~~~

Las rutas no tienen prefijo `/api`.

Endpoints disponibles:

| Método | Endpoint | Función |
|---|---|---|
| `POST` | `/login` | Obtener un Bearer token |
| `GET` | `/brokers/{idBroker}` | Obtener el perfil público de una inmobiliaria |
| `GET` | `/bienesraices` | Listar, paginar y filtrar propiedades |
| `GET` | `/bienesraices/{idBroker}/{idBienes}` | Obtener la ficha de una propiedad |
| `GET` | `/ubicaciones` | Autocompletar ubicaciones con propiedades disponibles |
| `POST` | `/consultas` | Enviar una consulta a la inmobiliaria responsable |
| `GET` | `/docs` | Abrir Swagger UI |
| `GET` | `/openapi.yaml` | Obtener el contrato OpenAPI |

Todos los endpoints de propiedades, ubicaciones y consultas requieren:

~~~http
Authorization: Bearer TOKEN
Accept: application/json
~~~

Límites actuales:

- `POST /login`: 10 solicitudes por minuto por dirección IP.
- Endpoints de propiedades: 120 solicitudes por minuto por broker autenticado.
- `POST /consultas`: 10 solicitudes por minuto y 100 por hora por broker e IP.

## 3. Arquitectura recomendada del portal

No se recomienda enviar `PwdWS` directamente desde el navegador. Es una credencial heredada almacenada en texto plano y debe tratarse como un secreto.

La arquitectura recomendada es:

~~~text
Navegador del visitante
        ↓
Servidor del portal / Backend for Frontend
        ↓
VisualGestion API
        ↓
MySQL
~~~

El servidor del portal debe:

1. Leer `IdBroker` y `PwdWS` desde variables de entorno privadas.
2. Ejecutar `POST /login` desde el servidor.
3. Guardar temporalmente el token y su vencimiento.
4. Usar el token para consultar propiedades.
5. Renovarlo cuando esté próximo a vencer o cuando la API responda `401`.
6. Entregar al navegador solamente los datos necesarios para renderizar el portal.

Variables sugeridas:

~~~dotenv
VISUALGESTION_API_URL=http://127.0.0.1:8000
VISUALGESTION_BROKER_ID=A004
VISUALGESTION_BROKER_PASSWORD=CLAVE_PRIVADA
~~~

Estas variables nunca deben publicarse con prefijos destinados al navegador, como `NEXT_PUBLIC_`, `VITE_` o equivalentes.

Para un portal de una sola inmobiliaria debe utilizarse su broker. Para un portal agregador de todas las inmobiliarias debe utilizarse `Z999`, siempre desde el servidor.

## 4. Autenticación

### Solicitud

~~~http
POST /login
Content-Type: application/json
Accept: application/json
~~~

~~~json
{
  "id_broker": "A004",
  "password": "CLAVE_PRIVADA"
}
~~~

Por compatibilidad también se admiten `IdBroker` y `PwdWS`, pero para código nuevo se recomienda `id_broker` y `password`.

### Respuesta exitosa

~~~json
{
  "data": {
    "token": "TOKEN_CIFRADO",
    "token_type": "Bearer",
    "expires_in": 86400,
    "expires_at": "2026-09-09T12:00:00-03:00",
    "broker": {
      "id": "A004",
      "razon_social": "Inmobiliaria Ejemplo",
      "email": "info@ejemplo.com",
      "telefono": "4444-4444",
      "telefonos": "4444-4444 / 5555-5555",
      "celular": "5491100000000",
      "direccion": "Av. Ejemplo 123",
      "localidad": "Ramos Mejía",
      "partido": "La Matanza",
      "provincia": "Buenos Aires",
      "pais": "Argentina",
      "codigo_postal": "1704",
      "latitud": -34.64,
      "longitud": -58.56,
      "web": "www.ejemplo.com",
      "matricula": "1234"
    }
  }
}
~~~

El token dura actualmente 24 horas, aunque el portal debe utilizar siempre `expires_in` o `expires_at` y no asumir una duración fija.

### Ejemplo de cliente del lado servidor

~~~ts
type LoginResponse = {
  data: {
    token: string;
    token_type: "Bearer";
    expires_in: number;
    expires_at: string;
    broker: Broker;
  };
};

let cachedToken: { value: string; expiresAt: number } | null = null;

async function getApiToken(): Promise<string> {
  const now = Date.now();

  if (cachedToken && cachedToken.expiresAt > now + 60_000) {
    return cachedToken.value;
  }

  const response = await fetch(
    process.env.VISUALGESTION_API_URL + "/login",
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify({
        id_broker: process.env.VISUALGESTION_BROKER_ID,
        password: process.env.VISUALGESTION_BROKER_PASSWORD,
      }),
      cache: "no-store",
    },
  );

  if (!response.ok) {
    throw new Error("No fue posible autenticar el portal con VisualGestion");
  }

  const result = (await response.json()) as LoginResponse;

  cachedToken = {
    value: result.data.token,
    expiresAt: Date.parse(result.data.expires_at),
  };

  return cachedToken.value;
}
~~~

En un entorno con varias instancias del portal conviene reemplazar la variable en memoria por Redis u otro almacenamiento compartido con vencimiento.

### Perfil público del broker

El portal puede volver a obtener los datos actuales de la inmobiliaria sin repetir el login:

~~~http
GET /brokers/A004
Authorization: Bearer TOKEN
Accept: application/json
~~~

~~~json
{
  "data": {
    "id": "A004",
    "razon_social": "Inmobiliaria Ejemplo",
    "email": "info@ejemplo.com",
    "telefono": "4444-4444",
    "telefonos": "4444-4444 / 5555-5555",
    "celular": "5491100000000",
    "direccion": "Av. Ejemplo 123",
    "localidad": "Ramos Mejía",
    "partido": "La Matanza",
    "provincia": "Buenos Aires",
    "pais": "Argentina",
    "codigo_postal": "1704",
    "latitud": -34.64,
    "longitud": -58.56,
    "web": "www.ejemplo.com",
    "matricula": "1234 - La Matanza"
  }
}
~~~

Un token normal solo puede consultar el perfil correspondiente a su propio `idBroker`. `Z999` puede consultar cualquier broker habilitado. Un perfil inexistente, deshabilitado o ajeno responde `404` sin revelar cuál de esas condiciones ocurrió.

No se exponen `PwdWS`, `Hab`, límites de propiedades, relaciones entre sucursales ni campos de auditoría. Las coordenadas inválidas o vacías se normalizan como `null`.

~~~ts
async function getBroker(brokerId: string): Promise<Broker> {
  const token = await getApiToken();
  const response = await fetch(
    process.env.VISUALGESTION_API_URL +
      "/brokers/" +
      encodeURIComponent(brokerId),
    {
      headers: {
        Authorization: "Bearer " + token,
        Accept: "application/json",
      },
      cache: "no-store",
    },
  );

  if (!response.ok) {
    throw await createApiError(response);
  }

  return ((await response.json()) as { data: Broker }).data;
}
~~~

## 5. Listado de propiedades

### Solicitud básica

~~~http
GET /bienesraices?page=1&per_page=20
Authorization: Bearer TOKEN
Accept: application/json
~~~

Un token de broker normal obtiene únicamente propiedades de ese broker. `Z999` obtiene propiedades de todas las inmobiliarias.

### Respuesta general

~~~json
{
  "data": [
    {
      "id": 585,
      "id_broker": "A004",
      "direccion": {},
      "descripcion": "...",
      "ubicacion": {},
      "caracteristicas": {},
      "comercializacion": {},
      "precios": {},
      "multimedia": {},
      "broker": {}
    }
  ],
  "meta": {
    "pagina_actual": 1,
    "por_pagina": 20,
    "total": 84,
    "ultima_pagina": 5
  },
  "links": {
    "primera": "http://127.0.0.1:8000/bienesraices?page=1",
    "ultima": "http://127.0.0.1:8000/bienesraices?page=5",
    "anterior": null,
    "siguiente": "http://127.0.0.1:8000/bienesraices?page=2"
  },
  "filtros_aplicados": {},
  "filtros_disponibles": {}
}
~~~

El portal puede usar los enlaces entregados por la API o construir la navegación con `meta.pagina_actual` y `meta.ultima_pagina`.

## 6. Estructura de una propiedad

Una propiedad del listado o de la ficha tiene actualmente esta forma:

~~~json
{
  "id": 585,
  "id_broker": "A004",
  "direccion": {
    "calle": "Sarmiento",
    "numero": 100,
    "piso": "1°",
    "torre": null,
    "barrio": "Ramos Mejía Sur"
  },
  "descripcion": "Descripción pública de la propiedad",
  "ubicacion": {
    "localidad": {
      "id": 67,
      "nombre": "Ramos Mejía"
    },
    "partido": {
      "id": 13,
      "nombre": "La Matanza"
    },
    "provincia": {
      "id": "BUE",
      "nombre": "Buenos Aires"
    },
    "pais": {
      "id": "ARG",
      "nombre": "Argentina"
    },
    "latitud": -34.6431162,
    "longitud": -58.5619598
  },
  "caracteristicas": {
    "antiguedad": {
      "id": "A20",
      "nombre": "Menor a 20"
    },
    "luminosidad": "Excelente",
    "plantas": 1,
    "frente_m": 4,
    "fondo_m": 4,
    "fondo_libre_m": 0,
    "superficie_cubierta_m2": 18,
    "superficie_terreno_m2": 0,
    "ambientes": 1,
    "banos": 1,
    "suites": 0,
    "dormitorios": 0,
    "lineas_telefonicas": 0,
    "vista": {
      "id": "FRENTE",
      "nombre": "Al Frente"
    },
    "uso": {
      "id": "TODO",
      "nombre": "Todo Destino"
    },
    "orientacion": {
      "id": "E",
      "nombre": "Este"
    },
    "tipologia": {
      "id": "OFIC",
      "nombre": "Oficina"
    },
    "cochera": {
      "id": "SCO",
      "nombre": "Sin Cochera"
    }
  },
  "comercializacion": {
    "id": "ALQ",
    "nombre": "Alquiler",
    "permite_venta": false,
    "permite_alquiler": true
  },
  "precios": {
    "visible": true,
    "texto": null,
    "venta": {
      "importe": 0,
      "moneda": {
        "id": 1,
        "nombre": "Pesos"
      },
      "simbolo": "$"
    },
    "alquiler": {
      "importe": 350000,
      "moneda": {
        "id": 1,
        "nombre": "Pesos"
      },
      "simbolo": "$"
    }
  },
  "multimedia": {
    "imagenes": [
      "https://www.pfdatos.com.ar/visualgestion/usuario/zsistema/imagenes/A004/fot/A004_585_1.jpg"
    ],
    "video": null,
    "tour_360": null
  },
  "broker": {
    "id": "A004",
    "razon_social": "Inmobiliaria Ejemplo",
    "email": "info@ejemplo.com",
    "telefono": "4444-4444",
    "celular": null,
    "direccion": "Av. Ejemplo 123",
    "localidad": "Ramos Mejía",
    "partido": "La Matanza",
    "provincia": "Buenos Aires",
    "pais": "Argentina",
    "codigo_postal": "1704",
    "web": "www.ejemplo.com",
    "matricula": "1234"
  }
}
~~~

Los objetos de catálogo siguen el patrón:

~~~ts
type CatalogValue = {
  id: string | number | null;
  nombre: string | null;
};
~~~

## 7. Reglas que el portal debe respetar

### Comercialización `A-V`

`A-V` significa que la propiedad permite venta y alquiler. La API ya lo representa mediante:

~~~json
{
  "id": "A-V",
  "permite_venta": true,
  "permite_alquiler": true
}
~~~

El portal debe decidir qué precios mostrar usando `permite_venta` y `permite_alquiler`, no comparando solamente textos.

### Precio oculto por `NoPPI`

Cuando el propietario no permite publicar el precio:

~~~json
{
  "precios": {
    "visible": false,
    "texto": "Consultar",
    "venta": {
      "importe": null
    },
    "alquiler": {
      "importe": null
    }
  }
}
~~~

El portal debe mostrar `Consultar` y nunca intentar recuperar o inferir el importe.

Ejemplo:

~~~ts
function formatMainPrice(property: Property): string {
  if (!property.precios.visible) {
    return property.precios.texto ?? "Consultar";
  }

  if (
    property.comercializacion.permite_venta &&
    property.precios.venta.importe
  ) {
    return (
      (property.precios.venta.simbolo ?? "") +
      " " +
      property.precios.venta.importe.toLocaleString("es-AR")
    );
  }

  if (
    property.comercializacion.permite_alquiler &&
    property.precios.alquiler.importe
  ) {
    return (
      (property.precios.alquiler.simbolo ?? "") +
      " " +
      property.precios.alquiler.importe.toLocaleString("es-AR")
    );
  }

  return "Consultar";
}
~~~

### Multimedia

- `imagenes` siempre es un array.
- Si `TieneFoto` no estaba activo, el array estará vacío.
- Actualmente la API genera como máximo las primeras cinco URLs.
- Algunas URLs generadas podrían no existir porque todavía no se conoce la cantidad real de fotografías.
- El portal debe manejar el error de carga de una imagen y mostrar un placeholder.
- `video` y `tour_360` pueden ser `null`.

Ejemplo de estrategia visual:

~~~text
Si imagenes.length > 0
    intentar mostrar imagenes[0]
    si falla, mostrar placeholder
Si imagenes.length = 0
    mostrar placeholder
~~~

### Coordenadas

`latitud` y `longitud` son números, pero algunos registros heredados pueden contener `0`. El portal no debe mostrar un mapa cuando ambas coordenadas sean cero.

## 8. Filtros disponibles

`filtros_disponibles` permite construir la interfaz sin mantener catálogos duplicados en el portal.

Ejemplo:

~~~json
{
  "filtros_disponibles": {
    "tipo": [
      {
        "id": "CASA",
        "nombre": "Casa",
        "cantidad": 18
      },
      {
        "id": "DPTO",
        "nombre": "Departamento",
        "cantidad": 42
      }
    ],
    "ambientes": [
      {
        "id": 2,
        "nombre": "2",
        "cantidad": 20
      },
      {
        "id": 3,
        "nombre": "3",
        "cantidad": 15
      }
    ],
    "precios": {
      "venta": {
        "minimo": 45000,
        "maximo": 750000
      },
      "alquiler": {
        "minimo": 300000,
        "maximo": 1800000
      }
    }
  }
}
~~~

Los grupos disponibles son:

- `localidad`
- `partido`
- `provincia`
- `pais`
- `antiguedad`
- `comercializacion`
- `vista`
- `orientacion`
- `tipo`
- `cochera`
- `ambientes`
- `moneda_venta`
- `moneda_alquiler`
- `precios`

Cada opción contiene:

~~~ts
type AvailableFilterOption = {
  id: string | number;
  nombre: string;
  cantidad: number;
};
~~~

Los filtros disponibles reflejan el resultado después de aplicar todos los filtros actuales. Por ejemplo, si se selecciona `tipo=Departamento`, las localidades disponibles serán solamente aquellas donde existan departamentos dentro del resultado.

Actualmente el propio grupo seleccionado también queda restringido. Si se aplica `tipo=Departamento`, `filtros_disponibles.tipo` normalmente contendrá únicamente `Departamento`.

### Autocompletado de ubicación

Para un buscador interactivo debe utilizarse el endpoint especializado:

~~~http
GET /ubicaciones?q=Ramos&limit=10
Authorization: Bearer TOKEN
Accept: application/json
~~~

Busca parcialmente en localidad, partido, provincia y país. Cada palabra escrita debe aparecer en alguno de esos niveles. Solo devuelve ubicaciones que tengan propiedades del broker autenticado; `Z999` puede consultar todas las inmobiliarias.

~~~json
{
  "data": [
    {
      "etiqueta": "Buenos Aires, La Matanza, Ramos Mejía",
      "pais": { "id": "ARG", "nombre": "Argentina" },
      "provincia": { "id": "BUE", "nombre": "Buenos Aires" },
      "partido": { "id": 13, "nombre": "La Matanza" },
      "localidad": { "id": 67, "nombre": "Ramos Mejía" },
      "cantidad_propiedades": 84,
      "filtro": {
        "parametro": "idLocalidad",
        "valor": 67
      }
    }
  ],
  "meta": {
    "busqueda": "Ramos",
    "cantidad": 1,
    "limite": 10,
    "hay_mas": false
  }
}
~~~

Reglas:

- `q` es opcional y acepta entre 2 y 100 caracteres.
- Con `q` y sin `limit`, el máximo predeterminado es 10.
- `limit` admite entre 1 y 100.
- Sin `q` ni `limit`, devuelve todas las ubicaciones disponibles.
- `meta.hay_mas` indica si existen más coincidencias que el límite solicitado.
- Al seleccionar una sugerencia, aplicar `filtro.parametro` y `filtro.valor` al listado.

Ejemplo de selección:

~~~text
Sugerencia elegida: Buenos Aires, La Matanza, Ramos Mejía
Filtro recibido: idLocalidad = 67
Nueva consulta: GET /bienesraices?idLocalidad=67&page=1
~~~

Cliente sugerido:

~~~ts
type LocationSuggestion = {
  etiqueta: string;
  pais: CatalogValue;
  provincia: CatalogValue;
  partido: CatalogValue;
  localidad: CatalogValue;
  cantidad_propiedades: number;
  filtro: {
    parametro: "idLocalidad";
    valor: string | number;
  };
};

async function searchLocations(
  text: string,
  signal?: AbortSignal,
): Promise<LocationSuggestion[]> {
  const normalized = text.trim();

  if (normalized.length < 2) {
    return [];
  }

  const token = await getApiToken();
  const params = new URLSearchParams({
    q: normalized,
    limit: "10",
  });

  const response = await fetch(
    process.env.VISUALGESTION_API_URL +
      "/ubicaciones?" +
      params.toString(),
    {
      headers: {
        Authorization: "Bearer " + token,
        Accept: "application/json",
      },
      signal,
      cache: "no-store",
    },
  );

  if (!response.ok) {
    throw await createApiError(response);
  }

  const result = (await response.json()) as {
    data: LocationSuggestion[];
  };

  return result.data;
}
~~~

En la interfaz se recomienda comenzar desde dos caracteres, aplicar un debounce cercano a 300 ms y cancelar con `AbortController` la solicitud anterior cuando el usuario continúa escribiendo.

## 9. Filtros aplicados

`filtros_aplicados` devuelve los parámetros activos en un formato conveniente para etiquetas o chips eliminables.

Solicitud:

~~~http
GET /bienesraices?tipo=Departamento,Casa&ambientes=2,3&precio_venta_desde=80000&page=1
~~~

Respuesta parcial:

~~~json
{
  "filtros_aplicados": {
    "tipo": ["Departamento", "Casa"],
    "ambientes": [2, 3],
    "precio_venta_desde": 80000
  }
}
~~~

`page` y `per_page` no aparecen porque no son filtros de búsqueda.

Cuando no hay filtros:

~~~json
{
  "filtros_aplicados": {}
}
~~~

Para eliminar un filtro completo, quitar su clave de los parámetros y volver siempre a la página 1.

Para eliminar un único valor, quitarlo del array; si el array queda vacío, eliminar el parámetro completo.

Ejemplo:

~~~ts
type AppliedFilters = Record<string, string[] | number[] | number>;

function removeAppliedFilterValue(
  current: AppliedFilters,
  parameter: string,
  value?: string | number,
): AppliedFilters {
  const next = { ...current };
  const currentValue = next[parameter];

  if (value === undefined || !Array.isArray(currentValue)) {
    delete next[parameter];
    return next;
  }

  const remaining = currentValue.filter(
    (item) => String(item) !== String(value),
  );

  if (remaining.length === 0) {
    delete next[parameter];
  } else {
    next[parameter] = remaining;
  }

  return next;
}
~~~

## 10. Parámetros de búsqueda

### Filtros legibles recomendados

| Parámetro | Tipo | Ejemplo |
|---|---|---|
| `localidad` | Nombres separados por coma | `Ramos Mejía,Haedo` |
| `partido` | Nombres separados por coma | `La Matanza` |
| `provincia` | Nombres separados por coma | `Buenos Aires` |
| `pais` | Nombres separados por coma | `Argentina` |
| `antiguedad` | Nombres separados por coma | `A Estrenar,Menor a 10` |
| `comercializacion` | Nombres separados por coma | `Venta,Alquiler` |
| `vista` | Nombres separados por coma | `Al Frente` |
| `orientacion` | Nombres separados por coma | `Norte,Este` |
| `tipo` | Nombres separados por coma | `Departamento,Casa` |
| `cochera` | Nombres separados por coma | `Cochera Cubierta` |
| `ambientes` | Enteros positivos separados por coma | `2,3,4` |
| `moneda_venta` | Nombre o símbolo | `Dolares` o `u$s` |
| `moneda_alquiler` | Nombre o símbolo | `Pesos` o `$` |

Al filtrar por `Venta` o `Alquiler`, la API incluye automáticamente las propiedades `A-V`.

### Precios

| Parámetro | Función |
|---|---|
| `ImporteVta` | Precio exacto de venta |
| `ImporteAlq` | Precio exacto de alquiler |
| `precio_venta_desde` | Precio mínimo de venta |
| `precio_venta_hasta` | Precio máximo de venta |
| `precio_alquiler_desde` | Precio mínimo de alquiler |
| `precio_alquiler_hasta` | Precio máximo de alquiler |

Los límites pueden utilizarse individualmente. Si se envían ambos, `hasta` debe ser mayor o igual que `desde`.

Los precios no se convierten entre monedas. Si se aplica un rango, se recomienda seleccionar también `moneda_venta` o `moneda_alquiler`.

### Filtros técnicos compatibles

También se admiten:

- `idLocalidad`
- `idPartido`
- `idProvincia`
- `idPais`
- `Antiguedad`
- `IdComercializacion`
- `IdVista`
- `idOrientacion`
- `idTipologia`
- `idcochera`
- `idTipoMonedaAlq`
- `idTipoMonedaVta`

Para una nueva interfaz se recomienda utilizar los filtros legibles y reservar los técnicos para integraciones específicas.

### Paginación

| Parámetro | Regla |
|---|---|
| `page` | Entero desde 1 |
| `per_page` | Entero entre 1 y 100; valor predeterminado 20 |

## 11. Construcción segura de la URL

Debe utilizarse `URLSearchParams` o la herramienta equivalente del framework. No concatenar manualmente valores que puedan contener espacios, acentos o símbolos.

~~~ts
type SearchFilters = {
  localidad?: string[];
  partido?: string[];
  provincia?: string[];
  pais?: string[];
  antiguedad?: string[];
  comercializacion?: string[];
  vista?: string[];
  orientacion?: string[];
  tipo?: string[];
  cochera?: string[];
  ambientes?: number[];
  moneda_venta?: string[];
  moneda_alquiler?: string[];
  precio_venta_desde?: number;
  precio_venta_hasta?: number;
  precio_alquiler_desde?: number;
  precio_alquiler_hasta?: number;
  page?: number;
  per_page?: number;
};

function buildPropertyQuery(filters: SearchFilters): string {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null) {
      continue;
    }

    if (Array.isArray(value)) {
      if (value.length > 0) {
        params.set(key, value.join(","));
      }
      continue;
    }

    params.set(key, String(value));
  }

  return params.toString();
}
~~~

Ejemplo:

~~~ts
const query = buildPropertyQuery({
  tipo: ["Departamento", "Casa"],
  ambientes: [2, 3],
  comercializacion: ["Venta"],
  provincia: ["Buenos Aires"],
  precio_venta_desde: 80000,
  precio_venta_hasta: 150000,
  page: 1,
  per_page: 20,
});
~~~

El navegador generará correctamente los espacios, acentos y símbolos.

## 12. Cliente para consultar el listado

~~~ts
async function getProperties(
  filters: SearchFilters,
): Promise<PropertiesResponse> {
  const token = await getApiToken();
  const query = buildPropertyQuery(filters);
  const url =
    process.env.VISUALGESTION_API_URL +
    "/bienesraices" +
    (query ? "?" + query : "");

  let response = await fetch(url, {
    headers: {
      Authorization: "Bearer " + token,
      Accept: "application/json",
    },
    cache: "no-store",
  });

  if (response.status === 401) {
    cachedToken = null;
    const renewedToken = await getApiToken();

    response = await fetch(url, {
      headers: {
        Authorization: "Bearer " + renewedToken,
        Accept: "application/json",
      },
      cache: "no-store",
    });
  }

  if (!response.ok) {
    throw await createApiError(response);
  }

  return (await response.json()) as PropertiesResponse;
}
~~~

## 13. Ficha individual

La identidad estable de una propiedad es la combinación de `id_broker` e `id`.

~~~http
GET /bienesraices/A004/585
Authorization: Bearer TOKEN
Accept: application/json
~~~

Respuesta:

~~~json
{
  "data": {
    "id": 585,
    "id_broker": "A004",
    "direccion": {},
    "ubicacion": {},
    "caracteristicas": {},
    "comercializacion": {},
    "precios": {},
    "multimedia": {},
    "broker": {}
  }
}
~~~

Un token normal solo puede solicitar fichas de su propio broker. `Z999` puede solicitar cualquier broker.

Si la propiedad no existe o pertenece a otro broker:

~~~http
HTTP/1.1 404 Not Found
~~~

~~~json
{
  "message": "La propiedad no existe."
}
~~~

Ejemplo:

~~~ts
async function getProperty(
  brokerId: string,
  propertyId: string | number,
): Promise<Property> {
  const token = await getApiToken();
  const path =
    "/bienesraices/" +
    encodeURIComponent(brokerId) +
    "/" +
    encodeURIComponent(String(propertyId));

  const response = await fetch(
    process.env.VISUALGESTION_API_URL + path,
    {
      headers: {
        Authorization: "Bearer " + token,
        Accept: "application/json",
      },
      cache: "no-store",
    },
  );

  if (response.status === 404) {
    throw new Error("PROPERTY_NOT_FOUND");
  }

  if (!response.ok) {
    throw await createApiError(response);
  }

  const result = (await response.json()) as { data: Property };
  return result.data;
}
~~~

`BienesraicesDetalleResource` hereda actualmente la estructura del listado. Está separado para poder ampliar la ficha en el futuro sin aumentar el peso de las tarjetas.

## 14. Envío de consultas

El formulario de contacto de una ficha debe enviar sus datos al backend del portal. Ese backend agrega el Bearer token y llama a la API; el token no debe exponerse en el navegador.

~~~http
POST /consultas
Authorization: Bearer TOKEN
Content-Type: application/json
Accept: application/json
~~~

~~~json
{
  "id_broker": "A004",
  "id_bienes": 585,
  "nombre": "Juan Pérez",
  "email": "juan@example.com",
  "telefono": "+54 11 4444-5555",
  "mensaje": "Quisiera coordinar una visita a la propiedad."
}
~~~

`telefono` es opcional. `nombre` admite entre 2 y 100 caracteres, `email` debe ser válido y `mensaje` admite entre 10 y 2000 caracteres. Por compatibilidad también se aceptan `idBroker` e `idBienes`, aunque se recomienda usar `snake_case`.

La API busca la propiedad aplicando las mismas reglas de aislamiento que la ficha. El cliente nunca envía el destinatario: se utiliza el campo `Email` del broker propietario de la propiedad. El email del interesado queda como `Reply-To`, de modo que la inmobiliaria pueda responderle directamente sin falsificar el remitente SMTP.

Respuesta correcta:

~~~http
HTTP/1.1 202 Accepted
~~~

~~~json
{
  "message": "La consulta fue enviada correctamente.",
  "data": {
    "id_broker": "A004",
    "id_bienes": 585
  }
}
~~~

Cliente recomendado en el servidor del portal:

~~~ts
type PropertyInquiryInput = {
  id_broker: string;
  id_bienes: string | number;
  nombre: string;
  email: string;
  telefono?: string;
  mensaje: string;
};

async function sendPropertyInquiry(input: PropertyInquiryInput): Promise<void> {
  const token = await getApiToken();
  const response = await fetch(
    process.env.VISUALGESTION_API_URL + "/consultas",
    {
      method: "POST",
      headers: {
        Authorization: "Bearer " + token,
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(input),
      cache: "no-store",
    },
  );

  if (!response.ok) {
    throw await createApiError(response);
  }
}
~~~

El portal debe impedir envíos duplicados mientras la solicitud está en curso y mostrar un mensaje claro para `422`, `429` y `503`. Antes de producción también conviene agregar CAPTCHA o una solución equivalente en el formulario público del portal.

## 15. URLs públicas y SEO

La API no utiliza slugs como identidad. El portal sí puede construir una URL pública amigable:

~~~text
/propiedades/departamento-3-ambientes-haedo-A004-585
~~~

El portal debe conservar dentro de esa URL, en parámetros o en metadatos, los identificadores reales:

~~~text
idBroker = A004
idBienes = 585
~~~

Luego debe consultar:

~~~http
GET /bienesraices/A004/585
~~~

No se debe buscar la propiedad en la API solamente por el slug, porque no existe un slug persistente y único en la base actual.

Para SEO se recomienda renderizado del lado servidor, metadatos dinámicos, URL canónica y datos estructurados de propiedad cuando el framework elegido lo permita.

## 16. Tipos TypeScript sugeridos

~~~ts
type Broker = {
  id: string;
  razon_social: string | null;
  email: string | null;
  telefono: string | null;
  telefonos: string | null;
  celular: string | null;
  direccion: string | null;
  localidad: string | null;
  partido: string | null;
  provincia: string | null;
  pais: string | null;
  codigo_postal: string | null;
  latitud: number | null;
  longitud: number | null;
  web: string | null;
  matricula: string | null;
};

type Price = {
  importe: number | null;
  moneda: CatalogValue;
  simbolo: string | null;
};

type Property = {
  id: number;
  id_broker: string;
  direccion: {
    calle: string | null;
    numero: number | null;
    piso: string | null;
    torre: string | null;
    barrio: string | null;
  };
  descripcion: string | null;
  ubicacion: {
    localidad: CatalogValue;
    partido: CatalogValue;
    provincia: CatalogValue;
    pais: CatalogValue;
    latitud: number;
    longitud: number;
  };
  caracteristicas: {
    antiguedad: CatalogValue;
    luminosidad: string | null;
    plantas: number;
    frente_m: number;
    fondo_m: number;
    fondo_libre_m: number;
    superficie_cubierta_m2: number;
    superficie_terreno_m2: number;
    ambientes: number;
    banos: number;
    suites: number;
    dormitorios: number;
    lineas_telefonicas: number;
    vista: CatalogValue;
    uso: CatalogValue;
    orientacion: CatalogValue;
    tipologia: CatalogValue;
    cochera: CatalogValue;
  };
  comercializacion: CatalogValue & {
    permite_venta: boolean;
    permite_alquiler: boolean;
  };
  precios: {
    visible: boolean;
    texto: string | null;
    venta: Price;
    alquiler: Price;
  };
  multimedia: {
    imagenes: string[];
    video: string | null;
    tour_360: string | null;
  };
  broker: Broker;
};

type AppliedFilters = Record<string, string[] | number[] | number>;

type AvailableFilters = {
  localidad: AvailableFilterOption[];
  partido: AvailableFilterOption[];
  provincia: AvailableFilterOption[];
  pais: AvailableFilterOption[];
  antiguedad: AvailableFilterOption[];
  comercializacion: AvailableFilterOption[];
  vista: AvailableFilterOption[];
  orientacion: AvailableFilterOption[];
  tipo: AvailableFilterOption[];
  cochera: AvailableFilterOption[];
  ambientes: AvailableFilterOption[];
  moneda_venta: AvailableFilterOption[];
  moneda_alquiler: AvailableFilterOption[];
  precios: {
    venta: { minimo: number | null; maximo: number | null };
    alquiler: { minimo: number | null; maximo: number | null };
  };
};

type PropertiesResponse = {
  data: Property[];
  meta: {
    pagina_actual: number;
    por_pagina: number;
    total: number;
    ultima_pagina: number;
  };
  links: {
    primera: string;
    ultima: string;
    anterior: string | null;
    siguiente: string | null;
  };
  filtros_aplicados: AppliedFilters;
  filtros_disponibles: AvailableFilters;
};
~~~

Aunque `IdBienes` es `double` en la base heredada, la mayoría de los valores se serializan como enteros. El cliente debe tratarlo como `number` y no generar por su cuenta un identificador global: siempre debe combinarlo con `id_broker`.

## 17. Manejo de errores

Estados esperables:

| Estado | Significado | Acción recomendada |
|---|---|---|
| `200` | Solicitud correcta | Renderizar los datos |
| `202` | Consulta aceptada y enviada | Confirmar el envío y limpiar el formulario |
| `401` | Token ausente, inválido, vencido o broker deshabilitado | Renovar una vez el token; si vuelve a fallar, registrar el problema |
| `404` | Ficha inexistente o ajena | Mostrar página de propiedad no encontrada |
| `422` | Parámetros inválidos | Mostrar/corregir filtros y registrar `errors` |
| `429` | Límite de solicitudes excedido | Esperar y reintentar con backoff |
| `503` | Servicio de correo temporalmente no disponible | Conservar el formulario y permitir reintentar más tarde |
| `500` | Error de servidor o base | Mostrar estado temporal y registrar el incidente |

Ejemplo de error `422`:

~~~json
{
  "message": "El campo ambientes debe contener números enteros positivos separados por coma.",
  "errors": {
    "ambientes": [
      "El campo ambientes debe contener números enteros positivos separados por coma."
    ]
  }
}
~~~

Helper sugerido:

~~~ts
class VisualGestionApiError extends Error {
  constructor(
    public status: number,
    public payload: unknown,
  ) {
    super("VisualGestion API respondió con estado " + status);
  }
}

async function createApiError(
  response: Response,
): Promise<VisualGestionApiError> {
  const body = await response.text();
  let payload: unknown = body;

  try {
    payload = body ? JSON.parse(body) : null;
  } catch {}

  return new VisualGestionApiError(response.status, payload);
}
~~~

No realizar reintentos infinitos. Para `401` se recomienda renovar el token una sola vez. Para `429` y errores temporales, utilizar backoff con un máximo definido.

## 18. Estado de filtros y navegación

Se recomienda que la URL pública del listado sea la fuente de verdad:

~~~text
/propiedades?tipo=Departamento&ambientes=2,3&page=1
~~~

Ventajas:

- Permite compartir búsquedas.
- Conserva los filtros al recargar.
- Facilita volver desde una ficha.
- Mejora navegación y SEO.

Cada vez que se agrega o elimina un filtro:

1. Actualizar los parámetros de la URL.
2. Eliminar `page` o establecerlo en `1`.
3. Solicitar nuevamente `GET /bienesraices`.
4. Reemplazar propiedades, filtros aplicados y filtros disponibles con la nueva respuesta.

No aplicar filtros adicionales solamente en el navegador sobre la página actual. La API debe volver a consultarse para que paginación, cantidades y opciones disponibles sean correctas.

## 19. Componentes sugeridos

Independientemente del framework, una separación práctica sería:

~~~text
PropertySearchPage
├── SearchSummary
│   ├── TotalResults
│   └── AppliedFilterChips
├── PropertyFilters
│   ├── LocationAutocomplete
│   ├── OperationFilter
│   ├── PropertyTypeFilter
│   ├── LocationFilters
│   ├── RoomsFilter
│   ├── GarageFilter
│   └── PriceRangeFilter
├── PropertyGrid
│   └── PropertyCard
└── Pagination

PropertyDetailPage
├── PropertyGallery
├── PropertyHeader
├── PriceBlock
├── FeatureList
├── Description
├── Map
├── VideoOrTour
└── BrokerContact
    └── PropertyInquiryForm
~~~

Las tarjetas deben usar `id_broker` e `id` para construir el enlace a la ficha.

## 20. Rendimiento y caché

- No cachear el token más allá de `expires_at`.
- Una consulta de listado calcula paginación y múltiples grupos de filtros; evitar repetirla innecesariamente durante una misma renderización.
- Aplicar debounce a campos que disparen búsquedas frecuentes.
- Las selecciones cerradas, como tipología o ambientes, pueden ejecutar la búsqueda inmediatamente.
- Una caché corta del lado del portal puede ser útil para páginas públicas, siempre separada por broker y por query string completa.
- No compartir respuestas de un broker normal con otro.
- Con `Z999`, cada propiedad ya incluye su broker correspondiente.

## 21. CORS y forma de consumo

El consumo servidor a servidor no necesita CORS y es la opción recomendada.

Si se decide llamar a la API directamente desde el navegador:

- Será necesario configurar en la API el origen permitido del portal.
- El Bearer token será visible para el navegador.
- Nunca se debe enviar `PwdWS` al frontend.
- Se debe evaluar cuidadosamente el almacenamiento del token.

Antes de optar por consumo directo, confirmar y configurar explícitamente la política CORS del entorno de despliegue.

## 22. Limitaciones actuales conocidas

- `PwdWS` sigue almacenado en texto plano en el sistema heredado.
- Los tokens son stateless; actualmente no existe una tabla de revocación.
- Cambiar `APP_KEY` invalida todos los tokens.
- La ficha devuelve por ahora la misma estructura base que cada elemento del listado.
- Se generan hasta cinco URLs de imágenes sin conocer aún la cantidad real.
- Los rangos de precios no convierten monedas.
- Los filtros disponibles se recalculan con todos los filtros aplicados, incluido el filtro de su propio grupo.
- No existe búsqueda por slug en la API.
- Las consultas se envían por email, pero no se guardan en una tabla ni poseen seguimiento de estado.

Estas limitaciones no deben resolverse silenciosamente desde el portal. Si alguna bloquea una funcionalidad, debe acordarse primero un cambio en el contrato de la API.

## 23. Secuencia recomendada de construcción

1. Crear el proyecto del portal y configurar variables de entorno privadas.
2. Implementar el cliente servidor de autenticación y renovación.
3. Obtener el perfil público del broker para encabezado, pie y contacto.
4. Definir los tipos del contrato.
5. Implementar el cliente de listado.
6. Mostrar tarjetas y paginación.
7. Implementar el autocompletado mediante `/ubicaciones`.
8. Construir los demás controles desde `filtros_disponibles`.
9. Sincronizar filtros con la URL.
10. Implementar chips desde `filtros_aplicados`.
11. Implementar la ficha con `idBroker` e `idBienes`.
12. Agregar manejo de precios ocultos y operaciones `A-V`.
13. Agregar galería con fallback, video y tour 360.
14. Agregar mapa solamente para coordenadas válidas.
15. Implementar el formulario de consulta desde el backend del portal.
16. Implementar estados de carga, vacío, error, 404 y límite de solicitudes.
17. Incorporar SSR/SEO y URLs amigables del portal.
18. Agregar pruebas de integración contra la API.

## 24. Casos mínimos de prueba del portal

- Autenticación correcta.
- Renovación de token después de un `401`.
- Perfil propio del broker y perfil ajeno rechazado.
- Portal global obteniendo perfiles de distintas inmobiliarias.
- Listado sin filtros.
- Listado vacío.
- Paginación y conservación de query string.
- Filtro con un valor.
- Filtro con múltiples valores separados por coma.
- Autocompletado por localidad, partido y provincia.
- Cancelación de una búsqueda de ubicación anterior al seguir escribiendo.
- Eliminación de un valor aplicado.
- Eliminación de un filtro completo.
- Reinicio de página al cambiar filtros.
- Propiedad `A-V` mostrando ambas operaciones.
- Propiedad con `NoPPI` mostrando `Consultar`.
- Propiedad sin imágenes.
- Imagen generada que responde con error.
- Video y tour 360 nulos o presentes.
- Coordenadas iguales a cero.
- Ficha válida.
- Ficha inexistente.
- Acceso de un broker normal únicamente a sus propiedades.
- Portal global `Z999` mostrando brokers diferentes.
- Errores `422` y `429`.
- Consulta enviada correctamente desde una ficha.
- Consulta inválida, duplicada o rechazada por límite de frecuencia.
- Falla temporal de correo (`503`) sin mostrar datos técnicos al visitante.

## 25. Criterio de finalización

El portal puede considerarse correctamente integrado cuando:

- Ninguna credencial `PwdWS` llega al navegador.
- El token se renueva sin intervención del usuario.
- Los listados respetan la paginación de la API.
- Los filtros se construyen desde `filtros_disponibles`.
- Los filtros aplicados pueden eliminarse individualmente.
- El estado de búsqueda se conserva en la URL.
- Las fichas usan `id_broker` + `id`.
- Los precios ocultos nunca se muestran.
- Las operaciones `A-V` se interpretan correctamente.
- Las consultas se envían desde el backend del portal sin exponer el Bearer token.
- Todas las imágenes tienen fallback.
- Los estados `401`, `404`, `422`, `429`, `500` y `503` tienen tratamiento visible o registrable.

## 26. Referencias del backend

Dentro del proyecto VisualGestion API:

- `README.md`: puesta en marcha y resumen del contrato.
- `CONTEXTO.md`: decisiones y estado del backend.
- `public/openapi.yaml`: contrato OpenAPI.
- `resources/views/docs.blade.php`: Swagger UI.
- `routes/api.php`: rutas activas.
- `app/Http/Controllers/Api/BienesraicesController.php`: armado de respuestas.
- `app/Repositories/BienesraicesRepository.php`: consultas y filtros.
- `app/Http/Resources/BienesraicesResource.php`: formato de una propiedad.
- `app/Http/Resources/BienesraicesDetalleResource.php`: extensión de la ficha.
- `app/Http/Controllers/Api/UbicacionesController.php`: endpoint de autocompletado.
- `app/Repositories/UbicacionesRepository.php`: búsqueda de ubicaciones disponibles.
- `app/Http/Resources/UbicacionResource.php`: formato de las sugerencias.
- `app/Http/Controllers/Api/BrokersController.php`: endpoint del perfil público.
- `app/Repositories/BrokersRepository.php`: acceso controlado a brokers habilitados.
- `app/Http/Resources/BrokerResource.php`: contrato público del broker.
- `app/Http/Controllers/Api/ConsultasController.php`: envío y respuestas de consultas.
- `app/Http/Requests/ConsultaStoreRequest.php`: validación de los datos del interesado.
- `app/Mail/ConsultaPropiedadMail.php`: estructura del mensaje y `Reply-To`.
- `Dockerfile` y `compose.yaml`: ejecución de la API en un contenedor de producción.
- `.env.docker.example`: configuración del contenedor sin secretos.
- `DOCKER.md`: procedimiento completo de despliegue en VPS.

Antes de desarrollar contra una API desplegada, abrir `/docs` o consultar `/openapi.yaml` para comprobar que el contrato no haya cambiado.

## 27. Instrucción breve para retomar en otra sesión

El objetivo es construir un portal inmobiliario consumidor de VisualGestion API. Antes de escribir código:

1. Leer este archivo completo.
2. Revisar el framework y estructura del proyecto del portal.
3. Confirmar la URL de la API y si el portal será de un broker o global con `Z999`.
4. Mantener `IdBroker` y `PwdWS` exclusivamente del lado servidor.
5. Implementar primero el cliente tipado de la API y después la interfaz.
6. No modificar el contrato ni asumir campos que no estén documentados.
7. Usar `id_broker` + `id` como identidad estable de cada propiedad.
8. Enviar `/consultas` desde el servidor del portal y no exponer el Bearer token.
