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

        // ── Audit Log ─────────────────────────────────
        $routes->get('audit-log', 'Api\V1\AuditController::index', ['filter' => 'jwt:admin']);

        $routes->get('empleados/buscar-rapido', 'Api\V1\EmpleadosController::buscarRapido');


        /* ── NÓMINA ── */
        $routes->group('nomina', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->get('preview',            'Api\V1\NominaController::preview');
            $routes->get('empleados-servicio', 'Api\V1\NominaController::empleadosPorServicio');
            $routes->get('empleados-zona',     'Api\V1\NominaController::empleadosPorZona');
        });

        $routes->group('nomina-fatiga', ['filter' => 'jwt'], function ($routes) {
            // Rutas de texto fijo PRIMERO (antes de cualquier (:num))
            $routes->get('/',                    'Api\V1\NominaFatigaController::index');
            $routes->get('lotes-abiertos',       'Api\V1\NominaFatigaController::lotesAbiertos');
            $routes->post('procesar',            'Api\V1\NominaFatigaController::procesar');
            $routes->post('procesar-asistencia', 'Api\V1\NominaFatigaController::procesarAsistencia');
            $routes->post('iniciar-asistencia',  'Api\V1\NominaFatigaController::iniciarAsistencia');
            $routes->post('procesar-xlsm',       'Api\V1\NominaFatigaController::procesarXlsm');

            // Rutas con (:num) DESPUÉS
            $routes->get('(:num)',                'Api\V1\NominaFatigaController::show/$1');
            $routes->put('(:num)/detalle/(:num)', 'Api\V1\NominaFatigaController::actualizarDetalle/$1/$2');
            $routes->post('(:num)/aprobar',       'Api\V1\NominaFatigaController::aprobar/$1',  ['filter' => 'jwt:admin']);
            $routes->post('(:num)/rechazar',      'Api\V1\NominaFatigaController::rechazar/$1', ['filter' => 'jwt:admin']);
            $routes->get('(:num)/dispersion',     'Api\V1\NominaFatigaController::dispersion/$1');
            $routes->post('(:num)/procesar-chunk','Api\V1\NominaFatigaController::procesarChunk/$1');
            $routes->get('(:num)/exportar-xlsx', 'Api\V1\NominaFatigaController::exportarXlsx/$1');

            $routes->get('(:num)/dispersion-ias',    'Api\V1\NominaFatigaController::dispersionIas/$1',    ['filter' => 'jwt']);
            $routes->get('(:num)/dispersion-fiscal',  'Api\V1\NominaFatigaController::dispersionFiscal/$1',  ['filter' => 'jwt']);
            $routes->post('preview-xlsm', 'Api\V1\NominaFatigaController::previewXlsm');
            $routes->post('guardar-manual', 'Api\V1\NominaFatigaController::guardarAsistenciaManual');
    
        });

        $routes->group('deducciones', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->post('fonacot',   'Api\V1\DeduccionesController::fonacot');
            $routes->post('infonavit', 'Api\V1\DeduccionesController::infonavit');
            $routes->post('pension',   'Api\V1\DeduccionesController::pension');
            $routes->get('/',          'Api\V1\DeduccionesController::index');
            $routes->get('resumen',    'Api\V1\DeduccionesController::resumen');
            $routes->delete('(:num)',  'Api\V1\DeduccionesController::delete/$1');
        });


        $routes->group('importacion-masiva', ['filter' => ['jwt', 'importClave']], function ($routes) {
            $routes->post('empleados',            'Api\V1\ImportacionMasivaController::empleados');
            $routes->post('ubicaciones',          'Api\V1\ImportacionMasivaController::ubicaciones');
            $routes->post('altas-xlsx',           'Api\V1\ImportacionMasivaController::altasXlsx');
            $routes->post('altas-xlsx/confirmar', 'Api\V1\ImportacionMasivaController::confirmarLote');
            $routes->post('altas-xlsx/rollback',  'Api\V1\ImportacionMasivaController::rollbackLote');
        });


        /* ─────────────────────────────────────────────────
           AUTH — Rutas públicas (no requieren JWT)
        ───────────────────────────────────────────────── */
        $routes->group('auth', function ($routes) {
            $routes->post('login',            'Api\V1\AuthController::login');
            $routes->post('refresh',          'Api\V1\AuthController::refresh');
            $routes->post('biometrico/buscar','Api\V1\AuthController::biometricoBuscar');
            $routes->post('biometrico/login', 'Api\V1\AuthController::biometricoLogin');
        });

        /* ─────────────────────────────────────────────────
           AUTH — Rutas protegidas (requieren JWT válido)
        ───────────────────────────────────────────────── */
        $routes->group('auth', ['filter' => 'jwt'], function ($routes) {
            $routes->get('me',                'Api\V1\AuthController::me');
            $routes->post('logout',           'Api\V1\AuthController::logout');
            $routes->post('logout-all',       'Api\V1\AuthController::logoutAll');
            $routes->put('me',                'Api\V1\AuthController::updateMe');
            $routes->post('cambiar-password', 'Api\V1\AuthController::cambiarPassword');
        });

        /* ─────────────────────────────────────────────────
           AUTH — Solo Admin o superior
        ───────────────────────────────────────────────── */
        $routes->group('auth', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->post('registro', 'Api\V1\AuthController::registro');
        });

        /* ─────────────────────────────────────────────────
           BIOMÉTRICO — Rutas PÚBLICAS para el kiosko
           (sin JWT — buscar y registrar asistencia)
        ───────────────────────────────────────────────── */
        // Versionamiento de la app del kiosko (público)
        $routes->get('app/version',   'Api\V1\AppVersionController::version');
        $routes->get('app/descargar', 'Api\V1\AppVersionController::descargar');

        // Enrolamiento facial por link con token (sin login; el token es el acceso)
        $routes->group('enrolamiento-publico', function ($routes) {
            $routes->get('(:segment)',  'Api\V1\EnrolamientoController::info/$1');
            $routes->post('(:segment)', 'Api\V1\EnrolamientoController::enrolarPublico/$1');
        });

        // Alta de empleados desde el kiosko (público; solo jefes de turno vía id_operador)
        $routes->group('empleados-kiosko', function ($routes) {
            $routes->get('catalogos', 'Api\V1\EmpleadosKioskoController::catalogos');
            $routes->post('/',        'Api\V1\EmpleadosKioskoController::crearKiosko');
            $routes->get('(:num)',    'Api\V1\EmpleadosKioskoController::detalle/$1');
            $routes->post('(:num)/actualizar', 'Api\V1\EmpleadosKioskoController::actualizarKiosko/$1');
        });

        // Expediente digital desde el kiosko (público; queda pendiente de aprobación)
        $routes->group('expediente-kiosko', function ($routes) {
            $routes->post('/',    'Api\V1\ExpedienteKioskoController::subir');
            $routes->get('mios',  'Api\V1\ExpedienteKioskoController::mios');
        });

        // Incidencias desde el kiosko (público; el operador va en id_operador)
        $routes->group('incidencias-kiosko', function ($routes) {
            $routes->get('motivos',   'Api\V1\IncidenciasKioskoController::motivos');
            $routes->get('servicios', 'Api\V1\IncidenciasKioskoController::servicios');
            $routes->post('/',        'Api\V1\IncidenciasKioskoController::crearKiosko');
        });

        $routes->group('biometrico', function ($routes) {
            $routes->post('buscar',   'Api\V1\BiometricoController::buscar');
            $routes->post('buscar-login', 'Api\V1\BiometricoController::buscarLogin'); 
            $routes->post('registro', 'Api\V1\BiometricoController::registro');
            $routes->get('estado/(:num)', 'Api\V1\BiometricoController::estado/$1');
            $routes->get('semana/(:num)', 'Api\V1\BiometricoController::semana/$1');
            $routes->get('permisos-app/(:num)', 'Api\V1\AppPermisosController::publico/$1');

            $routes->get('sync',        'Api\V1\BiometricoController::sync');
            $routes->get('buscar-sync', 'Api\V1\BiometricoController::buscarSync');
            $routes->get('ubicacion-cercana', 'Api\V1\BiometricoController::ubicacionCercana');
            $routes->get('ubicaciones-buscar', 'Api\V1\BiometricoController::ubicacionesBuscar'); // NUEVO -- búsqueda por nombre para el kiosko
            $routes->post('enrolar', 'Api\V1\BiometricoController::enrolar'); // NUEVO -- enrolamiento facial (2 capturas)

        });

        /* ─────────────────────────────────────────────────
           BIOMÉTRICO — Rutas protegidas con JWT
        ───────────────────────────────────────────────── */
        $routes->group('biometrico', ['filter' => 'jwt'], function ($routes) {
            $routes->post('buscar',     'Api\V1\BiometricoController::buscar');
            $routes->post('registro',   'Api\V1\BiometricoController::registro');
            $routes->post('qr/generar', 'Api\V1\BiometricoController::qrGenerar');
            $routes->post('qr/usar',    'Api\V1\BiometricoController::qrUsar');
            $routes->get('registros',   'Api\V1\BiometricoController::registros');
            $routes->get('registros/exportar-xlsx', 'Api\V1\BiometricoController::exportarXlsx');
        });

        /* ─────────────────────────────────────────────────
           EMPLEADOS
        ───────────────────────────────────────────────── */
        $routes->group('empleados', ['filter' => 'jwt'], function ($routes) {
            $routes->patch('(:num)/biometrico', 'Api\V1\EmpleadosController::toggleBiometrico/$1');
            $routes->patch('(:num)/salida-anticipada', 'Api\V1\EmpleadosController::toggleSalidaAnticipada/$1'); // NUEVO
            $routes->get('(:num)/estado-asistencia',   'Api\V1\EmpleadosController::estadoAsistencia/$1');       // NUEVO
            $routes->get('(:num)/asistencias',         'Api\V1\EmpleadosController::historialAsistencias/$1');   // NUEVO
            $routes->get('(:num)/permisos-app',        'Api\V1\AppPermisosController::ver/$1');
            $routes->post('(:num)/permisos-app',       'Api\V1\AppPermisosController::guardar/$1');
            $routes->get('(:num)/incidencias',         'Api\V1\EmpleadosController::incidencias/$1');            // NUEVO
            $routes->get('(:num)/documentos',           'Api\V1\ExpedienteController::listar/$1');               // NUEVO -- expediente digital
            $routes->post('(:num)/documentos',          'Api\V1\ExpedienteController::subir/$1');
            $routes->get('documentos/(:num)/archivo',   'Api\V1\ExpedienteController::archivo/$1');
            $routes->delete('documentos/(:num)',        'Api\V1\ExpedienteController::eliminar/$1');
            $routes->post('documentos/(:num)/aprobar',  'Api\V1\ExpedienteController::aprobar/$1');
            $routes->post('documentos/(:num)/rechazar', 'Api\V1\ExpedienteController::rechazar/$1');
            $routes->post('(:num)/enrolar',            'Api\V1\EnrolamientoController::enrolarPanel/$1');       // NUEVO -- TF.js desde el panel
            $routes->post('(:num)/enrolamiento-reset', 'Api\V1\EnrolamientoController::reiniciar/$1');   // NUEVO -- restablecer enrolamiento
            $routes->post('(:num)/enrolamiento-link',  'Api\V1\EnrolamientoController::generarLink/$1');        // NUEVO -- link para el empleado
            $routes->get('/',             'Api\V1\EmpleadosController::index');
            $routes->get('buscar',        'Api\V1\EmpleadosController::buscar');
            $routes->get('dashboard',     'Api\V1\EmpleadosController::dashboard');
            $routes->get('alertas',       'Api\V1\EmpleadosController::alertas');
            $routes->get('nomina/(:num)', 'Api\V1\EmpleadosController::nomina/$1');
            $routes->get('(:num)',        'Api\V1\EmpleadosController::show/$1');

            $routes->post('/',            'Api\V1\EmpleadosController::create');

            $routes->post('masivo',       'Api\V1\EmpleadosController::masivo', ['filter' => 'importClave']);

            $routes->put('(:num)',        'Api\V1\EmpleadosController::update/$1');
            $routes->post('(:num)/foto',  'Api\V1\EmpleadosController::subirFotoPerfil/$1');

            $routes->post('baja-masiva',        'Api\V1\EmpleadosController::bajaMasiva', ['filter' => ['jwt:admin', 'importClave']]);
            $routes->post('(:num)/baja',        'Api\V1\EmpleadosController::baja/$1',       ['filter' => 'jwt:admin']);
            $routes->post('(:num)/baja-accion', 'Api\V1\EmpleadosController::bajaAccion/$1', ['filter' => 'jwt:admin']);

            $routes->post('masivo-directo', 'Api\V1\EmpleadosController::masivoDirecto', ['filter' => ['jwt', 'importClave']]);
            $routes->post('actualizar-masivo-dinamico', 'Api\V1\EmpleadosController::actualizarMasivoDinamico', ['filter' => ['jwt', 'importClave']]);

        });

        $routes->group('importaciones', ['filter' => 'jwt'], function ($routes) {
            $routes->post('historial', 'Api\V1\ImportacionesController::registrarHistorial');
            $routes->get('historial',  'Api\V1\ImportacionesController::listadoHistorial');
        });

        /* ─────────────────────────────────────────────────
           INCIDENCIAS
        ───────────────────────────────────────────────── */
        /* ── Cámaras desde la app del kiosko (sin JWT, igual que biometrico/*;
              el operador se identifica con id_operador en el body) ── */
        $routes->group('camaras-kiosko', function ($routes) {
            $routes->get('geocodificar',   'Api\V1\CamarasController::geocodificar');
            $routes->get('/',              'Api\V1\CamarasController::index');
            $routes->post('/',             'Api\V1\CamarasController::create');
            $routes->post('(:num)/tickets','Api\V1\CamarasController::crearTicket/$1');
        });

        /* ── Mesa de ayuda desde la app del kiosko (sin JWT; operador = id_operador) ── */
        $routes->group('mesa-ayuda-kiosko', function ($routes) {
            $routes->get('categorias',   'Api\V1\MesaAyudaController::categorias');
            $routes->get('geocodificar', 'Api\V1\CamarasController::geocodificar');
            $routes->get('camaras',      'Api\V1\CamarasController::index');
            $routes->get('/',            'Api\V1\MesaAyudaController::index');
            $routes->post('/',           'Api\V1\MesaAyudaController::create');
        });

        /* ── Mesa de ayuda (panel SIAG) ── */
        $routes->group('mesa-ayuda', ['filter' => 'jwt'], function ($routes) {
            $routes->get('categorias',            'Api\V1\MesaAyudaController::categorias');
            $routes->get('usuarios',              'Api\V1\MesaAyudaController::usuarios');
            $routes->get('resumen',               'Api\V1\MesaAyudaController::resumen');
            $routes->get('/',                     'Api\V1\MesaAyudaController::index');
            $routes->post('/',                    'Api\V1\MesaAyudaController::create');
            $routes->get('(:num)',                'Api\V1\MesaAyudaController::show/$1');
            $routes->post('(:num)/seguimiento',   'Api\V1\MesaAyudaController::seguimiento/$1');
        });

        /* ── Cámaras georreferenciadas + tickets de falla (panel SIAG) ── */
        $routes->group('camaras', ['filter' => 'jwt'], function ($routes) {
            // Estáticas primero, antes de los (:num)
            $routes->get('resumen',                    'Api\V1\CamarasController::resumen');
            $routes->get('geocodificar',               'Api\V1\CamarasController::geocodificar');
            $routes->get('tickets',                    'Api\V1\CamarasController::tickets');
            $routes->get('tickets/(:num)',             'Api\V1\CamarasController::ticketDetalle/$1');
            $routes->post('tickets/(:num)/comentarios','Api\V1\CamarasController::comentarTicket/$1');

            $routes->get('/',                          'Api\V1\CamarasController::index');
            $routes->post('/',                         'Api\V1\CamarasController::create');
            $routes->get('(:num)',                     'Api\V1\CamarasController::show/$1');
            $routes->post('(:num)',                    'Api\V1\CamarasController::actualizar/$1');
            $routes->delete('(:num)',                  'Api\V1\CamarasController::delete/$1');
            $routes->post('(:num)/tickets',            'Api\V1\CamarasController::crearTicket/$1');
        });

        $routes->group('incidencias', ['filter' => 'jwt'], function ($routes) {
            $routes->get('/',               'Api\V1\IncidenciasController::index');
            $routes->post('/',              'Api\V1\IncidenciasController::create');
            $routes->post('(:num)/aprobar', 'Api\V1\IncidenciasController::aprobar/$1', ['filter' => 'jwt:admin']);
        });

        /* ─────────────────────────────────────────────────
           EMPRESAS — Solo SuperAdmin
        ───────────────────────────────────────────────── */
        $routes->group('empresas', ['filter' => 'jwt:superadmin'], function ($routes) {
            // módulo pendiente
        });

        /* ─────────────────────────────────────────────────
           REPORTES
        ─────────────────────────────────────────────────── */
        $routes->group('reportes', ['filter' => 'jwt'], function ($routes) {
            $routes->get('altas',        'Api\V1\ReportesController::altas');
            $routes->get('bajas',        'Api\V1\ReportesController::bajas');
            $routes->get('nomina/(:num)','Api\V1\ReportesController::nomina/$1');
            $routes->get('asistencia',   'Api\V1\ReportesController::asistencia');
            $routes->post('prenomina',   'Api\V1\ReportesController::prenomina');
        });

        /* ─────────────────────────────────────────────────
           TABULADOR DE SALARIOS
        ─────────────────────────────────────────────────── */
        $routes->group('tabulador', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->get('zonas',            'Api\V1\ReportesController::zonas');
            $routes->get('puestos',          'Api\V1\ReportesController::puestos');
            $routes->get('/',                'Api\V1\ReportesController::index');
            $routes->post('/',               'Api\V1\ReportesController::create');
            $routes->get('(:num)',           'Api\V1\ReportesController::show/$1');
            $routes->post('(:num)/item',     'Api\V1\ReportesController::upsertItem/$1');
            $routes->delete('item/(:num)',   'Api\V1\ReportesController::deshabilitarItem/$1');
            $routes->patch('(:num)/estatus', 'Api\V1\ReportesController::setEstatus/$1');
            $routes->put('(:num)',           'Api\V1\ReportesController::update/$1');
            $routes->post('(:num)/duplicar', 'Api\V1\ReportesController::duplicar/$1');

        });

        /* ─────────────────────────────────────────────────
           CATÁLOGOS — Lectura (jwt)
        ─────────────────────────────────────────────────── */
        $routes->group('catalogos', ['filter' => 'jwt'], function ($routes) {
            $routes->get('tipos',                    'Api\V1\CatalogosController::tipos');
            $routes->get('banco/(:segment)',          'Api\V1\CatalogosController::banco/$1');
            $routes->get('regionales',               'Api\V1\CatalogosController::regionales');
            $routes->get('servicios/select',         'Api\V1\CatalogosController::serviciosSelect');
            $routes->get('(:num)',                   'Api\V1\CatalogosController::show/$1');
            $routes->get('(:num)/buscar',            'Api\V1\CatalogosController::buscarPorNombre/$1');
            $routes->get('(:num)/select',            'Api\V1\CatalogosController::catalogosSelect/$1');
            $routes->get('empresas',                 'Api\V1\CatalogosController::empresas');
            $routes->get('partidas',                 'Api\V1\CatalogosController::partidas');
            $routes->get('zonas',                    'Api\V1\CatalogosController::zonas');
            $routes->get('regiones',                 'Api\V1\CatalogosController::regionesList');
            $routes->get('areas-geograficas',        'Api\V1\CatalogosController::areasGeograficas');
            $routes->get('areas-geograficas/gerentes','Api\V1\CatalogosController::regionalesGerentes');
            $routes->get('clientes',                 'Api\V1\CatalogosController::clientes');
            $routes->get('servicios',                'Api\V1\CatalogosController::servicios');
            $routes->post('servicios/masivo', 'Api\V1\CatalogosController::masivoServicios', ['filter' => ['jwt', 'importClave']]);
            $routes->post('servicios/upsert-masivo', 'Api\V1\CatalogosController::upsertMasivoServicios', ['filter' => ['jwt', 'importClave']]);

        });

        /* ─────────────────────────────────────────────────
           CATÁLOGOS — Escritura (jwt:admin)
        ─────────────────────────────────────────────────── */
        $routes->group('catalogos', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->post('tipos',                  'Api\V1\CatalogosController::crearTipo');
            $routes->post('(:num)/items',           'Api\V1\CatalogosController::crearItem/$1');
            $routes->put('items/(:num)',             'Api\V1\CatalogosController::actualizarItem/$1');
            $routes->delete('items/(:num)',          'Api\V1\CatalogosController::eliminarItem/$1');

            $routes->post('empresas',               'Api\V1\CatalogosController::crearEmpresa');
            $routes->put('empresas/(:num)',          'Api\V1\CatalogosController::actualizarEmpresa/$1');
            $routes->delete('empresas/(:num)',       'Api\V1\CatalogosController::eliminarEmpresa/$1');

            $routes->post('partidas',               'Api\V1\CatalogosController::crearPartida');
            $routes->put('partidas/(:num)',          'Api\V1\CatalogosController::actualizarPartida/$1');
            $routes->delete('partidas/(:num)',       'Api\V1\CatalogosController::eliminarPartida/$1');

            $routes->post('zonas',                  'Api\V1\CatalogosController::crearZona');
            $routes->put('zonas/(:num)',             'Api\V1\CatalogosController::actualizarZona/$1');
            $routes->delete('zonas/(:num)',          'Api\V1\CatalogosController::eliminarZona/$1');

            $routes->post('regiones',               'Api\V1\CatalogosController::crearRegion');
            $routes->put('regiones/(:num)',          'Api\V1\CatalogosController::actualizarRegion/$1');
            $routes->delete('regiones/(:num)',       'Api\V1\CatalogosController::eliminarRegion/$1');

            $routes->put('areas-geograficas/(:num)','Api\V1\CatalogosController::actualizarArea/$1');

            $routes->post('clientes',               'Api\V1\CatalogosController::crearCliente');
            $routes->put('clientes/(:num)',          'Api\V1\CatalogosController::actualizarCliente/$1');
            $routes->delete('clientes/(:num)',       'Api\V1\CatalogosController::eliminarCliente/$1');

            $routes->post('servicios',              'Api\V1\CatalogosController::crearServicio');
            $routes->put('servicios/(:num)',         'Api\V1\CatalogosController::actualizarServicio/$1');
            $routes->delete('servicios/(:num)',      'Api\V1\CatalogosController::eliminarServicio/$1');
        });

        /* ─────────────────────────────────────────────────
           HOSPITALES / INVENTARIO
        ─────────────────────────────────────────────────── */
        $routes->group('hospitales', ['filter' => 'jwt'], function ($routes) {
            $routes->get('/',                        'Api\V1\HospitalesController::index');
            $routes->get('productos',                'Api\V1\HospitalesController::productos');
            $routes->get('productos/(:num)',         'Api\V1\HospitalesController::producto/$1');
            $routes->get('(:num)',                   'Api\V1\HospitalesController::show/$1');
            $routes->post('(:num)/asignar-empleado', 'Api\V1\HospitalesController::asignarEmpleado/$1');
            $routes->get('(:num)/recepciones',       'Api\V1\HospitalesController::recepciones/$1');
            $routes->post('(:num)/recepciones',      'Api\V1\HospitalesController::recepciones/$1');
            $routes->get('(:num)/salidas',           'Api\V1\HospitalesController::salidas/$1');
            $routes->post('(:num)/salidas',          'Api\V1\HospitalesController::salidas/$1');
            $routes->get('(:num)/inventario',        'Api\V1\HospitalesController::inventario/$1');
            $routes->get('(:num)/inventario/(:num)', 'Api\V1\HospitalesController::stockProducto/$1/$2');
        });

        /* ─────────────────────────────────────────────────
           USUARIOS DEL SISTEMA
        ─────────────────────────────────────────────────── */
        $routes->group('usuarios', ['filter' => 'jwt:admin'], function ($routes) {
            $routes->get('/',                      'Api\V1\UsuariosSistemaController::index');
            $routes->get('roles',                  'Api\V1\UsuariosSistemaController::roles');
            $routes->get('buscar-empleado',        'Api\V1\UsuariosSistemaController::buscarEmpleado');
            $routes->patch('(:num)/empleado',      'Api\V1\UsuariosSistemaController::vincularEmpleado/$1');
            $routes->get('(:num)',                 'Api\V1\UsuariosSistemaController::show/$1');
            $routes->post('/',                     'Api\V1\UsuariosSistemaController::create');
            $routes->post('(:num)/roles',          'Api\V1\UsuariosSistemaController::asignarRoles/$1');
            $routes->post('(:num)/reset-password', 'Api\V1\UsuariosSistemaController::resetPassword/$1');
            $routes->patch('(:num)/estatus',       'Api\V1\UsuariosSistemaController::toggleEstatus/$1');
        });

        /* ─────────────────────────────────────────────────
           DASHBOARD
        ─────────────────────────────────────────────────── */
        $routes->group('dashboard', ['filter' => 'jwt'], function ($routes) {
            $routes->get('resumen',     'Api\V1\DashboardController::resumen');
            $routes->get('zona/(:num)', 'Api\V1\DashboardController::zona/$1');
            $routes->get('control-area','Api\V1\DashboardController::controlArea');
            $routes->get('mapa-servicios','Api\V1\DashboardController::mapaServicios');
            $routes->get('mapa-servicios/(:num)/empleados','Api\V1\DashboardController::mapaServicioEmpleados/$1');
        });

        

    });
});