# VisualGestion API

API REST desarrollada con Laravel 12 para publicar las propiedades de la base existente de VisualGestion y enviar consultas por email. No crea ni modifica tablas y no necesita migraciones ni seeders.

## Requisitos

- PHP 8.2 o superior con PDO MySQL y mbstring.
- Composer.
- MySQL en `127.0.0.1:3306`.
- Base `vgestion-api-2`, usuario `root` y contraseña vacía (valores de desarrollo solicitados).

## Puesta en marcha

1. Si la base todavía no existe, crear `vgestion-api-2` e importar [material/database.sql](material/database.sql).
2. Ejecutar `composer install`.
3. Copiar `.env.example` a `.env` si no existe y ejecutar `php artisan key:generate`.
4. Revisar las variables `DB_*` del `.env`.
5. Configurar las variables `MAIL_*` para habilitar el envío de consultas.
6. Ejecutar `php artisan config:clear` y `php artisan serve`.

La API quedará normalmente en `http://127.0.0.1:8000`. Swagger interactivo está en `http://127.0.0.1:8000/docs`.

No se debe ejecutar `php artisan migrate`: la API trabaja directamente con el esquema provisto.

## Docker

La aplicación incluye una imagen de producción con Apache y PHP 8.4, un `compose.yaml`, healthcheck y configuración externa de secretos. La guía completa para construirla y desplegarla en un VPS está en [DOCKER.md](DOCKER.md).

Inicio resumido:

~~~bash
cp .env.docker.example .env.docker
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker run --rm api php artisan key:generate --show
# Copiar la clave resultante a APP_KEY en .env.docker
docker compose --env-file .env.docker up -d
~~~

El puerto predeterminado es `127.0.0.1:8080`, pensado para utilizar un proxy inverso con HTTPS. MySQL permanece externo y no se ejecutan migraciones.

## Autenticación

### `POST /login`

Recibe las credenciales existentes en `mae_brokers`:

```json
{
  "id_broker": "A004",
  "password": "SU_PWDWS"
}
```

Por compatibilidad también acepta `IdBroker` y `PwdWS`. El broker debe existir, estar habilitado (`Hab = 1`) y tener `PwdWS` no vacío.

La respuesta contiene un token stateless cifrado y firmado mediante `APP_KEY`, con una duración predeterminada de 24 horas. Puede configurarse con `API_TOKEN_TTL` (segundos). El token no incluye la contraseña.

```json
{
  "data": {
    "token": "...",
    "token_type": "Bearer",
    "expires_in": 86400,
    "expires_at": "2026-09-09T12:00:00-03:00",
    "broker": {}
  }
}
```

## Perfil público de una inmobiliaria

### `GET /brokers/{idBroker}`

Devuelve los datos públicos de un broker habilitado:

```http
GET /brokers/A004
Authorization: Bearer TOKEN_OBTENIDO_EN_LOGIN
Accept: application/json
```

```json
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
```

Un broker normal solo puede obtener su propio perfil. `Z999` puede consultar cualquier broker habilitado. Un broker inexistente, deshabilitado o ajeno devuelve `404`. Nunca se publican `PwdWS`, `Hab`, límites, relaciones entre sucursales ni otros campos administrativos.

## Consulta de propiedades

### `GET /bienesraices`

Requiere:

```http
Authorization: Bearer TOKEN_OBTENIDO_EN_LOGIN
Accept: application/json
```

Un token normal obtiene exclusivamente filas cuyo `IdBroker` coincide con el token. El broker especial `Z999` puede obtener propiedades de todas las inmobiliarias.

La respuesta contiene:

- `data`: propiedades, catálogos descriptivos, precios, multimedia y broker.
- `meta` y `links`: paginación.
- `filtros_aplicados`: parámetros de búsqueda activos, normalizados para poder mostrarlos o eliminarlos desde el portal.
- `filtros_disponibles`: opciones que siguen presentes en el resultado filtrado, con identificador, nombre y cantidad, más rangos de precios visibles.

