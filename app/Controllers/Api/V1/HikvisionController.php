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

        $raw = (string)$this->request->getBody();
        $ct  = $this->request->getHeaderLine('Content-Type');
        // Se quitan las imágenes binarias (multipart): solo interesan los textos/JSON
        $texto = mb_substr(preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4\x80-\xBF]+/', ' ', $raw), 0, 60000);

        $f = $this->extraer($texto);
        $db = \Config\Database::connect();
        $idEmp = null; $insertado = 0;

        try {
            if ($f['empleado_no'] !== '' && $f['fecha'] !== null) {
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
            $db->table('hikvision_eventos')->insert([
                'recibido_at' => date('Y-m-d H:i:s'), 'ip' => $this->request->getIPAddress(), 'content_type' => mb_substr($ct, 0, 120),
                'empleado_no' => mb_substr($f['empleado_no'], 0, 40), 'nombre' => mb_substr($f['nombre'], 0, 120),
                'fecha_evento' => $f['fecha'] ? $f['fecha'] . ' ' . $f['hora'] : null, 'dispositivo' => mb_substr($f['dispositivo'], 0, 80),
                'major' => $f['major'], 'minor' => $f['minor'], 'metodo' => mb_substr($f['metodo'], 0, 30),
                'id_empleado' => $idEmp, 'registrado' => $insertado, 'crudo' => mb_substr($texto, 0, 20000),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Hikvision: ' . $e->getMessage());
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
            'major' => $g('majorEventType') !== '' ? (int)$g('majorEventType') : null,
            'minor' => $g('subEventType') !== '' ? (int)$g('subEventType') : null,
            'metodo' => $g('currentVerifyMode'),
        ];
    }
}
