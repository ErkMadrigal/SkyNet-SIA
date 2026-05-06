<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * ReportModel
 *
 * Migración de ConsultasReports.php legacy.
 * Encapsula todas las queries de reportes: altas, bajas, nómina,
 * asistencia preview (24x24) y pre-nómina agrupada por zona.
 */
class ReportModel extends Model
{
    protected $table   = 'empleados';
    protected $returnType = 'array';

    /* ═══════════════════════════════════════════════════════════════
       HELPERS PRIVADOS
    ═══════════════════════════════════════════════════════════════ */

    private function validarFechas(string $inicio, string $fin): ?string
    {
        if (!$inicio || !$fin) return 'fecha_inicio y fecha_fin son requeridas';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            return 'Formato de fecha inválido. Usa YYYY-MM-DD';
        }
        $dtI = \DateTime::createFromFormat('Y-m-d', $inicio);
        $dtF = \DateTime::createFromFormat('Y-m-d', $fin);
        if (!$dtI || $dtI->format('Y-m-d') !== $inicio || !$dtF || $dtF->format('Y-m-d') !== $fin) {
            return 'Fecha inválida (revisa día/mes)';
        }
        if ($inicio > $fin) return 'fecha_inicio no puede ser mayor que fecha_fin';
        return null;
    }

    private function ok(array $data = [], array $extra = []): array
    {
        return array_merge(['status' => 'ok', 'data' => $data], $extra);
    }

    private function fail(string $msg): array
    {
        return ['status' => 'error', 'data' => [], 'mensaje' => $msg];
    }

    /* ═══════════════════════════════════════════════════════════════
       ALTAS
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Reporte de altas en un rango de fecha_efectiva.
     */
    public function reporteAltas(string $inicio, string $fin): array
    {
        $err = $this->validarFechas($inicio, $fin);
        if ($err) return $this->fail($err);

        try {
            $rows = $this->db->query("
                SELECT
                    LPAD(e.id,6,'0') AS noEmpleado,
                    CONCAT_WS(' ',e.nombre,e.paterno,e.materno) AS nombreCompleto,
                    e.curp, e.rfc, e.CP_fiscal, e.nss, e.fecha_efectiva,
                    mt.valor  AS turno,  mp.valor  AS puesto,
                    e.alergias, mpe.valor AS periodicidad,
                    e.clave_interbancaria, mts.valor AS tipoSangre,
                    mes.valor AS escolaridad, mpa.valor AS parentesco,
                    e.nombreEmergencia, e.telefonoEmergencia, e.estatus
                FROM empleados e
                LEFT JOIN multicatalogo mt  ON e.id_turno     = mt.id
                LEFT JOIN multicatalogo mp  ON e.id_puesto    = mp.id
                LEFT JOIN multicatalogo mpe ON e.id_periocidad= mpe.id
                LEFT JOIN multicatalogo mts ON e.tipoSangre   = mts.id
                LEFT JOIN multicatalogo mes ON e.escolaridad  = mes.id
                LEFT JOIN multicatalogo mpa ON e.parentesco   = mpa.id
                WHERE e.estatus = 1
                AND e.fecha_efectiva BETWEEN ? AND ?
                ORDER BY e.fecha_efectiva ASC, e.id ASC
            ", [$inicio, $fin])->getResultArray();

            return array_merge($this->ok($rows), ['count' => count($rows)]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       BAJAS
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Reporte de bajas en un rango de fecha_efectiva de baja.
     */
    public function reporteBajas(string $inicio, string $fin): array
    {
        $err = $this->validarFechas($inicio, $fin);
        if ($err) return $this->fail($err);

        try {
            $rows = $this->db->query("
                SELECT
                    LPAD(e.id,6,'0') AS noEmpleado,
                    CONCAT_WS(' ',e.nombre,e.paterno,e.materno) AS nombreCompleto,
                    e.curp, e.rfc, e.CP_fiscal, e.nss, e.fecha_efectiva,
                    mt.valor  AS turno,  mp.valor  AS puesto,
                    e.alergias, mpe.valor AS periodicidad,
                    e.clave_interbancaria, mts.valor AS tipoSangre,
                    mes.valor AS escolaridad, mpa.valor AS parentesco,
                    e.nombreEmergencia, e.telefonoEmergencia, e.estatus,
                    mmb.valor AS motivoBaja,
                    IF(be.finiquito=1,'si','no') AS finiquito,
                    be.nota, be.fecha_efectiva AS fechaBaja, be.status
                FROM empleados e
                LEFT JOIN multicatalogo mt  ON e.id_turno     = mt.id
                LEFT JOIN multicatalogo mp  ON e.id_puesto    = mp.id
                LEFT JOIN multicatalogo mpe ON e.id_periocidad= mpe.id
                LEFT JOIN multicatalogo mts ON e.tipoSangre   = mts.id
                LEFT JOIN multicatalogo mes ON e.escolaridad  = mes.id
                LEFT JOIN multicatalogo mpa ON e.parentesco   = mpa.id
                LEFT JOIN baja_empleado be  ON e.id           = be.id_empleado
                LEFT JOIN multicatalogo mmb ON be.id_motivo   = mmb.id
                WHERE e.estatus = 0
                AND be.fecha_efectiva BETWEEN ? AND ?
                ORDER BY be.fecha_efectiva ASC, e.id ASC
            ", [$inicio, $fin])->getResultArray();

            return array_merge($this->ok($rows), ['count' => count($rows)]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       NÓMINA SIMPLE POR EMPLEADO
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Calcula nómina básica de un empleado en un periodo.
     * (Lógica de calcularNomina del legacy — $500/día, jornada 8h.)
     */
    public function getNominaEmpleado(int $idEmpleado, string $inicio, string $fin): array
    {
        try {
            $rows = $this->db->query("
                SELECT a.fecha, a.hora, a.id_status,
                    (6371000 * ACOS(
                        COS(RADIANS(a.latitud)) * COS(RADIANS(s.latitud))
                        * COS(RADIANS(s.longitud) - RADIANS(a.longitud))
                        + SIN(RADIANS(a.latitud)) * SIN(RADIANS(s.latitud))
                    )) AS distancia_metros
                FROM asistencias a
                JOIN empleados e ON e.id = a.id_empleado
                JOIN servicios s ON 1=1
                WHERE e.id = ? AND a.fecha BETWEEN ? AND ?
                HAVING distancia_metros <= 500
            ", [$idEmpleado, $inicio, $fin])->getResultArray();

            if (!$rows) {
                return $this->ok([], ['mensaje' => 'Sin asistencias en el periodo']);
            }

            return $this->ok($this->calcularNomina($rows));

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function calcularNomina(array $asistencias): array
    {
        $SUELDO_DIARIO = 500;
        $HORAS_JORNADA = 8;
        $VALOR_HORA    = $SUELDO_DIARIO / $HORAS_JORNADA;

        $diasTrabajados = $retardos = $deducciones = 0;
        $porDia = [];

        foreach ($asistencias as $row) {
            if ((float)$row['distancia_metros'] <= 500 && (int)$row['id_status'] === 1) {
                $porDia[$row['fecha']][] = $row;
            }
        }

        foreach ($porDia as $registros) {
            $diasTrabajados++;
            $entrada = null;
            foreach ($registros as $r) {
                if (!$entrada || $r['hora'] < $entrada) $entrada = $r['hora'];
            }
            if ($entrada && (new \DateTime($entrada)) > (new \DateTime('08:10:00'))) {
                $retardos++;
                $deducciones += $VALOR_HORA;
            }
        }

        $percepciones = $diasTrabajados * $SUELDO_DIARIO;

        return [
            'guardias_trabajadas' => $diasTrabajados,
            'retardos'            => $retardos,
            'percepciones'        => round($percepciones, 2),
            'deducciones'         => round($deducciones, 2),
            'neto'                => round($percepciones - $deducciones, 2),
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
       ASISTENCIA PREVIEW (24x24)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Preview paginado de asistencias con pivot por día.
     * Equivalente a reporteAsistencia del legacy.
     *
     * @param int $idUsuario  0 = todos (paginado), >0 = empleado específico
     * @param int $offset     Para paginación de 20 en 20
     */
    public function reporteAsistencia(string $inicio, string $fin, int $idUsuario = 0, int $offset = 0): array
    {
        $err = $this->validarFechas($inicio, $fin);
        if ($err) return $this->fail($err);

        $empFrom = $idUsuario > 0 ? $idUsuario : 0;
        $limit   = $empFrom > 0 ? 1 : 20;
        if ($empFrom > 0) $offset = 0;
        $offset = max(0, $offset);

        try {
            // Query principal con CTE (igual al legacy, LIMIT/OFFSET como ints seguros)
            $sql = "
                WITH
                params AS (
                    SELECT :f_ini AS f_ini, :f_fin AS f_fin, :emp_from AS emp_from,
                           500 AS max_metros, 2000 AS fallback_metros, 23.5 AS umbral_24
                ),
                top_emps AS (
                    SELECT e.id AS id_empleado FROM empleados e JOIN params p ON 1=1
                    WHERE (p.emp_from = 0 OR CAST(e.id AS UNSIGNED) = p.emp_from)
                    ORDER BY CAST(e.id AS UNSIGNED) LIMIT {$limit} OFFSET {$offset}
                ),
                ev AS (
                    SELECT a.id AS id_asistencia, a.id_empleado, a.id_status,
                           TIMESTAMP(a.fecha,a.hora) AS ts, a.fecha, a.hora, a.latitud, a.longitud
                    FROM asistencias a JOIN top_emps te ON te.id_empleado = a.id_empleado JOIN params p ON 1=1
                    WHERE a.fecha BETWEEN p.f_ini AND DATE_ADD(p.f_fin, INTERVAL 1 DAY)
                    AND a.latitud IS NOT NULL AND a.id_status IN (1,2)
                ),
                entradas AS (
                    SELECT e.*, (SELECT MIN(e2.ts) FROM ev e2
                        WHERE e2.id_empleado = e.id_empleado AND e2.id_status=1 AND e2.ts > e.ts) AS next_entry_ts
                    FROM ev e WHERE e.id_status = 1
                ),
                turnos AS (
                    SELECT en.id_empleado, en.ts AS entrada_ts,
                        (SELECT MIN(s.ts) FROM ev s WHERE s.id_empleado=en.id_empleado AND s.id_status=2
                            AND s.ts > en.ts AND (en.next_entry_ts IS NULL OR s.ts < en.next_entry_ts)) AS salida_ts,
                        en.latitud AS lat_entrada, en.longitud AS lon_entrada, DATE(en.ts) AS fecha_inicio
                    FROM entradas en
                ),
                turnos_ok AS (
                    SELECT t.*, ROUND(TIMESTAMPDIFF(SECOND,t.entrada_ts,t.salida_ts)/3600,2) AS horas
                    FROM turnos t WHERE t.salida_ts IS NOT NULL AND t.salida_ts > t.entrada_ts
                ),
                cand_serv AS (
                    SELECT tk.id_empleado, tk.entrada_ts, tk.salida_ts, tk.fecha_inicio, tk.horas,
                        s.id AS id_servicio, s.id_cliente, s.id_zona, s.servicio, s.ubicacion,
                        s.cp AS cp_servicio, s.latitud AS lat_servicio, s.longitud AS lon_servicio,
                        (6371000*ACOS(COS(RADIANS(tk.lat_entrada))*COS(RADIANS(s.latitud))
                            *COS(RADIANS(s.longitud)-RADIANS(tk.lon_entrada))
                            +SIN(RADIANS(tk.lat_entrada))*SIN(RADIANS(s.latitud)))) AS distancia_metros
                    FROM turnos_ok tk JOIN servicios s ON s.latitud IS NOT NULL JOIN params p ON 1=1
                    AND s.latitud BETWEEN tk.lat_entrada-(p.fallback_metros/111320)
                                     AND tk.lat_entrada+(p.fallback_metros/111320)
                    AND s.longitud BETWEEN tk.lon_entrada-(p.fallback_metros/(111320*GREATEST(COS(RADIANS(tk.lat_entrada)),0.2)))
                                      AND tk.lon_entrada+(p.fallback_metros/(111320*GREATEST(COS(RADIANS(tk.lat_entrada)),0.2)))
                ),
                serv_rank AS (
                    SELECT x.*, ROW_NUMBER() OVER (PARTITION BY x.id_empleado,x.entrada_ts ORDER BY x.distancia_metros) AS rn
                    FROM cand_serv x
                )
                SELECT sr.id_empleado, sr.fecha_inicio, sr.entrada_ts, sr.salida_ts, sr.horas,
                    sr.id_servicio, sr.id_cliente, sr.id_zona, sr.servicio, sr.ubicacion,
                    sr.cp_servicio, sr.lat_servicio, sr.lon_servicio, sr.distancia_metros,
                    CASE WHEN sr.distancia_metros <= (SELECT max_metros FROM params) THEN 0 ELSE 1 END AS fuera_rango
                FROM serv_rank sr WHERE sr.rn = 1
                ORDER BY sr.id_empleado, sr.fecha_inicio
            ";

            $stmt = $this->db->connID->prepare($sql);
            $stmt->bindValue(':f_ini',    $inicio,  \PDO::PARAM_STR);
            $stmt->bindValue(':f_fin',    $fin,     \PDO::PARAM_STR);
            $stmt->bindValue(':emp_from', $empFrom, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!$rows) {
                return $this->ok([], ['meta' => compact('inicio','fin','idUsuario','empFrom','offset','limit')]);
            }

            // Datos de empleados del lote
            $ids   = array_unique(array_column($rows, 'id_empleado'));
            $place = implode(',', array_fill(0, count($ids), '?'));
            $empMap = [];
            $empRows = $this->db->query(
                "SELECT id,estatus,paterno,materno,nombre,curp,rfc,nss FROM empleados WHERE id IN ({$place})",
                $ids
            )->getResultArray();
            foreach ($empRows as $er) $empMap[(int)$er['id']] = $er;

            // Lista de días del rango
            $dias = [];
            for ($d = new \DateTime($inicio), $end = new \DateTime($fin); $d <= $end; $d->modify('+1 day')) {
                $dias[] = $d->format('Y-m-d');
            }

            $prio = fn($c) => match(true) {
                $c === '24 FR'      => 60,
                $c === '24'         => 50,
                is_numeric($c)      => 30,
                $c === 'D FR'       => 20,
                $c === 'D'          => 10,
                default             => 0,
            };

            // Pivot en PHP
            $out = $servCount = $servMeta = [];

            foreach ($rows as $r) {
                $emp = (int)$r['id_empleado'];
                $sid = (int)$r['id_servicio'];

                if (!isset($out[$emp])) {
                    $cols = [];
                    foreach ($dias as $i => $date) {
                        $cols['dia_' . str_pad($i+1, 2, '0', STR_PAD_LEFT)] = 'F';
                    }
                    $em = $empMap[$emp] ?? [];
                    $out[$emp] = array_merge([
                        'id_empleado' => $emp,
                        'id_servicio' => null, 'servicio' => null,
                        'ubicacion'   => null, 'cp_servicio' => null, 'coordenadas' => null,
                        'curp' => $em['curp'] ?? null, 'rfc' => $em['rfc'] ?? null,
                        'nss'  => $em['nss']  ?? null, 'paterno' => $em['paterno'] ?? null,
                        'materno' => $em['materno'] ?? null, 'nombre' => $em['nombre'] ?? null,
                    ], $cols, ['estatus_servicio' => null]);
                    $servCount[$emp] = $servMeta[$emp] = [];
                }

                $servCount[$emp][$sid] = ($servCount[$emp][$sid] ?? 0) + 1;
                $servMeta[$emp][$sid]  ??= [
                    'servicio'    => $r['servicio'],
                    'ubicacion'   => $r['ubicacion'],
                    'cp_servicio' => $r['cp_servicio'],
                    'coordenadas' => ($r['lat_servicio'] ?? '') . ', ' . ($r['lon_servicio'] ?? ''),
                ];

                $horas  = (float)$r['horas'];
                $fr     = (int)$r['fuera_rango'] === 1;
                $fInicio = $r['fecha_inicio'];

                if ($horas >= 23.5) {
                    $celdaI = $fr ? '24 FR' : '24';
                    $celdaD = $fr ? 'D FR'  : 'D';
                    $nextDay = (new \DateTime($fInicio))->modify('+1 day')->format('Y-m-d');
                } else {
                    $celdaI  = rtrim(rtrim(number_format($horas, 2, '.', ''), '0'), '.');
                    $celdaD  = null;
                    $nextDay = null;
                }

                $idx = array_search($fInicio, $dias, true);
                if ($idx !== false) {
                    $k = 'dia_' . str_pad($idx+1, 2, '0', STR_PAD_LEFT);
                    if ($prio($celdaI) > $prio($out[$emp][$k])) $out[$emp][$k] = $celdaI;
                }
                if ($celdaD && $nextDay) {
                    $idx2 = array_search($nextDay, $dias, true);
                    if ($idx2 !== false) {
                        $k2 = 'dia_' . str_pad($idx2+1, 2, '0', STR_PAD_LEFT);
                        if ($prio($celdaD) > $prio($out[$emp][$k2])) $out[$emp][$k2] = $celdaD;
                    }
                }
            }

            // Encabezado por servicio principal
            foreach ($out as $emp => &$row) {
                if (!empty($servCount[$emp])) {
                    arsort($servCount[$emp]);
                    $pid = (int)array_key_first($servCount[$emp]);
                    $row['id_servicio'] = $pid;
                    $m = $servMeta[$emp][$pid] ?? [];
                    $row['servicio']    = $m['servicio']    ?? null;
                    $row['ubicacion']   = $m['ubicacion']   ?? null;
                    $row['cp_servicio'] = $m['cp_servicio'] ?? null;
                    $row['coordenadas'] = $m['coordenadas'] ?? null;
                }
                $estatus = $empMap[$emp]['estatus'] ?? null;
                $row['estatus_servicio'] = in_array($estatus, [1, '1', 'A', 'ACTIVO'], true) ? 'ES' : null;
            }
            unset($row);

            return $this->ok(array_values($out), [
                'meta' => compact('inicio','fin','idUsuario','empFrom','offset','limit'),
            ]);

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       PRE-NÓMINA AGRUPADA POR ZONA
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Pre-nómina completa agrupada por zona con cálculo de turnos 8h/12h/24h,
     * festivos, incidencias e importes adicionales.
     * Equivalente a reporteAsistenciasZonaAgrupado del legacy.
     */
    public function reporteAsistenciasZonaAgrupado(string $inicio, string $fin, int $idZona): array
    {
        $err = $this->validarFechas($inicio, $fin);
        if ($err) return $this->fail($err);
        if ($idZona <= 0) return $this->fail('id_zona es requerido');

        try {
            // Buffer ±3 días para capturar turnos que cruzan el borde
            $inicioCalc = (new \DateTime($inicio))->modify('-3 day')->format('Y-m-d');
            $finCalc    = (new \DateTime($fin))->modify('+3 day')->format('Y-m-d');

            $dbc = $this->db->connID;

            // ── Helpers de turno ──────────────────────────────────────
            $normalizeText = fn($t) => preg_replace('/\s+/', ' ',
                str_replace(['Á','É','Í','Ó','Ú','Ü','Ñ'],['A','E','I','O','U','U','N'],
                strtoupper(trim((string)$t))));

            $getShiftConfig = function(string $turnoText) use ($normalizeText): array {
                $t = $normalizeText($turnoText);
                if (strpos($t,'24X24')!==false || strpos($t,'24 X 24')!==false
                    || strpos($t,'24 HORAS')!==false || preg_match('/\b24\b/',$t)) {
                    return ['turno_texto'=>$turnoText,'horas_turno'=>24,'minutos_objetivo'=>1440,
                            'minutos_min_bono'=>23*60+30,'descanso_siguiente'=>true,'tipo_turno_detectado'=>'24H'];
                }
                if (strpos($t,'12X12')!==false || strpos($t,'12 X 12')!==false
                    || strpos($t,'12 HORAS')!==false || preg_match('/\b12\b/',$t)) {
                    return ['turno_texto'=>$turnoText,'horas_turno'=>12,'minutos_objetivo'=>720,
                            'minutos_min_bono'=>11*60+30,'descanso_siguiente'=>false,'tipo_turno_detectado'=>'12H'];
                }
                return ['turno_texto'=>$turnoText,'horas_turno'=>8,'minutos_objetivo'=>480,
                        'minutos_min_bono'=>7*60+30,'descanso_siguiente'=>false,'tipo_turno_detectado'=>'8H'];
            };

            // ── Festivos oficiales México ─────────────────────────────
            $getDiasFestivos = function(string $fi, string $ff) use (&$getDiasFestivos): array {
                $festivos = [];
                $nthLunes = fn($y,$m,$n) => (function() use($y,$m,$n) {
                    $dt = new \DateTime(sprintf('%04d-%02d-01',$y,$m));
                    while((int)$dt->format('N')!==1) $dt->modify('+1 day');
                    if($n>1) $dt->modify('+'.(($n-1)*7).' day');
                    return $dt->format('Y-m-d');
                })();

                for ($y=(int)substr($fi,0,4), $yf=(int)substr($ff,0,4); $y<=$yf; $y++) {
                    $cands = [
                        "$y-01-01", $nthLunes($y,2,1), $nthLunes($y,3,3),
                        "$y-05-01", "$y-09-16", $nthLunes($y,11,3), "$y-12-25",
                    ];
                    if ((($y-2024)%6)===0) $cands[] = "$y-10-01";
                    foreach ($cands as $f) {
                        if ($f>=$fi && $f<=$ff) {
                            $label = match(substr($f,5)) {
                                '01-01' => 'AÑO NUEVO', '05-01' => 'DIA DEL TRABAJO',
                                '09-16' => 'INDEPENDENCIA', '12-25' => 'NAVIDAD',
                                '10-01' => 'TRANSMISION PODER EJECUTIVO',
                                default => match(substr($f,5,2)) {
                                    '02' => 'CONMEMORACION 5 DE FEBRERO',
                                    '03' => 'CONMEMORACION 21 DE MARZO',
                                    '11' => 'CONMEMORACION 20 DE NOVIEMBRE',
                                    default => 'FESTIVO',
                                },
                            };
                            $festivos[$f] = ['fecha' => $f, 'label' => $label];
                        }
                    }
                }
                return $festivos;
            };

            // ── Query principal ───────────────────────────────────────
            $stmt = $dbc->prepare("
                SELECT e.id AS noEmpleado,
                    CONCAT_WS(' ',e.nombre,e.paterno,e.materno) AS nombreCompleto,
                    e.id_turno, mt.valor AS turno,
                    a.fecha, a.hora, s.servicio,
                    IF(a.id_status=1,'Entrada','Salida') AS tipo,
                    COALESCE(tsd.sueldo,0) AS sueldo,
                    COALESCE(tsd.bono,0) AS bono,
                    COALESCE(tsd.descuento,0) AS descuento
                FROM asistencias a
                LEFT JOIN empleados e ON a.id_empleado = e.id
                LEFT JOIN multicatalogo mt ON e.id_turno = mt.id
                LEFT JOIN servicios s ON a.id_ubicacion = s.id
                LEFT JOIN tabulador_salarios ts ON ts.id_zona = s.id_zona
                LEFT JOIN tabulador_salarios_detalle tsd ON tsd.id_tabulador = ts.id AND tsd.id_puesto = e.id_puesto
                WHERE a.fecha BETWEEN :ini AND :fin AND s.id_zona = :zona
                ORDER BY nombreCompleto DESC, a.fecha ASC, a.hora ASC
            ");
            $stmt->bindValue(':ini',  $inicioCalc, \PDO::PARAM_STR);
            $stmt->bindValue(':fin',  $finCalc,    \PDO::PARAM_STR);
            $stmt->bindValue(':zona', $idZona,     \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // ── Agrupar por empleado ──────────────────────────────────
            $group = [];
            foreach ($rows as $r) {
                $emp = (string)$r['noEmpleado'];
                if (!isset($group[$emp])) {
                    $cfg = $getShiftConfig((string)($r['turno'] ?? ''));
                    $group[$emp] = [
                        'noEmpleado' => (int)$r['noEmpleado'],
                        'nombreCompleto' => (string)$r['nombreCompleto'],
                        'servicio'       => (string)$r['servicio'],
                        'id_turno'       => (int)($r['id_turno'] ?? 0),
                        'turno'          => (string)($r['turno'] ?? ''),
                        'turno_config'   => $cfg,
                        'sueldo'         => (float)$r['sueldo'],
                        'bono'           => (float)$r['bono'],
                        'descuento'      => (float)$r['descuento'],
                        'detalle'        => [], 'incidencias_por_dia' => [],
                        'importes_adicionales' => [], '_events' => [],
                    ];
                }
                if ($r['fecha'] >= $inicio && $r['fecha'] <= $fin) {
                    $group[$emp]['detalle'][] = trim("{$r['tipo']} {$r['fecha']} {$r['hora']}");
                }
                $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $r['fecha'].' '.$r['hora']);
                if ($dt) $group[$emp]['_events'][] = ['tipo' => $r['tipo'], 'dt' => $dt];
            }

            // ── Rango de días ─────────────────────────────────────────
            $mapDow = [1=>'LUN',2=>'MAR',3=>'MIE',4=>'JUE',5=>'VIE',6=>'SAB',7=>'DOM'];
            $diasRango = $diasRangoCalc = [];

            for ($d=new \DateTime($inicio),$e=new \DateTime($fin); $d<=$e; $d->modify('+1 day')) {
                $diasRango[] = ['fecha'=>$d->format('Y-m-d'), 'dow'=>$mapDow[(int)$d->format('N')], 'dia'=>ltrim($d->format('d'),'0')];
            }
            for ($d=new \DateTime($inicioCalc),$e=new \DateTime($finCalc); $d<=$e; $d->modify('+1 day')) {
                $diasRangoCalc[] = $d->format('Y-m-d');
            }

            $festShow = $getDiasFestivos($inicio, $fin);

            // ── Incidencias ───────────────────────────────────────────
            $empIds = array_map(fn($x)=>(int)$x['noEmpleado'], array_values($group));
            $incByEmpDate = [];

            if ($empIds) {
                $ph   = implode(',', array_fill(0, count($empIds), '?'));
                $si   = $dbc->prepare("
                    SELECT i.id_empleado, i.descripcion, i.fecha_inicio, i.fecha_final,
                           i.id_tipo_incidencia, mi.valor AS tipoIncidencia, s.servicio
                    FROM incidencias i
                    LEFT JOIN multicatalogo mi ON i.id_tipo_incidencia = mi.id
                    LEFT JOIN servicios s ON i.id_servicio = s.id
                    WHERE i.activo = 1 AND i.id_empleado IN ({$ph})
                    AND i.fecha_inicio <= ? AND i.fecha_final >= ?
                ");
                $k = 1;
                foreach ($empIds as $id) $si->bindValue($k++, $id, \PDO::PARAM_INT);
                $si->bindValue($k++, $finCalc,    \PDO::PARAM_STR);
                $si->bindValue($k,   $inicioCalc, \PDO::PARAM_STR);
                $si->execute();
                foreach ($si->fetchAll(\PDO::FETCH_ASSOC) as $inc) {
                    $idEmp = (int)$inc['id_empleado'];
                    $fI = $inc['fecha_inicio']; $fF = $inc['fecha_final'] ?: $fI;
                    $di = \DateTime::createFromFormat('Y-m-d',$fI)?:new \DateTime($fI);
                    $df = (\DateTime::createFromFormat('Y-m-d',$fF)?:new \DateTime($fF))->modify('+1 day');
                    foreach (new \DatePeriod($di,new \DateInterval('P1D'),$df) as $day) {
                        $f = $day->format('Y-m-d');
                        if ($f < $inicioCalc || $f > $finCalc) continue;
                        $incByEmpDate[$idEmp][$f][] = [
                            'id_tipo_incidencia' => (int)($inc['id_tipo_incidencia']??0),
                            'tipo'        => (string)($inc['tipoIncidencia']??''),
                            'descripcion' => (string)($inc['descripcion']??''),
                            'servicio'    => (string)($inc['servicio']??''),
                            'inicio'      => $fI, 'final' => $fF,
                        ];
                    }
                }
            }

            // ── Importes adicionales ──────────────────────────────────
            $impByEmp = [];
            if ($empIds) {
                $ph2 = implode(',', array_fill(0, count($empIds), '?'));
                $si2 = $dbc->prepare("
                    SELECT ia.no_empleado, ia.concepto, ia.tipo, ia.importe, ia.fecha_aplicada, ia.descripcion
                    FROM importe_adicional ia
                    WHERE ia.status=1 AND ia.no_empleado IN ({$ph2}) AND ia.fecha_aplicada BETWEEN ? AND ?
                    ORDER BY ia.no_empleado, ia.fecha_aplicada, ia.id
                ");
                $k = 1;
                foreach ($empIds as $id) $si2->bindValue($k++, $id, \PDO::PARAM_INT);
                $si2->bindValue($k++, $inicio, \PDO::PARAM_STR);
                $si2->bindValue($k,   $fin,    \PDO::PARAM_STR);
                $si2->execute();
                foreach ($si2->fetchAll(\PDO::FETCH_ASSOC) as $imp) {
                    $idEmp = (int)($imp['no_empleado']??0);
                    $impByEmp[$idEmp] ??= ['ingresos'=>0.0,'descuentos'=>0.0,'detalle'=>[]];
                    $tipo = strtoupper(trim((string)($imp['tipo']??'')));
                    $monto = (float)($imp['importe']??0);
                    if ($tipo==='INGRESO')    $impByEmp[$idEmp]['ingresos']   += $monto;
                    elseif ($tipo==='DESCUENTO') $impByEmp[$idEmp]['descuentos'] += $monto;
                    $impByEmp[$idEmp]['detalle'][] = ['concepto'=>$imp['concepto'],'tipo'=>$tipo,
                        'importe'=>round($monto,2),'fecha_aplicada'=>$imp['fecha_aplicada'],'descripcion'=>$imp['descripcion']];
                }
            }

            $idsPermitidos = [1328,1329,1388,1289,1390,1392,1395,1397];
            $idsPermiteBono = [1392,1395];

            // ── Cálculo por empleado ──────────────────────────────────
            foreach ($group as &$empRow) {
                usort($empRow['_events'], fn($a,$b) => $a['dt']<=>$b['dt']);

                $turnoCfg = $empRow['turno_config'] ?? $getShiftConfig($empRow['turno']??'');
                $minBono  = (int)($turnoCfg['minutos_min_bono'] ?? 450);
                $esTurno24 = (int)($turnoCfg['horas_turno']??0) >= 24 || !empty($turnoCfg['descanso_siguiente']);

                $events = $empRow['_events'];
                $n      = count($events);
                $workMins = $restDates = [];
                $anchorDate = $primeraSalidaSuelta = null;

                if ($esTurno24) {
                    // Calcular minutos por fecha de entrada (todo el buffer)
                    for ($i=0; $i<$n; $i++) {
                        $ev = $events[$i];
                        if (!$ev['dt'] instanceof \DateTime || $ev['tipo']!=='Entrada') continue;
                        $workDate = $ev['dt']->format('Y-m-d');
                        $mins = 24*60;
                        for ($j=$i+1; $j<$n; $j++) {
                            if ($events[$j]['tipo']==='Salida' && $events[$j]['dt'] instanceof \DateTime) {
                                $diff = $events[$j]['dt']->getTimestamp() - $ev['dt']->getTimestamp();
                                if ($diff > 0) $mins = (int)floor($diff/60);
                                break;
                            }
                            if ($events[$j]['tipo']==='Entrada') break;
                        }
                        if (!isset($workMins[$workDate]) || $mins > $workMins[$workDate]) $workMins[$workDate] = $mins;
                    }

                    // Anchor solo con eventos del rango visible
                    $visibles = array_values(array_filter($events, fn($ev)=>
                        $ev['dt'] instanceof \DateTime &&
                        $ev['dt']->format('Y-m-d') >= $inicio &&
                        $ev['dt']->format('Y-m-d') <= $fin
                    ));

                    foreach ($visibles as $ev) {
                        if ($ev['tipo']==='Entrada') {
                            $anchorDate = $ev['dt']->format('Y-m-d');
                            break;
                        }
                        if ($ev['tipo']==='Salida') {
                            $primeraSalidaSuelta = $ev['dt'];
                            $hora = (int)$ev['dt']->format('G');
                            $tmp = clone $ev['dt'];
                            if ($hora < 12) $tmp->modify('-1 day');
                            $anchorDate = $tmp->format('Y-m-d');
                            break;
                        }
                    }

                    if ($anchorDate === null && !empty($workMins)) {
                        ksort($workMins);
                        $anchorDate = array_key_first($workMins);
                        $ad = new \DateTime($anchorDate);
                        $fd = new \DateTime($inicio);
                        if (abs((int)$ad->diff($fd)->format('%r%a')) % 2 === 1) {
                            $ad->modify('-1 day'); $anchorDate = $ad->format('Y-m-d');
                        }
                    }

                    if ($anchorDate !== null) {
                        $adDt = new \DateTime($anchorDate);
                        foreach ($diasRangoCalc as $fc) {
                            if (abs((int)$adDt->diff(new \DateTime($fc))->format('%r%a')) % 2 === 1) {
                                $restDates[$fc] = true;
                            }
                        }
                    } else {
                        foreach ($workMins as $wd => $_) {
                            $tmp = (new \DateTime($wd))->modify('+1 day');
                            $restDates[$tmp->format('Y-m-d')] = true;
                        }
                    }

                } else {
                    // 8h / 12h
                    for ($i=0; $i<$n; $i++) {
                        if ($events[$i]['tipo']!=='Entrada' || !$events[$i]['dt'] instanceof \DateTime) continue;
                        $eDt = $events[$i]['dt'];
                        $sDt = null;
                        for ($j=$i+1; $j<$n; $j++) {
                            if ($events[$j]['tipo']==='Salida' && $events[$j]['dt'] instanceof \DateTime) { $sDt=$events[$j]['dt']; $i=$j; break; }
                            if ($events[$j]['tipo']==='Entrada') break;
                        }
                        if (!$sDt) continue;
                        $diff = $sDt->getTimestamp() - $eDt->getTimestamp();
                        if ($diff < 0) continue;
                        $wd = $eDt->format('Y-m-d');
                        $mins = (int)floor($diff/60);
                        if (!isset($workMins[$wd]) || $mins > $workMins[$wd]) $workMins[$wd] = $mins;
                    }
                }

                // Status por día
                $statusByDate = [];
                foreach ($diasRangoCalc as $fecha) {
                    $mins = (int)($workMins[$fecha] ?? 0);
                    $incs = $incByEmpDate[(int)$empRow['noEmpleado']][$fecha] ?? [];
                    $descanso = !empty($restDates[$fecha]);
                    $statusByDate[$fecha] = $mins > 0 ? ['code'=>'W','mins'=>$mins,'incidencias'=>$incs,'raw'=>'TRABAJO']
                        : ($descanso ? ['code'=>'D','mins'=>0,'incidencias'=>[],'raw'=>'DESCANSO_POR_TURNO']
                        : (!empty($incs) ? ['code'=>'I','mins'=>0,'incidencias'=>$incs,'raw'=>'INCIDENCIA']
                        : ['code'=>'F','mins'=>0,'incidencias'=>[],'raw'=>'FALTA']));
                }

                // Pivot por día visible
                $filaDow=$filaDia=$filaVal=[];
                $faltas=$faltasJ=$faltasD=$diasT=$diasD=$diasI=$diasP=$diasNP=$diasVB=0;
                $perdioBono=false;
                $sueldo = (float)($empRow['sueldo']??0);
                $salDia = $sueldo/15;
                $diasFT=0; $montoFT=0.0; $detFest=[];

                foreach ($diasRango as $dr) {
                    $f = $dr['fecha'];
                    $filaDow[] = $dr['dow']; $filaDia[] = $dr['dia'];
                    $s = $statusByDate[$f] ?? ['code'=>'F','mins'=>0,'incidencias'=>[],'raw'=>'FALTA'];
                    $code=$s['code']; $mins=(int)($s['mins']??0); $incs=$s['incidencias']??[];
                    $idsInc = array_map(fn($x)=>(int)($x['id_tipo_incidencia']??0), is_array($incs)?$incs:[]);
                    $tieneP = count(array_intersect($idsInc,$idsPermitidos))>0;
                    $tieneB = count(array_intersect($idsInc,$idsPermiteBono))>0;
                    $esFest = isset($festShow[$f]);
                    $festInfo = $festShow[$f] ?? null;

                    if ($code==='D') { $filaVal[]='D'; $diasD++; continue; }
                    if ($code==='W') {
                        $v = str_pad((int)floor($mins/60),2,'0',STR_PAD_LEFT).':'.str_pad($mins%60,2,'0',STR_PAD_LEFT);
                        if ($esFest) { $v.=' *'; $extra=round($salDia*2,2); $diasFT++; $montoFT+=$extra;
                            $detFest[]=['fecha'=>$f,'label'=>$festInfo['label']??'FESTIVO','mins_trabajados'=>$mins,'extra_pagado'=>$extra]; }
                        $filaVal[]=$v; $diasT++; $diasP++;
                        if ($mins>=$minBono||$tieneB) $diasVB++; else $perdioBono=true;
                        continue;
                    }
                    if ($code==='I') {
                        $filaVal[]='I'; $empRow['incidencias_por_dia'][$f]=$incs; $diasI++;
                        if ($tieneP) { $diasP++; $faltasJ++; } else { $diasNP++; $faltas++; $faltasD++; $perdioBono=true; }
                        if ($tieneB) $diasVB++;
                        continue;
                    }
                    $filaVal[]='F'; $faltas++; $faltasD++; $diasNP++; $perdioBono=true;
                }

                $empRow['tabla'] = ['dow'=>$filaDow,'dia'=>$filaDia,'vals'=>$filaVal,'fechas'=>array_column($diasRango,'fecha')];

                $bono      = (float)($empRow['bono']??0);
                $desc      = (float)($empRow['descuento']??0);
                $descFalt  = $faltasD * $desc;
                $bonoAp    = $perdioBono ? 0 : $bono;
                $idEmp     = (int)$empRow['noEmpleado'];
                $ingAd     = (float)($impByEmp[$idEmp]['ingresos']   ?? 0);
                $descAd    = (float)($impByEmp[$idEmp]['descuentos'] ?? 0);
                $detImp    = $impByEmp[$idEmp]['detalle'] ?? [];
                $total     = max(0, $sueldo - $descFalt + $bonoAp + $ingAd + $montoFT - $descAd);

                $empRow['importes_adicionales'] = ['ingresos'=>round($ingAd,2),'descuentos'=>round($descAd,2),'detalle'=>$detImp];
                $empRow['festivos'] = ['dias_oficiales_en_periodo'=>array_values($festShow),'dias_festivos_trabajados'=>$diasFT,
                    'monto_festivos_trabajados'=>round($montoFT,2),'detalle'=>$detFest];
                $empRow['prenomina'] = [
                    'sueldo_base'=>round($sueldo,2),'bono_base'=>round($bono,2),
                    'descuento_por_falta'=>round($desc,2),'salario_diario'=>round($salDia,2),
                    'dias_trabajados'=>$diasT,'dias_descanso'=>$diasD,'dias_con_incidencia'=>$diasI,
                    'dias_festivos_trabajados'=>$diasFT,'dias_pagables'=>$diasP,'dias_no_pagables'=>$diasNP,
                    'dias_validos_bono'=>$diasVB,'faltas'=>$faltas,'faltas_justificadas'=>$faltasJ,
                    'faltas_descontables'=>$faltasD,'descuento_total_faltas'=>round($descFalt,2),
                    'ingresos_adicionales'=>round($ingAd,2),'descuentos_adicionales'=>round($descAd,2),
                    'monto_festivos_trabajados'=>round($montoFT,2),'descuento_total'=>round($descFalt+$descAd,2),
                    'bono_aplicado'=>round($bonoAp,2),'perdio_bono'=>$perdioBono?1:0,'total'=>round($total,2),
                    'turno'=>$empRow['turno']??'','tipo_turno_detectado'=>$turnoCfg['tipo_turno_detectado']??'',
                    'horas_turno'=>$turnoCfg['horas_turno']??0,'minutos_objetivo'=>$turnoCfg['minutos_objetivo']??0,
                    'minutos_min_bono'=>$turnoCfg['minutos_min_bono']??0,
                    'descanso_siguiente'=>!empty($turnoCfg['descanso_siguiente'])?1:0,
                    '_debug_anchor'=>$anchorDate,'_debug_salida_suelta'=>$primeraSalidaSuelta?$primeraSalidaSuelta->format('Y-m-d H:i:s'):null,
                ];
                unset($empRow['_events']);
            }
            unset($empRow);

            // ── Perfil completo ───────────────────────────────────────
            $sqlPerfil = $dbc->prepare("
                SELECT s.servicio, c.nombre_corto, z.zona, s.ubicacion,
                    CONCAT_WS(' ',s.latitud,s.longitud) AS georeferencia,
                    e.id AS noEmpleado, CONCAT_WS(' ',e.nombre,e.paterno,e.materno) AS nombreCompleto,
                    e.curp, e.rfc, e.nss, e.CP_fiscal, e.telefonoEmergencia, e.fecha_efectiva,
                    mt.valor AS turno, mp.valor AS puesto, e.clave_interbancaria,
                    a.fecha, a.hora, IF(a.id_status=1,'Entrada','Salida') AS tipo
                FROM empleados e
                LEFT JOIN multicatalogo mt ON e.id_turno = mt.id
                LEFT JOIN multicatalogo mp ON e.id_puesto = mp.id
                LEFT JOIN asistencias a ON a.id_empleado = e.id
                    AND CONCAT(a.fecha,' ',a.hora) = (SELECT MAX(CONCAT(a2.fecha,' ',a2.hora)) FROM asistencias a2 WHERE a2.id_empleado = e.id)
                LEFT JOIN servicios s ON a.id_ubicacion = s.id
                LEFT JOIN zonas z ON s.id_zona = z.id
                LEFT JOIN clientes c ON s.id_cliente = c.id
                WHERE e.id = :id LIMIT 1
            ");

            foreach ($group as &$empRow) {
                $sqlPerfil->bindValue(':id', (int)$empRow['noEmpleado'], \PDO::PARAM_INT);
                $sqlPerfil->execute();
                $empRow['perfil'] = $sqlPerfil->fetch(\PDO::FETCH_ASSOC) ?: new \stdClass();
            }
            unset($empRow);

            return ['status' => 'ok', 'count' => count($group), 'data' => array_values($group)];

        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
