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
        $this->fotoBaseUrl  = env('FOTOS_URL',  base_url('fotos/'));
        $this->fotoBasePath = env('FOTOS_PATH', FCPATH . 'fotos/');
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

        // Limpia la interbancaria antes de validar (puede venir con guiones de formato)
        $interbancariaLimpia = preg_replace('/\D/', '', $this->request->getVar('interbancaria') ?? '');

        if (strlen($interbancariaLimpia) !== 18) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Datos inválidos',
                'errors'  => ['interbancaria' => 'La clave interbancaria debe tener 18 dígitos'],
            ], 422);
        }

        $rules = [
            'curp'              => 'required|max_length[20]',
            'rfc'               => 'required|max_length[15]',
            'nss'               => 'required|exact_length[11]|numeric',
            'cp'                => 'required|exact_length[5]|numeric',
            'paterno'           => 'required|max_length[255]',
            'materno'           => 'permit_empty|max_length[255]',
            'nombre'            => 'required|max_length[255]',
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
            'clave_interbancaria' => $interbancariaLimpia,
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

        $body = $this->request->getJSON(true);
        $empleadosArr  = $body['empleados']      ?? [];
        $validateOnly  = (bool)($body['validate_only']   ?? false);
        $failThreshold = (float)($body['fail_threshold'] ?? 0.80);
        $allOrNothing  = (bool)($body['all_or_nothing']  ?? false);

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
                'modo_sueldo'        => in_array($emp['modo_sueldo'] ?? '', ['tabulador', 'salario'], true) ? $emp['modo_sueldo'] : 'tabulador',  // 👈 NUEVO
                'salario_mensual'    => is_numeric($emp['salario_mensual'] ?? null) && (float)$emp['salario_mensual'] > 0 ? (float)$emp['salario_mensual'] : null,  // 👈 NUEVO
    
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


    public function masivoDirecto(): mixed
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $body = $this->request->getJSON(true);
        $empleadosArr = $body['empleados'] ?? [];

        if (!is_array($empleadosArr) || count($empleadosArr) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibió el arreglo empleados[]'], 400);
        }

        $fotoDefault = $this->fotoBaseUrl . 'default.png';
        $detalle = [];

        // ── PASO 1: separa filas sin CURP/nombre ──────────────────────────
        $candidatos = [];
        foreach ($empleadosArr as $emp) {
            $row  = $emp['_row'] ?? null;
            $curp = trim($emp['curp'] ?? '');
            $nombreOriginal = trim($emp['nombre'] ?? '');

            if ($curp === '' || $nombreOriginal === '') {
                $detalle[] = ['row' => $row, 'status' => 'error', 'message' => 'Falta CURP o nombre'];
                continue;
            }

            $candidatos[] = ['row' => $row, 'curp' => strtoupper($curp), 'emp' => $emp];
        }

        // ── PASO 2: duplicados DENTRO del mismo archivo ───────────────────
        $vistos = [];
        $paraInsertar = [];
        foreach ($candidatos as $c) {
            if (isset($vistos[$c['curp']])) {
                $detalle[] = ['row' => $c['row'], 'status' => 'error', 'message' => "CURP duplicado en el archivo ({$c['curp']})"];
                continue;
            }
            $vistos[$c['curp']] = true;
            $paraInsertar[] = $c;
        }

        // ── PASO 3: bulk-fetch de CURPs que YA existen en BD ──────────────
        $curpsUnicos = array_column($paraInsertar, 'curp');
        $existentes = [];
        foreach (array_chunk($curpsUnicos, 1000) as $chunk) {
            if (!$chunk) continue;
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $db->query("SELECT curp FROM empleados WHERE curp IN ({$ph})", $chunk)->getResultArray();
            foreach ($rows as $r) $existentes[$r['curp']] = true;
        }

        $final = [];
        foreach ($paraInsertar as $c) {
            if (isset($existentes[$c['curp']])) {
                $detalle[] = ['row' => $c['row'], 'status' => 'error', 'message' => "CURP ya existe en la base de datos ({$c['curp']})"];
                continue;
            }
            $final[] = $c;
        }

        // ── PASO 4: arma $valores[] SOLO con los que sí van a insertarse ──
        $esc = fn($v) => str_replace("'", "''", (string)($v ?? ''));
        $valores = [];

        foreach ($final as $c) {
            $emp  = $c['emp'];
            $curp = $c['curp'];

            $nombre  = $this->normalizarTexto($emp['nombre'] ?? '');
            $paterno = $this->normalizarTexto($emp['paterno'] ?? '');
            $materno = $this->normalizarTexto($emp['materno'] ?? '');
            $alergias = $this->normalizarTexto($emp['alergias'] ?? '') ?: 'N/A';
            $nombreEmergencia = $this->normalizarTexto($emp['nombreEmergencia'] ?? '');

            $fecha = trim($emp['fecha_ingreso'] ?? '') ?: date('Y-m-d');
            $fechaEfectiva = trim($emp['fecha_efectiva'] ?? '') ?: $fecha;

            $idTurno       = is_numeric($emp['turno']        ?? null) ? (int)$emp['turno']        : 'NULL';
            $idPuesto      = is_numeric($emp['puesto']       ?? null) ? (int)$emp['puesto']       : 'NULL';
            $idPeriocidad  = is_numeric($emp['periodicidad'] ?? null) ? (int)$emp['periodicidad'] : 'NULL';
            $idEscolaridad = is_numeric($emp['escolaridad']  ?? null) ? (int)$emp['escolaridad']  : 'NULL';
            $idTipoSangre  = is_numeric($emp['tipoSangre']   ?? null) ? (int)$emp['tipoSangre']   : 'NULL';
            $idParentesco  = is_numeric($emp['parentesco']   ?? null) ? (int)$emp['parentesco']   : 'NULL';

            $salarioMensual = is_numeric($emp['salario_mensual'] ?? null) && (float)$emp['salario_mensual'] > 0
                ? (float)$emp['salario_mensual']
                : null;
            $modoSueldo = $salarioMensual !== null ? 'salario' : 'tabulador';

            $valores[] = "('{$esc($nombre)}','{$esc($paterno)}','{$esc($materno)}'," .
                "'{$esc($curp)}','{$esc($emp['rfc'] ?? '')}','{$esc($emp['nss'] ?? '')}'," .
                "'{$esc($emp['cp'] ?? '')}','{$esc($alergias)}'," .
                "{$idEscolaridad},{$idTipoSangre}," .
                "'{$esc($emp['telefonoEmergencia'] ?? '')}','{$esc($nombreEmergencia)}'," .
                "{$idParentesco},{$idTurno},{$idPuesto},{$idPeriocidad}," .
                "'{$fecha}','{$fechaEfectiva}'," .
                "'{$esc($emp['interbancaria'] ?? '')}'," .
                "1,1,0,'{$esc($fotoDefault)}',{$actor->id}," .
                "'{$modoSueldo}'," . ($salarioMensual !== null ? $salarioMensual : 'NULL') .
                ")";
        }

        if (empty($valores)) {
            return $this->respond([
                'status'     => 'ok',
                'message'    => 'No había filas nuevas por insertar (todas eran duplicadas o inválidas)',
                'total'      => count($empleadosArr),
                'insertados' => 0,
                'duplicados' => count($empleadosArr) - count($valores),
                'errores'    => count($detalle),
                'detalle'    => $detalle,
            ], 200);
        }

        // ── PASO 5: insertar con transacción (sin cambios respecto a antes) ──
        $insertadas = 0;
        $chunks = array_chunk($valores, 500);

        $db->transStart();

        try {
            foreach ($chunks as $chunk) {
                $db->query(
                    "INSERT IGNORE INTO empleados (nombre,paterno,materno,curp,rfc,nss,CP_fiscal,alergias,escolaridad,tipoSangre,telefonoEmergencia,nombreEmergencia,parentesco,id_turno,id_puesto,id_periocidad,fecha_ingreso,fecha_efectiva,clave_interbancaria,estatus,acceso_biometrico,is_deleted,fotos,created_by,modo_sueldo,salario_mensual) VALUES " .
                    implode(',', $chunk)
                );
                $insertadas += $db->affectedRows();
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \RuntimeException('La transacción falló internamente sin lanzar excepción');
            }

        } catch (\Throwable $e) {
            $db->transRollback();

            \App\Libraries\AuditLibrary::log(
                (int)$actor->id, 'CREAR_EMPLEADO_MASIVO_DIRECTO_ERROR', 'empleados', '-',
                "Carga directa FALLÓ y se hizo ROLLBACK completo: " . $e->getMessage()
            );

            return $this->respond([
                'status'  => 'error',
                'message' => 'La carga falló y se revirtió por completo (rollback). Nada se insertó. Detalle: ' . $e->getMessage(),
            ], 500);
        }

        $duplicadosPrevios = count($empleadosArr) - count($valores);

        \App\Libraries\AuditLibrary::log((int)$actor->id, 'CREAR_EMPLEADO_MASIVO_DIRECTO', 'empleados', '-',
            "Carga DIRECTA sin validación: {$insertadas} insertadas de " . count($empleadosArr) . " ({$duplicadosPrevios} duplicados)");

        return $this->respond([
            'status'     => 'ok',
            'message'    => 'Carga directa procesada (sin validación)',
            'total'      => count($empleadosArr),
            'insertados' => $insertadas,
            'duplicados' => $duplicadosPrevios,
            'errores'    => count($detalle),
            'detalle'    => $detalle,
        ], 201);
    }

    /**
     * POST /api/v1/empleados/actualizar-masivo-dinamico
     *
     * Actualización masiva GENÉRICA -- el ID siempre es la llave (WHERE id=?),
     * pero los CAMPOS a actualizar son dinámicos: hoy puede ser salario_mensual,
     * mañana fecha_ingreso, pasado telefonoEmergencia -- lo que traiga el Excel.
     *
     * Body: { empleados: [{ id: 177, salario_mensual: 15000, modo_sueldo: 'salario' }, ...] }
     *
     * SEGURIDAD: solo se actualizan campos que estén en la lista blanca
     * CAMPOS_ACTUALIZABLES. Cualquier campo fuera de esa lista se ignora
     * silenciosamente -- así el Excel puede traer columnas nuevas sin que
     * eso represente riesgo de inyección o de tocar columnas protegidas
     * (id, created_at, created_by, is_deleted, etc.)
     */

    /** Lista blanca de columnas que este endpoint puede tocar */
    private const CAMPOS_ACTUALIZABLES = [
        'CP_fiscal', 'fecha_ingreso', 'fecha_efectiva',
        'id_turno', 'id_puesto', 'alergias', 'fotos', 'id_periocidad',
        'tipoSangre', 'escolaridad', 'parentesco',
        'nombreEmergencia', 'telefonoEmergencia',
        'estatus', 'estado_actual', 'ultima_actividad', 'gerente', 'acceso_biometrico',
        'id_hospital', 'id_ubicacion_principal',
        'modo_sueldo', 'salario_mensual',
        'fronterizo', 'dispersion_alterna',
    ];

    public function actualizarMasivoDinamico(): mixed
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $body = $this->request->getJSON(true);
        $empleadosArr = $body['empleados'] ?? [];

        if (!is_array($empleadosArr) || count($empleadosArr) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibió el arreglo empleados[]'], 400);
        }

        $actualizados = 0;
        $camposVistos = [];
        $detalle = []; // 👈 NUEVO -- aquí se acumula el motivo por cada fila que NO se actualizó

        $db->transStart();

        try {
            foreach ($empleadosArr as $emp) {
                $row = $emp['_row'] ?? null;
                $id  = (int)($emp['id'] ?? 0);

                if ($id <= 0) {
                    $detalle[] = ['row' => $row, 'status' => 'error', 'message' => 'Fila sin id válido'];
                    continue;
                }

                $set = [];
                foreach ($emp as $campo => $valor) {
                    if ($campo === 'id' || $campo === '_row') continue;
                    if (!in_array($campo, self::CAMPOS_ACTUALIZABLES, true)) continue;
                    $set[$campo] = ($valor === '' ? null : $valor);
                    $camposVistos[$campo] = true;
                }

                if (empty($set)) {
                    $detalle[] = ['row' => $row, 'status' => 'error', 'message' => "Ninguno de los campos enviados es actualizable (id={$id})"];
                    continue;
                }

                $set['updated_at'] = date('Y-m-d H:i:s');
                $set['updated_by'] = (int)$actor->id;

                $db->table('empleados')->where('id', $id)->update($set);

                // affectedRows() en 0 significa que el id no existe o el valor ya era igual
                if ($db->affectedRows() === 0) {
                    // Verifica si el id realmente existe -- si no, es un error real;
                    // si sí existe pero no hubo cambio, no es un error (dato idéntico)
                    $existe = $db->table('empleados')->where('id', $id)->countAllResults();
                    if (!$existe) {
                        $detalle[] = ['row' => $row, 'status' => 'error', 'message' => "El id={$id} no existe en empleados"];
                        continue;
                    }
                    // Existe pero sin cambio real -- lo contamos como actualizado igual,
                    // ya que la fila SÍ fue procesada correctamente (dato ya estaba así)
                }

                $actualizados++;
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \RuntimeException('La transacción falló internamente sin lanzar excepción');
            }

        } catch (\Throwable $e) {
            $db->transRollback();

            \App\Libraries\AuditLibrary::log(
                (int)$actor->id, 'ACTUALIZAR_EMPLEADO_MASIVO_DINAMICO_ERROR', 'empleados', '-',
                "Actualización dinámica FALLÓ, rollback completo: " . $e->getMessage()
            );

            return $this->respond([
                'status'  => 'error',
                'message' => 'La actualización falló y se revirtió por completo (rollback). Detalle: ' . $e->getMessage(),
            ], 500);
        }

        \App\Libraries\AuditLibrary::log(
            (int)$actor->id, 'ACTUALIZAR_EMPLEADO_MASIVO_DINAMICO', 'empleados', '-',
            "Actualizó {$actualizados} empleados, campos: " . implode(', ', array_keys($camposVistos)) .
            " (" . count($detalle) . " con error)"
        );

        return $this->respond([
            'status'       => 'ok',
            'message'      => 'Actualización masiva procesada',
            'total'        => count($empleadosArr),
            'insertados'   => $actualizados, // 👈 se llama "insertados" para reusar el mismo contador del frontend
            'duplicados'   => 0,
            'errores'      => count($detalle),
            'detalle'      => $detalle,       // 👈 NUEVO -- ya fluye al "Exportar errores" del frontend
            'campos_actualizados' => array_keys($camposVistos),
        ], 200);
    }


    /**
     * Normaliza texto: mayúsculas, sin acentos EXCEPTO la Ñ, colapsa espacios
     * múltiples en uno solo, quita puntos/comas/símbolos raros.
     * Deja las letras A-Z, Ñ, espacios entre palabras, y nada más.
     */
    private function normalizarTexto(?string $texto): string
    {
        if ($texto === null) return '';
        $s = trim($texto);
        $s = mb_strtoupper($s, 'UTF-8');

        $s = str_replace('Ñ', '§', $s);
        $s = strtr($s, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);
        $s = str_replace('§', 'Ñ', $s);

        $s = preg_replace('/[^A-ZÑ ]/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);

        return trim($s);
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
                    'modo_sueldo'     => $this->request->getVar('modo_sueldo') ?? 'tabulador',
                    'salario_mensual' => $this->request->getVar('salario_mensual') ?: null,
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

        // ← Borra el archivo anterior si existe
        if (file_exists($destino)) {
            unlink($destino);
        }

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

    /**
     * GET /api/v1/empleados/buscar-rapido?nombre=...&curp=...&rfc=...&nss=...
     *
     * Búsqueda pública -- SIN filtro jwt (ver ruta abajo).
     * El resultado SOLO regresa no_empleado. No se exponen CURP, RFC, NSS,
     * nombre, turno ni puesto en la respuesta -- aunque se usen como criterio
     * de búsqueda, nunca se regresan en el output.
     *
     * Exige al menos 1 criterio de búsqueda no vacío.
     * no_Empleado = e.id formateado a 6 dígitos con ceros a la izquierda.
     */
    public function buscarRapido(): mixed
    {
        $q = trim((string)($this->request->getVar('q') ?? ''));

        if ($q === '') {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Escribe un nombre, CURP, RFC o NSS para buscar',
            ], 400);
        }

        $db = \Config\Database::connect();
        $like = '%' . $q . '%';

        $rows = $db->query("
            SELECT
                e.id AS no_empleado_raw,
                CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombreCompleto
            FROM empleados e
            WHERE e.is_deleted = 0
            AND (
                    e.nombre  LIKE ?
                OR e.paterno LIKE ?
                OR e.materno LIKE ?
                OR e.curp    LIKE ?
                OR e.rfc     LIKE ?
                OR e.nss     LIKE ?
                OR CONCAT_WS(' ', e.nombre, e.paterno, e.materno) LIKE ?
                OR CONCAT_WS(' ', e.paterno, e.materno, e.nombre) LIKE ?
            )
            LIMIT 50
        ", [$like, $like, $like, $like, $like, $like, $like, $like])->getResultArray();

        $resultado = array_map(fn($r) => [
            'no_empleado'    => str_pad((string)$r['no_empleado_raw'], 6, '0', STR_PAD_LEFT),
            'nombreCompleto' => $r['nombreCompleto'],
        ], $rows);

        return $this->respond([
            'status' => 'ok',
            'data'   => $resultado,
            'total'  => count($resultado),
        ]);
    }
}
