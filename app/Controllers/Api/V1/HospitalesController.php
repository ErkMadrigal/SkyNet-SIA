<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\HospitalModel;
use App\Libraries\AuditLibrary;

/**
 * HospitalesController
 * Migración de hospitals.php + ConsultasHospitals.php legacy.
 * Rutas base: /api/v1/hospitales
 *
 * GET  /hospitales                         → listar hospitales
 * GET  /hospitales/{id}                    → detalle hospital
 * POST /hospitales/{id}/asignar-empleado   → actualizar hospital del empleado logueado
 *
 * GET  /hospitales/productos               → listar productos activos
 * GET  /hospitales/productos/{id}          → detalle producto
 *
 * GET  /hospitales/{id}/recepciones        → listar entradas
 * POST /hospitales/{id}/recepciones        → registrar entrada
 *
 * GET  /hospitales/{id}/salidas            → listar salidas
 * POST /hospitales/{id}/salidas            → registrar salida
 *
 * GET  /hospitales/{id}/inventario         → inventario completo del hospital
 * GET  /hospitales/{id}/inventario/{prod}  → stock de un producto
 */
class HospitalesController extends ResourceController
{
    protected $format = 'json';

    /* ── HOSPITALES ──────────────────────────────────────── */

    public function index(): mixed
    {
        return $this->respond((new HospitalModel())->listarHospitales());
    }

    public function show($id = null): mixed
    {
        return $this->respond((new HospitalModel())->getHospital((int)$id));
    }

    /**
     * POST /api/v1/hospitales/{id}/asignar-empleado
     * Asigna el hospital al empleado del JWT (equivalente a actualizar_hospital).
     */
    public function asignarEmpleado($id = null): mixed
    {
        $actor = $this->request->jwtUser;

        if (!(int)$id) {
            return $this->respond(['status' => 'error', 'message' => 'id_hospital requerido'], 400);
        }

        $res = (new HospitalModel())->actualizarHospitalEmpleado((int)$actor->id, (int)$id);
        AuditLibrary::log((int)$actor->id, 'ASIGNAR_HOSPITAL', 'empleados', (string)$actor->id, "Hospital {$id}");
        return $this->respond($res);
    }

    /* ── PRODUCTOS ───────────────────────────────────────── */

    public function productos(): mixed
    {
        return $this->respond((new HospitalModel())->listarProductos());
    }

    public function producto($id = null): mixed
    {
        return $this->respond((new HospitalModel())->getProducto((int)$id));
    }

    /* ── RECEPCIONES (ENTRADAS) ──────────────────────────── */

    /**
     * GET  /api/v1/hospitales/{id}/recepciones
     * POST /api/v1/hospitales/{id}/recepciones
     */
    public function recepciones($id = null): mixed
    {
        $model = new HospitalModel();

        if ($this->request->getMethod() === 'get') {
            return $this->respond($model->listarRecepciones((int)$id));
        }

        // POST — registrar entrada
        $actor      = $this->request->jwtUser;
        $idProducto = (int)($this->request->getVar('id_producto') ?? 0);
        $cantidad   = (float)($this->request->getVar('cantidad')   ?? 0);
        $obs        = trim($this->request->getVar('observaciones') ?? '');

        if (!$idProducto || $cantidad <= 0 || !(int)$id) {
            return $this->respond(['status' => 'error', 'message' => 'id_producto, cantidad e id_hospital son requeridos'], 422);
        }

        $res = $model->registrarRecepcion((int)$actor->id, $idProducto, $cantidad, (int)$id, $obs);
        AuditLibrary::log((int)$actor->id, 'RECEPCION', 'recepcion', (string)($res['last_insert_id'] ?? ''), "Producto {$idProducto} x{$cantidad}");
        return $this->respond($res, 201);
    }

    /* ── SALIDAS ─────────────────────────────────────────── */

    /**
     * GET  /api/v1/hospitales/{id}/salidas
     * POST /api/v1/hospitales/{id}/salidas
     */
    public function salidas($id = null): mixed
    {
        $model = new HospitalModel();

        if ($this->request->getMethod() === 'get') {
            return $this->respond($model->listarSalidas((int)$id));
        }

        // POST — registrar salida
        $actor      = $this->request->jwtUser;
        $idProducto = (int)($this->request->getVar('id_producto') ?? 0);
        $cantidad   = (float)($this->request->getVar('cantidad')   ?? 0);
        $motivo     = trim($this->request->getVar('motivo')        ?? '');

        if (!$idProducto || $cantidad <= 0 || !(int)$id) {
            return $this->respond(['status' => 'error', 'message' => 'id_producto, cantidad e id_hospital son requeridos'], 422);
        }

        $res = $model->registrarSalida((int)$actor->id, $idProducto, $cantidad, (int)$id, $motivo);
        AuditLibrary::log((int)$actor->id, 'SALIDA', 'salida', (string)($res['last_insert_id'] ?? ''), "Producto {$idProducto} x{$cantidad}");
        return $this->respond($res, $res['status'] === 'ok' ? 201 : 422);
    }

    /* ── INVENTARIO ──────────────────────────────────────── */

    public function inventario($id = null): mixed
    {
        return $this->respond((new HospitalModel())->getInventario((int)$id));
    }

    public function stockProducto($idHospital = null, $idProducto = null): mixed
    {
        return $this->respond((new HospitalModel())->getStockProducto((int)$idProducto, (int)$idHospital));
    }
}
