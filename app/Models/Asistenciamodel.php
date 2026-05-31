<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * AsistenciaModel
 *
 * Maneja registros de entrada y salida en la tabla `asistencias`.
 *
 * id_status:
 *   1 = Entrada
 *   2 = Salida
 */
class AsistenciaModel extends Model
{
    protected $table         = 'asistencias';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'id_token',
        'fecha',
        'hora',
        'id_empleado',
        'id_capturista',
        'id_ubicacion',
        'latitud',
        'longitud',
        'ip',
        'id_status',
        // Campos nuevos para lógica de turnos
        'id_turno',
        'estado_entrada',
        'minutos_retardo',
        'estado_salida',
        'id_asistencia_entrada', // referencia a la entrada para ligar salida
    ];

    /* ═══════════════════════════════════════════════════════════════
       TURNO ACTIVO
       Busca si el empleado tiene una entrada sin salida correspondiente.
       Se considera "activo" si hay un registro id_status=1 y no existe
       un id_status=2 posterior para ese empleado.
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Obtiene la entrada activa (sin salida) de un empleado.
     * Retorna el registro de entrada o null si no hay turno activo.
     */
    public function getTurnoActivo(int $idEmpleado): ?array
    {
        $db = \Config\Database::connect();

        $entrada = $db->query(
            "SELECT a.*
             FROM asistencias a
             WHERE a.id_empleado = ?
               AND a.id_status   = 1
               AND NOT EXISTS (
                   SELECT 1 FROM asistencias s
                   WHERE s.id_empleado          = a.id_empleado
                     AND s.id_status            = 2
                     AND s.id_asistencia_entrada = a.id
               )
             ORDER BY a.id DESC
             LIMIT 1",
            [$idEmpleado]
        )->getRowArray();

        return $entrada ?: null;
    }

    /* ═══════════════════════════════════════════════════════════════
       REGISTRAR ENTRADA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Registra una entrada nueva.
     *
     * @param array $data {
     *   id_empleado, fecha_entrada (Y-m-d H:i:s),
     *   id_turno, estado_entrada, minutos_retardo,
     *   latitud?, longitud?, ip?
     * }
     * @return int ID del registro creado
     */
    public function registrarEntrada(array $data): int
    {
        $fechaHora = new \DateTime($data['fecha_entrada'],
                        new \DateTimeZone('America/Mexico_City'));

        $id = $this->insert([
            'id_token'       => 0, // campo legacy, ya no se usa
            'fecha'          => $fechaHora->format('Y-m-d'),
            'hora'           => $fechaHora->format('H:i:s'),
            'id_empleado'    => $data['id_empleado'],
            'id_status'      => 1, // Entrada
            'id_turno'       => $data['id_turno']       ?? null,
            'estado_entrada' => $data['estado_entrada'] ?? 'puntual',
            'minutos_retardo'=> $data['minutos_retardo'] ?? 0,
            'latitud'        => $data['latitud']  ?? null,
            'longitud'       => $data['longitud'] ?? null,
            'ip'             => $data['ip']       ?? \Config\Services::request()->getIPAddress(),
        ]);

        return (int) $this->db->insertID();
    }

    /* ═══════════════════════════════════════════════════════════════
       REGISTRAR SALIDA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Registra una salida ligada a su entrada.
     *
     * @param int    $idEntrada   ID del registro de entrada activo
     * @param string $fechaHoraStr  Y-m-d H:i:s
     * @param string $estadoSalida  'normal' | 'tardanza_salida'
     */
    public function registrarSalida(int $idEntrada, string $fechaHoraStr, string $estadoSalida = 'normal'): int
    {
        $fechaHora = new \DateTime($fechaHoraStr,
                        new \DateTimeZone('America/Mexico_City'));

        // Obtener la entrada para copiar id_turno
        $entrada = $this->find($idEntrada);

        $this->insert([
            'id_token'              => 0,
            'fecha'                 => $fechaHora->format('Y-m-d'),
            'hora'                  => $fechaHora->format('H:i:s'),
            'id_empleado'           => $entrada['id_empleado'],
            'id_status'             => 2, // Salida
            'id_turno'              => $entrada['id_turno'] ?? null,
            'estado_salida'         => $estadoSalida,
            'id_asistencia_entrada' => $idEntrada,
            'ip'                    => \Config\Services::request()->getIPAddress(),
        ]);

        return (int) $this->db->insertID();
    }

    /* ═══════════════════════════════════════════════════════════════
       CONSULTAS
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Historial de asistencias de un empleado con pares entrada/salida.
     *
     * @param int    $idEmpleado
     * @param string $fechaInicio  Y-m-d
     * @param string $fechaFin     Y-m-d
     */
    public function getHistorial(int $idEmpleado, string $fechaInicio, string $fechaFin): array
    {
        $db = \Config\Database::connect();

        return $db->query(
            "SELECT
                e.id                    AS id_entrada,
                e.fecha                 AS fecha_entrada,
                e.hora                  AS hora_entrada,
                e.estado_entrada,
                e.minutos_retardo,
                s.id                    AS id_salida,
                s.fecha                 AS fecha_salida,
                s.hora                  AS hora_salida,
                s.estado_salida,
                mt.valor                AS turno
             FROM asistencias e
             LEFT JOIN asistencias s
                    ON s.id_asistencia_entrada = e.id
                   AND s.id_status = 2
             LEFT JOIN multicatalogo mt
                    ON mt.id = e.id_turno
             WHERE e.id_empleado = ?
               AND e.id_status   = 1
               AND e.fecha BETWEEN ? AND ?
             ORDER BY e.fecha DESC, e.hora DESC",
            [$idEmpleado, $fechaInicio, $fechaFin]
        )->getResultArray();
    }

    /**
     * Resumen de asistencia del día para un servicio/zona (para dashboard).
     *
     * @param string $fecha  Y-m-d
     * @param int    $idServicio  (opcional, 0 = todos)
     */
    public function getResumenDia(string $fecha, int $idServicio = 0): array
    {
        $db = \Config\Database::connect();

        $where = $idServicio ? 'AND e.id_servicio = ' . $idServicio : '';

        return $db->query(
            "SELECT
                COUNT(DISTINCT a.id_empleado)                          AS total_entradas,
                SUM(CASE WHEN a.estado_entrada = 'puntual'       THEN 1 ELSE 0 END) AS puntuales,
                SUM(CASE WHEN a.estado_entrada = 'retardo_leve'  THEN 1 ELSE 0 END) AS retardos_leves,
                SUM(CASE WHEN a.estado_entrada = 'retardo_grave' THEN 1 ELSE 0 END) AS retardos_graves
             FROM asistencias a
             JOIN empleados e ON e.id = a.id_empleado
             WHERE a.fecha      = ?
               AND a.id_status  = 1
               {$where}",
            [$fecha]
        )->getRowArray();
    }
}