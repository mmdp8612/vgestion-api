# Despliegue de VisualGestion API con Docker

La imagen utiliza Apache con PHP 8.4 y está preparada para producción. MySQL no se incluye en `compose.yaml`: la API debe conectarse a la base existente y nunca ejecuta migraciones automáticamente.

## Archivos

- `Dockerfile`: construye la imagen de producción.
- `compose.yaml`: inicia el servicio y publica el puerto.
- `.env.docker.example`: plantilla de configuración y secretos.
- `.dockerignore`: evita copiar credenciales, el volcado SQL, pruebas y dependencias locales.
- `docker/apache-vhost.conf`: sirve exclusivamente el directorio `public`.
- `docker/php.ini`: configuración PHP y OPcache.
- `docker/entrypoint.sh`: valida `APP_KEY` y genera las cachés de Laravel.

## Primera ejecución

Crear el archivo privado de configuración:

~~~bash
cp .env.docker.example .env.docker
chmod 600 .env.docker
~~~

Construir la imagen:

~~~bash
docker compose --env-file .env.docker build --pull
~~~

Generar una clave de Laravel:

~~~bash
docker compose --env-file .env.docker run --rm api php artisan key:generate --show
~~~

Copiar el resultado completo, incluido el prefijo `base64:`, en `APP_KEY` dentro de `.env.docker`. La clave debe conservarse entre despliegues porque cambiarla invalida todos los Bearer tokens.

Completar también:

- `APP_URL`: dominio público con `https://`.
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.
- `MAIL_PASSWORD`: contraseña SMTP real.

Iniciar:

~~~bash
docker compose --env-file .env.docker up -d
docker compose --env-file .env.docker ps
~~~

Comprobar la aplicación:

~~~bash
curl --fail http://127.0.0.1:8080/up
curl http://127.0.0.1:8080/
~~~

El healthcheck `/up` comprueba que Laravel y Apache respondan; no ejecuta una consulta a MySQL.

## Conexión a MySQL

Dentro de un contenedor, `127.0.0.1` identifica al propio contenedor y no al VPS.

- Si MySQL está en otro servidor o contenedor, usar su IP privada o nombre DNS.
- Si MySQL está instalado directamente en el mismo VPS, puede utilizarse `DB_HOST=host.docker.internal`; `compose.yaml` ya agrega la resolución hacia el host.
- MySQL debe escuchar en una interfaz alcanzable desde la red Docker y el usuario debe aceptar conexiones desde esa red.

Se recomienda crear un usuario exclusivo para la API con los permisos mínimos necesarios. No utilizar `root` ni publicar el puerto 3306 en Internet.

La API supone que el esquema y los datos ya existen. No ejecutar `php artisan migrate`.

## Proxy inverso y HTTPS

Por defecto el contenedor queda disponible únicamente en `127.0.0.1:8080`. Esto evita publicar la API directamente y permite colocar Nginx o Caddy delante.

Ejemplo básico de Nginx:

~~~nginx
server {
    listen 80;
    server_name api.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
~~~

Después se debe habilitar HTTPS con el mecanismo elegido en el VPS. `TRUSTED_PROXIES=*` es apropiado cuando el puerto permanece enlazado a loopback y solo recibe tráfico del proxy confiable. Si se configura `APP_BIND_IP=0.0.0.0`, definir direcciones de proxy concretas o dejar `TRUSTED_PROXIES` vacío para impedir la falsificación de cabeceras.

## Logs y diagnóstico

~~~bash
docker compose --env-file .env.docker logs -f api
docker compose --env-file .env.docker exec api php artisan about
docker inspect --format='{{json .State.Health}}' visualgestion-api-api-1
~~~

Los logs de Laravel y Apache se envían a stdout/stderr y pueden consultarse con `docker compose logs`.

## Actualización

Después de subir una nueva versión del código:

~~~bash
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker up -d --remove-orphans
~~~

El inicio reconstruye las cachés de configuración y vistas. No se ejecutan migraciones ni se modifica la base.

## Exposición temporal sin proxy

Para una prueba directa, cambiar en `.env.docker`:

~~~dotenv
APP_BIND_IP=0.0.0.0
APP_PORT=8080
TRUSTED_PROXIES=
~~~

La API quedará accesible en el puerto 8080 del VPS. Esta modalidad debe protegerse con firewall y no reemplaza HTTPS para producción.
