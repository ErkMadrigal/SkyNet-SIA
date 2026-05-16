<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

class DashboardController extends ResourceController
{
    protected $format = 'json';

    /* GET /api/v1/dashboard/resumen */
    public function resumen(): mixed
    {
        $db  = \Config\Database::connect();
        $hoy = date('Y-m-d');

        // Entradas de hoy por zona
        $entradas = $db->query("
            SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
            FROM asistencias a
            JOIN servicios s ON a.id_ubicacion = s.id
            WHERE a.fecha = ? AND a.id_status = 1
            GROUP BY s.id_zona
        ", [$hoy])->getResultArray();

        // Salidas de hoy por zona
        $salidas = $db->query("
            SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
            FROM asistencias a
            JOIN servicios s ON a.id_ubicacion = s.id
            WHERE a.fecha = ? AND a.id_status = 2
            GROUP BY s.id_zona
        ", [$hoy])->getResultArray();

        // Total empleados activos por zona
        $totales = $db->query("
            SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
            FROM asistencias a
            JOIN servicios s ON a.id_ubicacion = s.id
            JOIN empleados e ON a.id_empleado = e.id
            WHERE e.estatus = 1 AND e.is_deleted = 0
              AND a.fecha >= DATE_SUB(?, INTERVAL 30 DAY)
            GROUP BY s.id_zona
        ", [$hoy])->getResultArray();

        // Zonas
        $zonas = $db->query("SELECT id, zona FROM zonas ORDER BY zona ASC")->getResultArray();

        // Indexar por zona
        $entradasMap = array_column($entradas, 'total', 'id_zona');
        $salidasMap  = array_column($salidas,  'total', 'id_zona');
        $totalesMap  = array_column($totales,  'total', 'id_zona');

        $data = array_map(function($z) use ($entradasMap, $salidasMap, $totalesMap) {
            $total    = (int)($totalesMap[$z['id']]  ?? 0);
            $entradas = (int)($entradasMap[$z['id']] ?? 0);
            $salidas  = (int)($salidasMap[$z['id']]  ?? 0);
            $faltas   = max(0, $total - $entradas);
            $pct      = $total > 0 ? round($entradas / $total * 100) : 0;

            return [
                'id_zona'  => (int)$z['id'],
                'zona'     => $z['zona'],
                'total'    => $total,
                'entradas' => $entradas,
                'salidas'  => $salidas,
                'faltas'   => $faltas,
                'pct'      => $pct,
            ];
        }, $zonas);

        // Solo zonas con empleados
        $data = array_values(array_filter($data, fn($z) => $z['total'] > 0));

        return $this->respond(['status' => 'ok', 'fecha' => $hoy, 'data' => $data]);
    }

    /* GET /api/v1/dashboard/zona/:id */
    public function zona(int $idZona): mixed
    {
        $db  = \Config\Database::connect();
        $hoy = date('Y-m-d');

        $servicios = $db->query("
            SELECT
                s.id, s.servicio,
                COUNT(DISTINCT e.id) AS total_empleados,
                SUM(CASE WHEN a.fecha = ? AND a.id_status = 1 THEN 1 ELSE 0 END) AS entradas,
                SUM(CASE WHEN a.fecha = ? AND a.id_status = 2 THEN 1 ELSE 0 END) AS salidas
            FROM servicios s
            JOIN asistencias a ON a.id_ubicacion = s.id
            JOIN empleados e ON a.id_empleado = e.id
            WHERE s.id_zona = ? AND e.estatus = 1 AND e.is_deleted = 0
              AND a.fecha >= DATE_SUB(?, INTERVAL 30 DAY)
            GROUP BY s.id, s.servicio
            ORDER BY entradas DESC
        ", [$hoy, $hoy, $idZona, $hoy])->getResultArray();

        $zona = $db->query("SELECT zona FROM zonas WHERE id = ?", [$idZona])->getRowArray();

        return $this->respond([
            'status'    => 'ok',
            'fecha'     => $hoy,
            'zona'      => $zona['zona'] ?? '',
            'id_zona'   => $idZona,
            'servicios' => array_map(fn($s) => [
                'id'               => (int)$s['id'],
                'servicio'         => $s['servicio'],
                'total_empleados'  => (int)$s['total_empleados'],
                'entradas'         => (int)$s['entradas'],
                'salidas'          => (int)$s['salidas'],
                'faltas'           => max(0, (int)$s['total_empleados'] - (int)$s['entradas']),
            ], $servicios),
        ]);
    }
}