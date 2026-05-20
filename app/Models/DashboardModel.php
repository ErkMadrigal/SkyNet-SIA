<?php

namespace App\Models;

use CodeIgniter\Model;

class DashboardModel extends Model
{
    protected $table      = 'asistencias';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    /* ─────────────────────────────────────────
       HELPERS
    ───────────────────────────────────────── */

    private function ok($data = [], array $extra = []): array
    {
        return array_merge(['status' => 'ok', 'data' => $data], $extra);
    }

    private function fail(string $msg): array
    {
        return ['status' => 'error', 'data' => [], 'mensaje' => $msg];
    }

    private function calcEstatus(int $pct): string
    {
        if ($pct >= 90) return 'completa';
        if ($pct >= 60) return 'parcial';
        return 'deficit';
    }

    /* ─────────────────────────────────────────
       RESUMEN POR ZONA
    ───────────────────────────────────────── */

    /**
     * GET /api/v1/dashboard/resumen
     * Entradas, salidas, faltas y % por zona para el día actual.
     */
    public function resumen(string $fecha): array
    {
        try {
            // Entradas de hoy por zona
            $entradas = $this->db->query("
                SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
                FROM asistencias a
                JOIN servicios s ON a.id_ubicacion = s.id
                WHERE a.fecha = ? AND a.id_status = 1
                GROUP BY s.id_zona
            ", [$fecha])->getResultArray();

            // Salidas de hoy por zona
            $salidas = $this->db->query("
                SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
                FROM asistencias a
                JOIN servicios s ON a.id_ubicacion = s.id
                WHERE a.fecha = ? AND a.id_status = 2
                GROUP BY s.id_zona
            ", [$fecha])->getResultArray();

            // Total empleados activos por zona (últimos 30 días)
            $totales = $this->db->query("
                SELECT s.id_zona, COUNT(DISTINCT a.id_empleado) AS total
                FROM asistencias a
                JOIN servicios s ON a.id_ubicacion = s.id
                JOIN empleados e ON a.id_empleado = e.id
                WHERE e.estatus = 1 AND e.is_deleted = 0
                  AND a.fecha >= DATE_SUB(?, INTERVAL 30 DAY)
                GROUP BY s.id_zona
            ", [$fecha])->getResultArray();

            // Todas las zonas
            $zonas = $this->db->query(
                "SELECT id, zona FROM zonas ORDER BY zona ASC"
            )->getResultArray();

            $entradasMap = array_column($entradas, 'total', 'id_zona');
            $salidasMap  = array_column($salidas,  'total', 'id_zona');
            $totalesMap  = array_column($totales,  'total', 'id_zona');

            $data = array_map(function ($z) use ($entradasMap, $salidasMap, $totalesMap) {
                $total    = (int)($totalesMap[$z['id']]  ?? 0);
                $entradas = (int)($entradasMap[$z['id']] ?? 0);
                $salidas  = (int)($salidasMap[$z['id']]  ?? 0);
                $pct      = $total > 0 ? round($entradas / $total * 100) : 0;

                return [
                    'id_zona'  => (int)$z['id'],
                    'zona'     => $z['zona'],
                    'total'    => $total,
                    'entradas' => $entradas,
                    'salidas'  => $salidas,
                    'faltas'   => max(0, $total - $entradas),
                    'pct'      => $pct,
                ];
            }, $zonas);

            // Solo zonas con empleados
            $data = array_values(array_filter($data, fn($z) => $z['total'] > 0));

            return $this->ok($data);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
       DETALLE POR ZONA
    ───────────────────────────────────────── */

    /**
     * GET /api/v1/dashboard/zona/:id
     * Servicios con entradas/salidas/faltas de una zona específica.
     */
    public function zona(int $idZona, string $fecha): array
    {
        try {
            $zona = $this->db->query(
                "SELECT zona FROM zonas WHERE id = ?", [$idZona]
            )->getRowArray();

            if (!$zona) {
                return $this->fail('Zona no encontrada');
            }

            $servicios = $this->db->query("
                SELECT
                    s.id,
                    s.servicio,
                    COUNT(DISTINCT e.id) AS total_empleados,
                    SUM(CASE WHEN a.fecha = ? AND a.id_status = 1 THEN 1 ELSE 0 END) AS entradas,
                    SUM(CASE WHEN a.fecha = ? AND a.id_status = 2 THEN 1 ELSE 0 END) AS salidas
                FROM servicios s
                JOIN asistencias a ON a.id_ubicacion = s.id
                JOIN empleados e ON a.id_empleado = e.id
                WHERE s.id_zona = ?
                  AND e.estatus = 1
                  AND e.is_deleted = 0
                  AND a.fecha >= DATE_SUB(?, INTERVAL 30 DAY)
                GROUP BY s.id, s.servicio
                ORDER BY entradas DESC
            ", [$fecha, $fecha, $idZona, $fecha])->getResultArray();

            $data = array_map(fn($s) => [
                'id'              => (int)$s['id'],
                'servicio'        => $s['servicio'],
                'total_empleados' => (int)$s['total_empleados'],
                'entradas'        => (int)$s['entradas'],
                'salidas'         => (int)$s['salidas'],
                'faltas'          => max(0, (int)$s['total_empleados'] - (int)$s['entradas']),
            ], $servicios);

            return $this->ok($data, [
                'zona'    => $zona['zona'],
                'id_zona' => $idZona,
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
       CONTROL DE ÁREA
    ───────────────────────────────────────── */

    /**
     * GET /api/v1/dashboard/control-area
     * Cobertura actual por zona y servicio.
     * Toma el último registro del día por empleado para determinar
     * si está dentro (id_status=1) o fuera (id_status=2).
     */
    public function controlArea(string $fecha): array
    {
        try {
            $sql = "
                SELECT
                    z.id            AS id_zona,
                    z.zona          AS zona,
                    s.id            AS id_servicio,
                    s.servicio      AS servicio,
                    s.elementos     AS requeridos,
                    COUNT(DISTINCT CASE WHEN a.id_status = 1 THEN a.id_empleado END) AS activos,
                    COUNT(DISTINCT a.id_empleado) AS registrados
                FROM asistencias a
                INNER JOIN (
                    SELECT id_empleado, MAX(id) AS max_id
                    FROM asistencias
                    WHERE fecha = ?
                    GROUP BY id_empleado
                ) ult ON ult.id_empleado = a.id_empleado AND ult.max_id = a.id
                INNER JOIN servicios s ON a.id_ubicacion = s.id
                INNER JOIN zonas z ON s.id_zona = z.id
                WHERE a.fecha = ?
                  AND s.id_zona IS NOT NULL
                GROUP BY z.id, z.zona, s.id, s.servicio, s.elementos
                ORDER BY z.zona ASC, s.servicio ASC
            ";

            $rows = $this->db->query($sql, [$fecha, $fecha])->getResultArray();

            $zonas = [];
            foreach ($rows as $row) {
                $idZona     = $row['id_zona'];
                $requeridos = (int)($row['requeridos'] ?? 0); 
                $activos    = (int)$row['activos'];
                $pct        = $requeridos > 0 ? round(($activos / $requeridos) * 100) : 0;

                if (!isset($zonas[$idZona])) {
                    $zonas[$idZona] = [
                        'id_zona'    => (int)$idZona,
                        'zona'       => $row['zona'],
                        'requeridos' => 0,
                        'activos'    => 0,
                        'servicios'  => [],
                    ];
                }

                $zonas[$idZona]['requeridos'] += $requeridos;
                $zonas[$idZona]['activos']    += $activos;
                $zonas[$idZona]['servicios'][] = [
                    'id_servicio' => (int)$row['id_servicio'],
                    'servicio'    => $row['servicio'],
                    'requeridos'  => $requeridos,
                    'activos'     => $activos,
                    'registrados' => (int)$row['registrados'],
                    'pct'         => $pct,
                    'estatus' => $requeridos > 0 ? $this->calcEstatus($pct) : 'sin-datos',
                ];
            }

            $resultado = [];
            foreach ($zonas as $zona) {
                $pct = $zona['requeridos'] > 0
                    ? round(($zona['activos'] / $zona['requeridos']) * 100)
                    : 0;

                $resultado[] = array_merge($zona, [
                    'pct'     => $pct,
                    'estatus' => $this->calcEstatus($pct),
                ]);
            }

            return $this->ok(array_values($resultado));

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}