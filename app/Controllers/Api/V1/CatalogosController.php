<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CatalogoModel;

/**
 * CatalogosController
 *
 * Migración de catalogs.php legacy — 18 acciones en endpoints REST.
 * Rutas base: /api/v1/catalogos
 *
 * ── Lectura general ──────────────────────────────────────────────
 * GET  /catalogos/tipos                → getTipoCatalogos
 * GET  /catalogos/{id}                 → getCatalogos (por id_catalogo)
 * GET  /catalogos/{id}/buscar          → getCatalogosName (LIKE)
 * GET  /catalogos/{id}/select          → getCatalogosSelect (con status)
 * GET  /catalogos/banco/{clabe}        → getInstitucionBancaria
 * GET  /catalogos/regionales           → getRegionales
 * GET  /catalogos/servicios/select     → getServiciosSelect (autocomplete)
 *
 * ── Multicatálogo (admin) ────────────────────────────────────────
 * POST /catalogos/tipos                → newCatalogo (tipo catalogo)
 * POST /catalogos/{id}/items           → newMultiCatalogo (ítem)
 * PUT  /catalogos/items/{id}           → updateMultiCatalogo
 * DELETE /catalogos/items/{id}         → deleteMultiCatalogo
 *
 * ── Entidades CRUD (admin) ───────────────────────────────────────
 * Cada entidad: GET / POST / PUT /{id} / DELETE /{id}
 *   /catalogos/empresas
 *   /catalogos/partidas
 *   /catalogos/zonas
 *   /catalogos/regiones
 *   /catalogos/areas-geograficas
 *   /catalogos/clientes
 *   /catalogos/servicios
 */
class CatalogosController extends ResourceController
{
    protected $format = 'json';

    /* ═══════════════════════════════════════════════════════════════
       MULTICATÁLOGO — LECTURA
    ═══════════════════════════════════════════════════════════════ */

    /** GET /api/v1/catalogos/tipos */
    public function tipos(): mixed
    {
        return $this->respond((new CatalogoModel())->getTipoCatalogos());
    }

