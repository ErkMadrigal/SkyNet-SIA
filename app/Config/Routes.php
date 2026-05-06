<?php

use CodeIgniter\Router\RouteCollection;

/**
 * SkyNet-SIA — Rutas
 *
 * Convención:
 *   /api/v1/{modulo}/{accion}
 *
 * Filtros disponibles:
 *   jwt             → cualquier usuario autenticado
 *   jwt:admin       → Admin o superior (nivel ≤ 2)
 *   jwt:superadmin  → Solo SuperAdmin (nivel = 1)
 */

/** @var RouteCollection $routes */

// ── Health check / Ping ──────────────────────────────
$routes->get('api/ping', function () {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'ok',
        'service' => 'SkyNet-SIA',
        'version' => '1.0.0',
        'time'    => date('Y-m-d H:i:s'),
        'env'     => env('CI_ENVIRONMENT', 'production'),
    ], JSON_UNESCAPED_UNICODE);
});


$routes->get('/', 'Home::index');

$routes->group('api', function ($routes) {
    $routes->group('v1', function ($routes) {

        /* ─────────────────────────────────────────────────
           AUTH — Rutas públicas (no requieren JWT)
        ───────────────────────────────────────────────── */
        $routes->group('auth', function ($routes) {

            // Login del sistema web
            $routes->post('login', 'Api\V1\AuthController::login');

            // Renovar access token (usa el refresh token como Bearer)
            $routes->post('refresh', 'Api\V1\AuthController::refresh');

            /* ── Biométrico (protegido por API Key en middleware) ─── */
            $routes->post('biometrico/buscar', 'Api\V1\AuthController::biometricoBuscar');
            $routes->post('biometrico/login',  'Api\V1\AuthController::biometricoLogin');
        });

        /* ─────────────────────────────────────────────────
           AUTH — Rutas protegidas (requieren JWT válido)
        ───────────────────────────────────────────────── */
        $routes->group('auth', ['filter' => 'jwt'], function ($routes) {

            // Info del usuario actual
            $routes->get('me', 'Api\V1\AuthController::me');

            // Logout (sesión actual)
            $routes->post('logout',     'Api\V1\AuthController::logout');

            // Logout de todas las sesiones
            $routes->post('logout-all', 'Api\V1\AuthController::logoutAll');
        });

        /* ─────────────────────────────────────────────────
           AUTH — Solo Admin o superior
        ───────────────────────────────────────────────── */
        $routes->group('auth', ['filter' => 'jwt:admin'], function ($routes) {

            // Registrar nuevo usuario del sistema
            $routes->post('registro', 'Api\V1\AuthController::registro');
        });

        /* ─────────────────────────────────────────────────
           USUARIOS — Gestión (Admin o superior)
        ───────────────────────────────────────────────── */
        $routes->group('usuarios', ['filter' => 'jwt:admin'], function ($routes) {
            // Se llenará en el siguiente módulo
            // $routes->get('/',          'Api\V1\UsuariosController::index');
            // $routes->get('(:num)',     'Api\V1\UsuariosController::show/$1');
            // $routes->put('(:num)',     'Api\V1\UsuariosController::update/$1');
            // $routes->delete('(:num)',  'Api\V1\UsuariosController::delete/$1');
        });

        /* ─────────────────────────────────────────────────
           EMPLEADOS
        ───────────────────────────────────────────────── */
        $routes->group('empleados', ['filter' => 'jwt'], function ($routes) {

            // Lectura (cualquier usuario autenticado)
            $routes->get('/',             'Api\V1\EmpleadosController::index');
            $routes->get('buscar',        'Api\V1\EmpleadosController::buscar');
            $routes->get('dashboard',     'Api\V1\EmpleadosController::dashboard');
            $routes->get('alertas',       'Api\V1\EmpleadosController::alertas');
            $routes->get('nomina/(:num)', 'Api\V1\EmpleadosController::nomina/$1');
            $routes->get('(:num)',        'Api\V1\EmpleadosController::show/$1');

            // Escritura individual (operador o superior)
            $routes->post('/',                      'Api\V1\EmpleadosController::create');
            $routes->post('masivo',                 'Api\V1\EmpleadosController::masivo');
            $routes->put('(:num)',                  'Api\V1\EmpleadosController::update/$1');
            $routes->post('(:num)/foto',            'Api\V1\EmpleadosController::subirFotoPerfil/$1');

            // Bajas (solo admin o superior)
            $routes->post('baja-masiva',            'Api\V1\EmpleadosController::bajaMasiva',  ['filter' => 'jwt:admin']);
            $routes->post('(:num)/baja',            'Api\V1\EmpleadosController::baja/$1',      ['filter' => 'jwt:admin']);
            $routes->post('(:num)/baja-accion',     'Api\V1\EmpleadosController::bajaAccion/$1', ['filter' => 'jwt:admin']);
        });

        /* ─────────────────────────────────────────────────
           BIOMÉTRICO
        ───────────────────────────────────────────────── */
        $routes->group('biometrico', ['filter' => 'jwt'], function ($routes) {
            $routes->post('buscar',       'Api\V1\BiometricoController::buscar');
            $routes->post('registro',     'Api\V1\BiometricoController::registro');
            $routes->post('qr/generar',   'Api\V1\BiometricoController::qrGenerar');
            $routes->post('qr/usar',      'Api\V1\BiometricoController::qrUsar');
            $routes->get('registros',     'Api\V1\BiometricoController::registros');
        });

        /* ─────────────────────────────────────────────────
           INCIDENCIAS
        ───────────────────────────────────────────────── */
        $routes->group('incidencias', ['filter' => 'jwt'], function ($routes) {
            $routes->get('/',                    'Api\V1\IncidenciasController::index');
            $routes->post('/',                   'Api\V1\IncidenciasController::create');
            $routes->post('(:num)/aprobar',      'Api\V1\IncidenciasController::aprobar/$1', ['filter' => 'jwt:admin']);
        });

        /* ─────────────────────────────────────────────────
           EMPRESAS — Solo SuperAdmin
        ───────────────────────────────────────────────── */
        $routes->group('empresas', ['filter' => 'jwt:superadmin'], function ($routes) {
            // Se llenará en el módulo de empresas
        });

        /* ─────────────────────────────────────────────────
           REPORTES
        ─────────────────────────────────────────────────── */
        $routes->group('reportes', ['filter' => 'jwt'], function ($routes) {
            $routes->get('altas',          'Api\V1\ReportesController::altas');
            $routes->get('bajas',          'Api\V1\ReportesController::bajas');
            $routes->get('nomina/(:num)',   'Api\V1\ReportesController::nomina/$1');
            $routes->get('asistencia',     'Api\V1\ReportesController::asistencia');
            $routes->post('prenomina',     'Api\V1\ReportesController::prenomina');
        });

        /* ─────────────────────────────────────────────────
           TABULADOR DE SALARIOS
        ─────────────────────────────────────────────────── */
        $routes->group('tabulador', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->get('zonas',              'Api\V1\ReportesController::zonas');
            $routes->get('puestos',            'Api\V1\ReportesController::puestos');
            $routes->get('/',                  'Api\V1\ReportesController::index');
            $routes->post('/',                 'Api\V1\ReportesController::create');
            $routes->get('(:num)',             'Api\V1\ReportesController::show/$1');
            $routes->post('(:num)/item',       'Api\V1\ReportesController::upsertItem/$1');
            $routes->delete('item/(:num)',     'Api\V1\ReportesController::deshabilitarItem/$1');
            $routes->patch('(:num)/estatus',   'Api\V1\ReportesController::setEstatus/$1');
        });


        /* ─────────────────────────────────────────────────
           CATÁLOGOS
        ─────────────────────────────────────────────────── */
        $routes->group('catalogos', ['filter' => 'jwt'], function ($routes) {

            // Lectura (cualquier usuario autenticado)
            $routes->get('tipos',                   'Api\V1\CatalogosController::tipos');
            $routes->get('banco/(:segment)',         'Api\V1\CatalogosController::banco/$1');
            $routes->get('regionales',               'Api\V1\CatalogosController::regionales');
            $routes->get('servicios/select',         'Api\V1\CatalogosController::serviciosSelect');
            $routes->get('(:num)',                   'Api\V1\CatalogosController::show/$1');
            $routes->get('(:num)/buscar',            'Api\V1\CatalogosController::buscarPorNombre/$1');
            $routes->get('(:num)/select',            'Api\V1\CatalogosController::catalogosSelect/$1');

            // Entidades — solo lectura para cualquier auth
            $routes->get('empresas',                 'Api\V1\CatalogosController::empresas');
            $routes->get('partidas',                 'Api\V1\CatalogosController::partidas');
            $routes->get('zonas',                    'Api\V1\CatalogosController::zonas');
            $routes->get('regiones',                 'Api\V1\CatalogosController::regionesList');
            $routes->get('areas-geograficas',        'Api\V1\CatalogosController::areasGeograficas');
            $routes->get('areas-geograficas/gerentes','Api\V1\CatalogosController::regionalesGerentes');
            $routes->get('clientes',                 'Api\V1\CatalogosController::clientes');
            $routes->get('servicios',                'Api\V1\CatalogosController::servicios');
        });

        $routes->group('catalogos', ['filter' => 'jwt:admin'], function ($routes) {

            // Multicatálogo escritura
            $routes->post('tipos',                   'Api\V1\CatalogosController::crearTipo');
            $routes->post('(:num)/items',            'Api\V1\CatalogosController::crearItem/$1');
            $routes->put('items/(:num)',              'Api\V1\CatalogosController::actualizarItem/$1');
            $routes->delete('items/(:num)',           'Api\V1\CatalogosController::eliminarItem/$1');

            // Empresas
            $routes->post('empresas',                'Api\V1\CatalogosController::crearEmpresa');
            $routes->put('empresas/(:num)',           'Api\V1\CatalogosController::actualizarEmpresa/$1');
            $routes->delete('empresas/(:num)',        'Api\V1\CatalogosController::eliminarEmpresa/$1');

            // Partidas
            $routes->post('partidas',                'Api\V1\CatalogosController::crearPartida');
            $routes->put('partidas/(:num)',           'Api\V1\CatalogosController::actualizarPartida/$1');
            $routes->delete('partidas/(:num)',        'Api\V1\CatalogosController::eliminarPartida/$1');

            // Zonas
            $routes->post('zonas',                   'Api\V1\CatalogosController::crearZona');
            $routes->put('zonas/(:num)',              'Api\V1\CatalogosController::actualizarZona/$1');
            $routes->delete('zonas/(:num)',           'Api\V1\CatalogosController::eliminarZona/$1');

            // Regiones
            $routes->post('regiones',                'Api\V1\CatalogosController::crearRegion');
            $routes->put('regiones/(:num)',           'Api\V1\CatalogosController::actualizarRegion/$1');
            $routes->delete('regiones/(:num)',        'Api\V1\CatalogosController::eliminarRegion/$1');

            // Áreas geográficas (solo update)
            $routes->put('areas-geograficas/(:num)', 'Api\V1\CatalogosController::actualizarArea/$1');

            // Clientes
            $routes->post('clientes',                'Api\V1\CatalogosController::crearCliente');
            $routes->put('clientes/(:num)',           'Api\V1\CatalogosController::actualizarCliente/$1');
            $routes->delete('clientes/(:num)',        'Api\V1\CatalogosController::eliminarCliente/$1');

            // Servicios
            $routes->post('servicios',               'Api\V1\CatalogosController::crearServicio');
            $routes->put('servicios/(:num)',          'Api\V1\CatalogosController::actualizarServicio/$1');
            $routes->delete('servicios/(:num)',        'Api\V1\CatalogosController::eliminarServicio/$1');
        });


        /* ─────────────────────────────────────────────────
           HOSPITALES / INVENTARIO
        ─────────────────────────────────────────────────── */
        $routes->group('hospitales', ['filter' => 'jwt'], function ($routes) {
            $routes->get('/',                              'Api\V1\HospitalesController::index');
            $routes->get('productos',                      'Api\V1\HospitalesController::productos');
            $routes->get('productos/(:num)',               'Api\V1\HospitalesController::producto/$1');
            $routes->get('(:num)',                         'Api\V1\HospitalesController::show/$1');
            $routes->post('(:num)/asignar-empleado',       'Api\V1\HospitalesController::asignarEmpleado/$1');
            $routes->get('(:num)/recepciones',             'Api\V1\HospitalesController::recepciones/$1');
            $routes->post('(:num)/recepciones',            'Api\V1\HospitalesController::recepciones/$1');
            $routes->get('(:num)/salidas',                 'Api\V1\HospitalesController::salidas/$1');
            $routes->post('(:num)/salidas',                'Api\V1\HospitalesController::salidas/$1');
            $routes->get('(:num)/inventario',              'Api\V1\HospitalesController::inventario/$1');
            $routes->get('(:num)/inventario/(:num)',       'Api\V1\HospitalesController::stockProducto/$1/$2');
        });


        /* ─────────────────────────────────────────────────
           USUARIOS DEL SISTEMA
        ─────────────────────────────────────────────────── */
        $routes->group('usuarios', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->get('/',               'Api\V1\UsuariosSistemaController::index');
            $routes->get('roles',           'Api\V1\UsuariosSistemaController::roles');
            $routes->get('(:num)',          'Api\V1\UsuariosSistemaController::show/$1');
            $routes->post('/',              'Api\V1\UsuariosSistemaController::create');
            $routes->post('(:num)/roles',   'Api\V1\UsuariosSistemaController::asignarRoles/$1');
        });
    });
});


