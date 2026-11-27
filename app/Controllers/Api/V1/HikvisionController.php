<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Receptor de eventos de Hikvision (Device Gateway / terminales de control de acceso).
 *
 *   POST|GET /api/v1/webhooks/hikvision?token=HIKVISION_TOKEN
 *
 * El equipo/gateway no permite encabezados personalizados, por eso la clave va en la URL (HIKVISION_TOKEN en .env).
 * Guarda SIEMPRE el evento crudo en hikvision_eventos (para ver el formato real) y, si trae número de persona
 * y fecha/hora, lo registra en biometrico_logs ligado al empleado cuyo id_biometrico coincida.
 * Responde 200 siempre que la clave sea válida, para que el equipo no reintente sin parar.
 */
class HikvisionController extends ResourceController
{
    protected $format = 'json';

    public function recibir()
    {
        $tokenCfg = (string)env('HIKVISION_TOKEN', '');
        $tok = (string)($this->request->getGet('token') ?? '');
        if ($tokenCfg === '' || $tok === '' || !hash_equals($tokenCfg, $tok)) {
            return $this->respond(['status' => 'error', 'message' => 'Token inválido'], 401);
        }
        if (strtolower((string)$this->request->getMethod()) === 'get') return $this->respond(['status' => 'ok', 'message' => 'Receptor activo']);

        // Cuerpo directo (JSON/XML). En multipart PHP lo deja vacío y reparte las partes en $_POST / $_FILES.
        $raw = (string)@file_get_contents('php://input');
        foreach ($_POST as $k => $v) {
            $raw .= "\n" . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v);
        }
        foreach ($_FILES as $k => $fl) {
            // Solo partes de texto (event_log en JSON/XML); las imágenes se ignoran
            $tipo = (string)($fl['type'] ?? '');
            $tmp  = $fl['tmp_name'] ?? '';
            if (is_string($tmp) && $tmp !== '' && is_file($tmp) && (int)($fl['size'] ?? 0) < 200000
                && (stripos($tipo, 'json') !== false || stripos($tipo, 'xml') !== false || stripos($tipo, 'text') !== false || stripos((string)$k, 'event') !== false)) {
                $raw .= "\n" . (string)@file_get_contents($tmp);
            }
        }
        $ct  = $this->request->getHeaderLine('Content-Type');
        // Se quitan las imágenes binarias (multipart): solo interesan los textos/JSON
        $texto = mb_substr(preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4\x80-\xBF]+/', ' ', $raw), 0, 60000);

        $f = $this->extraer($texto);
        // Sin número de persona no es una marcación (intentos no reconocidos, pasos intermedios, puerta, etc.):
        // se responde 200 sin tocar la base para no saturar el servidor.
        if ($f['empleado_no'] === '') return $this->respond(['status' => 'ok']);
        $db = \Config\Database::connect();
        $idEmp = null; $insertado = 0;

        // 1) Equipo (por MAC): se da de alta solo la primera vez y se actualiza su último evento
        $idEquipo = null;
        if ($f['mac'] !== '') {
            try {
                $q = $db->query('SELECT id FROM biometricos_equipos WHERE mac = ? LIMIT 1', [$f['mac']])->getRowArray();
                if ($q) {
                    $idEquipo = (int)$q['id'];
                    $db->query('UPDATE biometricos_equipos SET ultimo_evento_at = NOW(), eventos_total = eventos_total + 1, ip = COALESCE(NULLIF(?, \'\'), ip) WHERE id = ?', [mb_substr($f['ip_equipo'], 0, 45), $idEquipo]);
                } else {
                    $db->query('INSERT INTO biometricos_equipos (nombre, mac, ip, auto_registrado, ultimo_evento_at, eventos_total) VALUES (?,?,?,1,NOW(),1)',
                        ['Equipo ' . $f['mac'], $f['mac'], mb_substr($f['ip_equipo'], 0, 45)]);
                    $idEquipo = (int)$db->insertID();
                }
            } catch (\Throwable $e) { log_message('error', 'Hikvision equipo: ' . $e->getMessage()); }
        }

