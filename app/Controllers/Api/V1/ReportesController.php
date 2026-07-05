<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ReportModel;
use App\Models\TabuladorModel;

/**
 * ReportesController
 *
 * Migración de all-reports.php legacy.
 * Rutas base: /api/v1/reportes  y  /api/v1/tabulador
 *
 * ── Reportes ─────────────────────────────────────────────────────
 * GET  /reportes/altas          → altas en periodo
 * GET  /reportes/bajas          → bajas en periodo
 * GET  /reportes/nomina/{id}    → nómina simple de un empleado
 * GET  /reportes/asistencia     → preview paginado 24x24
 * POST /reportes/prenomina      → pre-nómina agrupada por zona
 *
 * ── Tabulador ────────────────────────────────────────────────────
 * GET  /tabulador/zonas         → catálogo de zonas
 * GET  /tabulador/puestos       → catálogo de puestos
 * GET  /tabulador               → lista tabuladores
 * POST /tabulador               → crear tabulador
 * GET  /tabulador/{id}          → detalle con ítems
 * POST /tabulador/{id}/item     → upsert ítem
 * DELETE /tabulador/item/{id}   → deshabilitar ítem
 * PATCH /tabulador/{id}/estatus → cambiar estatus
 */
class ReportesController extends ResourceController
{
    protected $format = 'json';

    /* ═══════════════════════════════════════════════════════════════
       REPORTES
    ═══════════════════════════════════════════════════════════════ */

    /**
     * GET /api/v1/reportes/altas?fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
     */
    public function altas(): mixed
    {
        $inicio = trim($this->request->getVar('fecha_inicio') ?? '');
        $fin    = trim($this->request->getVar('fecha_fin')    ?? '');

        if (!$inicio || !$fin) {
            return $this->respond(['status' => 'error', 'message' => 'fecha_inicio y fecha_fin son requeridas'], 400);
        }

        $model = new ReportModel();
        return $this->respond($model->reporteAltas($inicio, $fin));
    }

    /**
     * GET /api/v1/reportes/bajas?fecha_inicio=YYYY-MM-DD&fecha_fin=YYYY-MM-DD
     */
    public function bajas(): mixed
    {
        $inicio = trim($this->request->getVar('fecha_inicio') ?? '');
        $fin    = trim($this->request->getVar('fecha_fin')    ?? '');

        if (!$inicio || !$fin) {
            return $this->respond(['status' => 'error', 'message' => 'fecha_inicio y fecha_fin son requeridas'], 400);
        }

        $model = new ReportModel();
        return $this->respond($model->reporteBajas($inicio, $fin));
    }

    /**
     * GET /api/v1/reportes/nomina/{id}?fecha_Inicio=YYYY-MM-DD&fecha_Final=YYYY-MM-DD
     */
    public function nomina($id = null): mixed
    {
        $inicio = trim($this->request->getVar('fecha_Inicio') ?? '');
        $fin    = trim($this->request->getVar('fecha_Final')  ?? '');

        if (!$id || !$inicio || !$fin) {
            return $this->respond(['status' => 'error', 'message' => 'id_empleado, fecha_Inicio y fecha_Final son requeridos'], 400);
        }

        $model = new ReportModel();
        return $this->respond($model->getNominaEmpleado((int)$id, $inicio, $fin));
    }

    /**
     * GET /api/v1/reportes/asistencia
     * Parámetros: fecha_inicio, fecha_fin, id_usuario (opcional), offset (opcional)
     */
    public function asistencia(): mixed
    {
        $inicio    = trim($this->request->getVar('fecha_inicio') ?? '');
        $fin       = trim($this->request->getVar('fecha_fin')    ?? '');
        $idUsuario = (int)($this->request->getVar('id_usuario')  ?? 0);
        $offset    = (int)($this->request->getVar('offset')      ?? 0);

        if (!$inicio || !$fin) {
            return $this->respond(['status' => 'error', 'message' => 'fecha_inicio y fecha_fin son requeridas'], 400);
        }

        $model = new ReportModel();
        return $this->respond($model->reporteAsistencia($inicio, $fin, $idUsuario, $offset));
    }

    /**
     * POST /api/v1/reportes/prenomina
     * Body: { fecha_Inicio, fecha_Final, id_zona }
     */
    public function prenomina(): mixed
    {
        $inicio = trim($this->request->getVar('fecha_Inicio') ?? '');
        $fin    = trim($this->request->getVar('fecha_Final')  ?? '');
        $idZona = (int)($this->request->getVar('id_zona')     ?? 0);

        if (!$inicio || !$fin || !$idZona) {
            return $this->respond(['status' => 'error', 'message' => 'fecha_Inicio, fecha_Final e id_zona son requeridos'], 400);
        }

        $model = new ReportModel();
        return $this->respond($model->reporteAsistenciasZonaAgrupado($inicio, $fin, $idZona));
    }

    /* ═══════════════════════════════════════════════════════════════
       TABULADOR
    ═══════════════════════════════════════════════════════════════ */

