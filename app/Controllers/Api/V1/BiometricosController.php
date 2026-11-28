<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Biométricos (equipos Hikvision) — solo administradores (jwt:admin).
 *
 *   GET  biometricos/equipos          lista de equipos con marcaciones de hoy y último evento
 *   POST biometricos/equipos          alta manual de un equipo
 *   PUT  biometricos/equipos/(id)     editar (nombre, ubicación, ip, mac, modelo, serie, notas, activo)
 *   GET  biometricos/marcaciones      ?desde=&hasta=&q=&id_equipo=&sin_asignar=1  quién marcó en los equipos
 */
class BiometricosController extends ResourceController
{
    protected $format = 'json';

    private function admin(): ?object
    {
        $u = $this->request->jwtUser ?? null;
        return ($u && (int)($u->nivel ?? 99) <= 2) ? $u : null;
    }

    private function err(string $m, int $c = 400)
    {
        return $this->respond(['status' => 'error', 'message' => $m], $c);
    }

    public function equipos()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $db = \Config\Database::connect();
        try {
            $rows = $db->query("
                SELECT q.id, q.nombre, q.mac, q.ip, q.modelo, q.serie, q.ubicacion, q.notas, q.activo, q.auto_registrado,
                       q.ultimo_evento_at, q.eventos_total,
                       (SELECT COUNT(*) FROM hikvision_eventos h WHERE h.id_equipo = q.id AND h.fecha_evento >= CURDATE()) AS marcaciones_hoy,
                       (SELECT COUNT(DISTINCT h.empleado_no) FROM hikvision_eventos h WHERE h.id_equipo = q.id AND h.fecha_evento >= CURDATE()) AS personas_hoy
                FROM biometricos_equipos q
                ORDER BY q.activo DESC, q.nombre")->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Biometricos equipos: ' . $e->getMessage());
            return $this->err('Falta correr migracion_biometricos_equipos.sql', 500);
        }
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    private function campos(): array
    {
        $b = $this->request->getJSON(true) ?? [];
        $t = fn($k, $n) => ($v = trim((string)($b[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $n);
        return [
            'nombre'    => $t('nombre', 120),
            'mac'       => ($m = $t('mac', 20)) ? strtolower($m) : null,
            'ip'        => $t('ip', 45),
            'modelo'    => $t('modelo', 80),
            'serie'     => $t('serie', 80),
            'ubicacion' => $t('ubicacion', 200),
            'notas'     => $t('notas', 255),
            'activo'    => array_key_exists('activo', $b) ? ((int)(bool)$b['activo']) : 1,
        ];
    }

    public function crear()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $d = $this->campos();
        if (!$d['nombre']) return $this->err('Falta el nombre del equipo', 422);
        $db = \Config\Database::connect();
        try {
            if ($d['mac'] && $db->query('SELECT id FROM biometricos_equipos WHERE mac = ?', [$d['mac']])->getRowArray())
                return $this->err('Ya existe un equipo con esa MAC', 409);
            $db->table('biometricos_equipos')->insert($d);
        } catch (\Throwable $e) {
            log_message('error', 'Biometricos crear: ' . $e->getMessage());
            return $this->err('No se pudo guardar (¿falta correr migracion_biometricos_equipos.sql?)', 500);
        }
        return $this->respondCreated(['status' => 'ok', 'id' => (int)$db->insertID()]);
    }

    public function actualizar($id = null)
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $id = (int)$id;
        $d = $this->campos();
        if (!$d['nombre']) return $this->err('Falta el nombre del equipo', 422);
        $db = \Config\Database::connect();
        try {
            if ($d['mac'] && $db->query('SELECT id FROM biometricos_equipos WHERE mac = ? AND id <> ?', [$d['mac'], $id])->getRowArray())
                return $this->err('Ya existe otro equipo con esa MAC', 409);
            $db->table('biometricos_equipos')->where('id', $id)->update($d);
        } catch (\Throwable $e) {
            log_message('error', 'Biometricos actualizar: ' . $e->getMessage());
            return $this->err('No se pudo guardar', 500);
        }
        return $this->respond(['status' => 'ok']);
    }

    /**
     * Asistencia unificada: por empleado y día, lo del biométrico (primera/última marcación) junto con lo de la app
     * (entrada/salida), el origen de cada registro y la diferencia de entrada entre ambos.
     *   ?desde=&hasta=  (máx. 31 días)  &q=  &origen=ambos|biometrico|app
     */
    public function asistencia()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $desde = (string)($this->request->getGet('desde') ?? '');
        $hasta = (string)($this->request->getGet('hasta') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) return $this->err('Elige el rango de fechas', 422);
        if (strtotime($hasta) < strtotime($desde)) return $this->err('La fecha final es anterior a la inicial', 422);
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 31) return $this->err('El rango máximo es de 31 días', 422);
        $q      = trim((string)($this->request->getGet('q') ?? ''));
        $origen = (string)($this->request->getGet('origen') ?? '');
        if (!in_array($origen, ['ambos', 'biometrico', 'app'], true)) $origen = '';
        $max = 20000;

        $db = \Config\Database::connect();
        try {
            $bio = $db->query(
                'SELECT l.id_empleado, l.fecha, MIN(l.hora) AS primera, MAX(l.hora) AS ultima, COUNT(*) AS marcaciones
                 FROM biometrico_logs l
                 WHERE l.id_empleado IS NOT NULL AND l.fecha BETWEEN ? AND ?
                 GROUP BY l.id_empleado, l.fecha', [$desde, $hasta])->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Asistencia unificada (biométrico): ' . $e->getMessage());
            return $this->err('Falta correr migracion_lector_biometrico_2.sql', 500);
        }
        try {
            $app = $db->query(
                'SELECT id_empleado, fecha,
                        MIN(CASE WHEN id_status = 1 THEN hora END) AS entrada,
                        MAX(CASE WHEN id_status = 2 THEN hora END) AS salida
                 FROM asistencias
                 WHERE fecha BETWEEN ? AND ?
                 GROUP BY id_empleado, fecha', [$desde, $hasta])->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Asistencia unificada (app): ' . $e->getMessage());
            return $this->err('No se pudo leer la asistencia de la app', 500);
        }

        // Se juntan por empleado + día
        $mapa = [];
        foreach ($bio as $b) {
            $k = (int)$b['id_empleado'] . '|' . $b['fecha'];
            $mapa[$k] = ['id_empleado' => (int)$b['id_empleado'], 'fecha' => $b['fecha'],
                'bio_primera' => $b['primera'], 'bio_ultima' => $b['ultima'], 'bio_marcaciones' => (int)$b['marcaciones'],
                'app_entrada' => null, 'app_salida' => null];
        }
        foreach ($app as $a) {
            $k = (int)$a['id_empleado'] . '|' . $a['fecha'];
            if (!isset($mapa[$k])) {
                $mapa[$k] = ['id_empleado' => (int)$a['id_empleado'], 'fecha' => $a['fecha'],
                    'bio_primera' => null, 'bio_ultima' => null, 'bio_marcaciones' => 0, 'app_entrada' => null, 'app_salida' => null];
            }
            $mapa[$k]['app_entrada'] = $a['entrada'];
            $mapa[$k]['app_salida']  = $a['salida'];
        }

        // Nombres y ubicación
        $ids = array_values(array_unique(array_map(fn($r) => $r['id_empleado'], $mapa)));
        $emp = [];
        foreach (array_chunk($ids, 1000) as $lote) {
            if (!$lote) continue;
            $in = implode(',', array_map('intval', $lote));
            foreach ($db->query(
                "SELECT e.id, CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS empleado, s.servicio AS ubicacion
                 FROM empleados e LEFT JOIN servicios s ON s.id = e.id_ubicacion_principal
                 WHERE e.id IN ($in)")->getResultArray() as $e) $emp[(int)$e['id']] = $e;
        }

        $min = fn($t) => $t ? (int)round(strtotime('1970-01-01 ' . $t . ' UTC') / 60) : null;
        $rows = [];
        $kp = ['filas' => 0, 'ambos' => 0, 'biometrico' => 0, 'app' => 0, 'con_diferencia' => 0];
        $personas = [];
        foreach ($mapa as $r) {
            $tieneBio = $r['bio_marcaciones'] > 0;
            $tieneApp = $r['app_entrada'] !== null || $r['app_salida'] !== null;
            $org = $tieneBio && $tieneApp ? 'ambos' : ($tieneBio ? 'biometrico' : 'app');
            if ($origen !== '' && $org !== $origen) continue;
            $e = $emp[$r['id_empleado']] ?? null;
            $nombre = $e ? trim((string)$e['empleado']) : '';
            $numero = str_pad((string)$r['id_empleado'], 6, '0', STR_PAD_LEFT);
            if ($q !== '' && stripos($nombre, $q) === false && stripos($numero, $q) === false && (string)$r['id_empleado'] !== ltrim($q, '0')) continue;

            // Diferencia de entrada: minutos que separan la entrada de la app de la primera marcación del biométrico
            $dif = null;
            if ($r['app_entrada'] !== null && $tieneBio) $dif = $min($r['app_entrada']) - $min($r['bio_primera']);

            // Entrada / salida consolidadas: la más temprana y la más tardía entre las dos fuentes
            $entradas = array_filter([$r['bio_primera'], $r['app_entrada']]);
            $salidas  = array_filter([$r['bio_marcaciones'] >= 2 ? $r['bio_ultima'] : null, $r['app_salida']]);
            $r += [
                'numero' => $numero, 'empleado' => $nombre !== '' ? $nombre : null, 'ubicacion' => $e['ubicacion'] ?? null,
                'origen' => $org, 'dif_entrada_min' => $dif,
                'entrada' => $entradas ? min($entradas) : null, 'salida' => $salidas ? max($salidas) : null,
            ];
            $rows[] = $r;
            $kp['filas']++; $kp[$org]++; $personas[$r['id_empleado']] = 1;
            if ($dif !== null && abs($dif) > 15) $kp['con_diferencia']++;
        }
        usort($rows, fn($x, $y) => [$y['fecha'], (string)$x['empleado']] <=> [$x['fecha'], (string)$y['empleado']]);
        $trunc = count($rows) > $max;
        if ($trunc) $rows = array_slice($rows, 0, $max);
        $kp['personas'] = count($personas);
        return $this->respond(['status' => 'ok', 'truncado' => $trunc, 'resumen' => $kp, 'data' => $rows]);
    }

    /**
     * Marcaciones del biométrico de un empleado (para su perfil): últimas 10, cuántas lleva hoy y en total.
     *   GET biometricos/empleado/(id)
     */
    public function deEmpleado($id = null)
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $id  = (int)$id;
        $hoy = (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $db  = \Config\Database::connect();
        try {
            $tot = $db->query('SELECT COUNT(*) AS total, SUM(fecha = ?) AS hoy FROM biometrico_logs WHERE id_empleado = ?', [$hoy, $id])->getRowArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'ok', 'data' => ['disponible' => false, 'hoy' => 0, 'total' => 0, 'ultimas' => []]]);
        }
        try {
            $ult = $db->query(
                "SELECT x.id, x.fecha, x.hora, x.metodo,
                        (SELECT q.nombre FROM hikvision_eventos h JOIN biometricos_equipos q ON q.id = h.id_equipo
                          WHERE h.id_empleado = ? AND h.fecha_evento = TIMESTAMP(x.fecha, x.hora) LIMIT 1) AS equipo
                 FROM (SELECT id, fecha, hora, metodo FROM biometrico_logs WHERE id_empleado = ? ORDER BY fecha DESC, hora DESC LIMIT 10) x
                 ORDER BY x.fecha DESC, x.hora DESC", [$id, $id])->getResultArray();
        } catch (\Throwable $e) {
            // Sin tabla de equipos todavía: solo la marcación
            $ult = $db->query('SELECT id, fecha, hora, metodo, NULL AS equipo FROM biometrico_logs WHERE id_empleado = ? ORDER BY fecha DESC, hora DESC LIMIT 10', [$id])->getResultArray();
        }
        // ── Jornadas por turno ───────────────────────────────────────────────────────
        // El biométrico solo da horas sueltas (no dice si es entrada o salida). Se agrupan según el turno:
        // la primera marcación abre la jornada y las que caen dentro de [duración del turno + tolerancia]
        // pertenecen a ella; la última es la salida. Así un turno nocturno (19:00 a 07:00) cruza la medianoche.
        $turnoNombre = null; $idTurno = 0; $dur = null; $jornadas = [];
        try {
            $t = $db->query('SELECT e.id_turno, mt.valor AS turno FROM empleados e LEFT JOIN multicatalogo mt ON mt.id = e.id_turno WHERE e.id = ?', [$id])->getRowArray();
            $idTurno = (int)($t['id_turno'] ?? 0); $turnoNombre = $t['turno'] ?? null;
            $dur = $idTurno ? \App\Libraries\TurnosLibrary::duracionHoras($idTurno) : null;

            $tz = new \DateTimeZone('America/Mexico_City');
            $ahora = new \DateTime('now', $tz);
            $desde = (clone $ahora)->modify('-14 days')->format('Y-m-d');
            $raw = $db->query('SELECT fecha, hora FROM biometrico_logs WHERE id_empleado = ? AND fecha >= ? ORDER BY fecha, hora LIMIT 600', [$id, $desde])->getResultArray();

            // Se ignoran dobles toques (menos de 3 min entre uno y otro)
            $marc = [];
            foreach ($raw as $r) {
                $d = new \DateTime($r['fecha'] . ' ' . $r['hora'], $tz);
                if ($marc && ($d->getTimestamp() - end($marc)->getTimestamp()) < 180) continue;
                $marc[] = $d;
            }
            $durH   = $dur ?: 12.0;
            $gracia = 240; // minutos de tolerancia después de cumplir el turno (igual que el cierre automático)
            $n = count($marc); $i = 0;
            while ($i < $n) {
                $t0 = $marc[$i];
                $limite = (clone $t0)->modify('+' . (int)round($durH * 60 + $gracia) . ' minutes');
                $ultM = $t0; $j = $i + 1; $cuenta = 1;
                while ($j < $n && $marc[$j] <= $limite) { $ultM = $marc[$j]; $cuenta++; $j++; }
                $i = $j;

                $salida   = ($cuenta >= 2 && ($ultM->getTimestamp() - $t0->getTimestamp()) >= 1800) ? $ultM : null;
                $esperada = (clone $t0)->modify('+' . (int)round($durH * 60) . ' minutes');
                $estado   = $salida ? 'completa' : ($ahora < (clone $esperada)->modify("+{$gracia} minutes") ? 'en_turno' : 'sin_salida');
                $antic    = ($salida && $dur && $salida < (clone $esperada)->modify('-15 minutes'))
                    ? (int)round(($esperada->getTimestamp() - $salida->getTimestamp()) / 60) : null;
                $jornadas[] = [
                    'entrada' => $t0->format('Y-m-d H:i:s'), 'salida' => $salida ? $salida->format('Y-m-d H:i:s') : null,
                    'esperada' => $dur ? $esperada->format('Y-m-d H:i:s') : null,
                    'marcaciones' => $cuenta, 'estado' => $estado, 'anticipada_min' => $antic,
                    'horas' => $salida ? round(($salida->getTimestamp() - $t0->getTimestamp()) / 3600, 1) : null,
                ];
            }
            $jornadas = array_slice(array_reverse($jornadas), 0, 6);
        } catch (\Throwable $e) {
            log_message('error', 'Jornadas biométrico: ' . $e->getMessage());
        }

        return $this->respond(['status' => 'ok', 'data' => [
            'disponible' => true, 'hoy' => (int)($tot['hoy'] ?? 0), 'total' => (int)($tot['total'] ?? 0), 'ultimas' => $ult,
            'turno' => $turnoNombre, 'duracion_horas' => $dur, 'jornadas' => $jornadas,
        ]]);
    }

    public function marcaciones()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $desde = (string)($this->request->getGet('desde') ?? '');
        $hasta = (string)($this->request->getGet('hasta') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) return $this->err('Elige el rango de fechas', 422);
        $q    = trim((string)($this->request->getGet('q') ?? ''));
        $idEq = (int)($this->request->getGet('id_equipo') ?? 0);
        $sin  = $this->request->getGet('sin_asignar') === '1';
        $max  = 5000;

        $where = "h.empleado_no <> '' AND h.fecha_evento >= ? AND h.fecha_evento < DATE_ADD(?, INTERVAL 1 DAY)";
        $par = [$desde, $hasta];
        if ($idEq > 0) { $where .= ' AND h.id_equipo = ?'; $par[] = $idEq; }
        if ($sin) $where .= ' AND h.id_empleado IS NULL';

        $db = \Config\Database::connect();
        try {
            // Se agrupa por persona+hora+equipo: el equipo reenvía eventos y así no salen repetidos
            $sql = "SELECT x.*,
                           LPAD(e.id, 6, '0') AS numero,
                           CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS empleado,
                           q.nombre AS equipo
                    FROM (
                        SELECT MIN(h.id) AS id, h.empleado_no, MAX(h.nombre) AS nombre_equipo, h.fecha_evento,
                               MAX(h.metodo) AS metodo, h.id_equipo, h.id_empleado
                        FROM hikvision_eventos h
                        WHERE {$where}
                        GROUP BY h.empleado_no, h.fecha_evento, h.id_equipo, h.id_empleado
                    ) x
                    LEFT JOIN empleados e ON e.id = x.id_empleado
                    LEFT JOIN biometricos_equipos q ON q.id = x.id_equipo";
            if ($q !== '') {
                $like = '%' . $q . '%';
                $sql .= " WHERE (CONCAT_WS(' ', e.nombre, e.paterno, e.materno) LIKE ? OR x.nombre_equipo LIKE ? OR x.empleado_no = ? OR LPAD(e.id, 6, '0') LIKE ?)";
                array_push($par, $like, $like, $q, $like);
            }
            $sql .= ' ORDER BY x.fecha_evento DESC LIMIT ' . ($max + 1);
            $rows = $db->query($sql, $par)->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Biometricos marcaciones: ' . $e->getMessage());
            return $this->err('Falta correr migracion_biometricos_equipos.sql', 500);
        }
        $trunc = count($rows) > $max;
        if ($trunc) array_pop($rows);
        return $this->respond(['status' => 'ok', 'truncado' => $trunc, 'data' => $rows]);
    }
}