`per_page` es 20 por defecto y admite hasta 100 resultados.

Por ejemplo, una consulta con `?tipo=Departamento,Casa&ambientes=2,3&precio_venta_desde=80000` incluye:

```json
{
  "filtros_aplicados": {
    "tipo": ["Departamento", "Casa"],
    "ambientes": [2, 3],
    "precio_venta_desde": 80000
  }
}
```

`page` y `per_page` no se consideran filtros de búsqueda y por eso no aparecen en este objeto. Si no se aplicó ningún filtro, se devuelve un objeto vacío: `{}`.

### Filtros legibles

Los filtros de texto aceptan uno o varios nombres separados por coma:

| Parámetro | Ejemplo |
|---|---|
| `localidad` | `Ramos Mejía,Haedo` |
| `partido` | `La Matanza` |
| `provincia` | `Buenos Aires` |
| `pais` | `Argentina` |
| `antiguedad` | `A Estrenar,Menor a 10` |
| `comercializacion` | `Venta,Alquiler` |
| `vista` | `Al Frente` |
| `orientacion` | `Norte,Este` |
| `tipo` | `Departamento,Casa` |
| `cochera` | `Cochera Cubierta` |
| `ambientes` | `2,3,4` |
| `moneda_venta` | `Dolares` o `u$s` |
| `moneda_alquiler` | `Pesos` o `$` |

Ejemplo:

```http
GET /bienesraices?tipo=Departamento,Casa&ambientes=2,3&provincia=Buenos%20Aires&comercializacion=Venta&per_page=30
```

Al filtrar por `Venta` o `Alquiler` también se incluyen propiedades con `IdComercializacion = A-V`.

### Precios

- Exactos: `ImporteVta`, `ImporteAlq`.
- Venta: `precio_venta_desde`, `precio_venta_hasta`.
- Alquiler: `precio_alquiler_desde`, `precio_alquiler_hasta`.

Cuando `NoPPI = 1`, los importes son `null`, `precios.visible` es `false` y `precios.texto` contiene `Consultar`. La moneda y el símbolo provienen de `tip_tipomoneda`.

### Compatibilidad con identificadores

También pueden usarse los filtros técnicos originales, con varios IDs separados por coma: `idLocalidad`, `idPartido`, `idProvincia`, `idPais`, `Antiguedad`, `IdComercializacion`, `IdVista`, `idOrientacion`, `idTipologia`, `idcochera`, `idTipoMonedaAlq` e `idTipoMonedaVta`.

### Multimedia

- Si `TieneFoto = 1`, se generan las primeras cinco URLs con la estructura indicada.
- Si `TieneVideo = 1`, se devuelve `UrlVideo`.
- Si `Tiene360 = 1`, se devuelve `URL360`.

Los campos descartados (`VeoTot`/`VeoTop`, `VeoVis`, `Visitas`, `PropDestacada`, `idEmpren` y `TituloPortales`) no se seleccionan ni se publican.

## Autocompletado de ubicaciones

### `GET /ubicaciones`

Devuelve solamente ubicaciones que tengan propiedades para el broker autenticado. `Z999` obtiene ubicaciones de todas las inmobiliarias.

Para buscar parcialmente en localidad, partido, provincia o país:

```http
GET /ubicaciones?q=Ramos&limit=10
Authorization: Bearer TOKEN_OBTENIDO_EN_LOGIN
```

`q` es opcional y, cuando se informa, requiere entre 2 y 100 caracteres. Sin `q` ni `limit` se devuelven todas las ubicaciones disponibles. Con `q`, el límite predeterminado es 10; `limit` admite entre 1 y 100.

