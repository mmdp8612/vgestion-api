# Contexto de VisualGestion API

## Objetivo actual

Exponer en Laravel 12 una API REST sobre la base MySQL existente `vgestion-api-2`, consultar propiedades y enviar consultas por email. La fuente del esquema y los datos de prueba es `material/database.sql`. No se agregaron migraciones ni seeders.

## Implementado

- `POST /login`: valida `IdBroker` y `PwdWS` en `mae_brokers`, exige `Hab = 1` y emite un Bearer token stateless cifrado/firmado con `APP_KEY`.
- Middleware `broker.auth`: valida integridad, vencimiento y vigencia del broker en cada consulta.
- `GET /bienesraices`: Query Builder, paginación y aislamiento obligatorio por el broker del token.
- `GET /bienesraices/{idBroker}/{idBienes}`: ficha individual mediante la clave compuesta; devuelve `404` para propiedades inexistentes o ajenas.
- `GET /ubicaciones`: autocompletado parcial sobre localidad, partido, provincia y país, limitado a ubicaciones con propiedades del broker autenticado.
- `GET /brokers/{idBroker}`: perfil público de un broker habilitado, con aislamiento normal y acceso global para `Z999`.
- `POST /consultas`: valida propiedad e interesado, obtiene el destinatario desde `mae_brokers` y envía el email con el interesado como `Reply-To`.
- El broker configurable `Z999` ve todas las propiedades.
- Joins con todos los catálogos indicados y con `mae_brokers`.
- `A-V` se interpreta como venta y alquiler.
- `NoPPI = 1` oculta importes; moneda y símbolo provienen de `tip_tipomoneda`.
- Hasta cinco imágenes, video y tour 360 según sus indicadores.
- No se consultan/publican `VeoTot` (mencionado como VeoTop), `VeoVis`, `Visitas`, `PropDestacada`, `idEmpren` ni `TituloPortales`.
- Filtros descriptivos multi-valor, cantidad de ambientes, IDs originales, precios exactos y rangos de venta/alquiler.
- Opciones de filtros todavía disponibles, cantidades y rangos de precios visibles en cada respuesta.
- Filtros aplicados normalizados en `filtros_aplicados`; no incluye `page` ni `per_page`.
- Swagger UI en `/docs`, basado en `/openapi.yaml`.
- Pruebas feature con SQLite en memoria para no tocar MySQL.
- Imagen Docker de producción con Apache, PHP 8.4, OPcache, healthcheck y secretos externos.

## Decisiones técnicas

- Rutas exactas `/login`, `/bienesraices`, `/bienesraices/{idBroker}/{idBienes}`, `/ubicaciones`, `/brokers/{idBroker}` y `/consultas`, sin prefijo `/api`.
- Sin Sanctum ni tablas de tokens: el token guarda versión, broker y timestamps cifrados por Laravel.
- TTL predeterminado: 86400 segundos (`API_TOKEN_TTL`).
- Login: 10 solicitudes/minuto por IP. Lecturas: 120/minuto por broker. Envío de consultas: 10/minuto y 100/hora por broker e IP.
- Paginación: 20 por defecto, máximo 100.
- Los filtros disponibles reflejan el conjunto después de aplicar todos los filtros actuales.
- Swagger UI carga `swagger-ui-dist@5` desde CDN; el contrato OpenAPI es local.
- El contenedor no incluye MySQL ni ejecuta migraciones; se conecta a la base existente.
- El puerto Docker se enlaza por defecto a `127.0.0.1:8080` para utilizar un proxy inverso.

## Archivos principales

- `routes/api.php`: endpoints.
- `app/Http/Controllers/Api/AuthController.php`: login.
- `app/Http/Controllers/Api/BienesraicesController.php`: listado y ficha.
- `app/Http/Middleware/AuthenticateBrokerToken.php`: Bearer.
- `app/Repositories/BienesraicesRepository.php`: queries, joins, filtros y facets.
- `app/Http/Resources/BienesraicesResource.php`: salida y reglas de precio/multimedia.
- `app/Http/Resources/BienesraicesDetalleResource.php`: salida extensible de la ficha individual.
- `app/Http/Controllers/Api/UbicacionesController.php`: respuesta del autocompletado.
- `app/Repositories/UbicacionesRepository.php`: búsqueda y agrupación de ubicaciones.
- `app/Http/Resources/UbicacionResource.php`: formato de cada sugerencia.
- `app/Http/Controllers/Api/BrokersController.php`: respuesta del perfil público.
- `app/Repositories/BrokersRepository.php`: consulta segura del broker habilitado.
- `app/Http/Resources/BrokerResource.php`: campos públicos y normalización del broker.
- `app/Http/Controllers/Api/ConsultasController.php`: validación de propiedad, destinatario y envío.
- `app/Http/Requests/ConsultaStoreRequest.php`: contrato y normalización de la consulta.
- `app/Mail/ConsultaPropiedadMail.php`: asunto, `Reply-To` y vistas del email.
- `config/visualgestion.php`: TTL, broker global e imágenes.
- `public/openapi.yaml` y `resources/views/docs.blade.php`: documentación.
- `implementacion.md`: guía de traspaso para construir un portal consumidor de la API.
- `tests/Feature/BienesRaicesApiTest.php`: cobertura funcional.
- `Dockerfile`, `compose.yaml` y `docker/`: imagen y runtime de producción.
- `.env.docker.example`: variables requeridas sin secretos.
- `DOCKER.md`: construcción, despliegue, proxy, actualización y diagnóstico.

## Configuración prevista

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vgestion-api-2
DB_USERNAME=root
DB_PASSWORD=
API_TOKEN_TTL=86400
API_SUPER_BROKER=Z999
PROPERTY_IMAGE_LIMIT=5
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.titan.email
MAIL_PORT=465
MAIL_USERNAME=info@visualgestion.net
MAIL_PASSWORD=CLAVE_SMTP
MAIL_FROM_ADDRESS=info@visualgestion.net
```

## Próximos temas sugeridos

- Definir revocación/rotación y migrar `PwdWS` a hashes antes de producción.
- Confirmar si `Hab = 0` siempre debe impedir login.
- Reemplazar la suposición fija de cinco fotos cuando exista el número real.
- Evaluar cachear facets si aumenta considerablemente el volumen.
- Incorporar próximos endpoints manteniendo la separación controller/repository/resource.