        // 2) Marcación ligada al empleado (por id_biometrico)
        try {
            if ($f['fecha'] !== null) {
                $e = $db->query('SELECT id FROM empleados WHERE id_biometrico = ? LIMIT 1', [$f['empleado_no']])->getRowArray();
                if ($e) {
                    $idEmp = (int)$e['id'];
                    $db->query(
                        'INSERT IGNORE INTO biometrico_logs (id_biometrico, id_empleado, nombre, fecha, hora, tipo, metodo, resultado, id_lote, created_at)
                         VALUES (?,?,?,?,?,?,?,?,0,NOW())',
                        [$f['empleado_no'], $idEmp, mb_substr($f['nombre'], 0, 120), $f['fecha'], $f['hora'], 'evento', mb_substr($f['metodo'], 0, 30), 'ok']
                    );
                    $insertado = $db->affectedRows() > 0 ? 1 : 0;
                }
            }
        } catch (\Throwable $e) { log_message('error', 'Hikvision log: ' . $e->getMessage()); }

        // 3) Bitácora del evento (con respaldo si aún no se corre la migración de equipos)
        $fila = [
            'recibido_at' => date('Y-m-d H:i:s'), 'ip' => $this->request->getIPAddress(), 'content_type' => mb_substr($ct, 0, 120),
            'empleado_no' => mb_substr($f['empleado_no'], 0, 40), 'nombre' => mb_substr($f['nombre'], 0, 120),
            'fecha_evento' => $f['fecha'] ? $f['fecha'] . ' ' . $f['hora'] : null, 'dispositivo' => mb_substr($f['dispositivo'], 0, 80),
            'major' => $f['major'], 'minor' => $f['minor'], 'metodo' => mb_substr($f['metodo'], 0, 30),
            'id_empleado' => $idEmp, 'registrado' => $insertado, 'crudo' => mb_substr($texto, 0, 4000),
        ];
        try {
            $db->table('hikvision_eventos')->insert($fila + ['mac' => $f['mac'] !== '' ? $f['mac'] : null, 'id_equipo' => $idEquipo]);
        } catch (\Throwable $e) {
            try { $db->table('hikvision_eventos')->insert($fila); }
            catch (\Throwable $e2) { log_message('error', 'Hikvision: ' . $e2->getMessage()); }
        }
        return $this->respond(['status' => 'ok']);
    }

    /** Saca los campos útiles del texto (sirve para JSON y para multipart con JSON dentro). */
    private function extraer(string $t): array
    {
        $g = function (string $k) use ($t) {
            if (preg_match('/"' . $k . '"\s*:\s*"([^"]*)"/', $t, $m)) return $m[1];
            if (preg_match('/"' . $k . '"\s*:\s*(-?\d+)/', $t, $m)) return $m[1];
            return '';
        };
        $dt = $g('dateTime');
        $fecha = null; $hora = null;
        if ($dt !== '') {
            try {
                $d = new \DateTime($dt);
                $d->setTimezone(new \DateTimeZone('America/Mexico_City'));
                $fecha = $d->format('Y-m-d'); $hora = $d->format('H:i:s');
            } catch (\Throwable $e) { /* sin fecha válida */ }
        }
        $no = $g('employeeNoString') ?: $g('employeeNo');
        return [
            'empleado_no' => trim($no), 'nombre' => $g('name'), 'fecha' => $fecha, 'hora' => $hora,
            'dispositivo' => $g('deviceName') ?: $g('ipAddress'),
            'mac' => strtolower(trim($g('macAddress'))), 'ip_equipo' => $g('ipAddress'),
            'major' => $g('majorEventType') !== '' ? (int)$g('majorEventType') : null,
            'minor' => $g('subEventType') !== '' ? (int)$g('subEventType') : null,
            'metodo' => $g('currentVerifyMode'),
        ];
    }
}