    /** GET /api/v1/catalogos/{id} */
    public function show($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->getCatalogo((int)$id));
    }

    /** GET /api/v1/catalogos/{id}/buscar?name=... */
    public function buscarPorNombre($id = null): mixed
    {
        $name = trim($this->request->getVar('name') ?? '');
        if ($name === '') {
            return $this->respond(['status' => 'error', 'message' => 'name es requerido'], 400);
        }
        return $this->respond((new CatalogoModel())->getCatalogoName((int)$id, $name));
    }

    /** GET /api/v1/catalogos/{id}/select */
    public function catalogosSelect($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->getCatalogosSelect((int)$id));
    }

    /** GET /api/v1/catalogos/banco/{clabe} */
    public function banco($clabe = null): mixed
    {
        if (!$clabe) {
            return $this->respond(['status' => 'error', 'message' => 'clabe es requerida'], 400);
        }
        return $this->respond((new CatalogoModel())->getInstitucionBancaria($clabe));
    }

    /** GET /api/v1/catalogos/regionales */
    public function regionales(): mixed
    {
        return $this->respond((new CatalogoModel())->getRegionales());
    }

    /** GET /api/v1/catalogos/servicios/select?query=&limit=&page= */
    public function serviciosSelect(): mixed
    {
        $query = trim($this->request->getVar('query') ?? '');
        $limit = (int)($this->request->getVar('limit') ?? 20);
        $page  = (int)($this->request->getVar('page')  ?? 1);

        $limit = max(1, min(100, $limit));
        $page  = max(1, $page);

        if (mb_strlen($query) < 2) {
            return $this->respond(['status' => 'ok', 'data' => []]);
        }

        return $this->respond((new CatalogoModel())->getServiciosSelect($query, $limit, $page));
    }

    /* ═══════════════════════════════════════════════════════════════
       MULTICATÁLOGO — ESCRITURA (admin)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/catalogos/tipos
     * Body: { descripcion }
     */
    public function crearTipo(): mixed
    {
        $desc = trim($this->request->getVar('descripcion') ?? '');
        if (!$desc) return $this->respond(['status' => 'error', 'message' => 'descripcion es requerida'], 422);
        return $this->respond((new CatalogoModel())->newCatalogo($desc), 201);
    }

    /**
     * POST /api/v1/catalogos/{id}/items
     * Body: { valor, descripcion }
     */
    public function crearItem($id = null): mixed
    {
        $valor = trim($this->request->getVar('valor')       ?? '');
        $desc  = trim($this->request->getVar('descripcion') ?? '');
        if (!$valor) return $this->respond(['status' => 'error', 'message' => 'valor es requerido'], 422);
        return $this->respond((new CatalogoModel())->newMultiCatalogo((int)$id, $valor, $desc), 201);
    }

    /**
     * PUT /api/v1/catalogos/items/{id}
     * Body: { status, valor, descripcion }
     */
    public function actualizarItem($id = null): mixed
    {
        $model = new CatalogoModel();
        return $this->respond($model->updateMultiCatalogo(
            (int)$id,
            (int)($this->request->getVar('status')      ?? 1),
            trim($this->request->getVar('valor')        ?? ''),
            trim($this->request->getVar('descripcion')  ?? '')
        ));
    }

    /**
     * DELETE /api/v1/catalogos/items/{id}
     */
    public function eliminarItem($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteMultiCatalogo((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       EMPRESAS
    ═══════════════════════════════════════════════════════════════ */

    /** GET /api/v1/catalogos/empresas */
    public function empresas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectEmpresas());
    }

    /** POST /api/v1/catalogos/empresas — Body: { empresa } */
    public function crearEmpresa(): mixed
    {
        $empresa = trim($this->request->getVar('empresa') ?? '');
        if (!$empresa) return $this->respond(['status' => 'error', 'message' => 'empresa es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertEmpresa($empresa), 201);
    }

    /** PUT /api/v1/catalogos/empresas/{id} — Body: { empresa, status? } */
    public function actualizarEmpresa($id = null): mixed
    {
        $model = new CatalogoModel();
        return $this->respond($model->updateEmpresa(
            (int)$id,
            trim($this->request->getVar('empresa') ?? ''),
            (int)($this->request->getVar('status') ?? $this->request->getVar('estatus') ?? 1)
        ));
    }

    /** DELETE /api/v1/catalogos/empresas/{id} */
    public function eliminarEmpresa($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteEmpresa((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       PARTIDAS
    ═══════════════════════════════════════════════════════════════ */

    public function partidas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectPartidas());
    }

    public function crearPartida(): mixed
    {
        $partida = trim($this->request->getVar('partida') ?? '');
        if (!$partida) return $this->respond(['status' => 'error', 'message' => 'partida es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertPartida($partida), 201);
    }

    public function actualizarPartida($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updatePartida(
            (int)$id,
            trim($this->request->getVar('partida') ?? ''),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarPartida($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deletePartida((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       ZONAS
    ═══════════════════════════════════════════════════════════════ */

    public function zonas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectZonas());
    }

    public function crearZona(): mixed
    {
        $zona = trim($this->request->getVar('zona') ?? '');
        if (!$zona) return $this->respond(['status' => 'error', 'message' => 'zona es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertZona($zona), 201);
    }

    public function actualizarZona($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateZona(
            (int)$id,
            trim($this->request->getVar('zona') ?? ''),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarZona($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteZona((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       REGIONES
    ═══════════════════════════════════════════════════════════════ */

    public function regionesList(): mixed
    {
        return $this->respond((new CatalogoModel())->selectRegiones());
    }

    public function crearRegion(): mixed
    {
        $estado = trim($this->request->getVar('estado')            ?? '');
        $area   = (int)($this->request->getVar('id_area_geografica') ?? 0);
        if (!$estado || !$area) return $this->respond(['status' => 'error', 'message' => 'estado e id_area_geografica son requeridos'], 422);
        return $this->respond((new CatalogoModel())->insertRegion($estado, $area), 201);
    }

    public function actualizarRegion($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateRegion(
            (int)$id,
            trim($this->request->getVar('estado') ?? ''),
            (int)($this->request->getVar('id_area_geografica') ?? 0),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarRegion($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteRegion((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       ÁREAS GEOGRÁFICAS
    ═══════════════════════════════════════════════════════════════ */

    public function areasGeograficas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectAreaGeografica());
    }

    public function regionalesGerentes(): mixed
    {
        return $this->respond((new CatalogoModel())->selectRegionalesGerentes());
    }

    public function actualizarArea($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateAreaGeografica(
            (int)$id,
            (int)($this->request->getVar('id_regional') ?? 0),
            trim($this->request->getVar('region')       ?? ''),
            (int)($this->request->getVar('status')      ?? 1)
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       CLIENTES
    ═══════════════════════════════════════════════════════════════ */

    public function clientes(): mixed
    {
        return $this->respond((new CatalogoModel())->selectClientes());
    }

    public function crearCliente(): mixed
    {
        $rules = [
            'razon_social' => 'required|max_length[255]',
            'nombre_corto' => 'required|max_length[120]',
            'id_empresa'   => 'required|integer',
            'id_partida'   => 'required|integer',
        ];
        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }
        return $this->respond((new CatalogoModel())->insertCliente(
            $this->request->getVar('razon_social'),
            $this->request->getVar('nombre_corto'),
            (int)$this->request->getVar('id_empresa'),
            (int)$this->request->getVar('id_partida')
        ), 201);
    }

    public function actualizarCliente($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateCliente(
            (int)$id,
            trim($this->request->getVar('razon_social') ?? ''),
            trim($this->request->getVar('nombre_corto') ?? ''),
            (int)($this->request->getVar('id_empresa')  ?? 0),
            (int)($this->request->getVar('id_partida')  ?? 0),
            (int)($this->request->getVar('status')      ?? 1)
        ));
    }

    public function eliminarCliente($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteCliente((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       SERVICIOS
    ═══════════════════════════════════════════════════════════════ */

    public function servicios(): mixed
    {
        return $this->respond((new CatalogoModel())->selectServicios());
    }

    public function crearServicio(): mixed
    {
        $rules = [
            'servicio'   => 'required|max_length[255]',
            'ubicacion'  => 'permit_empty|max_length[255]',
            'cp'         => 'permit_empty|exact_length[5]',
            'latitud'    => 'permit_empty|decimal',
            'longitud'   => 'permit_empty|decimal',
            'id_cliente' => 'required|integer',
            'id_empresa' => 'required|integer',
            'id_partida' => 'required|integer',
            'id_zona'    => 'required|integer',
            'status'     => 'required|integer',
        ];
        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }
    
        $data = (array)$this->request->getVar();
        return $this->respond((new CatalogoModel())->insertServicio($data ?: []), 201);
    }
    
    public function actualizarServicio($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateServicio((int)$id, (array)$this->request->getVar() ?: []));
    }

    public function eliminarServicio($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteServicio((int)$id));
    }

    public function masivoServicios(): mixed
    {
        $actor = $this->request->jwtUser;
        $model = new CatalogoModel();
        $db    = \Config\Database::connect();

        // ── FIX: decodifica el JSON completo como array asociativo, no con
        // getVar() que regresa stdClass para los items anidados y truena
        // al hacer $s['servicio'] más abajo ──
        $body = $this->request->getJSON(true);

        $serviciosArr  = $body['servicios']       ?? [];
        $validateOnly  = (bool)($body['validate_only']   ?? false);
        $failThreshold = (float)($body['fail_threshold'] ?? 0.80);
        $allOrNothing  = (bool)($body['all_or_nothing']  ?? false);

        if (!is_array($serviciosArr) || count($serviciosArr) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibió el arreglo servicios[]'], 400);
        }

        $total    = count($serviciosArr);
        $errores  = 0;
        $dupCount = 0;
        $prevalid = [];

        // ── Pre-validación ───────────────────────────────────────────
        foreach ($serviciosArr as $idx => $s) {
            $row    = isset($s['_row']) ? (int)$s['_row'] : ($idx + 1);
            $rowErr = [];

            $servicio  = trim($s['servicio']   ?? '');
            $idCliente = trim((string)($s['id_cliente'] ?? ''));
            $idEmpresa = trim((string)($s['id_empresa'] ?? ''));
            $idPartida = trim((string)($s['id_partida'] ?? ''));
            $idZona    = trim((string)($s['id_zona']    ?? ''));
            $cp        = preg_replace('/\D/', '', $s['cp'] ?? '');

            if ($servicio === '')  $rowErr[] = 'Servicio (nombre) obligatorio';
            if ($idCliente === '') $rowErr[] = 'id_cliente obligatorio';
            if ($idEmpresa === '') $rowErr[] = 'id_empresa obligatorio';
            if ($idPartida === '') $rowErr[] = 'id_partida obligatorio';
            if ($idZona === '')    $rowErr[] = 'id_zona obligatorio';
            if ($cp !== '' && !preg_match('/^\d{5}$/', $cp)) $rowErr[] = 'CP inválido (5 dígitos)';

            // Duplicado en BD: mismo nombre de servicio + misma zona, activo
            if ($servicio !== '' && $idZona !== '') {
                $existe = $db->table('servicios')
                    ->where('servicio', $servicio)
                    ->where('id_zona', (int)$idZona)
                    ->where('estatus', 1)
                    ->countAllResults();
                if ($existe > 0) {
                    $dupCount++;
                    $rowErr[] = 'Duplicado en BD: ya existe ese servicio en esa zona';
                }
            }

            $ok = count($rowErr) === 0;
            $prevalid[] = ['row' => $row, 'ok' => $ok, 'errors' => $rowErr, 'data' => $s];
            if (!$ok) $errores++;
        }

        $detalleResumen = fn($x) => ['row' => $x['row'], 'status' => $x['ok'] ? 'ok' : 'error', 'message' => $x['ok'] ? 'OK' : implode(' | ', $x['errors'])];

        // ── Umbral de fallo ──────────────────────────────────────────
        if ($total > 0 && ($errores / $total) >= $failThreshold) {
            return $this->respond([
                'status'     => 'error',
                'message'    => 'Lote cancelado: ' . round(($errores / $total) * 100, 1) . '% de errores supera el umbral',
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

        if ($allOrNothing) $db->transStart();

        foreach ($prevalid as $item) {
            if (!$item['ok']) {
                $detalle[] = ['row' => $item['row'], 'status' => 'error', 'message' => implode(' | ', $item['errors'])];
                continue;
            }

            $s = $item['data'];

            $datos = [
                'servicio'   => trim($s['servicio']),
                'elementos'  => trim((string)($s['elementos'] ?? '')),
                'ubicacion'  => trim($s['ubicacion'] ?? ''),
                'cp'         => preg_replace('/\D/', '', $s['cp'] ?? ''),
                'latitud'    => trim((string)($s['latitud']  ?? '0')) ?: '0',
                'longitud'   => trim((string)($s['longitud'] ?? '0')) ?: '0',
                'id_cliente' => (int)$s['id_cliente'],
                'id_empresa' => (int)$s['id_empresa'],
                'id_partida' => (int)$s['id_partida'],
                'id_zona'    => (int)$s['id_zona'],
            ];

            $res = $model->insertServicio($datos);

            if ($res['status'] === 'ok') {
                $insertados++;
                $detalle[] = ['row' => $item['row'], 'status' => 'ok', 'message' => 'OK'];
                \App\Libraries\AuditLibrary::log((int)$actor->id, 'CREAR_SERVICIO_MASIVO', 'servicios', (string)($res['id'] ?? ''), 'Carga masiva');
            } else {
                $errores++;
                $detalle[] = ['row' => $item['row'], 'status' => 'error', 'message' => $res['mensaje'] ?? 'Error desconocido'];
                if ($allOrNothing) {
                    $db->transRollback();
                    return $this->respond(['status' => 'error', 'message' => 'Rollback: fallo en fila ' . $item['row'], 'detalle' => $detalle], 500);
                }
            }
        }

        if ($allOrNothing) $db->transComplete();

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
}
