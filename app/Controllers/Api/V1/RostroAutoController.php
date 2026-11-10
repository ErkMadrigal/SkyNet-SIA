<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Reenrolamiento automático: el kiosko manda, tras un registro exitoso con buen porcentaje,
 * el descriptor que acaba de leer. El servidor junta varias capturas buenas y, cuando hay
 * suficientes y son consistentes, mezcla el rostro enrolado con ellas para que el sistema
 * se adapte (barba, peso, cabello) sin que nadie tenga que reenrolar.
 *
 * Salvaguardas: puntaje mínimo, 1 captura por empleado por día, mínimo 3 capturas
 * consistentes entre sí, no se aleja demasiado del enrolamiento ORIGINAL, y máximo
 * un ajuste cada 7 días. El historial de descriptores se conserva (is_active = 0).
 *
 * Variables de .env (opcionales):
 *   REENROL_AUTO=true|false     (por defecto true)
 *   REENROL_MIN_SCORE=0.72      puntaje mínimo de cada captura
 *
 * MODO MULTI-VECTOR (ROSTRO_MULTI=true, por defecto apagado): en lugar de mezclar todo en un
 * solo vector, se guardan hasta ROSTRO_MULTI_MAX (8) vectores por empleado -- el original
 * nunca se toca y los extra se renuevan (el más viejo sale). La app compara contra todos y se
 * queda con el más parecido. Una captura solo se guarda si es una coincidencia clara
 * (REENROL_MIN_SCORE) y además aporta algo nuevo (ROSTRO_MULTI_MIN_DIST, 0.12), máximo una al día.
 */
class RostroAutoController extends ResourceController
{
    protected $format = 'json';

    private const CAPTURAS_NECESARIAS = 3;
    private const PESO_NUEVO          = 0.35;  // cuánto pesa el promedio nuevo frente al actual
    private const DIST_ENTRE_CAPTURAS = 0.45;
    private const DIST_MAX_ORIGINAL   = 0.45;
    private const DIAS_ENTRE_AJUSTES  = 7;

