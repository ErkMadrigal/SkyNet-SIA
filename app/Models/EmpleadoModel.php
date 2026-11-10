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
        'deleted_at','deleted_by','is_deleted','estado_actual','ultima_actividad','acceso_biometrico','permitir_salida_anticipada'
    ];

    protected $useTimestamps = false;

    /* ═══════════════════════════════════════════════════════════════
       LECTURA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Lista paginada con filtros (equivalente a getEmpleados legacy).
     */
    public function listar(int $limit, int $offset, array $zonas = [], array $puestos = [], ?string $fechas = null, ?string $status = null, ?int $ubicacion = null, ?array $alcance = null): array
    {
        $builder = $this->db->table('empleados e')
            ->select('e.id, CONCAT(e.nombre," ",e.paterno," ",e.materno) AS nombre, e.curp, e.fecha_efectiva, mp.valor AS puesto, e.estatus, e.acceso_biometrico, s.servicio AS ubicacion_principal')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left')
            ->join('servicios s', 'e.id_ubicacion_principal = s.id', 'left');

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

        if ($ubicacion !== null) {
            $builder->where('e.id_ubicacion_principal', $ubicacion); // ← nuevo
        }

        if ($cond = \App\Libraries\AlcanceLibrary::condicion($alcance, 's')) {
            $builder->where($cond, null, false); // roles finos
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
            ->select('e.*, CONCAT(e.nombre," ",e.paterno," ",e.materno) AS nombreCompleto, mp.valor AS puesto, mb.valor AS institucionBancaria, s.servicio AS ubicacion_principal')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left')
            ->join('multicatalogo mb', 'e.id_banco = mb.id', 'left')
            ->join('servicios s', 'e.id_ubicacion_principal = s.id', 'left')  // ← esto
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
    public function buscar(string $search, int $limit, int $offset, ?array $alcance = null): array
    {
        $search  = preg_replace('/\s+/', ' ', trim($search));
        $like    = '%' . $search . '%';
        $idExact = ctype_digit($search) ? (int)ltrim($search, '0') : -1;

        $base = $this->db->table('empleados e')
            ->join('multicatalogo mp', 'e.id_puesto = mp.id', 'left')
            ->join('servicios s', 'e.id_ubicacion_principal = s.id', 'left')  // ← aquí
            ->groupStart()
                ->like('CONCAT_WS(" ", TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno))', $search)
                ->orLike('CONCAT_WS(" ", TRIM(e.paterno), TRIM(e.materno), TRIM(e.nombre))', $search)
                ->orLike('e.curp', $search)
                ->orLike('e.rfc', $search)
                ->orLike('e.nss', $search)
                ->orWhere('e.id', $idExact)
            ->groupEnd();

        if ($cond = \App\Libraries\AlcanceLibrary::condicion($alcance, 's')) {
            $base->where($cond, null, false); // roles finos
        }

        $total = (clone $base)->countAllResults(false);

        $data = $base->select('e.id, CONCAT_WS(" ",e.nombre,e.paterno,e.materno) AS nombre, e.fecha_ingreso, mp.valor AS puesto, e.estatus, e.curp, e.fecha_efectiva, e.acceso_biometrico, s.servicio AS ubicacion_principal')
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
                'acceso_biometrico'  => 0,
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

        // NUEVO -- rostro_enrolado + descriptor_servidor (el embedding
        // activo, si ya se enroló) para que el kiosko sepa si debe
        // mandar al empleado a enrolarse antes de dejarlo checar, y
        // para usar el vector capturado en vivo en vez de derivarlo de
        // la foto de perfil.
        $selectConEnrolamiento = 'id, CONCAT(nombre," ",paterno," ",materno) AS nombreCompleto, curp, rfc, fotos, id_puesto, '
            . 'rostro_enrolado, '
            . '(SELECT descriptor FROM empleado_rostro_embeddings WHERE id_empleado = empleados.id AND is_active = 1 LIMIT 1) AS descriptor_servidor';

        if (ctype_digit($query)) {
            $fila = $this->select($selectConEnrolamiento)
                        ->where('id', (int)ltrim($query, '0'))
                        ->where('estatus', 1)
                        ->first();
        } else {
            $fila = $this->select($selectConEnrolamiento)
                    ->groupStart()
                        ->where('curp', strtoupper($query))
                        ->orWhere('rfc', strtoupper($query))
                    ->groupEnd()
                    ->where('estatus', 1)
                    ->first();
        }

        // Vectores extra aprendidos (solo si ROSTRO_MULTI está activo)
        if ($fila) {
            $fila['descriptores_extra'] = \App\Controllers\Api\V1\RostroAutoController::extrasPara([$fila['id']])[(int)$fila['id']] ?? [];
        }
        return $fila;
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
     * Cierra entradas que se quedaron sin salida: si ya pasaron $graciaMin minutos desde
     * que terminó su turno, se agrega una salida a la hora de fin del turno con
     * estado_salida = 'no_registrada'. Sirve para que no se descuadren los reportes y para
     * que el empleado pueda registrar su entrada del siguiente turno.
     *
     * @param int|null $idEmpleado  solo ese empleado (null = todos)
     * @return int  cuántas entradas se cerraron
     */
    public function cerrarEntradasAbiertas(?int $idEmpleado = null, ?\DateTime $ahora = null, int $graciaMin = 240): int
    {
        $tz    = new \DateTimeZone('America/Mexico_City');
        $ahora = $ahora ?: new \DateTime('now', $tz);

        $sql = "SELECT e.id, e.id_empleado, e.fecha, e.hora, e.ip, e.id_capturista, e.id_ubicacion,
                       e.latitud, e.longitud, COALESCE(NULLIF(e.id_turno, 0), emp.id_turno) AS id_turno
                FROM asistencias e
                JOIN empleados emp ON emp.id = e.id_empleado
                WHERE e.id_status = 1
                  AND e.fecha >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
                  AND NOT EXISTS (SELECT 1 FROM asistencias n WHERE n.id_empleado = e.id_empleado AND n.id > e.id)";
        $par = [];
        if ($idEmpleado) { $sql .= ' AND e.id_empleado = ?'; $par[] = $idEmpleado; }

        $cerradas = 0;
        foreach ($this->db->query($sql, $par)->getResultArray() as $r) {
            $fin = \App\Libraries\TurnosLibrary::horaSalida((int)$r['id_turno'], new \DateTime($r['fecha'] . ' ' . $r['hora'], $tz));
            if (!$fin) continue;
            $limite = (clone $fin)->modify("+{$graciaMin} minutes");
            if ($ahora->getTimestamp() < $limite->getTimestamp()) continue;

            $data = [
                'id_empleado'           => (int)$r['id_empleado'],
                'fecha'                 => $fin->format('Y-m-d'),
                'hora'                  => $fin->format('H:i:s'),
                'latitud'               => $r['latitud'],
                'longitud'              => $r['longitud'],
                'ip'                    => $r['ip'],
                'id_status'             => 2,
                'id_capturista'         => $r['id_capturista'],
                'id_ubicacion'          => $r['id_ubicacion'],
                'id_turno'              => $r['id_turno'],
                'id_asistencia_entrada' => (int)$r['id'],
                'estado_salida'         => 'no_registrada',
            ];
            try {
                $ok = $this->db->table('asistencias')->insert($data);
            } catch (\Throwable $e) { $ok = false; }
            if (!$ok) { // estado_salida aún es ENUM (falta migración): se cierra igual
                unset($data['estado_salida']);
                try { $ok = $this->db->table('asistencias')->insert($data); } catch (\Throwable $e) { $ok = false; }
            }
            if ($ok) {
                $cerradas++;
                if ($this->db->fieldExists('origen', 'asistencias')) {
                    $this->db->query("UPDATE asistencias SET origen = 'sistema' WHERE id = ?", [$this->db->insertID()]);
                }
                $this->actualizarActividad((int)$r['id_empleado'], 2);
            }
        }
        return $cerradas;
    }

    /**
     * Registra entrada o salida en la tabla asistencias.
     */
    public function registrarAsistencia(
        int $idEmpleado, float $lat, float $lon, string $ip,
        int $status, int $idCapturista, int $idUbicacion,
        array $extra = []  // ← nuevo parámetro opcional
    ): array {
        try {
            $data = [
                'id_empleado'   => $idEmpleado,
                // Hora de México (el servidor corre en UTC: date() daría +6 h)
                'fecha'         => $extra['fecha'] ?? (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d'),
                'hora'          => $extra['hora']  ?? (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('H:i:s'),
                'latitud'       => $lat,
                'longitud'      => $lon,
                'ip'            => $ip,
                'id_status'     => $status,
                'id_capturista' => $idCapturista,
                'id_ubicacion'  => $idUbicacion ?: null,
            ];

            // Campos de turno — solo si vienen
            if (!empty($extra['id_turno']))              $data['id_turno']              = $extra['id_turno'];
            if (!empty($extra['estado_entrada']))         $data['estado_entrada']         = $extra['estado_entrada'];
            if (isset($extra['minutos_retardo']))         $data['minutos_retardo']         = $extra['minutos_retardo'];
            if (!empty($extra['estado_salida']))          $data['estado_salida']          = $extra['estado_salida'];
            if (!empty($extra['id_asistencia_entrada']))  $data['id_asistencia_entrada']  = $extra['id_asistencia_entrada'];

            // Origen del registro (web / kiosko) — solo si la columna ya existe
            if (!empty($extra['origen']) && $this->db->fieldExists('origen', 'asistencias')) {
                $data['origen'] = $extra['origen'];
            }

            // Dispositivo que hizo el registro (antifraude) — solo si la columna ya existe
            if (!empty($extra['device_id']) && $this->db->fieldExists('device_id', 'asistencias')) {
                $data['device_id'] = $extra['device_id'];
            }

            // Minutos de salida anticipada — solo si la columna ya existe (migración)
            if (isset($extra['minutos_anticipada']) && $this->db->fieldExists('minutos_anticipada', 'asistencias')) {
                $data['minutos_anticipada'] = (int)$extra['minutos_anticipada'];
            }

            $ok = false; $errIns = '';
            try {
                $ok = $this->db->table('asistencias')->insert($data);
            } catch (\Throwable $e) { $errIns = $e->getMessage(); }

            // Si estado_salida aún es un ENUM sin 'anticipada' (falta la migración), no perder el registro
            if (!$ok && isset($data['estado_salida'])) {
                unset($data['estado_salida'], $data['minutos_anticipada']);
                $this->db->table('asistencias')->insert($data);
                log_message('error', 'estado_salida no se pudo guardar (correr migracion_salida_anticipada.sql): ' . $errIns);
            } elseif (!$ok) {
                throw new \RuntimeException($errIns ?: 'No se pudo insertar la asistencia');
            }

            $this->db->query("
                UPDATE empleados
                SET id_ubicacion_principal = (
                    SELECT id_ubicacion
                    FROM asistencias
                    WHERE id_empleado = ? AND id_ubicacion IS NOT NULL
                    AND id_ubicacion IN (SELECT id FROM servicios)
                    GROUP BY id_ubicacion
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                )
                WHERE id = ?
            ", [$idEmpleado, $idEmpleado]);

            return [
                'status'  => 'ok',
                'mensaje' => $status === 1 ? 'Entrada registrada' : 'Salida registrada',
                'id'      => $this->db->insertID(),
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
}
