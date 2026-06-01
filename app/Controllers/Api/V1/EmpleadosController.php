<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\EmpleadoModel;
use App\Libraries\AuditLibrary;

/**
 * EmpleadosController
 *
 * Migración del employees.php legacy — módulo principal de empleados.
 * Rutas base: /api/v1/empleados
 *
 * GET  /                    → lista paginada (getEmpleados)
 * GET  /{id}                → detalle (getEmpleado)
 * GET  /buscar              → búsqueda (searchEmpleado)
 * GET  /dashboard           → stats del dashboard (empleadosDash)
 * GET  /alertas             → badges de alertas
 * GET  /nomina/{id}         → nómina de empleado por periodo
 * POST /                    → registrar empleado (empleados)
 * POST /masivo              → carga masiva (empleado_masivo)
 * POST /baja-masiva         → baja masiva por nro. empleado
 * POST /{id}/baja           → baja individual (activar legacy)
 * POST /{id}/baja-accion    → reingreso o reactivación
 * PUT  /{id}                → actualizar (personal | trabajo | banco)
 * POST /{id}/foto           → actualizar foto (updatePhoto)
 */
class EmpleadosController extends ResourceController
{
    protected $format = 'json';

    /** URL base de fotos */
    private string $fotoBaseUrl  = '';
    private string $fotoBasePath = '';

    public function __construct()
    {
        $this->fotoBaseUrl  = env('FOTOS_URL', 'https://arma2.com.mx/SIA/app/photos/');
        $this->fotoBasePath = env('FOTOS_PATH', ROOTPATH . '../app/photos/');
    }

