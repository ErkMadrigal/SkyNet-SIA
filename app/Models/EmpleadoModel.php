<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * EmpleadoModel
 *
 * Migración de ConsultasEmpleados + ControllerEmpleados legacy.
 * Toda la lógica de BD centralizada aquí.
 */
class EmpleadoModel extends Model
{
    protected $table         = 'empleados';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'curp','rfc','nss','CP_fiscal','fecha_ingreso','fecha_efectiva',
        'paterno','materno','nombre','id_turno','id_puesto','id_periocidad',
        'clave_interbancaria','id_banco','estatus','alergias','fotos',
        'tipoSangre','escolaridad','parentesco','nombreEmergencia',
        'telefonoEmergencia','created_by','updated_at','updated_by',
        'deleted_at','deleted_by','is_deleted','estado_actual','ultima_actividad',
    ];

    protected $useTimestamps = false;

    /* ═══════════════════════════════════════════════════════════════
       LECTURA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Lista paginada con filtros (equivalente a getEmpleados legacy).
     */
    public function listar(int $limit, int $offset, array $zonas = [], array $puestos = [], ?string $fechas = null, ?string $status = null): array
    {
        $builder = $this->db->table('empleados e')
            ->select('e.id, CONCAT(e.nombre," ",e.paterno," ",e.materno) AS nombre, e.curp, e.fecha_efectiva, mp.valor AS puesto, e.estatus')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left');

        if (!empty($puestos)) {
            $builder->whereIn('e.id_puesto', $puestos);
        }

        if (!empty($fechas)) {
            $rangos = explode(' a ', $fechas);
            if (count($rangos) === 2) {
                $builder->where('e.fecha_ingreso >=', trim($rangos[0]))
                        ->where('e.fecha_ingreso <=', trim($rangos[1]));
            }
        }

        if (!empty($status) && $status !== '000') {
            $builder->where('e.estatus', $status);
        }

        $total = (clone $builder)->countAllResults(false);

        $data = $builder->orderBy('e.id', 'DESC')
                        ->limit($limit, $offset)
                        ->get()->getResultArray();

        return [
            'status' => 'ok',
            'total'  => $total,
            'data'   => $data,
        ];
    }

    /**
     * Empleado individual con catálogos (equivalente a getEmpleado legacy).
     */
    public function getConCatalogos(int $id): array
    {
        $data = $this->db->table('empleados e')
            ->select('e.*, CONCAT(e.nombre," ",e.paterno," ",e.materno) AS nombreCompleto, mp.valor AS puesto, mb.valor AS institucionBancaria')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left')
            ->join('multicatalogo mb', 'e.id_banco = mb.id', 'left')
            ->where('e.id', $id)
            ->get()->getRowArray();

        if (!$data) {
            return ['status' => 'error', 'mensaje' => 'Empleado no encontrado', 'data' => []];
        }

        return ['status' => 'ok', 'data' => $data];
    }

    /**
     * Búsqueda full-text (equivalente a searchEmpleado legacy).
     */
    public function buscar(string $search, int $limit, int $offset): array
    {
        $search = preg_replace('/\s+/', ' ', trim($search));
        $like   = '%' . $search . '%';
        $idExact = ctype_digit($search) ? (int)ltrim($search, '0') : -1;

        $base = $this->db->table('empleados e')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left')
            ->groupStart()
                ->like('CONCAT_WS(" ", TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno))', $search)
                ->orLike('CONCAT_WS(" ", TRIM(e.paterno), TRIM(e.materno), TRIM(e.nombre))', $search)
                ->orLike('e.curp', $search)
                ->orLike('e.rfc', $search)
                ->orLike('e.nss', $search)
                ->orWhere('e.id', $idExact)
            ->groupEnd();

        $total = (clone $base)->countAllResults(false);

        $data = $base->select('e.id, CONCAT_WS(" ",e.nombre,e.paterno,e.materno) AS nombre, e.fecha_ingreso, mp.valor AS puesto, e.estatus, e.curp, e.fecha_efectiva')
                     ->orderBy('e.id', 'DESC')
                     ->limit($limit, $offset)
                     ->get()->getResultArray();

        return ['status' => 'ok', 'data' => $data, 'total' => $total];
    }

    /**
     * Conteos para dashboard.
     */
    public function conteos(): array
    {
        $row = $this->db->query("
            SELECT
                COUNT(*) AS total_empleados,
                SUM(estado_actual = 1) AS activos,
                SUM(estado_actual != 1) AS inactivos,
                IF(COUNT(*)=0, 0, ROUND((SUM(estado_actual=1)/COUNT(*))*100,2)) AS porcentaje_activos,
                IF(COUNT(*)=0, 0, ROUND((SUM(estado_actual!=1)/COUNT(*))*100,2)) AS porcentaje_inactivos
            FROM empleados
        ")->getRowArray();

        return [
            'status' => 'ok',
            'data'   => [
                'total_empleados'       => (int)$row['total_empleados'],
                'activos'               => (int)$row['activos'],
                'inactivos'             => (int)$row['inactivos'],
                'porcentaje_activos'    => (float)$row['porcentaje_activos'],
                'porcentaje_inactivos'  => (float)$row['porcentaje_inactivos'],
            ],
        ];
    }

    /**
     * Alertas: incidencias pendientes + perfiles incompletos.
     */
    public function alertas(): array
    {
        $incidencias = (int)$this->db->query(
            "SELECT COUNT(*) as t FROM incidencias WHERE activo = 0"
        )->getRowArray()['t'];

        $incompletos = (int)$this->db->query("
            SELECT COUNT(*) AS t FROM empleados WHERE estatus = 1
            AND (
                (curp IS NULL OR TRIM(curp)='') OR (rfc IS NULL OR TRIM(rfc)='') OR
                (CP_fiscal IS NULL OR TRIM(CP_fiscal)='') OR (nss IS NULL OR TRIM(nss)='') OR
                (fecha_ingreso IS NULL) OR (fecha_efectiva IS NULL) OR
                (paterno IS NULL OR TRIM(paterno)='') OR (materno IS NULL OR TRIM(materno)='') OR
                (nombre IS NULL OR TRIM(nombre)='') OR (id_turno IS NULL OR id_turno=0) OR
                (id_puesto IS NULL OR id_puesto=0) OR (alergias IS NULL OR TRIM(alergias)='') OR
                (fotos IS NULL OR TRIM(fotos)='') OR (id_periocidad IS NULL OR id_periocidad=0) OR
                (clave_interbancaria IS NULL OR TRIM(clave_interbancaria)='') OR
                (tipoSangre IS NULL OR TRIM(tipoSangre)='') OR
                (escolaridad IS NULL OR TRIM(escolaridad)='') OR
                (parentesco IS NULL OR TRIM(parentesco)='') OR
                (nombreEmergencia IS NULL OR TRIM(nombreEmergencia)='') OR
                (telefonoEmergencia IS NULL OR TRIM(telefonoEmergencia)='')
            )
        ")->getRowArray()['t'];

        return [
            'status' => 'ok',
            'data'   => [
                'incidencias_pendientes'  => $incidencias,
                'perfiles_incompletos'    => $incompletos,
            ],
        ];
    }

    /**
     * Total por estatus (para dashboard).
     */
    public function totalPorEstatus(int $estatus): int
    {
        return (int)$this->where('estatus', $estatus)->countAllResults();
    }

    /* ═══════════════════════════════════════════════════════════════
       IDENTIDAD (para validar duplicados)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Busca por CURP, RFC o NSS. Retorna el primer match o null.
     */
    public function buscarPorIdentidad(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') return null;

        return $this->groupStart()
                    ->where('curp', $query)
                    ->orWhere('rfc', $query)
                    ->orWhere('nss', $query)
                    ->groupEnd()
                    ->first();
    }

    /* ═══════════════════════════════════════════════════════════════
       ESCRITURA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Registro individual (equivalente a ControllerEmpleados::registro).
     */
    public function registrar(array $datos): array
    {
        try {
            $id = $this->insert($datos, true);
            return ['status' => 'ok', 'mensaje' => 'Registro exitoso', 'last_insert_id' => $id];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Actualizar datos personales.
     */
    public function actualizarPersonal(int $id, array $datos): array
    {
        try {
            $datos['updated_at'] = date('Y-m-d H:i:s');
            $this->update($id, $datos);
            return ['status' => 'ok', 'mensaje' => 'Datos personales actualizados'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Actualizar datos laborales.
     */
    public function actualizarTrabajo(int $id, array $datos): array
    {
        try {
            $datos['updated_at'] = date('Y-m-d H:i:s');
            $this->update($id, $datos);
            return ['status' => 'ok', 'mensaje' => 'Datos laborales actualizados'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Actualizar datos bancarios.
     */
    public function actualizarBancario(int $id, array $datos): array
    {
        try {
            $datos['updated_at'] = date('Y-m-d H:i:s');
            $this->update($id, $datos);
            return ['status' => 'ok', 'mensaje' => 'Datos bancarios actualizados'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Actualizar foto de perfil.
     */
    public function actualizarFoto(int $id, string $rutaFoto, int $updatedBy): array
    {
        try {
            $this->update($id, [
                'fotos'      => $rutaFoto,
                'updated_at' => date('Y-m-d H:i:s'),
                'updated_by' => $updatedBy,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Foto actualizada'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Actualizar estado_actual y ultima_actividad (registro biométrico).
     */
    public function actualizarActividad(int $id, int $estado): void
    {
        $this->update($id, [
            'estado_actual'   => $estado,
            'ultima_actividad' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════
       BAJA / REACTIVACIÓN
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Dar de baja un empleado (soft delete + registro en baja_empleado).
     */
    public function darDeBaja(int $id, int $idMotivo, bool $finiquito, string $nota, string $fechaEfectiva, int $userId): array
    {
        $this->db->transStart();

        try {
            $this->update($id, [
                'estatus'    => 0,
                'is_deleted' => 1,
                'deleted_at' => date('Y-m-d H:i:s'),
                'deleted_by' => $userId,
            ]);

            $this->db->table('baja_empleado')->insert([
                'id_empleado'   => $id,
                'id_motivo'     => $idMotivo,
                'finiquito'     => (int)$finiquito,
                'nota'          => $nota,
                'fecha_efectiva' => $fechaEfectiva,
                'status'        => 'aprobado',
            ]);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return ['status' => 'error', 'mensaje' => 'Error en la transacción de baja'];
            }

            return ['status' => 'ok', 'mensaje' => 'Empleado dado de baja correctamente'];

        } catch (\Exception $e) {
            $this->db->transRollback();
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Reingreso: solo activa el estatus (nueva fecha de ingreso, baja queda en historial).
     */
    public function reingreso(int $id): array
    {
        $affected = $this->db->table('empleados')
                             ->where('id', $id)
                             ->update(['estatus' => 1]);

        if (!$affected || $this->db->affectedRows() === 0) {
            return ['status' => 'error', 'mensaje' => 'No se actualizó (¿id incorrecto o ya activo?)'];
        }

        return ['status' => 'ok', 'mensaje' => 'Reingreso aplicado', 'data' => ['id' => $id]];
    }

    /**
     * Reactivación: cancela la última baja y reactiva al empleado (transacción).
     */
    public function reactivar(int $id): array
    {
        $this->db->transStart();

        try {
            // Cancelar la última baja
            $this->db->query("
                UPDATE baja_empleado
                SET status = 'cancelado'
                WHERE id = (
                    SELECT id2 FROM (
                        SELECT id AS id2 FROM baja_empleado
                        WHERE id_empleado = ?
                        ORDER BY fecha_efectiva DESC, id DESC
                        LIMIT 1
                    ) x
                )
            ", [$id]);

            // Reactivar empleado
            $this->update($id, ['estatus' => 1]);

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return ['status' => 'error', 'mensaje' => 'Error en la transacción de reactivación'];
            }

            return ['status' => 'ok', 'mensaje' => 'Reactivación aplicada: baja cancelada y empleado activo', 'data' => ['id' => $id]];

        } catch (\Exception $e) {
            $this->db->transRollback();
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       BIOMÉTRICO / ASISTENCIAS
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Busca empleado para el biométrico por CURP (18), RFC (13) o ID numérico.
     */
    public function buscarBiometrico(string $query, int $len): ?array
    {
        $query = trim($query);

        if (ctype_digit($query)) {
            return $this->select('id, CONCAT(nombre," ",paterno," ",materno) AS nombreCompleto, curp, rfc, fotos, id_puesto')
                        ->where('id', (int)ltrim($query, '0'))
                        ->where('estatus', 1)
                        ->first();
        }

        return $this->select('id, CONCAT(nombre," ",paterno," ",materno) AS nombreCompleto, curp, rfc, fotos, id_puesto')
                    ->groupStart()
                        ->where('curp', strtoupper($query))
                        ->orWhere('rfc', strtoupper($query))
                    ->groupEnd()
                    ->where('estatus', 1)
                    ->first();
    }

    /**
     * Datos del empleado + última asistencia (para el biométrico con entrada/salida).
     */
    public function buscarBiometricoConAsistencia(string $query): array
    {
        $query = trim($query);
        $where = ctype_digit($query)
            ? "e.id = " . (int)ltrim($query, '0') . " AND e.estatus = 1"
            : (strlen($query) === 18
                ? "e.curp = '" . $this->db->escapeLikeString(strtoupper($query)) . "' AND e.estatus = 1"
                : "e.rfc  = '" . $this->db->escapeLikeString(strtoupper($query)) . "' AND e.estatus = 1");

        $row = $this->db->query("
            SELECT e.id, CONCAT(e.nombre,' ',e.paterno,' ',e.materno) AS nombreCompleto,
                e.curp, e.rfc, e.fotos,
                a.latitud, a.longitud, a.fecha, a.hora, a.id_status
            FROM empleados e
            LEFT JOIN asistencias a ON e.id = a.id_empleado AND a.id_status IN (1,2)
            WHERE {$where}
            ORDER BY a.id DESC
            LIMIT 1
        ")->getRowArray();

        if (!$row) {
            return ['status' => 'error', 'data' => [], 'mensaje' => 'Empleado no encontrado'];
        }

        return ['status' => 'ok', 'data' => $row];
    }

    /**
     * Última asistencia de un empleado (para validar doble entrada/salida).
     */
    public function ultimaAsistencia(int $id): array
    {
        $row = $this->db->query("
            SELECT a.*, CASE WHEN a.id_status=1 THEN 'Entrada' WHEN a.id_status=2 THEN 'Salida' ELSE 'Desconocido' END AS tipo_asistencia
            FROM asistencias a WHERE a.id_empleado = ? ORDER BY a.id DESC LIMIT 1
        ", [$id])->getRowArray();

        if (!$row) {
            return ['status' => 'empty', 'data' => []];
        }

        return ['status' => 'ok', 'data' => $row];
    }

    /**
     * Encuentra el servicio más cercano a una coordenada en un radio dado.
     */
    public function servicioMasCercano(float $lat, float $lon, int $radio = 500): array
    {
        $row = $this->db->query("
            SELECT *, (
                6371000 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(latitud)) * COS(RADIANS(longitud) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(latitud))
                )
            ) AS distancia_m
            FROM servicios
            HAVING distancia_m <= ?
            ORDER BY distancia_m ASC
            LIMIT 1
        ", [$lat, $lon, $lat, $radio])->getRowArray();

        if (!$row) {
            return ['status' => 'empty', 'data' => [], 'mensaje' => 'Sin servicios en el rango'];
        }

        return ['status' => 'ok', 'data' => $row];
    }

    /**
     * Registra entrada o salida en la tabla asistencias.
     */
    public function registrarAsistencia(int $idEmpleado, float $lat, float $lon, string $ip, int $status, int $idCapturista, int $idUbicacion): array
    {
        try {
            $this->db->table('asistencias')->insert([
                'id_empleado'   => $idEmpleado,
                'fecha'         => date('Y-m-d'),
                'hora'          => date('H:i:s'),
                'latitud'       => $lat,
                'longitud'      => $lon,
                'ip'            => $ip,
                'id_status'     => $status,
                'id_capturista' => $idCapturista,
                'id_ubicacion'  => $idUbicacion ?: null,
            ]);

            return [
                'status'  => 'ok',
                'mensaje' => $status === 1 ? 'Entrada registrada' : 'Salida registrada',
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
}