```json
{
  "data": [
    {
      "etiqueta": "Buenos Aires, La Matanza, Ramos Mejía",
      "pais": { "id": "ARG", "nombre": "Argentina" },
      "provincia": { "id": "BUE", "nombre": "Buenos Aires" },
      "partido": { "id": 13, "nombre": "La Matanza" },
      "localidad": { "id": 67, "nombre": "Ramos Mejía" },
      "cantidad_propiedades": 84,
      "filtro": { "parametro": "idLocalidad", "valor": 67 }
    }
  ],
  "meta": {
    "busqueda": "Ramos",
    "cantidad": 1,
    "limite": 10,
    "hay_mas": false
  }
}
```

Al seleccionar una sugerencia, el portal debe utilizar `filtro.parametro` y `filtro.valor`, por ejemplo `/bienesraices?idLocalidad=67`.

## Ficha de una propiedad

### `GET /bienesraices/{idBroker}/{idBienes}`

Devuelve una propiedad identificada por la clave compuesta real de `mae_bienesraices`:

```http
GET /bienesraices/A004/585
Authorization: Bearer TOKEN_OBTENIDO_EN_LOGIN
```

Un broker normal solamente puede solicitar fichas cuyo `idBroker` coincida con el broker de su token. `Z999` puede solicitar fichas de cualquier broker. Tanto una propiedad inexistente como una propiedad ajena responden `404`, evitando revelar su existencia.

La ficha utiliza `BienesraicesDetalleResource`. Actualmente conserva la estructura pública de cada elemento del listado y queda separada para incorporar más datos de detalle en el futuro.

## Envío de consultas

### `POST /consultas`

Envía una consulta al email registrado en `mae_brokers` para la inmobiliaria propietaria. Requiere Bearer token y el broker normal solo puede consultar por propiedades propias; `Z999` puede hacerlo por cualquier propiedad.

```http
POST /consultas
Authorization: Bearer TOKEN_OBTENIDO_EN_LOGIN
Content-Type: application/json
Accept: application/json
```

```json
{
  "id_broker": "A004",
  "id_bienes": 585,
  "nombre": "Juan Pérez",
  "email": "juan@example.com",
  "telefono": "+54 11 4444-5555",
  "mensaje": "Quisiera coordinar una visita a la propiedad."
}
```

`telefono` es opcional. También se aceptan `idBroker` e `idBienes` por compatibilidad. La API obtiene el destinatario desde la base de datos; el cliente no puede indicarlo. El correo del interesado queda configurado como `Reply-To`.

Una respuesta correcta utiliza estado `202`:

```json
{
  "message": "La consulta fue enviada correctamente.",
  "data": {
    "id_broker": "A004",
    "id_bienes": 585
  }
}
```

La ruta admite 10 solicitudes por minuto y 100 por hora para cada combinación de broker e IP. Puede responder `404` si la propiedad no es accesible, `422` ante datos inválidos o si el broker no tiene email, `429` por exceso de solicitudes y `503` si falla SMTP.

Configuración SMTP esperada:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.titan.email
MAIL_PORT=465
MAIL_USERNAME=info@visualgestion.net
MAIL_PASSWORD=CLAVE_SMTP
MAIL_FROM_ADDRESS=info@visualgestion.net
MAIL_FROM_NAME="${APP_NAME}"
```

Después de cambiar estas variables se debe ejecutar `php artisan config:clear`.

## Pruebas

Las pruebas crean un esquema SQLite efímero y no alteran MySQL:

```bash
php artisan test
```

Cubren login, Bearer obligatorio, perfiles públicos de brokers, aislamiento por broker, acceso global de `Z999`, fichas individuales, autocompletado de ubicaciones, envío de consultas, filtros descriptivos, tratamiento de `A-V`, imágenes, moneda y ocultamiento por `NoPPI`.

## Seguridad actual

`PwdWS` se compara solamente durante el login y nunca se expone. Se conserva en texto plano porque así está en el sistema heredado. Antes de producción conviene migrar esas claves a hashes y definir revocación/rotación de tokens. Cambiar `APP_KEY` invalida todos los tokens emitidos.
