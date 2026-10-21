<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\DashboardModel;

class DashboardController extends ResourceController
{
    protected $format = 'json';

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/resumen
    ───────────────────────────────────────── */
    public function resumen(): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->resumen($hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status' => 'ok',
            'fecha'  => $hoy,
            'data'   => $result['data'],
        ]);
    }

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/zona/:id
    ───────────────────────────────────────── */
    public function zona(int $idZona): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->zona($idZona, $hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status'    => 'ok',
            'fecha'     => $hoy,
            'zona'      => $result['zona'],
            'id_zona'   => $result['id_zona'],
            'servicios' => $result['data'],
        ]);
    }

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/control-area
    ───────────────────────────────────────── */
    public function controlArea(): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->controlArea($hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status' => 'ok',
            'fecha'  => $hoy,
            'data'   => $result['data'],
        ]);
    }

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/mapa-servicios?fecha=YYYY-MM-DD
       Servicios georreferenciados con las entradas/salidas del día.
    ───────────────────────────────────────── */
    public function mapaServicios(): mixed
    {
        $actor = $this->request->jwtUser;
        if ((int)($actor->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Solo administradores'], 403);
        }
        $fecha = (string)($this->request->getVar('fecha') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        }
        try {
            $rows = \Config\Database::connect()->query("
                SELECT s.id, s.servicio, s.cp, s.elementos,
                       CAST(s.latitud AS DECIMAL(10,6)) AS latitud, CAST(s.longitud AS DECIMAL(10,6)) AS longitud,
                       COALESCE(x.entradas, 0) AS entradas, COALESCE(x.salidas, 0) AS salidas, x.ultima
                FROM servicios s
                LEFT JOIN (
                    SELECT id_ubicacion,
                           COUNT(DISTINCT CASE WHEN id_status = 1 THEN id_empleado END) AS entradas,
                           COUNT(DISTINCT CASE WHEN id_status = 2 THEN id_empleado END) AS salidas,
                           MAX(hora) AS ultima
                    FROM asistencias WHERE fecha = ? GROUP BY id_ubicacion
                ) x ON x.id_ubicacion = s.id
                WHERE s.estatus = 1
                  AND CAST(s.latitud AS DECIMAL(10,6)) BETWEEN 14 AND 33
                  AND CAST(s.longitud AS DECIMAL(10,6)) BETWEEN -119 AND -86
                ORDER BY s.servicio
            ", [$fecha])->getResultArray();
            return $this->respond(['status' => 'ok', 'fecha' => $fecha, 'data' => $rows]);
        } catch (\Exception $e) {
            return $this->respond(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /* GET /api/v1/dashboard/mapa-servicios/(:num)/empleados?fecha=  -> registros del servicio en el día */
    public function mapaServicioEmpleados($idServicio = null): mixed
    {
        $actor = $this->request->jwtUser;
        if ((int)($actor->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Solo administradores'], 403);
        }
        $fecha = (string)($this->request->getVar('fecha') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        }
        try {
            $rows = \Config\Database::connect()->query("
                SELECT a.id_empleado, TRIM(CONCAT_WS(' ', e.nombre, e.paterno, e.materno)) AS nombre,
                       e.fotos, a.hora, a.id_status
                FROM asistencias a
                INNER JOIN empleados e ON e.id = a.id_empleado
                WHERE a.id_ubicacion = ? AND a.fecha = ? AND a.id_status IN (1, 2)
                ORDER BY a.hora ASC
            ", [(int)$idServicio, $fecha])->getResultArray();

            $porEmp = [];
            foreach ($rows as $r) {
                $k = (int)$r['id_empleado'];
                $porEmp[$k] ??= ['id' => $k, 'nombre' => $r['nombre'], 'fotos' => $r['fotos'], 'entrada' => null, 'salida' => null];
                if ((int)$r['id_status'] === 1 && $porEmp[$k]['entrada'] === null) $porEmp[$k]['entrada'] = $r['hora'];
                if ((int)$r['id_status'] === 2) $porEmp[$k]['salida'] = $r['hora'];
            }
            return $this->respond(['status' => 'ok', 'fecha' => $fecha, 'data' => array_values($porEmp)]);
        } catch (\Exception $e) {
            return $this->respond(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
