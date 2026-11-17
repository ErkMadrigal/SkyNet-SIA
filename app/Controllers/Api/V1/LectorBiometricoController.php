<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Lector biométrico (terminal facial). Solo administradores (jwt:admin).
 * Importa el CSV del lector, deja asignar a cada empleado su número de biométrico y
 * permite consultar los movimientos por día (y compararlos con los de la app).
 *
 * Formato de cada línea del CSV (sin encabezado):
 *   id_biometrico, nombre, yyyy.mm.dd, hh:mm:ss, tipo, método, resultado, extra
 *
 *   POST lector-biometrico/importar        archivo CSV (multipart "archivo"): solo registra a quien tiene su número asignado
 *   GET  lector-biometrico/registros       ?desde=&hasta=&q=&modo=resumen|detalle  (primera/última del día o todos los movimientos)
 *   GET  lector-biometrico/resumen         totales + fechas con datos + últimos lotes
 *   GET  lector-biometrico/movimientos     ?fecha=YYYY-MM-DD&q=&sin_asignar=1
 *   GET  lector-biometrico/personas        ?q=&sin_asignar=1  (números distintos del lector)
 *   POST lector-biometrico/asignar         { id_biometrico, id_empleado|null }
 *   POST lector-biometrico/asignar-masivo  { filas: [{ id_empleado, id_biometrico }] }
 *   GET  lector-biometrico/empleados       ?q=  (buscador de empleados para asignar)
 *   GET  lector-biometrico/empleado/(id)   número de biométrico de un empleado
 */
