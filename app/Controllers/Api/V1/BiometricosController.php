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