    public static function activo(): bool
    {
        return filter_var(env('REENROL_AUTO', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function multiActivo(): bool
    {
        return filter_var(env('ROSTRO_MULTI', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Vectores extra de varios empleados: [id_empleado => [descriptor JSON, ...]]. Vacío si el modo está apagado. */
    public static function extrasPara(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
        if (!$ids || !self::multiActivo()) return [];
        try {
            $rows = \Config\Database::connect()->query(
                'SELECT id_empleado, descriptor FROM empleado_rostro_extras WHERE id_empleado IN (' . implode(',', $ids) . ') ORDER BY id'
            )->getResultArray();
        } catch (\Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) $out[(int)$r['id_empleado']][] = $r['descriptor'];
        return $out;
    }

    /** Borra los vectores extra (al reenrolar o reiniciar el enrolamiento). */
    public static function borrarExtras(int $idEmpleado): void
    {
        try { \Config\Database::connect()->query('DELETE FROM empleado_rostro_extras WHERE id_empleado = ?', [$idEmpleado]); }
        catch (\Throwable $e) { /* tabla aún no existe */ }
    }

    private static function dist(array $a, array $b): float
    {
        $s = 0.0;
        for ($i = 0; $i < 128; $i++) { $d = (float)$a[$i] - (float)$b[$i]; $s += $d * $d; }
        return sqrt($s);
    }

    private static function vector($v): ?array
    {
        if (is_string($v)) $v = json_decode($v, true);
        if (!is_array($v) || count($v) !== 128) return null;
        $v = array_values(array_map('floatval', $v));
        foreach ($v as $x) { if (!is_finite($x)) return null; }
        return $v;
    }

    public function refrescar()
    {
        if (!self::activo()) return $this->respond(['status' => 'ok', 'aplicado' => false]);

        $in      = $this->request->getJSON(true) ?: [];
        $idEmp   = (int)($in['id_empleado'] ?? 0);
        $score   = (float)($in['score'] ?? 0);
        $desc    = self::vector($in['descriptor'] ?? null);
        $device  = preg_match('/^[A-Za-z0-9\-]{8,64}$/', (string)($in['device_id'] ?? '')) ? $in['device_id'] : null;
        $minimo  = (float)env('REENROL_MIN_SCORE', 0.72);

        if ($idEmp <= 0 || !$desc) return $this->respond(['status' => 'error', 'message' => 'Datos inválidos'], 422);
        if ($score < $minimo)      return $this->respond(['status' => 'ok', 'aplicado' => false]);

        $db = \Config\Database::connect();

        if (self::multiActivo()) {
            return $this->aprenderMulti($db, $idEmp, $desc, $score, $device, $minimo);
        }

        try {
            $activo = $db->query(
                'SELECT id, descriptor, created_at FROM empleado_rostro_embeddings WHERE id_empleado = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
                [$idEmp]
            )->getRowArray();
            if (!$activo) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            $actual = self::vector($activo['descriptor']);
            if (!$actual) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            // La captura debe parecerse de verdad al rostro enrolado (el servidor lo vuelve a comprobar)
            $dActual = self::dist($actual, $desc);
            if (sqrt(max(0.0, 1 - $dActual)) < $minimo) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            // Máximo un ajuste cada N días
            $ult = strtotime((string)$activo['created_at']);
            if ($ult && $ult > strtotime('-' . self::DIAS_ENTRE_AJUSTES . ' days')) {
                return $this->respond(['status' => 'ok', 'aplicado' => false]);
            }

            // Una captura por día por empleado
            if ($db->query('SELECT 1 FROM rostro_candidatos WHERE id_empleado = ? AND DATE(created_at) = CURDATE()', [$idEmp])->getRowArray()) {
                return $this->respond(['status' => 'ok', 'aplicado' => false]);
            }

            $db->query(
                'INSERT INTO rostro_candidatos (id_empleado, descriptor, score, device_id, created_at) VALUES (?,?,?,?,NOW())',
                [$idEmp, json_encode($desc), round($score, 4), $device]
            );
            // Máximo 10 capturas guardadas por empleado
            $db->query(
                'DELETE FROM rostro_candidatos WHERE id_empleado = ? AND id NOT IN (
                    SELECT id FROM (SELECT id FROM rostro_candidatos WHERE id_empleado = ? ORDER BY id DESC LIMIT 10) t)',
                [$idEmp, $idEmp]
            );

            $cands = $db->query(
                'SELECT id, descriptor FROM rostro_candidatos WHERE id_empleado = ? ORDER BY id DESC LIMIT 10', [$idEmp]
            )->getResultArray();
            if (count($cands) < self::CAPTURAS_NECESARIAS) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            // Se usan las más recientes y se descartan las que no son consistentes con el rostro actual
            $vs = [];
            foreach ($cands as $c) {
                $v = self::vector($c['descriptor']);
                if ($v && self::dist($actual, $v) <= (1 - $minimo * $minimo)) $vs[] = $v;
            }
            if (count($vs) < self::CAPTURAS_NECESARIAS) return $this->respond(['status' => 'ok', 'aplicado' => false]);
            $vs = array_slice($vs, 0, 5);

            // Las capturas deben ser consistentes entre sí
            for ($i = 0; $i < count($vs); $i++)
                for ($j = $i + 1; $j < count($vs); $j++)
                    if (self::dist($vs[$i], $vs[$j]) > self::DIST_ENTRE_CAPTURAS) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            // Promedio de las capturas y mezcla con el rostro actual
            $prom = array_fill(0, 128, 0.0);
            foreach ($vs as $v) for ($k = 0; $k < 128; $k++) $prom[$k] += $v[$k] / count($vs);
            $nuevo = [];
            for ($k = 0; $k < 128; $k++) $nuevo[$k] = round((1 - self::PESO_NUEVO) * $actual[$k] + self::PESO_NUEVO * $prom[$k], 8);

            // No alejarse demasiado del enrolamiento ORIGINAL (evita deriva acumulada)
            $orig = $db->query('SELECT descriptor FROM empleado_rostro_embeddings WHERE id_empleado = ? ORDER BY id ASC LIMIT 1', [$idEmp])->getRowArray();
            $vo   = $orig ? self::vector($orig['descriptor']) : null;
            if ($vo && self::dist($vo, $nuevo) > self::DIST_MAX_ORIGINAL) {
                $db->query('DELETE FROM rostro_candidatos WHERE id_empleado = ?', [$idEmp]);
                return $this->respond(['status' => 'ok', 'aplicado' => false]);
            }

            $db->transStart();
            $db->query('UPDATE empleado_rostro_embeddings SET is_active = 0 WHERE id_empleado = ? AND is_active = 1', [$idEmp]);
            $db->table('empleado_rostro_embeddings')->insert([
                'id_empleado'            => $idEmp,
                'descriptor'             => json_encode($nuevo),
                'distancia_verificacion' => round(self::dist($actual, $nuevo), 6),
                'is_active'              => 1,
                'id_capturista'          => null,
                'created_at'             => date('Y-m-d H:i:s'),
            ]);
            $db->query('DELETE FROM rostro_candidatos WHERE id_empleado = ?', [$idEmp]);
            $db->transComplete();

            if (!$db->transStatus()) return $this->respond(['status' => 'ok', 'aplicado' => false]);

            AuditLibrary::log(0, 'REENROLAMIENTO_AUTO', 'empleados', (string)$idEmp,
                'Rostro adaptado automáticamente con ' . count($vs) . ' capturas buenas');
            return $this->respond(['status' => 'ok', 'aplicado' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'Reenrolamiento automático: ' . $e->getMessage());
            return $this->respond(['status' => 'ok', 'aplicado' => false]);
        }
    }

    /** Modo multi-vector: guarda la captura como un vector extra si es buena y aporta algo nuevo. */
    private function aprenderMulti($db, int $idEmp, array $desc, float $score, ?string $device, float $minimo)
    {
        $no = fn() => $this->respond(['status' => 'ok', 'aplicado' => false]);
        try {
            $princ = $db->query('SELECT descriptor FROM empleado_rostro_embeddings WHERE id_empleado = ? AND is_active = 1 ORDER BY id DESC LIMIT 1', [$idEmp])->getRowArray();
            $vp    = $princ ? self::vector($princ['descriptor']) : null;
            if (!$vp) return $no();

            $extras = $db->query('SELECT id, descriptor FROM empleado_rostro_extras WHERE id_empleado = ? ORDER BY id', [$idEmp])->getResultArray();

            // Distancia al vector MÁS parecido (original o extra): el servidor vuelve a comprobar la coincidencia
            $mejor = self::dist($vp, $desc);
            foreach ($extras as $e) {
                $v = self::vector($e['descriptor']);
                if ($v) $mejor = min($mejor, self::dist($v, $desc));
            }
            if (sqrt(max(0.0, 1 - $mejor)) < $minimo) return $no();           // no es una coincidencia clara

            $minDist = (float)env('ROSTRO_MULTI_MIN_DIST', 0.12);
            if ($mejor < $minDist) return $no();                              // casi idéntica a una ya guardada: no aporta

            // Máximo un vector nuevo por día por empleado
            if ($db->query('SELECT 1 FROM empleado_rostro_extras WHERE id_empleado = ? AND DATE(created_at) = CURDATE() LIMIT 1', [$idEmp])->getRowArray()) {
                return $no();
            }

            $db->query(
                'INSERT INTO empleado_rostro_extras (id_empleado, descriptor, score, device_id, created_at) VALUES (?,?,?,?,NOW())',
                [$idEmp, json_encode($desc), round($score, 4), $device]
            );

            // Tope: original + (MAX-1) extras; al pasarse sale el más viejo (el original nunca se toca)
            $maxExtras = max(1, (int)env('ROSTRO_MULTI_MAX', 8) - 1);
            $db->query(
                'DELETE FROM empleado_rostro_extras WHERE id_empleado = ? AND id NOT IN (
                    SELECT id FROM (SELECT id FROM empleado_rostro_extras WHERE id_empleado = ? ORDER BY id DESC LIMIT ' . $maxExtras . ') t)',
                [$idEmp, $idEmp]
            );

            AuditLibrary::log(0, 'ROSTRO_EXTRA', 'empleados', (string)$idEmp, 'Vector facial extra aprendido (' . round($score * 100) . '%)');
            return $this->respond(['status' => 'ok', 'aplicado' => true]);
        } catch (\Throwable $e) {
            log_message('error', 'Rostro multi: ' . $e->getMessage());
            return $no();
        }
    }

    /** GET empleados/(id)/rostro-extras (admin): vectores aprendidos, sin el descriptor. */
    public function extras($id = null)
    {
        $u = $this->request->jwtUser ?? null;
        if (!$u || (int)($u->nivel ?? 99) > 2) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        try {
            $rows = \Config\Database::connect()->query(
                'SELECT id, score, device_id, created_at FROM empleado_rostro_extras WHERE id_empleado = ? ORDER BY id DESC', [(int)$id]
            )->getResultArray();
        } catch (\Throwable $e) { $rows = []; }
        return $this->respond(['status' => 'ok', 'activo' => self::multiActivo(), 'data' => $rows]);
    }

    /** DELETE empleados/(id)/rostro-extras (admin): borra todos los vectores aprendidos. */
    public function borrar($id = null)
    {
        $u = $this->request->jwtUser ?? null;
        if (!$u || (int)($u->nivel ?? 99) > 2) return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        self::borrarExtras((int)$id);
        AuditLibrary::log((int)($u->id ?? 0), 'ROSTRO_EXTRA_BORRAR', 'empleados', (string)(int)$id, 'Vectores faciales aprendidos eliminados');
        return $this->respond(['status' => 'ok']);
    }
}