    /* ═══════════════════════════════════════════════════════════════
       LECTURA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * GET /api/v1/empleados
     * Parámetros: pagina, limit, zonas[], puestos[], fechas, status
     */
    public function index(): mixed
    {
        $model  = new EmpleadoModel();
        $pagina = (int)($this->request->getVar('pagina') ?? 1);
        $limit  = (int)($this->request->getVar('limit')  ?? 50);
        $offset = ($pagina - 1) * $limit;

        $zonas    = $this->request->getVar('zonas')    ?? [];
        $puestos  = $this->request->getVar('puestos')  ?? [];
        $fechas   = $this->request->getVar('fechas');
        $status   = $this->request->getVar('status');
        $ubicacion = $this->request->getVar('ubicacion') ? (int)$this->request->getVar('ubicacion') : null; // ← nuevo

        $resultado = $model->listar($limit, $offset, (array)$zonas, (array)$puestos, $fechas, $status, $ubicacion); // ← pásalo

        return $this->respond([
            'status'      => 'ok',
            'empleado'    => $resultado,
            'AllTotal'    => ['empleadosTotales' => $model->countAll()],
            'completados' => ['empleadosTotales' => $model->totalPorEstatus(1)],
            'pendientes'  => ['empleadosTotales' => $model->totalPorEstatus(2)],
            'bajas'       => ['empleadosTotales' => $model->totalPorEstatus(0)],
            'timestamp'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET /api/v1/empleados/{id}
     */
    public function show($id = null): mixed
    {
        $model  = new EmpleadoModel();
        $result = $model->getConCatalogos((int)$id);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 404);
        }

        return $this->respond($result);
    }

    /**
     * GET /api/v1/empleados/buscar?search=&limit=&offset=
     */
    public function buscar(): mixed
    {
        $model  = new EmpleadoModel();
        $search = $this->request->getVar('search') ?? '';
        $limit  = (int)($this->request->getVar('limit')  ?? 50);
        $offset = (int)($this->request->getVar('offset') ?? 0);

        return $this->respond($model->buscar($search, $limit, $offset));
    }

    /**
     * GET /api/v1/empleados/dashboard
     */
    public function dashboard(): mixed
    {
        $model = new EmpleadoModel();
        return $this->respond($model->conteos());
    }

    /**
     * GET /api/v1/empleados/alertas
     */
    public function alertas(): mixed
    {
        $model = new EmpleadoModel();
        return $this->respond($model->alertas());
    }

    /**
     * GET /api/v1/empleados/nomina/{id}?fecha_Inicio=&fecha_Final=
     * (La lógica de nómina usa getNominaEmpleado del legacy — aquí dejamos el endpoint listo)
     */
    public function nomina($id = null): mixed
    {
        // TODO: implementar getNominaEmpleado con su query cuando tengas la tabla de nómina
        return $this->respond([
            'status'  => 'ok',
            'mensaje' => 'Endpoint de nómina pendiente de implementar',
            'data'    => [],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       REGISTRO INDIVIDUAL
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/empleados
     * Equivalente a case "empleados" del legacy.
     */
    public function create(): mixed
    {
        $actor = $this->request->jwtUser;

        $rules = [
            'curp'              => 'required|max_length[20]',
            'rfc'               => 'required|max_length[15]',
            'nss'               => 'required|exact_length[11]|numeric',
            'cp'                => 'required|exact_length[5]|numeric',
            'paterno'           => 'required|max_length[255]',
            'materno'           => 'permit_empty|max_length[255]',
            'nombre'            => 'required|max_length[255]',
            'interbancaria'     => 'required|exact_length[18]|numeric',
            'turno'             => 'required',
            'puesto'            => 'required',
            'periodicidad'      => 'required',
            'fecha_ingreso'     => 'permit_empty|valid_date[Y-m-d]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new EmpleadoModel();

        // Validar duplicados
        $curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->request->getVar('curp')));
        $rfc  = strtoupper($this->request->getVar('rfc'));
        $nss  = preg_replace('/\D/', '', $this->request->getVar('nss'));

        $duplicados = [];
        if ($model->buscarPorIdentidad($curp)) $duplicados[] = 'CURP';
        if ($model->buscarPorIdentidad($rfc))  $duplicados[] = 'RFC';
        if ($model->buscarPorIdentidad($nss))  $duplicados[] = 'NSS';

        if ($duplicados) {
            return $this->respond(['status' => 'error', 'message' => 'Ya existe un empleado con el mismo ' . implode(', ', $duplicados)], 409);
        }

        // Foto
        $fotoUrl = $this->fotoBaseUrl . 'default.png';
        $archivo  = $this->request->getFile('fotoEmpleado');

        if ($archivo && $archivo->isValid() && !$archivo->hasMoved()) {
            $resultado = $this->subirFoto($archivo, $curp);
            if ($resultado['status'] !== 'ok') {
                return $this->respond($resultado, 422);
            }
            $fotoUrl = $resultado['url'];
        }

        $datos = [
            'curp'               => $curp,
            'rfc'                => $rfc,
            'nss'                => $nss,
            'CP_fiscal'          => preg_replace('/\D/', '', $this->request->getVar('cp')),
            'fecha_ingreso'      => $this->request->getVar('fecha_ingreso') ?: date('Y-m-d'),
            'fecha_efectiva'     => $this->request->getVar('fecha_efectiva') ?: null,
            'paterno'            => $this->request->getVar('paterno'),
            'materno'            => $this->request->getVar('materno') ?? '',
            'nombre'             => $this->request->getVar('nombre'),
            'id_turno'           => $this->request->getVar('turno'),
            'id_puesto'          => $this->request->getVar('puesto'),
            'id_periocidad'      => $this->request->getVar('periodicidad'),
            'clave_interbancaria' => preg_replace('/\D/', '', $this->request->getVar('interbancaria')),
            'id_banco'           => $this->request->getVar('institucionBancaria') ?? null,
            'estatus'            => 1,
            'alergias'           => $this->request->getVar('alergias') ?: 'N/A',
            'fotos'              => $fotoUrl,
            'tipoSangre'         => $this->request->getVar('tipoSangre') ?? null,
            'escolaridad'        => $this->request->getVar('escolaridad') ?? null,
            'parentesco'         => $this->request->getVar('parentesco') ?? null,
            'nombreEmergencia'   => $this->request->getVar('nombreEmergencia') ?? null,
            'telefonoEmergencia' => $this->request->getVar('telefonoEmergencia') ?? null,
            'created_by'         => (int)$actor->id,
        ];

        $res = $model->registrar($datos);

        AuditLibrary::log((int)$actor->id, 'CREAR_EMPLEADO', 'empleados', (string)($res['last_insert_id'] ?? ''), 'Creó empleado', null, $datos);

        return $this->respond($res, $res['status'] === 'ok' ? 201 : 500);
    }

    /* ═══════════════════════════════════════════════════════════════
       CARGA MASIVA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/empleados/masivo
     * Equivalente a case "empleado_masivo" del legacy.
     * Body: { empleados:[], validate_only:bool, fail_threshold:float, all_or_nothing:bool }
     */
    public function masivo(): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();

        $empleadosArr   = $this->request->getVar('empleados') ?? [];
        $validateOnly   = (bool)($this->request->getVar('validate_only')   ?? false);
        $failThreshold  = (float)($this->request->getVar('fail_threshold') ?? 0.80);
        $allOrNothing   = (bool)($this->request->getVar('all_or_nothing')  ?? false);

        if (!is_array($empleadosArr) || count($empleadosArr) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibió el arreglo empleados[]'], 400);
        }

        $total     = count($empleadosArr);
        $errores   = 0;
        $dupCount  = 0;
        $prevalid  = [];

        // ── Pre-validación ───────────────────────────────────────────
        foreach ($empleadosArr as $idx => $emp) {
            $row    = isset($emp['_row']) ? (int)$emp['_row'] : ($idx + 1);
            $rowErr = [];

            $curp  = strtoupper(trim($emp['curp'] ?? ''));
            $rfc   = strtoupper(trim($emp['rfc']  ?? ''));
            $nss   = preg_replace('/\D/', '', $emp['nss'] ?? '');
            $cp    = preg_replace('/\D/', '', $emp['cp']  ?? '');
            $inter = preg_replace('/\D/', '', $emp['interbancaria'] ?? '');

            if (trim($emp['paterno'] ?? '') === '' || trim($emp['nombre'] ?? '') === '') $rowErr[] = 'Nombre incompleto';
            if ($curp === '')  $rowErr[] = 'CURP obligatorio';
            if ($rfc === '')   $rowErr[] = 'RFC obligatorio';
            if ($nss === '')   $rowErr[] = 'NSS obligatorio';
            if ($cp === '')    $rowErr[] = 'CP obligatorio';
            if ($inter === '') $rowErr[] = 'Clabe interbancaria obligatoria';
            if ($nss !== '' && !preg_match('/^\d{11}$/', $nss)) $rowErr[] = 'NSS inválido (11 dígitos)';
            if ($cp  !== '' && !preg_match('/^\d{5}$/', $cp))   $rowErr[] = 'CP inválido (5 dígitos)';
            if ($inter !== '' && !preg_match('/^\d{18}$/', $inter)) $rowErr[] = 'Clabe inválida (18 dígitos)';
            if (trim($emp['turno']        ?? '') === '') $rowErr[] = 'Turno obligatorio';
            if (trim($emp['puesto']       ?? '') === '') $rowErr[] = 'Puesto obligatorio';
            if (trim($emp['periodicidad'] ?? '') === '') $rowErr[] = 'Periodicidad obligatoria';

            // Duplicados en BD
            $dups = [];
            if ($curp && $model->buscarPorIdentidad($curp)) $dups[] = 'CURP';
            if ($rfc  && $model->buscarPorIdentidad($rfc))  $dups[] = 'RFC';
            if ($nss  && $model->buscarPorIdentidad($nss))  $dups[] = 'NSS';

            if ($dups) {
                $dupCount++;
                $rowErr[] = 'Duplicado en BD: ' . implode(', ', $dups);
            }

            $ok = count($rowErr) === 0;
            $prevalid[] = ['row' => $row, 'ok' => $ok, 'errors' => $rowErr, 'data' => $emp];
            if (!$ok) $errores++;
        }

        $detalleResumen = fn($x) => ['row' => $x['row'], 'status' => $x['ok'] ? 'ok' : 'error', 'message' => $x['ok'] ? 'OK' : implode(' | ', $x['errors'])];

        // ── Umbral de fallo ──────────────────────────────────────────
        if ($total > 0 && ($errores / $total) >= $failThreshold) {
            return $this->respond([
                'status'     => 'error',
                'message'    => 'Lote cancelado: ' . round(($errores/$total)*100,1) . '% de errores supera el umbral',
                'total'      => $total, 'insertados' => 0,
                'duplicados' => $dupCount, 'errores' => $errores,
                'detalle'    => array_map($detalleResumen, $prevalid),
            ], 422);
        }

        // ── Solo validar ─────────────────────────────────────────────
        if ($validateOnly) {
            return $this->respond([
                'status'     => 'ok',
                'message'    => 'Validación completada (sin insertar)',
                'total'      => $total, 'insertados' => 0,
                'duplicados' => $dupCount, 'errores' => $errores,
                'detalle'    => array_map($detalleResumen, $prevalid),
            ]);
        }

        // ── Insertar ─────────────────────────────────────────────────
        $insertados = 0;
        $detalle    = [];

        if ($allOrNothing) $this->db->transStart();

        foreach ($prevalid as $item) {
            if (!$item['ok']) {
                $detalle[] = ['row' => $item['row'], 'status' => 'error', 'message' => implode(' | ', $item['errors'])];
                continue;
            }

            $emp = $item['data'];

            $datos = [
                'curp'               => strtoupper(trim($emp['curp'])),
                'rfc'                => strtoupper(trim($emp['rfc'])),
                'nss'                => preg_replace('/\D/', '', $emp['nss']),
                'CP_fiscal'          => preg_replace('/\D/', '', $emp['cp']),
                'fecha_ingreso'      => trim($emp['fecha_ingreso'] ?? date('Y-m-d')),
                'fecha_efectiva'     => trim($emp['fecha_efectiva'] ?? ''),
                'paterno'            => trim($emp['paterno']),
                'materno'            => trim($emp['materno'] ?? ''),
                'nombre'             => trim($emp['nombre']),
                'id_turno'           => trim($emp['turno']),
                'id_puesto'          => trim($emp['puesto']),
                'id_periocidad'      => trim($emp['periodicidad']),
                'clave_interbancaria' => preg_replace('/\D/', '', $emp['interbancaria']),
                'id_banco'           => trim($emp['institucionBancaria'] ?? ''),
                'estatus'            => 1,
                'alergias'           => trim($emp['alergias'] ?? 'N/A'),
                'fotos'              => $this->fotoBaseUrl . 'default.png',
                'tipoSangre'         => trim($emp['tipoSangre'] ?? ''),
                'escolaridad'        => trim($emp['escolaridad'] ?? ''),
                'parentesco'         => trim($emp['parentesco'] ?? ''),
                'nombreEmergencia'   => trim($emp['nombreEmergencia'] ?? ''),
                'telefonoEmergencia' => trim($emp['telefonoEmergencia'] ?? ''),
                'created_by'         => (int)$actor->id,
            ];

            $res = $model->registrar($datos);

            if ($res['status'] === 'ok') {
                $insertados++;
                $detalle[] = ['row' => $item['row'], 'status' => 'ok', 'message' => 'OK'];
                AuditLibrary::log((int)$actor->id, 'CREAR_EMPLEADO_MASIVO', 'empleados', (string)$res['last_insert_id'], 'Carga masiva');
            } else {
                $errores++;
                $detalle[] = ['row' => $item['row'], 'status' => 'error', 'message' => $res['mensaje']];
                if ($allOrNothing) {
                    $this->db->transRollback();
                    return $this->respond(['status' => 'error', 'message' => 'Rollback: fallo en fila ' . $item['row'], 'detalle' => $detalle], 500);
                }
            }
        }

        if ($allOrNothing) $this->db->transComplete();

        return $this->respond([
            'status'     => 'ok',
            'message'    => $allOrNothing ? 'Lote insertado con transacción' : 'Lote procesado',
            'total'      => $total,
            'insertados' => $insertados,
            'duplicados' => $dupCount,
            'errores'    => $errores,
            'detalle'    => $detalle,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       BAJA MASIVA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/empleados/baja-masiva
     * Body: { numeros:[...], motivo:"..." }
     */
    public function bajaMasiva(): mixed
    {
        $actor   = $this->request->jwtUser;
        $model   = new EmpleadoModel();
        $numeros = $this->request->getVar('numeros') ?? [];
        $motivo  = trim($this->request->getVar('motivo') ?? 'BAJA MASIVA');

        if (!is_array($numeros) || count($numeros) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibieron números de empleado'], 400);
        }

        $clean = array_values(array_unique(array_filter(array_map(fn($n) => (int)$n, $numeros))));

        if (count($clean) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'Números inválidos'], 422);
        }

        $ok = $notFound = $fails = [];

        foreach ($clean as $num) {
            $emp = $model->find($num);
            if (!$emp) {
                $notFound[] = $num;
                continue;
            }

            $res = $model->darDeBaja($num, 1, false, $motivo, date('Y-m-d'), (int)$actor->id);

            if ($res['status'] === 'ok') {
                $ok[] = $num;
                AuditLibrary::log((int)$actor->id, 'BAJA_MASIVA', 'empleados', (string)$num, $motivo);
            } else {
                $fails[] = ['id' => $num, 'error' => $res['mensaje']];
            }
        }

        return $this->respond([
            'status'  => 'ok',
            'message' => 'Proceso terminado',
            'data'    => [
                'procesados'      => count($clean),
                'bajados'         => count($ok),
                'no_encontrados'  => count($notFound),
                'fallidos'        => count($fails),
                'ok'              => $ok,
                'notFound'        => $notFound,
                'fails'           => $fails,
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       ACTUALIZACIÓN
    ═══════════════════════════════════════════════════════════════ */

    /**
     * PUT /api/v1/empleados/{id}
     * Body: { tipo:"personal"|"trabajo"|"banco", ...campos }
     */
    public function update($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();
        $tipo  = $this->request->getVar('tipo');

        $empleadoAntes = $model->find((int)$id);
        if (!$empleadoAntes) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        switch ($tipo) {

            case 'personal':
                $curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->request->getVar('curp') ?? ''));

                // Verificar que el CURP no pertenezca a otro empleado
                $existe = $model->buscarPorIdentidad($curp);
                if ($existe && (string)$existe['id'] !== (string)$id) {
                    return $this->respond(['status' => 'error', 'message' => 'El CURP ya existe en otro empleado'], 409);
                }

                $datos = [
                    'curp'               => $curp,
                    'rfc'                => strtoupper($this->request->getVar('rfc') ?? ''),
                    'nss'                => preg_replace('/\D/', '', $this->request->getVar('nss') ?? ''),
                    'CP_fiscal'          => preg_replace('/\D/', '', $this->request->getVar('cp') ?? ''),
                    'paterno'            => $this->request->getVar('paterno'),
                    'materno'            => $this->request->getVar('materno'),
                    'nombre'             => $this->request->getVar('nombre'),
                    'alergias'           => $this->request->getVar('alergias'),
                    'tipoSangre'         => $this->request->getVar('tipo_sangre'),
                    'escolaridad'        => $this->request->getVar('escolaridad'),
                    'parentesco'         => $this->request->getVar('parentesco'),
                    'nombreEmergencia'   => $this->request->getVar('nombreEmergencia'),
                    'telefonoEmergencia' => $this->request->getVar('telefonoEmergencia'),
                    'updated_by'         => (int)$actor->id,
                ];

                $res = $model->actualizarPersonal((int)$id, $datos);
                break;

            case 'trabajo':
                $datos = [
                    'id_turno'      => $this->request->getVar('id_turno'),
                    'id_puesto'     => $this->request->getVar('id_puesto'),
                    'id_periocidad' => $this->request->getVar('id_periocidad'),
                    'fecha_efectiva' => $this->request->getVar('fecha'),
                    'updated_by'    => (int)$actor->id,
                ];
                $res = $model->actualizarTrabajo((int)$id, $datos);
                break;

            case 'banco':
                $datos = [
                    'clave_interbancaria' => preg_replace('/\D/', '', $this->request->getVar('clave_interbancaria') ?? ''),
                    'id_banco'            => $this->request->getVar('id_banco'),
                    'updated_by'          => (int)$actor->id,
                ];
                $res = $model->actualizarBancario((int)$id, $datos);
                break;

            default:
                return $this->respond(['status' => 'error', 'message' => 'Tipo de actualización inválido (personal|trabajo|banco)'], 400);
        }

        $empleadoDespues = $model->find((int)$id);
        AuditLibrary::log((int)$actor->id, 'EDITAR_EMPLEADO', 'empleados', (string)$id, "Actualizó {$tipo}", $empleadoAntes, $empleadoDespues);

        return $this->respond($res);
    }


    
    /**
     * POST /api/v1/empleados/{id}/foto
     * Multipart: foto (archivo)
     */
    public function subirFotoPerfil($id = null): mixed
    {
        $actor   = $this->request->jwtUser;
        $model   = new EmpleadoModel();
        $archivo = $this->request->getFile('foto');

        if (!$archivo || !$archivo->isValid()) {
            return $this->respond(['status' => 'error', 'message' => 'No se envió imagen válida'], 400);
        }

        $empleado = $model->find((int)$id);
        if (!$empleado) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $nombreFoto = $this->request->getVar('nombreFoto') ?? ($empleado['curp'] . '.jpg');
        $destino    = rtrim($this->fotoBasePath, '/') . '/' . $nombreFoto;
        $rutaImg    = rtrim($this->fotoBaseUrl, '/') . '/' . $nombreFoto;

        if (!$archivo->move(dirname($destino), basename($destino))) {
            return $this->respond(['status' => 'error', 'message' => 'Error al guardar imagen'], 500);
        }

        $res = $model->actualizarFoto((int)$id, $rutaImg, (int)$actor->id);

        AuditLibrary::log((int)$actor->id, 'FOTO_EMPLEADO', 'empleados', (string)$id, 'Actualizó foto');

        return $this->respond($res);
    }

    /**
     * PATCH /api/v1/empleados/{id}/biometrico
     * Body: { acceso_biometrico: 0|1 }
     */
    public function toggleBiometrico($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();

        $empleado = $model->find((int)$id);
        if (!$empleado) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $body  = $this->request->getJSON(true);
        $valor = isset($body['acceso_biometrico']) ? (int)$body['acceso_biometrico'] : null;

        if ($valor === null || !in_array($valor, [0, 1], true)) {
            return $this->respond(['status' => 'error', 'message' => 'Valor inválido. Use 0 o 1'], 422);
        }

        $model->update((int)$id, ['acceso_biometrico' => $valor]);

        $accion = $valor === 1 ? 'HABILITAR_BIOMETRICO' : 'REVOCAR_BIOMETRICO';
        $msg    = $valor === 1 ? 'Acceso biométrico habilitado' : 'Acceso biométrico revocado';

        AuditLibrary::log((int)$actor->id, $accion, 'empleados', (string)$id, $msg);

        return $this->respond([
            'status'  => 'ok',
            'message' => $msg,
            'data'    => ['id' => (int)$id, 'acceso_biometrico' => $valor],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       BAJA INDIVIDUAL Y ACCIONES
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/empleados/{id}/baja
     * Equivalente a case "activar" del legacy (a pesar del nombre, da de baja).
     * Body: { motivo_baja, finiquito, nota_baja, fecha_baja }
     */
    public function baja($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();

        if (!$model->find((int)$id)) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $rules = [
            'motivo_baja' => 'required|integer',
            'fecha_baja'  => 'required|valid_date[Y-m-d]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }

        $res = $model->darDeBaja(
            (int)$id,
            (int)$this->request->getVar('motivo_baja'),
            (bool)$this->request->getVar('finiquito'),
            $this->request->getVar('nota_baja') ?? '',
            $this->request->getVar('fecha_baja'),
            (int)$actor->id
        );

        AuditLibrary::log((int)$actor->id, 'BAJA_EMPLEADO', 'empleados', (string)$id, 'Baja lógica');

        return $this->respond($res);
    }

    /**
     * POST /api/v1/empleados/{id}/baja-accion
     * Equivalente a case "baja_accion" del legacy.
     * Body: { tipo: "reingreso" | "reactivacion" }
     */
    public function bajaAccion($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();
        $tipo  = $this->request->getVar('tipo');

        if (!in_array($tipo, ['reingreso', 'reactivacion'], true)) {
            return $this->respond(['status' => 'error', 'message' => 'tipo inválido (reingreso|reactivacion)'], 400);
        }

        if (!$model->find((int)$id)) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $res = $tipo === 'reingreso'
            ? $model->reingreso((int)$id)
            : $model->reactivar((int)$id);

        AuditLibrary::log((int)$actor->id, strtoupper($tipo), 'empleados', (string)$id, $tipo);

        return $this->respond($res);
    }

    /* ═══════════════════════════════════════════════════════════════
       HELPER PRIVADO
    ═══════════════════════════════════════════════════════════════ */

    private function subirFoto($archivo, string $curp): array
    {
        $mimePermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        $mime = $archivo->getMimeType();

        if (!isset($mimePermitidos[$mime])) {
            return ['status' => 'error', 'message' => 'Formato de imagen no permitido (jpg/png)'];
        }

        if (!is_dir($this->fotoBasePath)) {
            mkdir($this->fotoBasePath, 0755, true);
        }

        $nombre  = $curp . '.' . $mimePermitidos[$mime];
        $destino = rtrim($this->fotoBasePath, '/') . '/' . $nombre;

        if (!$archivo->move(dirname($destino), $nombre)) {
            return ['status' => 'error', 'message' => 'Error al guardar la imagen'];
        }

        return ['status' => 'ok', 'url' => rtrim($this->fotoBaseUrl, '/') . '/' . $nombre];
    }
}