    /**
     * GET /api/v1/tabulador/zonas
     */
    public function zonas(): mixed
    {
        return $this->respond((new TabuladorModel())->getZonas());
    }

    /**
     * GET /api/v1/tabulador/puestos
     */
    public function puestos(): mixed
    {
        return $this->respond((new TabuladorModel())->getPuestos());
    }

    /**
     * GET /api/v1/tabulador
     */
    public function index(): mixed
    {
        return $this->respond((new TabuladorModel())->listar());
    }

    /**
     * POST /api/v1/tabulador
     * Body: { id_zona, nombre, vigencia_inicio, vigencia_fin? }
     */
    public function create(): mixed
    {
        $rules = [
            'id_zona'         => 'required|integer',
            'nombre'          => 'required|max_length[120]',
            'vigencia_inicio' => 'required|valid_date[Y-m-d]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new TabuladorModel();
        return $this->respond($model->crear(
            (int)$this->request->getVar('id_zona'),
            $this->request->getVar('nombre'),
            $this->request->getVar('vigencia_inicio'),
            $this->request->getVar('vigencia_fin')
        ), 201);
    }

    /**
     * GET /api/v1/tabulador/{id}
     */
    public function show($id = null): mixed
    {
        return $this->respond((new TabuladorModel())->getDetalle((int)$id));
    }

    /**
     * POST /api/v1/tabulador/{id}/item
     * Body: { id_puesto, sueldo, bono, descuento }
     */
    public function upsertItem($id = null): mixed
    {
        $rules = [
            'id_puesto' => 'required|integer',
            'sueldo'    => 'required|decimal',
            'bono'      => 'permit_empty|decimal',
            'descuento' => 'permit_empty|decimal',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new TabuladorModel();
        return $this->respond($model->upsertItem(
            (int)$id,
            (int)$this->request->getVar('id_puesto'),
            (float)($this->request->getVar('sueldo')    ?? 0),
            (float)($this->request->getVar('bono')      ?? 0),
            (float)($this->request->getVar('descuento') ?? 0),
        ));
    }

    /**
     * DELETE /api/v1/tabulador/item/{id}
     */
    public function deshabilitarItem($id = null): mixed
    {
        return $this->respond((new TabuladorModel())->deshabilitarItem((int)$id));
    }

    /**
     * PATCH /api/v1/tabulador/{id}/estatus
     * Body: { estatus: 0|1 }
     */
    public function setEstatus($id = null): mixed
    {
        $estatus = (int)($this->request->getVar('estatus') ?? -1);

        if (!in_array($estatus, [0, 1], true)) {
            return $this->respond(['status' => 'error', 'message' => 'estatus debe ser 0 o 1'], 422);
        }

        return $this->respond((new TabuladorModel())->setEstatus((int)$id, $estatus));
    }

    /**
     * PUT /api/v1/tabulador/{id}
     * Body: { id_zona, nombre, vigencia_inicio, vigencia_fin?, estatus }
     * Agregar esta ruta en Routes.php dentro del grupo 'tabulador':
     *   $routes->put('(:num)', 'Api\V1\ReportesController::update/$1');
     */
    public function update($id = null): mixed
    {
        $rules = [
            'id_zona'         => 'required|integer',
            'nombre'          => 'permit_empty|max_length[120]',
            'vigencia_inicio' => 'required|valid_date[Y-m-d]',
            'estatus'         => 'permit_empty|in_list[0,1]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new TabuladorModel();
        return $this->respond($model->actualizar(
            (int)$id,
            (int)$this->request->getVar('id_zona'),
            $this->request->getVar('nombre') ?? '',
            $this->request->getVar('vigencia_inicio'),
            $this->request->getVar('vigencia_fin'),
            (int)($this->request->getVar('estatus') ?? 1)
        ));
    }

    /**
     * POST /api/v1/tabulador/{id}/duplicar
     * Body: { id_zona, nombre, vigencia_inicio, vigencia_fin? }
     * Clona el tabulador {id} (origen) con todos sus items activos,
     * pero con la nueva zona/nombre/vigencia que envíes.
     *
     * Agregar esta ruta en Routes.php dentro del grupo 'tabulador':
     *   $routes->post('(:num)/duplicar', 'Api\V1\ReportesController::duplicar/$1');
     */
    public function duplicar($id = null): mixed
    {
        $rules = [
            'id_zona'         => 'required|integer',
            'nombre'          => 'permit_empty|max_length[120]',
            'vigencia_inicio' => 'required|valid_date[Y-m-d]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new TabuladorModel();
        $res = $model->duplicar(
            (int)$id,
            (int)$this->request->getVar('id_zona'),
            $this->request->getVar('nombre') ?? '',
            $this->request->getVar('vigencia_inicio'),
            $this->request->getVar('vigencia_fin')
        );

        return $this->respond($res, $res['status'] === 'ok' ? 201 : 422);
    }
}
