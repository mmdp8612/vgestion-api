<?php

namespace Tests\Feature;

use App\Mail\ConsultaPropiedadMail;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BienesRaicesApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'visualgestion.token_ttl' => 3600,
            'visualgestion.super_broker' => 'Z999',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        $this->seedDatabase();
    }

    public function test_login_emite_un_token_y_rechaza_credenciales_incorrectas(): void
    {
        $this->postJson('/login', ['id_broker' => 'A004', 'password' => 'incorrecta'])
            ->assertUnauthorized();

        $this->postJson('/login', ['IdBroker' => 'A004', 'PwdWS' => 'secreto'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.broker.id', 'A004')
            ->assertJsonStructure(['data' => ['token', 'expires_in', 'expires_at']]);
    }

    public function test_un_broker_solo_obtiene_sus_propiedades_y_puede_filtrar_por_nombres(): void
    {
        $token = $this->login('A004', 'secreto');

        $response = $this->withToken($token)
            ->getJson('/bienesraices?tipo=Departamento,Casa&comercializacion=Venta');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id_broker', 'A004')
            ->assertJsonPath('data.0.comercializacion.permite_venta', true)
            ->assertJsonCount(5, 'data.0.multimedia.imagenes')
            ->assertJsonPath('data.0.precios.venta.simbolo', 'u$s');

        $this->assertNotContains('B112', collect($response->json('data'))->pluck('id_broker')->all());
        $this->assertContains('Departamento', collect($response->json('filtros_disponibles.tipo'))->pluck('nombre')->all());
        $this->assertArrayNotHasKey('Visitas', $response->json('data.0'));
    }

    public function test_noppi_oculta_importes_y_el_token_es_obligatorio(): void
    {
        $this->getJson('/bienesraices')->assertUnauthorized();

        $response = $this->withToken($this->login('A004', 'secreto'))
            ->getJson('/bienesraices?idTipologia=CASA');

        $response->assertOk()
            ->assertJsonPath('data.0.precios.visible', false)
            ->assertJsonPath('data.0.precios.texto', 'Consultar')
            ->assertJsonPath('data.0.precios.venta.importe', null);
    }

    public function test_z999_puede_obtener_propiedades_de_todos_los_brokers(): void
    {
        $response = $this->withToken($this->login('Z999', 'global'))
            ->getJson('/bienesraices?per_page=100');

        $response->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertSee('"filtros_aplicados":{}', false);

        $this->assertEqualsCanonicalizing(
            ['A004', 'B112'],
            collect($response->json('data'))->pluck('id_broker')->unique()->values()->all()
        );
    }

    public function test_los_rangos_aceptan_un_solo_limite_y_validan_el_orden(): void
    {
        $token = $this->login('A004', 'secreto');

        $this->withToken($token)
            ->getJson('/bienesraices?precio_venta_hasta=100000')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withToken($token)
            ->getJson('/bienesraices?precio_venta_desde=200000&precio_venta_hasta=100000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('precio_venta_hasta');
    }

    public function test_puede_filtrar_por_uno_o_varios_ambientes(): void
    {
        $token = $this->login('A004', 'secreto');

        $this->withToken($token)
            ->getJson('/bienesraices?ambientes=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.caracteristicas.ambientes', 2)
            ->assertJsonPath('filtros_disponibles.ambientes.0.id', 2);

        $this->withToken($token)
            ->getJson('/bienesraices?ambientes=2,3&tipo=Departamento,Casa&precio_venta_desde=80000&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('filtros_aplicados.ambientes', [2, 3])
            ->assertJsonPath('filtros_aplicados.tipo', ['Departamento', 'Casa'])
            ->assertJsonPath('filtros_aplicados.precio_venta_desde', 80000)
            ->assertJsonMissingPath('filtros_aplicados.page')
            ->assertJsonMissingPath('filtros_aplicados.per_page');

        $this->withToken($token)
            ->getJson('/bienesraices?ambientes=2,dos')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ambientes');
    }

    public function test_la_ficha_respeta_el_broker_autenticado(): void
    {
        $token = $this->login('A004', 'secreto');

        $this->withToken($token)
            ->getJson('/bienesraices/a004/1')
            ->assertOk()
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.id_broker', 'A004')
            ->assertJsonPath('data.caracteristicas.tipologia.nombre', 'Departamento')
            ->assertJsonCount(5, 'data.multimedia.imagenes');

        $this->withToken($token)
            ->getJson('/bienesraices/B112/3')
            ->assertNotFound()
            ->assertJsonPath('message', 'La propiedad no existe.');

        $this->withToken($token)
            ->getJson('/bienesraices/A004/999999')
            ->assertNotFound();
    }

    public function test_z999_puede_obtener_la_ficha_de_cualquier_broker(): void
    {
        $this->getJson('/bienesraices/A004/1')->assertUnauthorized();

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/bienesraices/B112/3')
            ->assertOk()
            ->assertJsonPath('data.id', 3)
            ->assertJsonPath('data.id_broker', 'B112');
    }

    public function test_autocompleta_ubicaciones_del_broker_autenticado(): void
    {
        $this->getJson('/ubicaciones?q=Ramos')->assertUnauthorized();

        $token = $this->login('A004', 'secreto');

        $this->withToken($token)
            ->getJson('/ubicaciones?q=Ramos&limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.etiqueta', 'Buenos Aires, La Matanza, Ramos Mejía')
            ->assertJsonPath('data.0.localidad.id', 1)
            ->assertJsonPath('data.0.cantidad_propiedades', 2)
            ->assertJsonPath('data.0.filtro.parametro', 'idLocalidad')
            ->assertJsonPath('data.0.filtro.valor', 1)
            ->assertJsonPath('meta.busqueda', 'Ramos')
            ->assertJsonPath('meta.limite', 5)
            ->assertJsonPath('meta.hay_mas', false);

        $this->withToken($token)
            ->getJson('/ubicaciones?q=R')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_ubicaciones_sin_busqueda_devuelve_todas_y_respeta_z999(): void
    {
        DB::table('tip_localidad')->insert([
            'IdLocalidad' => 2,
            'Descrip' => 'Haedo',
        ]);
        DB::table('mae_bienesraices')->insert([
            'IdBroker' => 'B112',
            'IdBienes' => 4,
            'idLocalidad' => 2,
            'IdPartido' => '13',
            'IdProvincia' => 'BUE',
            'IdPais' => 'ARG',
        ]);

        $this->withToken($this->login('A004', 'secreto'))
            ->getJson('/ubicaciones')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.limite', null);

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/ubicaciones?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.hay_mas', true);

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/ubicaciones')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_envia_una_consulta_al_email_del_broker_de_la_propiedad(): void
    {
        Mail::fake();

        $response = $this->withToken($this->login('A004', 'secreto'))
            ->postJson('/consultas', [
                'id_broker' => 'A004',
                'id_bienes' => 1,
                'nombre' => 'Juan Pérez',
                'email' => 'juan@example.com',
                'telefono' => '+54 11 4444-5555',
                'mensaje' => 'Quisiera coordinar una visita a la propiedad.',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('message', 'La consulta fue enviada correctamente.')
            ->assertJsonPath('data.id_broker', 'A004')
            ->assertJsonPath('data.id_bienes', 1)
            ->assertJsonMissingPath('data.destinatario');

        Mail::assertSent(
            ConsultaPropiedadMail::class,
            function (ConsultaPropiedadMail $mail): bool {
                $html = $mail->render();

                return $mail->hasTo('broker1@example.com')
                    && $mail->hasReplyTo('juan@example.com', 'Juan Pérez')
                    && $mail->propiedad['referencia'] === 'A004 #1'
                    && str_contains($html, 'Quisiera coordinar una visita a la propiedad.');
            }
        );
    }

    public function test_consultas_requiere_token_y_valida_los_datos(): void
    {
        Mail::fake();

        $this->postJson('/consultas', [])->assertUnauthorized();

        $this->withToken($this->login('A004', 'secreto'))
            ->postJson('/consultas', [
                'id_broker' => 'A004',
                'id_bienes' => 1,
                'nombre' => 'J',
                'email' => 'correo-invalido',
                'telefono' => 'teléfono',
                'mensaje' => 'Corto',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'email', 'telefono', 'mensaje']);

        Mail::assertNothingSent();
    }

    public function test_consultas_respeta_el_alcance_del_broker_y_el_destinatario_configurado(): void
    {
        Mail::fake();

        $payload = [
            'idBroker' => 'B112',
            'idBienes' => 3,
            'nombre' => 'Ana López',
            'email' => 'ana@example.com',
            'mensaje' => 'Necesito más información sobre esta propiedad.',
        ];

        $this->withToken($this->login('A004', 'secreto'))
            ->postJson('/consultas', $payload)
            ->assertNotFound();

        $this->withToken($this->login('Z999', 'global'))
            ->postJson('/consultas', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.id_broker', 'B112');

        Mail::assertSent(
            ConsultaPropiedadMail::class,
            fn (ConsultaPropiedadMail $mail): bool => $mail->hasTo('broker2@example.com')
        );
    }

    public function test_consulta_no_se_envia_si_el_broker_no_tiene_email_valido(): void
    {
        Mail::fake();
        DB::table('mae_brokers')->where('IdBroker', 'A004')->update(['Email' => null]);

        $this->withToken($this->login('A004', 'secreto'))
            ->postJson('/consultas', [
                'id_broker' => 'A004',
                'id_bienes' => 1,
                'nombre' => 'Juan Pérez',
                'email' => 'juan@example.com',
                'mensaje' => 'Quisiera recibir más información de la propiedad.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'La inmobiliaria no tiene un email válido configurado para recibir consultas.'
            );

        Mail::assertNothingSent();
    }

    public function test_un_broker_puede_obtener_su_perfil_publico(): void
    {
        $this->getJson('/brokers/A004')->assertUnauthorized();

        $this->withToken($this->login('A004', 'secreto'))
            ->getJson('/brokers/a004')
            ->assertOk()
            ->assertJsonPath('data.id', 'A004')
            ->assertJsonPath('data.razon_social', 'Broker Uno')
            ->assertJsonPath('data.email', 'broker1@example.com')
            ->assertJsonPath('data.telefono', '4444-1111')
            ->assertJsonPath('data.telefonos', '4444-1111 / 4444-2222')
            ->assertJsonPath('data.latitud', -34.64)
            ->assertJsonPath('data.longitud', -58.56)
            ->assertJsonMissingPath('data.PwdWS')
            ->assertJsonMissingPath('data.Hab')
            ->assertJsonMissingPath('data.LimitePropiedades');
    }

    public function test_el_perfil_del_broker_respeta_el_aislamiento_y_z999(): void
    {
        $this->withToken($this->login('A004', 'secreto'))
            ->getJson('/brokers/B112')
            ->assertNotFound()
            ->assertJsonPath('message', 'La inmobiliaria no existe.');

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/brokers/B112')
            ->assertOk()
            ->assertJsonPath('data.id', 'B112')
            ->assertJsonPath('data.razon_social', 'Broker Dos');

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/brokers/NOEXIS')
            ->assertNotFound();

        DB::table('mae_brokers')->where('IdBroker', 'B112')->update(['Hab' => '0']);

        $this->withToken($this->login('Z999', 'global'))
            ->getJson('/brokers/B112')
            ->assertNotFound();
    }

    private function login(string $brokerId, string $password): string
    {
        return (string) $this->postJson('/login', [
            'id_broker' => $brokerId,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    private function createSchema(): void
    {
        Schema::create('mae_brokers', function (Blueprint $table): void {
            $table->string('IdBroker', 6)->primary();
            $table->string('PwdWS')->nullable();
            $table->string('Hab', 1)->default('1');
            $table->string('RazonSocial')->default('');
            $table->string('Email')->nullable();
            $table->string('Telefonos')->nullable();
            $table->string('TelPrincipal')->nullable();
            $table->string('Celular')->nullable();
            $table->string('Direccion')->nullable();
            $table->string('Localidad')->nullable();
            $table->string('Partido')->nullable();
            $table->string('Provincia')->nullable();
            $table->string('Pais')->nullable();
            $table->string('CodigoPostal')->nullable();
            $table->string('Latitud')->nullable();
            $table->string('Longitud')->nullable();
            $table->string('Web')->nullable();
            $table->string('Matricula')->nullable();
        });

        Schema::create('mae_bienesraices', function (Blueprint $table): void {
            $table->string('IdBroker', 6);
            $table->double('IdBienes');
            $table->string('Calle')->nullable();
            $table->integer('Numero')->default(0);
            $table->string('Piso')->nullable();
            $table->string('Torre')->nullable();
            $table->text('Caratula')->nullable();
            $table->string('Barrio')->nullable();
            $table->integer('idLocalidad')->default(0);
            $table->string('IdPartido')->nullable();
            $table->string('IdProvincia')->nullable();
            $table->string('IdPais')->nullable();
            $table->string('Antiguedad')->nullable();
            $table->string('Luminosidad')->nullable();
            $table->integer('Plantas')->default(0);
            $table->float('Frente')->default(0);
            $table->float('Fondo')->default(0);
            $table->float('MtsFondo')->default(0);
            $table->float('SupCubiertaPropia')->default(0);
            $table->float('SupTerreno')->default(0);
            $table->integer('Ambientes')->default(0);
            $table->integer('Sanitarios')->default(0);
            $table->integer('Suite')->default(0);
            $table->integer('Dormitorios')->default(0);
            $table->integer('LineasTel')->default(0);
            $table->double('ImporteVta')->default(0);
            $table->double('ImporteAlq')->default(0);
            $table->string('IdComercializacion')->default('VTA');
            $table->string('IdVista')->default('FRENTE');
            $table->string('IdUso')->default('VIVI');
            $table->string('IdOrientacion')->default('N');
            $table->string('IdTipologia')->default('DPTO');
            $table->string('IdCochera')->nullable();
            $table->integer('idTipoMonedaVta')->nullable();
            $table->integer('idTipoMonedaAlq')->nullable();
            $table->integer('TieneFoto')->default(0);
            $table->integer('TieneVideo')->default(0);
            $table->double('Latitud')->default(0);
            $table->double('Longitud')->default(0);
            $table->string('UrlVideo')->nullable();
            $table->integer('NoPPI')->default(0);
            $table->integer('Tiene360')->default(0);
            $table->string('URL360')->nullable();
        });

        $catalogs = [
            'tip_localidad' => ['IdLocalidad', 'integer'],
            'tip_partido' => ['IdPartido', 'string'],
            'tip_provincia' => ['IdProvincia', 'string'],
            'tip_pais' => ['IdPais', 'string'],
            'tip_antiguedad' => ['IdAntiguedad', 'string'],
            'tip_comercializacion' => ['IdComercializacion', 'string'],
            'tip_vista' => ['IdVista', 'string'],
            'tip_uso' => ['IdUso', 'string'],
            'tip_orientacion' => ['IdOrientacion', 'string'],
            'tip_tipologia' => ['IdTipologia', 'string'],
            'tip_cochera' => ['IdCochera', 'string'],
        ];

        foreach ($catalogs as $tableName => [$idColumn, $type]) {
            Schema::create($tableName, function (Blueprint $table) use ($idColumn, $type): void {
                $type === 'integer' ? $table->integer($idColumn) : $table->string($idColumn);
                $table->string('Descrip')->nullable();
            });
        }

        Schema::create('tip_tipomoneda', function (Blueprint $table): void {
            $table->integer('idTipoMoneda');
            $table->string('Descrip');
            $table->string('Simbolo');
        });
    }

    private function seedDatabase(): void
    {
        DB::table('mae_brokers')->insert([
            [
                'IdBroker' => 'A004', 'PwdWS' => 'secreto', 'Hab' => '1',
                'RazonSocial' => 'Broker Uno', 'Email' => 'broker1@example.com',
                'Telefonos' => '4444-1111 / 4444-2222', 'TelPrincipal' => '4444-1111',
                'Latitud' => '-34.64', 'Longitud' => '-58.56',
            ],
            [
                'IdBroker' => 'B112', 'PwdWS' => 'otro', 'Hab' => '1',
                'RazonSocial' => 'Broker Dos', 'Email' => 'broker2@example.com',
                'Telefonos' => null, 'TelPrincipal' => null,
                'Latitud' => null, 'Longitud' => null,
            ],
            [
                'IdBroker' => 'Z999', 'PwdWS' => 'global', 'Hab' => '1',
                'RazonSocial' => 'Portal', 'Email' => 'portal@example.com',
                'Telefonos' => null, 'TelPrincipal' => null,
                'Latitud' => null, 'Longitud' => null,
            ],
        ]);

        DB::table('tip_localidad')->insert(['IdLocalidad' => 1, 'Descrip' => 'Ramos Mejía']);
        DB::table('tip_partido')->insert(['IdPartido' => '13', 'Descrip' => 'La Matanza']);
        DB::table('tip_provincia')->insert(['IdProvincia' => 'BUE', 'Descrip' => 'Buenos Aires']);
        DB::table('tip_pais')->insert(['IdPais' => 'ARG', 'Descrip' => 'Argentina']);
        DB::table('tip_antiguedad')->insert(['IdAntiguedad' => 'A10', 'Descrip' => 'Menor a 10']);
        DB::table('tip_comercializacion')->insert([
            ['IdComercializacion' => 'VTA', 'Descrip' => 'Venta'],
            ['IdComercializacion' => 'A-V', 'Descrip' => 'Ambos V/A'],
        ]);
        DB::table('tip_vista')->insert(['IdVista' => 'FRENTE', 'Descrip' => 'Al Frente']);
        DB::table('tip_uso')->insert(['IdUso' => 'VIVI', 'Descrip' => 'Vivienda']);
        DB::table('tip_orientacion')->insert(['IdOrientacion' => 'N', 'Descrip' => 'Norte']);
        DB::table('tip_tipologia')->insert([
            ['IdTipologia' => 'DPTO', 'Descrip' => 'Departamento'],
            ['IdTipologia' => 'CASA', 'Descrip' => 'Casa'],
        ]);
        DB::table('tip_cochera')->insert(['IdCochera' => 'CCU', 'Descrip' => 'Cochera Cubierta']);
        DB::table('tip_tipomoneda')->insert([
            ['idTipoMoneda' => 0, 'Descrip' => 'Dolares', 'Simbolo' => 'u$s'],
            ['idTipoMoneda' => 1, 'Descrip' => 'Pesos', 'Simbolo' => '$'],
        ]);

        $base = [
            'Calle' => 'Siempre Viva', 'Numero' => 123, 'idLocalidad' => 1,
            'IdPartido' => '13', 'IdProvincia' => 'BUE', 'IdPais' => 'ARG',
            'Antiguedad' => 'A10', 'IdVista' => 'FRENTE', 'IdUso' => 'VIVI',
            'IdOrientacion' => 'N', 'IdCochera' => 'CCU', 'idTipoMonedaVta' => 0,
            'idTipoMonedaAlq' => 1, 'ImporteVta' => 100000, 'ImporteAlq' => 500000,
            'TieneFoto' => 0, 'NoPPI' => 0,
        ];

        DB::table('mae_bienesraices')->insert([
            array_merge($base, [
                'IdBroker' => 'A004', 'IdBienes' => 1, 'IdComercializacion' => 'A-V',
                'IdTipologia' => 'DPTO', 'TieneFoto' => 1, 'Ambientes' => 2,
            ]),
            array_merge($base, [
                'IdBroker' => 'A004', 'IdBienes' => 2, 'IdComercializacion' => 'VTA',
                'IdTipologia' => 'CASA', 'NoPPI' => 1, 'Ambientes' => 3,
            ]),
            array_merge($base, [
                'IdBroker' => 'B112', 'IdBienes' => 3, 'IdComercializacion' => 'VTA',
                'IdTipologia' => 'DPTO', 'Ambientes' => 2,
            ]),
        ]);
    }
}
