<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * IncidenciaModel
 * Maneja incidencias, biométrico QR y registros de asistencia biométrica.
 */
class IncidenciaModel extends Model
{
    protected $table      = 'incidencias';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_empleado','id_servicio','id_tipo_incidencia','descripcion',
        'comentario','comprobante_ruta','comprobante_nombre_original',
        'fecha_inicio','fecha_final','fecha_alta','fecha_aprobacion',
        'approved_by','activo','created_by','updated_at','updated_by',
        'deleted_at','deleted_by','is_deleted',
    ];

    protected $useTimestamps = false;

    /* ─────────────────────────────────────────
       LISTADO
    ───────────────────────────────────────── */

    /**
     * Lista incidencias pendientes (activo = 0 = pendiente de aprobación).
     */
    public function listarPendientes(): array
    {
        $data = $this->db->query("
            SELECT i.id, i.id_empleado, m.valor, i.descripcion, e.rfc, e.curp,
                   CONCAT(e.nombre,' ',e.paterno,' ',e.materno) AS nombre,
                   i.fecha_inicio, i.fecha_final,
                   i.comprobante_nombre_original, i.comprobante_ruta
            FROM incidencias i
            LEFT JOIN empleados e ON e.id = i.id_empleado
            LEFT JOIN multicatalogo m ON m.id = i.id_tipo_incidencia
            WHERE i.activo = 0
            ORDER BY i.id DESC
            LIMIT 500
        ")->getResultArray();

        return ['status' => 'ok', 'data' => $data];
    }

    public function listarTodas(string $estado = 'todas'): array
    {
        $where = match($estado) {
            'pendiente' => 'i.activo = 0',
            'aprobada'  => 'i.activo = 1',
            'rechazada' => 'i.activo = 2',
            default     => '1=1',
        };

        $data = $this->db->query("
            SELECT i.id, i.id_empleado, i.activo,
                m.valor, i.descripcion, e.rfc, e.curp,
                CONCAT(e.nombre,' ',e.paterno,' ',e.materno) AS nombre,
                i.fecha_inicio, i.fecha_final,
                i.comprobante_nombre_original, i.comprobante_ruta
            FROM incidencias i
            LEFT JOIN empleados e ON e.id = i.id_empleado
            LEFT JOIN multicatalogo m ON m.id = i.id_tipo_incidencia
            WHERE {$where}
            ORDER BY i.id DESC
            LIMIT 1000
        ")->getResultArray();

        return ['status' => 'ok', 'data' => $data];
    }

    
    /**
     * Registros del biométrico con búsqueda y paginación.
     */
    public function registrosBiometrico(
        string $search,
        string $dateFrom,
        string $dateTo,
        int $page,
        int $pageSize,
        int $servicioId = 0,
        int $clienteId  = 0,
        int $zonaId     = 0
    ): array {
        $pageSize = max(1, min(200, $pageSize));
        $offset   = ($page - 1) * $pageSize;
    
        $db = \Config\Database::connect();
    
        // Expresión reusable: nombre completo SIN espacios dobles, ni al
        // inicio/final de cada parte ni entre ellas. Se usa igual para
        // empleado (e.*) y capturista (ec.*) -- solo cambia el alias.
        $nombreLimpio = function (string $alias): string {
            $concat = "CONCAT_WS(' ', TRIM({$alias}.nombre), TRIM({$alias}.paterno), TRIM({$alias}.materno))";
            // 3 pasadas de REPLACE colapsan hasta 8 espacios seguidos a 1 --
            // de sobra para el caso real (dobles/triples espacios sueltos).
            return "REPLACE(REPLACE(REPLACE({$concat}, '  ', ' '), '  ', ' '), '  ', ' ')";
        };
    
        $empleadoExpr    = $nombreLimpio('e');
        $capturistaExpr  = $nombreLimpio('ec');
    
        $baseQuery = $db->table('asistencias a')
            ->select("
                {$empleadoExpr} AS empleado,
                CONCAT_WS(' ', a.fecha, a.hora) AS fecha,
                CONCAT_WS(' | ', s.servicio, s.ubicacion, c.nombre_corto) AS servicio,
                CONCAT_WS(', ', a.latitud, a.longitud) AS ubicacion,
                CONCAT('https://www.google.com/maps?output=embed&q=', a.latitud, ',', a.longitud) AS maps_embed,
                CONCAT('https://www.google.com/maps?q=', a.latitud, ',', a.longitud) AS maps_url,
                a.ip,
                {$capturistaExpr} AS capturista,
                CASE WHEN a.id_status=1 THEN 'Entrada' WHEN a.id_status=2 THEN 'Salida' ELSE 'Desconocido' END AS estado
            ")
            ->join('empleados e',  'e.id = a.id_empleado',   'left')
            ->join('empleados ec', 'a.id_capturista = ec.id', 'left')
            ->join('servicios s',  'a.id_ubicacion = s.id',   'left')
            ->join('clientes c',   's.id_cliente = c.id',     'left');
    
        if ($search !== '') {
            $norm = preg_replace('/\s+/', ' ', trim($search));
            $baseQuery->groupStart()
                ->like($empleadoExpr, $norm)
                ->orLike($capturistaExpr, $norm)
                ->orLike('a.ip', $norm)
                ->orLike('s.ubicacion', $norm)
                ->orLike('e.id', $norm) // NUEVO -- búsqueda por número de empleado
                ->groupEnd();
        }
    
        if ($dateFrom !== '' && $dateTo !== '') {
            $baseQuery->where('a.fecha >=', $dateFrom)->where('a.fecha <=', $dateTo);
        } elseif ($dateFrom !== '') {
            $baseQuery->where('a.fecha >=', $dateFrom);
        } elseif ($dateTo !== '') {
            $baseQuery->where('a.fecha <=', $dateTo);
        }
    
        if ($servicioId > 0) $baseQuery->where('s.id', $servicioId);
        if ($clienteId  > 0) $baseQuery->where('s.id_cliente', $clienteId);
        if ($zonaId     > 0) $baseQuery->where('s.id_zona', $zonaId);
    
        // Total — misma lógica, clonada antes de limit/offset
        $total = $db->table('asistencias a')
            ->join('empleados e',  'e.id = a.id_empleado',   'left')
            ->join('empleados ec', 'a.id_capturista = ec.id', 'left')
            ->join('servicios s',  'a.id_ubicacion = s.id',   'left')
            ->join('clientes c',   's.id_cliente = c.id',     'left');
    
        if ($search !== '') {
            $norm = preg_replace('/\s+/', ' ', trim($search));
            $total->groupStart()
                ->like($empleadoExpr, $norm)
                ->orLike($capturistaExpr, $norm)
                ->orLike('a.ip', $norm)
                ->orLike('s.ubicacion', $norm)
                ->orLike('e.id', $norm)
                ->groupEnd();
        }
    
        if ($dateFrom !== '' && $dateTo !== '') {
            $total->where('a.fecha >=', $dateFrom)->where('a.fecha <=', $dateTo);
        } elseif ($dateFrom !== '') {
            $total->where('a.fecha >=', $dateFrom);
        } elseif ($dateTo !== '') {
            $total->where('a.fecha <=', $dateTo);
        }
    
        if ($servicioId > 0) $total->where('s.id', $servicioId);
        if ($clienteId  > 0) $total->where('s.id_cliente', $clienteId);
        if ($zonaId     > 0) $total->where('s.id_zona', $zonaId);
    
        $totalCount = (int)($total->countAllResults());
    
        $data = $baseQuery
            ->orderBy('a.id', 'DESC')
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();
    
        return [
            'status' => 'ok',
            'data'   => $data,
            'meta'   => [
                'page'        => $page,
                'pageSize'    => $pageSize,
                'total'       => $totalCount,
                'totalPages'  => (int)ceil($totalCount / max(1, $pageSize)),
                'search'      => $search,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'servicio_id' => $servicioId,
                'cliente_id'  => $clienteId,
                'zona_id'     => $zonaId,
            ],
        ];
    }

    /* ─────────────────────────────────────────
       ESCRITURA
    ───────────────────────────────────────── */

    /**
     * Crear incidencia (equivalente a confirmar_incidencia legacy).
     */
    public function crear(array $datos): array
    {
        try {
            $datos['fecha_alta'] = date('Y-m-d H:i:s');
            $datos['activo']     = 0; // pendiente de aprobación
            $id = $this->insert($datos, true);
            return ['status' => 'ok', 'mensaje' => 'Incidencia registrada correctamente', 'id' => $id];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Aprobar o rechazar incidencia (equivalente a aprobar_incidencia legacy).
     * $tipo: 1 = aprobar, 2 = rechazar
     */
    public function aprobar(int $idIncidencia, int $tipo, ?string $comentario, int $userId): array
    {
        try {
            $this->db->table('incidencias')->where('id', $idIncidencia)->update([
                'activo'           => $tipo,
                'comentario'       => $comentario,
                'approved_by'      => $userId,
                'fecha_aprobacion' => date('Y-m-d H:i:s'),
            ]);

            $affected = $this->db->affectedRows();

            if ($affected === 0) {
                return [
                    'status'  => 'warning',
                    'mensaje' => 'No se actualizó: id no existe o valores ya son iguales',
                ];
            }

            return ['status' => 'ok', 'mensaje' => $tipo === 1 ? 'Incidencia aprobada' : 'Incidencia rechazada'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
}
