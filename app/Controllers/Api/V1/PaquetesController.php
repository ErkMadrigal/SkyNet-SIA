<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Paquetes de empleados: un administrador arma un paquete (servicios y/o empleados
 * sueltos) y obtiene un código corto. Quien tenga el código lo importa en la app y
 * ésta descarga los empleados (con su rostro enrolado) desde el servidor.
 *
 *   Panel (jwt:admin)  /paquetes
 *     GET    /                 lista
 *     POST   /                 crea { nombre, servicios[], empleados[], dias_vigencia }
 *     DELETE (id)              revoca
 *     GET    servicios         catálogo de servicios con # de empleados
 *     GET    empleados?q=      búsqueda de empleados para agregar sueltos
 *
 *   Kiosko (público)  GET biometrico/paquete/(codigo)
 */
class PaquetesController extends ResourceController
{
    protected $format = 'json';

    private function admin(): ?object
    {
        $u = $this->request->jwtUser ?? null;
        return ($u && (int)($u->nivel ?? 99) <= 2) ? $u : null;
    }

    private function sinAcceso()
    {
        return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
    }

    /** Ids enteros positivos, sin repetir. */
    private function ids($v): array
    {
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v)) return [];
        return array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0)));
    }

    /** Empleados activos con acceso biométrico de esos servicios + los sueltos (mismo formato que /biometrico/sync). */
    private function resolver(array $servicios, array $empleados): array
    {
        if (!$servicios && !$empleados) return [];
        $db    = \Config\Database::connect();
        $or    = [];
        $param = [];
        if ($servicios) { $or[] = 'e.id_ubicacion_principal IN (' . implode(',', array_fill(0, count($servicios), '?')) . ')'; $param = array_merge($param, $servicios); }
        if ($empleados) { $or[] = 'e.id IN (' . implode(',', array_fill(0, count($empleados), '?')) . ')'; $param = array_merge($param, $empleados); }

        $filas = $db->query("
            SELECT
                e.id,
                CONCAT(e.nombre, ' ', e.paterno, ' ', e.materno) AS nombreCompleto,
                e.curp, e.rfc, e.fotos, e.id_turno, e.acceso_biometrico,
                e.id_ubicacion_principal, e.rostro_enrolado, e.permitir_salida_anticipada,
                s.servicio AS ubicacion_nombre,
                mp.valor   AS puesto,
                ere.descriptor AS descriptor_servidor
            FROM empleados e
            LEFT JOIN servicios s ON e.id_ubicacion_principal = s.id
            LEFT JOIN multicatalogo mp ON e.id_puesto = mp.id
            LEFT JOIN empleado_rostro_embeddings ere ON ere.id_empleado = e.id AND ere.is_active = 1
            WHERE e.estatus = 1 AND e.acceso_biometrico = 1
              AND (" . implode(' OR ', $or) . ")
            ORDER BY e.paterno, e.nombre
        ", $param)->getResultArray();

        // Vectores extra aprendidos (solo si ROSTRO_MULTI está activo)
        $ex = RostroAutoController::extrasPara(array_column($filas, 'id'));
        foreach ($filas as &$f) { $f['descriptores_extra'] = $ex[(int)$f['id']] ?? []; }
        return $filas;
    }

    private function generarCodigo(): string
    {
        // Sin 0/O/1/I/L para que se pueda dictar o leer sin confundirse
        $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $db = \Config\Database::connect();
        do {
            $c = '';
            for ($i = 0; $i < 8; $i++) $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            $existe = $db->query('SELECT 1 FROM paquetes_empleados WHERE codigo = ?', [$c])->getRowArray();
        } while ($existe);
        return $c;
    }

    /* ═══════════════ PANEL ═══════════════ */

    public function index()
    {
        if (!$this->admin()) return $this->sinAcceso();
        try {
            $db   = \Config\Database::connect();
            $rows = $db->query("
                SELECT p.*, TRIM(CONCAT_WS(' ', u.nombre, u.paterno)) AS creador
                FROM paquetes_empleados p
                LEFT JOIN usuario u ON u.id = p.created_by
                ORDER BY p.id DESC LIMIT 200
            ")->getResultArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as &$r) {
            $srv = $this->ids($r['servicios']);
            $emp = $this->ids($r['empleados']);
            $r['servicios']       = $srv;
            $r['empleados']       = $emp;
            $r['total_empleados'] = count($this->resolver($srv, $emp));
            $r['vencido']         = !empty($r['expira_at']) && $r['expira_at'] < $now;
            $r['vigente']         = (int)$r['activo'] === 1 && !$r['vencido'];
        }
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function crear()
    {
        $u = $this->admin();
        if (!$u) return $this->sinAcceso();

        $in      = $this->request->getJSON(true) ?? [];
        $nombre  = trim((string)($in['nombre'] ?? ''));
        $srv     = $this->ids($in['servicios'] ?? []);
        $emp     = $this->ids($in['empleados'] ?? []);
        $dias    = (int)($in['dias_vigencia'] ?? 7);

        if ($nombre === '')          return $this->respond(['status' => 'error', 'message' => 'Ponle un nombre al paquete'], 422);
        if (!$srv && !$emp)          return $this->respond(['status' => 'error', 'message' => 'Elige al menos un servicio o un empleado'], 422);
        if (!$this->resolver($srv, $emp)) return $this->respond(['status' => 'error', 'message' => 'Esa selección no tiene empleados activos con acceso biométrico'], 422);

        $codigo = $this->generarCodigo();
        $db     = \Config\Database::connect();
        try {
            $db->query(
                'INSERT INTO paquetes_empleados (codigo, nombre, servicios, empleados, expira_at, activo, created_by, created_at)
                 VALUES (?,?,?,?,?,1,?,NOW())',
                [
                    $codigo, mb_substr($nombre, 0, 120), json_encode($srv), json_encode($emp),
                    $dias > 0 ? date('Y-m-d H:i:s', strtotime("+{$dias} days")) : null,
                    (int)($u->id ?? 0),
                ]
            );
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        AuditLibrary::log((int)($u->id ?? 0), 'PAQUETE_CREAR', 'paquetes_empleados', (string)$db->insertID(), "Paquete {$codigo}: {$nombre}");
        return $this->respondCreated(['status' => 'ok', 'data' => ['id' => $db->insertID(), 'codigo' => $codigo]]);
    }

    public function revocar($id = null)
    {
        $u = $this->admin();
        if (!$u) return $this->sinAcceso();
        \Config\Database::connect()->query('UPDATE paquetes_empleados SET activo = 0 WHERE id = ?', [(int)$id]);
        AuditLibrary::log((int)($u->id ?? 0), 'PAQUETE_REVOCAR', 'paquetes_empleados', (string)(int)$id, 'Paquete revocado');
        return $this->respond(['status' => 'ok']);
    }

    public function servicios()
    {
        if (!$this->admin()) return $this->sinAcceso();
        $rows = \Config\Database::connect()->query("
            SELECT s.id, s.servicio,
                   (SELECT COUNT(*) FROM empleados e
                    WHERE e.id_ubicacion_principal = s.id AND e.estatus = 1 AND e.acceso_biometrico = 1) AS empleados
            FROM servicios s
            ORDER BY s.servicio
        ")->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function empleados()
    {
        if (!$this->admin()) return $this->sinAcceso();
        $q = trim((string)($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) return $this->respond(['status' => 'ok', 'data' => []]);
        $like = '%' . $q . '%';
        $rows = \Config\Database::connect()->query("
            SELECT e.id, CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno), TRIM(e.materno)) AS nombre,
                   e.rostro_enrolado, s.servicio AS ubicacion
            FROM empleados e
            LEFT JOIN servicios s ON s.id = e.id_ubicacion_principal
            WHERE e.estatus = 1 AND e.acceso_biometrico = 1
              AND (CONCAT_WS(' ', e.nombre, e.paterno, e.materno) LIKE ? OR e.curp LIKE ? OR LPAD(e.id, 6, '0') LIKE ?)
            ORDER BY e.paterno, e.nombre LIMIT 20
        ", [$like, $like, $like])->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    /* ═══════════════ KIOSKO (público) ═══════════════ */

    public function descargar($codigo = null)
    {
        $codigo = strtoupper(trim((string)$codigo));
        if (!preg_match('/^[A-Z0-9]{6,12}$/', $codigo)) {
            return $this->respond(['status' => 'error', 'message' => 'Código inválido'], 400);
        }

        $db = \Config\Database::connect();
        try {
            $p = $db->query('SELECT * FROM paquetes_empleados WHERE codigo = ?', [$codigo])->getRowArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Servicio no disponible'], 500);
        }

        // Mismo mensaje para inexistente/revocado/vencido: no revela qué códigos existen
        if (!$p || (int)$p['activo'] !== 1 || (!empty($p['expira_at']) && $p['expira_at'] < date('Y-m-d H:i:s'))) {
            return $this->respond(['status' => 'error', 'message' => 'El código no existe o ya venció'], 404);
        }

        $empleados = $this->resolver($this->ids($p['servicios']), $this->ids($p['empleados']));
        $db->query('UPDATE paquetes_empleados SET usos = usos + 1, ultimo_uso = NOW() WHERE id = ?', [(int)$p['id']]);

        return $this->respond([
            'status'    => 'ok',
            'nombre'    => $p['nombre'],
            'total'     => count($empleados),
            'empleados' => $empleados,
        ]);
    }
}
