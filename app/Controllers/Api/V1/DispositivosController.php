<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Control de dispositivos (celulares/tablets con la app del kiosko).
 *
 *   Kiosko (público)  POST biometrico/dispositivo        el equipo se reporta (ping) y recibe si está bloqueado
 *   Panel (jwt:admin) GET   dispositivos                 lista + resumen
 *                     PATCH dispositivos/(id)            { nombre?, bloqueado? }
 *                     GET   dispositivos/alertas         antifraude y equipos sin sincronizar
 */
class DispositivosController extends ResourceController
{
    protected $format = 'json';

    /** Días sin contacto para considerar a un dispositivo "sin sincronizar". */
    private const DIAS_SIN_CONTACTO = 3;

    private function admin(): ?object
    {
        $u = $this->request->jwtUser ?? null;
        return ($u && (int)($u->nivel ?? 99) <= 2) ? $u : null;
    }

    private function versionPublicada(): int
    {
        $f = FCPATH . 'app/version.json';
        if (!is_file($f)) return 0;
        $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f)), true);
        return (int)($j['versionCode'] ?? 0);
    }

    /* ═══════════════ KIOSKO ═══════════════ */

    public function ping()
    {
        $in = $this->request->getJSON(true) ?: ($this->request->getPost() ?: []);
        $id = trim((string)($in['device_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $id)) {
            return $this->respond(['status' => 'error', 'message' => 'device_id inválido'], 400);
        }

        $sync = null;
        if (!empty($in['ultima_sync'])) {
            $t = strtotime((string)$in['ultima_sync']);
            if ($t) $sync = date('Y-m-d H:i:s', $t);
        }

        $db = \Config\Database::connect();
        try {
            $db->query(
                'INSERT INTO dispositivos
                    (device_id, modelo, version_name, version_code, ubicacion_id, empleados_locales, pendientes,
                     ultima_sync, ultimo_contacto, ultimo_operador, ip, created_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),?,?,NOW())
                 ON DUPLICATE KEY UPDATE
                    modelo = COALESCE(VALUES(modelo), modelo),
                    version_name = COALESCE(VALUES(version_name), version_name),
                    version_code = COALESCE(VALUES(version_code), version_code),
                    ubicacion_id = COALESCE(VALUES(ubicacion_id), ubicacion_id),
                    empleados_locales = VALUES(empleados_locales),
                    pendientes = VALUES(pendientes),
                    ultima_sync = COALESCE(VALUES(ultima_sync), ultima_sync),
                    ultimo_contacto = NOW(),
                    ultimo_operador = COALESCE(VALUES(ultimo_operador), ultimo_operador),
                    ip = VALUES(ip)',
                [
                    $id,
                    isset($in['modelo']) ? mb_substr((string)$in['modelo'], 0, 120) : null,
                    isset($in['version_name']) ? mb_substr((string)$in['version_name'], 0, 20) : null,
                    isset($in['version_code']) ? (int)$in['version_code'] : null,
                    !empty($in['ubicacion_id']) ? (int)$in['ubicacion_id'] : null,
                    (int)($in['empleados_locales'] ?? 0),
                    (int)($in['pendientes'] ?? 0),
                    $sync,
                    !empty($in['operador_id']) ? (int)$in['operador_id'] : null,
                    $this->request->getIPAddress(),
                ]
            );
            $row = $db->query('SELECT bloqueado FROM dispositivos WHERE device_id = ?', [$id])->getRowArray();
        } catch (\Throwable $e) {
            // Sin la migración no se rompe la app: simplemente no se registra
            return $this->respond(['status' => 'ok', 'bloqueado' => false]);
        }

        // Métricas de uso de esta versión (cantidades nuevas desde el último reporte)
        $m = $in['metricas'] ?? null;
        if (is_array($m) && !empty($in['version_code'])) {
            try {
                $db->query(
                    'INSERT INTO dispositivo_metricas (device_id, version_code, registros, fallos, ms_total, ms_muestras, updated_at)
                     VALUES (?,?,?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE registros = registros + VALUES(registros), fallos = fallos + VALUES(fallos),
                        ms_total = ms_total + VALUES(ms_total), ms_muestras = ms_muestras + VALUES(ms_muestras), updated_at = NOW()',
                    [$id, (int)$in['version_code'],
                     max(0, (int)($m['registros'] ?? 0)), max(0, (int)($m['fallos'] ?? 0)),
                     max(0, (int)($m['ms_total'] ?? 0)), max(0, (int)($m['ms_muestras'] ?? 0))]
                );
            } catch (\Throwable $e) { /* tabla aún no existe */ }
        }

        return $this->respond([
            'status'            => 'ok',
            'bloqueado'         => (int)($row['bloqueado'] ?? 0) === 1,
            'version_publicada' => $this->versionPublicada(),
            // Configuración que el kiosko aplica sin necesidad de un APK nuevo
            'config'            => [
                'reenrolamiento' => RostroAutoController::activo() || RostroAutoController::multiActivo(),
                'evidencia'      => filter_var(env('EVIDENCIA_ACTIVA', false), FILTER_VALIDATE_BOOLEAN),
            ],
        ]);
    }

    /* ═══════════════ PANEL ═══════════════ */

    public function index()
    {
        if (!$this->admin()) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        try {
            $rows = \Config\Database::connect()->query("
                SELECT d.*, s.servicio AS ubicacion,
                       TRIM(CONCAT_WS(' ', e.nombre, e.paterno)) AS operador,
                       TIMESTAMPDIFF(MINUTE, d.ultimo_contacto, NOW()) AS min_sin_contacto
                FROM dispositivos d
                LEFT JOIN servicios s  ON s.id = d.ubicacion_id
                LEFT JOIN empleados e  ON e.id = d.ultimo_operador
                ORDER BY d.ultimo_contacto DESC
                LIMIT 500
            ")->getResultArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        $publicada = $this->versionPublicada();
        $res = ['total' => count($rows), 'en_linea' => 0, 'sin_sincronizar' => 0, 'desactualizados' => 0, 'bloqueados' => 0, 'pendientes' => 0];
        foreach ($rows as &$r) {
            $min = $r['min_sin_contacto'] === null ? null : (int)$r['min_sin_contacto'];
            $r['en_linea']        = $min !== null && $min <= 15;
            $r['sin_sincronizar'] = $min === null || $min >= self::DIAS_SIN_CONTACTO * 1440;
            $r['desactualizado']  = $publicada > 0 && (int)$r['version_code'] > 0 && (int)$r['version_code'] < $publicada;
            $res['en_linea']        += $r['en_linea'] ? 1 : 0;
            $res['sin_sincronizar'] += $r['sin_sincronizar'] ? 1 : 0;
            $res['desactualizados'] += $r['desactualizado'] ? 1 : 0;
            $res['bloqueados']      += (int)$r['bloqueado'] === 1 ? 1 : 0;
            $res['pendientes']      += (int)$r['pendientes'];
        }
        return $this->respond(['status' => 'ok', 'data' => $rows, 'resumen' => $res, 'version_publicada' => $publicada]);
    }

    public function actualizar($id = null)
    {
        $u = $this->admin();
        if (!$u) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);

        $in  = $this->request->getJSON(true) ?? [];
        $set = []; $par = [];
        if (array_key_exists('nombre', $in))    { $set[] = 'nombre = ?';    $par[] = mb_substr(trim((string)$in['nombre']), 0, 120) ?: null; }
        if (array_key_exists('bloqueado', $in)) { $set[] = 'bloqueado = ?'; $par[] = $in['bloqueado'] ? 1 : 0; }
        if (!$set) return $this->respond(['status' => 'error', 'message' => 'Nada que actualizar'], 422);

        $par[] = (int)$id;
        \Config\Database::connect()->query('UPDATE dispositivos SET ' . implode(', ', $set) . ' WHERE id = ?', $par);
        AuditLibrary::log((int)($u->id ?? 0), 'DISPOSITIVO_EDITAR', 'dispositivos', (string)(int)$id, json_encode($in));
        return $this->respond(['status' => 'ok']);
    }

    /** Versiones instaladas + métricas (para comparar una versión piloto contra la estable). */
    public function versiones()
    {
        if (!$this->admin()) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        $db = \Config\Database::connect();
        try {
            $rows = $db->query("
                SELECT d.version_code, MAX(d.version_name) AS version_name,
                       COUNT(*) AS equipos,
                       SUM(d.ultimo_contacto >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS activos_hoy,
                       SUM(d.pendientes) AS pendientes
                FROM dispositivos d
                WHERE d.version_code IS NOT NULL
                GROUP BY d.version_code
                ORDER BY d.version_code DESC
            ")->getResultArray();

            $met = [];
            try {
                foreach ($db->query('SELECT version_code, SUM(registros) AS registros, SUM(fallos) AS fallos,
                                            SUM(ms_total) AS ms_total, SUM(ms_muestras) AS ms_muestras
                                     FROM dispositivo_metricas GROUP BY version_code')->getResultArray() as $m) {
                    $met[(int)$m['version_code']] = $m;
                }
            } catch (\Throwable $e) { /* sin tabla de métricas */ }
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        $json    = AppVersionController::leerJson() ?: [];
        $piloto  = is_array($json['piloto'] ?? null) ? $json['piloto'] : null;

        foreach ($rows as &$r) {
            $m   = $met[(int)$r['version_code']] ?? null;
            $reg = (int)($m['registros'] ?? 0); $fal = (int)($m['fallos'] ?? 0);
            $r['registros']   = $reg;
            $r['fallos']      = $fal;
            $r['pct_fallos']  = ($reg + $fal) > 0 ? round($fal * 100 / ($reg + $fal), 1) : null;
            $r['ms_promedio'] = !empty($m['ms_muestras']) ? (int)round($m['ms_total'] / $m['ms_muestras']) : null;
            $r['rol']         = (int)$r['version_code'] === (int)($json['versionCode'] ?? -1) ? 'estable'
                              : ($piloto && (int)$r['version_code'] === (int)($piloto['versionCode'] ?? -2) ? 'piloto' : '');
        }

        return $this->respond(['status' => 'ok', 'data' => $rows, 'estable' => [
            'versionCode' => (int)($json['versionCode'] ?? 0), 'versionName' => (string)($json['versionName'] ?? ''),
        ], 'piloto' => $piloto ? [
            'versionCode' => (int)($piloto['versionCode'] ?? 0), 'versionName' => (string)($piloto['versionName'] ?? ''),
            'porcentaje'  => (int)($piloto['porcentaje'] ?? 0), 'dispositivos' => count((array)($piloto['dispositivos'] ?? [])),
        ] : null]);
    }

    /** Alertas: sin sincronizar, un equipo con muchos empleados distintos y registros fuera de zona. */
    public function alertas()
    {
        if (!$this->admin()) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);

        $db      = \Config\Database::connect();
        $umbral  = max(2, (int)($this->request->getGet('umbral') ?? 8));
        $alertas = [];

        try {
            // 1) Dispositivos sin contacto
            foreach ($db->query("
                SELECT id, COALESCE(nombre, modelo, device_id) AS equipo, ultimo_contacto, pendientes
                FROM dispositivos
                WHERE ultimo_contacto IS NULL OR ultimo_contacto < DATE_SUB(NOW(), INTERVAL " . self::DIAS_SIN_CONTACTO . " DAY)
                ORDER BY ultimo_contacto ASC LIMIT 50
            ")->getResultArray() as $d) {
                $alertas[] = [
                    'tipo' => 'sin_sincronizar', 'severidad' => 'media',
                    'titulo' => 'Dispositivo sin sincronizar',
                    'detalle' => "{$d['equipo']} no se reporta desde " . ($d['ultimo_contacto'] ?: 'nunca') . ($d['pendientes'] ? " · {$d['pendientes']} registros pendientes" : ''),
                    'fecha' => $d['ultimo_contacto'], 'id_dispositivo' => (int)$d['id'],
                ];
            }
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        if ($db->fieldExists('device_id', 'asistencias')) {
            // 2) Un mismo equipo registrando a demasiados empleados distintos el mismo día
            foreach ($db->query("
                SELECT a.device_id, a.fecha, COUNT(DISTINCT a.id_empleado) AS empleados, COUNT(*) AS registros,
                       COALESCE(d.nombre, d.modelo, a.device_id) AS equipo, d.id AS id_dispositivo
                FROM asistencias a
                LEFT JOIN dispositivos d ON d.device_id = a.device_id
                WHERE a.device_id IS NOT NULL AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                GROUP BY a.device_id, a.fecha, d.nombre, d.modelo, d.id
                HAVING empleados >= ?
                ORDER BY a.fecha DESC, empleados DESC LIMIT 50
            ", [$umbral])->getResultArray() as $x) {
                $alertas[] = [
                    'tipo' => 'muchos_empleados', 'severidad' => 'alta',
                    'titulo' => 'Un equipo registró a muchos empleados',
                    'detalle' => "{$x['equipo']} registró a {$x['empleados']} empleados distintos ({$x['registros']} registros)",
                    'fecha' => $x['fecha'], 'id_dispositivo' => $x['id_dispositivo'] ? (int)$x['id_dispositivo'] : null,
                ];
            }

            // 3) Registros del kiosko sin un servicio cercano (GPS fuera de zona)
            foreach ($db->query("
                SELECT a.id, a.fecha, a.hora, a.latitud, a.longitud,
                       CONCAT_WS(' ', TRIM(e.nombre), TRIM(e.paterno)) AS empleado,
                       COALESCE(d.nombre, d.modelo, a.device_id) AS equipo, d.id AS id_dispositivo
                FROM asistencias a
                LEFT JOIN empleados e ON e.id = a.id_empleado
                LEFT JOIN dispositivos d ON d.device_id = a.device_id
                WHERE a.device_id IS NOT NULL
                  AND (a.id_ubicacion IS NULL OR a.id_ubicacion = 0)
                  AND a.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                ORDER BY a.fecha DESC, a.hora DESC LIMIT 50
            ")->getResultArray() as $x) {
                $alertas[] = [
                    'tipo' => 'fuera_de_zona', 'severidad' => 'alta',
                    'titulo' => 'Registro fuera de zona',
                    'detalle' => "{$x['empleado']} registró a las {$x['hora']} desde " . ($x['equipo'] ?: 'un equipo') . " sin ningún servicio cerca",
                    'fecha' => $x['fecha'], 'id_dispositivo' => $x['id_dispositivo'] ? (int)$x['id_dispositivo'] : null,
                    'latitud' => $x['latitud'], 'longitud' => $x['longitud'],
                ];
            }
        }

        usort($alertas, fn($a, $b) => strcmp((string)$b['fecha'], (string)$a['fecha']));
        return $this->respond(['status' => 'ok', 'data' => $alertas, 'umbral' => $umbral]);
    }
}
