<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;
use App\Libraries\PermisoLibrary;

/**
 * Enrolamiento facial desde el panel (TensorFlow.js / face-api en el navegador).
 *
 * Panel (jwt):
 *   POST empleados/(id)/enrolar            -> enrolar desde el panel (2 descriptores)
 *   POST empleados/(id)/enrolamiento-link  -> genera link con token (24 h)
 *
 * Público (con token del link):
 *   GET  enrolamiento-publico/(token)      -> datos mínimos del empleado
 *   POST enrolamiento-publico/(token)      -> enrolar (2 descriptores)
 *
 * Guarda en empleado_rostro_embeddings igual que BiometricoController::enrolar,
 * así el kiosko usa el mismo descriptor.
 */
class EnrolamientoController extends ResourceController
{
    protected $format = 'json';

    private const UMBRAL    = 0.376;   // mismo que tensorflow.service.ts
    private const HORAS_TTL = 24;

    /* ───────────── Panel (jwt) ───────────── */

    public function enrolarPanel($id = null): mixed
    {
        $user = $this->request->jwtUser;
        if ((int)($user->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Solo administradores pueden enrolar rostros'], 403);
        }
        return $this->guardar((int)$id, (int)$user->id);
    }

    public function generarLink($id = null): mixed
    {
        $user = $this->request->jwtUser;
        if ((int)($user->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Solo administradores pueden generar links de enrolamiento'], 403);
        }

        $db = \Config\Database::connect();
        $emp = $db->table('empleados')->select('id')->where('id', (int)$id)->where('estatus', 1)->get()->getRowArray();
        if (!$emp) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        // Invalida links anteriores sin usar de este empleado
        $db->table('enrolamiento_tokens')
            ->where('id_empleado', (int)$id)->where('used_at', null)
            ->update(['expires_at' => date('Y-m-d H:i:s')]);

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::HORAS_TTL * 3600);
        $db->table('enrolamiento_tokens')->insert([
            'id_empleado' => (int)$id,
            'token'       => $token,
            'expires_at'  => $expires,
            'created_by'  => (int)$user->id,
        ]);

        AuditLibrary::log((int)$user->id, 'LINK_ENROLAMIENTO', 'empleados', (string)$id, 'Generó link de enrolamiento facial');

        return $this->respond(['status' => 'ok', 'data' => ['token' => $token, 'expires_at' => $expires]]);
    }

    /* ───────────── Público (token) ───────────── */

    public function info($token = null): mixed
    {
        $row = $this->tokenValido((string)$token);
        if (!$row) {
            return $this->respond(['status' => 'error', 'message' => 'El link no es válido o ya venció. Pide uno nuevo.'], 410);
        }
        $db  = \Config\Database::connect();
        $emp = $db->query("SELECT TRIM(CONCAT_WS(' ', nombre, paterno, materno)) AS nombre FROM empleados WHERE id = ?", [(int)$row['id_empleado']])->getRowArray();
        return $this->respond(['status' => 'ok', 'data' => ['nombre' => $emp['nombre'] ?? '', 'expires_at' => $row['expires_at']]]);
    }

    public function enrolarPublico($token = null): mixed
    {
        $row = $this->tokenValido((string)$token);
        if (!$row) {
            return $this->respond(['status' => 'error', 'message' => 'El link no es válido o ya venció. Pide uno nuevo.'], 410);
        }

        $res = $this->guardar((int)$row['id_empleado'], null);

        // Marca el token como usado solo si se guardó bien
        if ($res->getStatusCode() === 200) {
            \Config\Database::connect()->table('enrolamiento_tokens')
                ->where('id', (int)$row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);
        }
        return $res;
    }

    /* ───────────── Núcleo ───────────── */

    private function tokenValido(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $db = \Config\Database::connect();
        return $db->query(
            'SELECT * FROM enrolamiento_tokens WHERE token = ? AND used_at IS NULL AND expires_at > NOW()',
            [$token]
        )->getRowArray() ?: null;
    }

    private function guardar(int $idEmpleado, ?int $idCapturista): mixed
    {
        if ($idEmpleado <= 0) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado requerido'], 400);
        }

        $g = $this->request->getVar('descriptor_guardar');
        $v = $this->request->getVar('descriptor_verificar');
        $g = is_string($g) ? json_decode($g, true) : (is_object($g) ? (array)$g : $g);
        $v = is_string($v) ? json_decode($v, true) : (is_object($v) ? (array)$v : $v);

        if (!is_array($g) || !is_array($v) || count($g) !== 128 || count($v) !== 128) {
            return $this->respond(['status' => 'error', 'message' => 'Se requieren 2 descriptores de 128 valores'], 422);
        }

        $db = \Config\Database::connect();
        $emp = $db->table('empleados')->where('id', $idEmpleado)->where('estatus', 1)->get()->getRowArray();
        if (!$emp) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $dist = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $d = ((float)$g[$i]) - ((float)$v[$i]);
            $dist += $d * $d;
        }
        $dist = sqrt($dist);

        if ($dist > self::UMBRAL) {
            return $this->respond([
                'status'    => 'error',
                'message'   => 'Las dos capturas no coinciden lo suficiente, intenta de nuevo con mejor luz y sin moverte',
                'distancia' => round($dist, 6),
            ], 422);
        }

        $db->transStart();
        $db->table('empleado_rostro_embeddings')->where('id_empleado', $idEmpleado)->where('is_active', 1)->update(['is_active' => 0]);
        $db->table('empleado_rostro_embeddings')->insert([
            'id_empleado'            => $idEmpleado,
            'descriptor'             => json_encode(array_values($g)),
            'distancia_verificacion' => round($dist, 6),
            'is_active'              => 1,
            'id_capturista'          => $idCapturista,
            'created_at'             => date('Y-m-d H:i:s'),
        ]);
        $db->table('empleados')->where('id', $idEmpleado)->update(['rostro_enrolado' => 1]);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->respond(['status' => 'error', 'message' => 'Error al guardar el enrolamiento'], 500);
        }

        AuditLibrary::log($idCapturista ?? 0, 'ENROLAMIENTO_FACIAL', 'empleados', (string)$idEmpleado,
            'Enrolamiento facial (' . ($idCapturista ? 'panel' : 'link') . ') -- distancia ' . round($dist, 6));

        return $this->respond(['status' => 'ok', 'message' => 'Rostro enrolado correctamente', 'distancia' => round($dist, 6)]);
    }
}