class LectorBiometricoController extends ResourceController
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

    /* ═══════════════ IMPORTAR ═══════════════ */

    public function importar()
    {
        $u = $this->admin();
        if (!$u) return $this->err('Sin permiso', 403);

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) return $this->err('Sube el archivo CSV del lector', 422);
        if ($archivo->getSize() > 20 * 1024 * 1024) return $this->err('El archivo pesa más de 20 MB', 422);

        $fh = fopen($archivo->getTempName(), 'r');
        if (!$fh) return $this->err('No se pudo leer el archivo', 422);

        $db = \Config\Database::connect();
        @set_time_limit(120);

        $validas = []; $invalidas = 0; $desde = null; $hasta = null;
        while (($f = fgetcsv($fh, 0, ',')) !== false) {
            if (count($f) < 7) { if (count(array_filter($f, fn($x) => trim((string)$x) !== '')) > 0) $invalidas++; continue; }
            $id    = trim((string)$f[0]);
            $fecha = str_replace('.', '-', trim((string)$f[2]));
            $hora  = trim((string)$f[3]);
            if ($id === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $hora)) { $invalidas++; continue; }
            if (strlen($hora) === 5) $hora .= ':00';
            $validas[] = [
                'id_biometrico' => mb_substr($id, 0, 20),
                'nombre'        => mb_substr(trim((string)$f[1]), 0, 100, 'UTF-8'),
                'fecha'         => $fecha,
                'hora'          => $hora,
                'tipo'          => mb_substr(trim((string)$f[4]), 0, 30),
                'metodo'        => mb_substr(trim((string)($f[5] ?? '')), 0, 40),
                'resultado'     => mb_substr(trim((string)($f[6] ?? '')), 0, 40),
            ];
            if ($desde === null || $fecha < $desde) $desde = $fecha;
            if ($hasta === null || $fecha > $hasta) $hasta = $fecha;
        }
        fclose($fh);

        if (!$validas) return $this->err('No se encontró ninguna fila válida. Revisa que sea el CSV del lector (número, nombre, fecha, hora...)', 422);

        try {
            // Solo se registra a quien ya tiene su número de biométrico asignado a un empleado en el SIAG
            $mapa = [];
            foreach ($db->query('SELECT id, id_biometrico FROM empleados WHERE id_biometrico IS NOT NULL')->getResultArray() as $e) {
                $mapa[(string)$e['id_biometrico']] = (int)$e['id'];
            }

            $aInsertar = []; $sinAsignar = []; $noReconocidas = 0; $empleadosDelArchivo = [];
            foreach ($validas as $r) {
                if ($r['id_biometrico'] === '-1') { $noReconocidas++; continue; }
                if (!isset($mapa[$r['id_biometrico']])) {
                    $k = $r['id_biometrico'];
                    if (!isset($sinAsignar[$k])) $sinAsignar[$k] = ['id_biometrico' => $k, 'nombre' => $r['nombre'], 'marcaciones' => 0];
                    $sinAsignar[$k]['marcaciones']++;
                    continue;
                }
                $r['id_empleado'] = $mapa[$r['id_biometrico']];
                $empleadosDelArchivo[$r['id_empleado']] = true;
                $aInsertar[] = $r;
            }

            $db->query('INSERT INTO biometrico_lotes (archivo, filas, desde, hasta, created_by, created_at) VALUES (?,?,?,?,?,NOW())',
                [mb_substr($archivo->getClientName(), 0, 255), count($validas), $desde, $hasta, (int)($u->id ?? 0)]);
            $idLote = (int)$db->insertID();

            $nuevas = 0;
            foreach (array_chunk($aInsertar, 500) as $lote) {
                $ph = []; $par = [];
                foreach ($lote as $r) {
                    $ph[] = '(?,?,?,?,?,?,?,?,?,NOW())';
                    array_push($par, $r['id_biometrico'], $r['id_empleado'], $r['nombre'], $r['fecha'], $r['hora'], $r['tipo'], $r['metodo'], $r['resultado'], $idLote);
                }
                $db->query('INSERT IGNORE INTO biometrico_logs (id_biometrico, id_empleado, nombre, fecha, hora, tipo, metodo, resultado, id_lote, created_at) VALUES ' . implode(',', $ph), $par);
                $nuevas += $db->affectedRows();
            }
            $repetidas = count($aInsertar) - $nuevas;
            $db->query('UPDATE biometrico_lotes SET nuevas = ?, duplicadas = ?, invalidas = ? WHERE id = ?', [$nuevas, $repetidas, $invalidas, $idLote]);
        } catch (\Throwable $e) {
            log_message('error', 'Importar lector: ' . $e->getMessage());
            return $this->err('Falta correr migracion_lector_biometrico.sql y migracion_lector_biometrico_2.sql, o hubo un error al guardar', 500);
        }

        usort($sinAsignar, fn($a, $b) => $b['marcaciones'] <=> $a['marcaciones']);
        $omitidasFilas = array_sum(array_column($sinAsignar, 'marcaciones'));

        AuditLibrary::log((int)($u->id ?? 0), 'LECTOR_IMPORTAR', 'biometrico_lotes', (string)$idLote, "Importó {$nuevas} marcaciones de {$archivo->getClientName()}");
        return $this->respond(['status' => 'ok', 'data' => [
            'id_lote'          => $idLote,
            'filas'            => count($validas),
            'registradas'      => $nuevas,
            'repetidas'        => $repetidas,
            'invalidas'        => $invalidas,
            'empleados'        => count($empleadosDelArchivo),
            'desde'            => $desde,
            'hasta'            => $hasta,
            'sin_asignar'      => count($sinAsignar),          // números del archivo sin empleado en el SIAG
            'sin_asignar_filas'=> $omitidasFilas,
            'sin_asignar_lista'=> array_slice($sinAsignar, 0, 300),
            'no_reconocidas'   => $noReconocidas,
        ]]);
    }

    /* ═══════════════ CONSULTAS ═══════════════ */

    public function resumen()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $db = \Config\Database::connect();
        try {
            $tot = $db->query("SELECT COUNT(*) AS marcaciones, COUNT(DISTINCT id_empleado) AS empleados, MIN(fecha) AS desde, MAX(fecha) AS hasta FROM biometrico_logs")->getRowArray();
            $lotes = $db->query('SELECT id, archivo, filas, nuevas, duplicadas, invalidas, desde, hasta, created_at FROM biometrico_lotes ORDER BY id DESC LIMIT 10')->getResultArray();
            $asig = $db->query('SELECT COUNT(*) AS n FROM empleados WHERE id_biometrico IS NOT NULL')->getRowArray();
        } catch (\Throwable $e) {
            return $this->err('Falta correr migracion_lector_biometrico.sql y migracion_lector_biometrico_2.sql', 500);
        }
        $tot['con_id_asignado'] = (int)($asig['n'] ?? 0);
        return $this->respond(['status' => 'ok', 'data' => ['totales' => $tot, 'lotes' => $lotes]]);
    }

    /**
     * Registros del lector ya guardados (solo empleados con ID asignado).
     *   modo=resumen  -> por empleado y día: primera entrada, última marcación y total
     *   modo=detalle  -> todos los movimientos
     */
    public function registros()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $desde = (string)($this->request->getGet('desde') ?? '');
        $hasta = (string)($this->request->getGet('hasta') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) return $this->err('Elige el rango de fechas', 422);
        $q    = trim((string)($this->request->getGet('q') ?? ''));
        $modo = $this->request->getGet('modo') === 'detalle' ? 'detalle' : 'resumen';
        $max  = 20000;

        $where = 'l.fecha BETWEEN ? AND ?'; $par = [$desde, $hasta];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= " AND (CONCAT_WS(' ', e.nombre, e.paterno, e.materno) LIKE ? OR LPAD(e.id, 6, '0') LIKE ? OR l.id_biometrico = ?)";
            array_push($par, $like, $like, $q);
        }

        $db = \Config\Database::connect();
        try {
            if ($modo === 'resumen') {
                $sql = "SELECT l.id_empleado, LPAD(e.id, 6, '0') AS numero, CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS empleado,
                               s.servicio AS ubicacion, MAX(l.id_biometrico) AS id_biometrico, l.fecha,
                               MIN(l.hora) AS primera, MAX(l.hora) AS ultima, COUNT(*) AS marcaciones
                        FROM biometrico_logs l
                        JOIN empleados e ON e.id = l.id_empleado
                        LEFT JOIN servicios s ON s.id = e.id_ubicacion_principal
                        WHERE {$where}
                        GROUP BY l.id_empleado, e.id, e.nombre, e.paterno, e.materno, s.servicio, l.fecha
                        ORDER BY l.fecha, e.paterno, e.nombre LIMIT " . ($max + 1);
            } else {
                $sql = "SELECT l.id_empleado, LPAD(e.id, 6, '0') AS numero, CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS empleado,
                               s.servicio AS ubicacion, l.id_biometrico, l.fecha, l.hora, l.metodo
                        FROM biometrico_logs l
                        JOIN empleados e ON e.id = l.id_empleado
                        LEFT JOIN servicios s ON s.id = e.id_ubicacion_principal
                        WHERE {$where}
                        ORDER BY l.fecha, e.paterno, e.nombre, l.hora LIMIT " . ($max + 1);
            }
            $rows = $db->query($sql, $par)->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Registros lector: ' . $e->getMessage());
            return $this->err('Falta correr migracion_lector_biometrico_2.sql', 500);
        }
        $trunc = count($rows) > $max;
        if ($trunc) array_pop($rows);
        return $this->respond(['status' => 'ok', 'modo' => $modo, 'truncado' => $trunc, 'data' => $rows]);
    }

    /** Movimientos de un día, agrupados por persona. */
    public function movimientos()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $fecha = (string)($this->request->getGet('fecha') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return $this->err('Fecha inválida', 422);
        $q   = trim((string)($this->request->getGet('q') ?? ''));
        $sin = $this->request->getGet('sin_asignar') === '1';

        $db = \Config\Database::connect();
        try {
            $filas = $db->query("
                SELECT l.id_biometrico, MAX(l.nombre) AS nombre_lector,
                       GROUP_CONCAT(l.hora ORDER BY l.hora SEPARATOR ',') AS horas,
                       COUNT(*) AS marcaciones, MAX(l.resultado) AS resultado,
                       e.id AS id_empleado, CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS empleado
                FROM biometrico_logs l
                LEFT JOIN empleados e ON e.id_biometrico = l.id_biometrico
                WHERE l.fecha = ?
                GROUP BY l.id_biometrico, e.id
                ORDER BY (l.id_biometrico = '-1'), MIN(l.hora)
            ", [$fecha])->getResultArray();

            // Lo que registró cada empleado en la app ese día (primera entrada / última salida)
            $ids = array_values(array_filter(array_map(fn($r) => (int)$r['id_empleado'], $filas)));
            $app = [];
            if ($ids) {
                $in = implode(',', $ids);
                foreach ($db->query("
                    SELECT id_empleado,
                           MIN(CASE WHEN id_status = 1 THEN hora END) AS entrada,
                           MAX(CASE WHEN id_status = 2 THEN hora END) AS salida
                    FROM asistencias WHERE fecha = ? AND id_empleado IN ($in) GROUP BY id_empleado
                ", [$fecha])->getResultArray() as $a) $app[(int)$a['id_empleado']] = $a;
            }
        } catch (\Throwable $e) {
            return $this->err('Falta correr migracion_lector_biometrico.sql', 500);
        }

        $out = [];
        foreach ($filas as $r) {
            $asignado = !empty($r['id_empleado']);
            if ($sin && $asignado) continue;
            if ($q !== '') {
                $hay = stripos((string)$r['nombre_lector'], $q) !== false || stripos((string)$r['empleado'], $q) !== false
                    || (string)$r['id_biometrico'] === $q || ($asignado && (int)$r['id_empleado'] === (int)ltrim($q, '0'));
                if (!$hay) continue;
            }
            $horas = explode(',', (string)$r['horas']);
            $out[] = [
                'id_biometrico'  => $r['id_biometrico'],
                'nombre_lector'  => $r['nombre_lector'],
                'reconocido'     => $r['id_biometrico'] !== '-1',
                'id_empleado'    => $asignado ? (int)$r['id_empleado'] : null,
                'numero'         => $asignado ? str_pad((string)$r['id_empleado'], 6, '0', STR_PAD_LEFT) : null,
                'empleado'       => $asignado ? trim($r['empleado']) : null,
                'horas'          => array_map(fn($h) => substr($h, 0, 5) . substr($h, 5), $horas),
                'marcaciones'    => (int)$r['marcaciones'],
                'primera'        => $horas[0] ?? null,
                'ultima'         => end($horas) ?: null,
                'app_entrada'    => $asignado ? ($app[(int)$r['id_empleado']]['entrada'] ?? null) : null,
                'app_salida'     => $asignado ? ($app[(int)$r['id_empleado']]['salida'] ?? null) : null,
            ];
        }
        return $this->respond(['status' => 'ok', 'data' => $out]);
    }

    /** Números distintos del lector con su empleado asignado (para la pantalla de asignación). */
    public function personas()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $q   = trim((string)($this->request->getGet('q') ?? ''));
        $sin = $this->request->getGet('sin_asignar') === '1';
        $db  = \Config\Database::connect();
        try {
            $rows = $db->query("
                SELECT l.id_biometrico, MAX(l.nombre) AS nombre_lector, COUNT(*) AS marcaciones,
                       MIN(l.fecha) AS desde, MAX(l.fecha) AS hasta,
                       e.id AS id_empleado, CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS empleado
                FROM biometrico_logs l
                LEFT JOIN empleados e ON e.id_biometrico = l.id_biometrico
                WHERE l.id_biometrico <> '-1'
                GROUP BY l.id_biometrico, e.id
                ORDER BY (e.id IS NOT NULL), MAX(l.nombre)
            ")->getResultArray();
        } catch (\Throwable $e) {
            return $this->err('Falta correr migracion_lector_biometrico.sql', 500);
        }
        $out = [];
        foreach ($rows as $r) {
            $asignado = !empty($r['id_empleado']);
            if ($sin && $asignado) continue;
            if ($q !== '' && stripos((string)$r['nombre_lector'], $q) === false && stripos((string)$r['empleado'], $q) === false && (string)$r['id_biometrico'] !== $q) continue;
            $out[] = [
                'id_biometrico' => $r['id_biometrico'], 'nombre_lector' => $r['nombre_lector'], 'marcaciones' => (int)$r['marcaciones'],
                'desde' => $r['desde'], 'hasta' => $r['hasta'],
                'id_empleado' => $asignado ? (int)$r['id_empleado'] : null,
                'numero'      => $asignado ? str_pad((string)$r['id_empleado'], 6, '0', STR_PAD_LEFT) : null,
                'empleado'    => $asignado ? trim($r['empleado']) : null,
            ];
        }
        return $this->respond(['status' => 'ok', 'data' => $out]);
    }

    /* ═══════════════ ASIGNACIÓN ═══════════════ */

    /** Asigna (o quita con id_empleado = null) el número del biométrico a un empleado. */
    private function asignarUno($db, string $idBio, ?int $idEmp): array
    {
        $idBio = trim($idBio);
        if (!preg_match('/^\d{1,20}$/', $idBio) || $idBio === '-1') return ['ok' => false, 'msg' => 'Número de biométrico inválido'];

        if ($idEmp === null) { // quitar la asignación de ese número
            $db->query('UPDATE empleados SET id_biometrico = NULL WHERE id_biometrico = ?', [$idBio]);
            return ['ok' => true, 'msg' => 'Asignación quitada'];
        }
        $emp = $db->query('SELECT id FROM empleados WHERE id = ?', [$idEmp])->getRowArray();
        if (!$emp) return ['ok' => false, 'msg' => "El empleado {$idEmp} no existe"];

        $antes = $db->query('SELECT id FROM empleados WHERE id_biometrico = ? AND id <> ?', [$idBio, $idEmp])->getRowArray();
        $db->query('UPDATE empleados SET id_biometrico = NULL WHERE id_biometrico = ?', [$idBio]);   // el número pasa a un solo empleado
        $db->query('UPDATE empleados SET id_biometrico = ? WHERE id = ?', [$idBio, $idEmp]);
        return ['ok' => true, 'msg' => 'Asignado', 'reasignado_de' => $antes ? (int)$antes['id'] : null];
    }

    public function asignar()
    {
        $u = $this->admin();
        if (!$u) return $this->err('Sin permiso', 403);
        $in = $this->request->getJSON(true) ?? [];
        $idBio = (string)($in['id_biometrico'] ?? '');
        $idEmp = isset($in['id_empleado']) && $in['id_empleado'] !== null && $in['id_empleado'] !== '' ? (int)$in['id_empleado'] : null;

        $db = \Config\Database::connect();
        try { $r = $this->asignarUno($db, $idBio, $idEmp); }
        catch (\Throwable $e) { log_message('error', 'Asignar biométrico: ' . $e->getMessage()); return $this->err('Falta correr migracion_lector_biometrico.sql', 500); }
        if (!$r['ok']) return $this->err($r['msg'], 422);

        AuditLibrary::log((int)($u->id ?? 0), 'LECTOR_ASIGNAR', 'empleados', (string)($idEmp ?? 0), "Biométrico {$idBio} → " . ($idEmp ?? 'sin asignar'));
        return $this->respond(['status' => 'ok'] + $r);
    }

    public function asignarMasivo()
    {
        $u = $this->admin();
        if (!$u) return $this->err('Sin permiso', 403);
        $filas = ($this->request->getJSON(true)['filas'] ?? null);
        if (!is_array($filas) || !$filas) return $this->err('No hay filas para asignar', 422);
        if (count($filas) > 5000) return $this->err('Máximo 5,000 filas por carga', 422);

        $db = \Config\Database::connect();
        $ok = 0; $errores = [];
        $vistosBio = []; $vistosEmp = [];
        foreach ($filas as $i => $f) {
            $n    = $i + 1;
            $idE  = (int)preg_replace('/\D/', '', (string)($f['id_empleado'] ?? ''));
            $idB  = trim((string)($f['id_biometrico'] ?? ''));
            if ($idE <= 0 || $idB === '') { $errores[] = "Fila {$n}: falta el empleado o el número del biométrico"; continue; }
            if (isset($vistosBio[$idB]))  { $errores[] = "Fila {$n}: el biométrico {$idB} ya venía en la fila {$vistosBio[$idB]}"; continue; }
            if (isset($vistosEmp[$idE]))  { $errores[] = "Fila {$n}: el empleado {$idE} ya venía en la fila {$vistosEmp[$idE]}"; continue; }
            try { $r = $this->asignarUno($db, $idB, $idE); } catch (\Throwable $e) { return $this->err('Falta correr migracion_lector_biometrico.sql', 500); }
            if ($r['ok']) { $ok++; $vistosBio[$idB] = $n; $vistosEmp[$idE] = $n; }
            else $errores[] = "Fila {$n}: {$r['msg']}";
        }
        AuditLibrary::log((int)($u->id ?? 0), 'LECTOR_ASIGNAR_MASIVO', 'empleados', '0', "Asignó {$ok} biométricos por archivo");
        return $this->respond(['status' => 'ok', 'data' => ['asignados' => $ok, 'errores' => array_slice($errores, 0, 100), 'total_errores' => count($errores)]]);
    }

    /** Buscador de empleados activos para asignarles su número de biométrico. */
    public function empleados()
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        $q = trim((string)($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) return $this->respond(['status' => 'ok', 'data' => []]);
        $like = '%' . $q . '%';
        try {
            $rows = \Config\Database::connect()->query("
                SELECT e.id, CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS nombre, e.curp, e.id_biometrico
                FROM empleados e
                WHERE e.estatus = 1
                  AND (CONCAT_WS(' ', e.nombre, e.paterno, e.materno) LIKE ? OR e.curp LIKE ? OR LPAD(e.id, 6, '0') LIKE ?)
                ORDER BY e.paterno, e.nombre LIMIT 15
            ", [$like, $like, $like])->getResultArray();
        } catch (\Throwable $e) {
            $rows = [];
        }
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    /** Número de biométrico asignado a un empleado (para su ficha). */
    public function deEmpleado($id = null)
    {
        if (!$this->admin()) return $this->err('Sin permiso', 403);
        try {
            $r = \Config\Database::connect()->query('SELECT id_biometrico FROM empleados WHERE id = ?', [(int)$id])->getRowArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'ok', 'data' => ['id_biometrico' => null, 'disponible' => false]]);   // aún sin migración
        }
        return $this->respond(['status' => 'ok', 'data' => ['id_biometrico' => $r['id_biometrico'] ?? null, 'disponible' => true]]);
    }
}
