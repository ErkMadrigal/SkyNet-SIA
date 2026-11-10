<?php

namespace App\Libraries;

/**
 * Evidencia fotográfica de cada registro (miniatura JPEG), opcional.
 * Se guarda FUERA de public/ (writable/evidencias) y solo se sirve con sesión del panel.
 * Retención: EVIDENCIA_DIAS (por defecto 30) — la purga corre desde el cron.
 */
class EvidenciaLibrary
{
    public static function activa(): bool
    {
        return filter_var(env('EVIDENCIA_ACTIVA', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function base(): string
    {
        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'evidencias' . DIRECTORY_SEPARATOR;
    }

    /** Guarda la miniatura (base64 de un JPEG ≤ 80 KB). Devuelve true si se guardó. */
    public static function guardar(int $idAsistencia, string $b64): bool
    {
        if (!self::activa() || $idAsistencia <= 0 || $b64 === '') return false;
        try {
            $bin = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64), true);
            if ($bin === false || strlen($bin) < 200 || strlen($bin) > 80 * 1024) return false;
            if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") return false; // debe ser JPEG

            $rel = date('Y/m') . '/' . $idAsistencia . '.jpg';
            $abs = self::base() . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir(dirname($abs))) mkdir(dirname($abs), 0775, true);
            if (file_put_contents($abs, $bin) === false) return false;

            \Config\Database::connect()->query(
                'REPLACE INTO asistencias_evidencia (id_asistencia, ruta, created_at) VALUES (?,?,NOW())',
                [$idAsistencia, $rel]
            );
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'Evidencia: ' . $e->getMessage());
            return false;
        }
    }

    /** Ruta absoluta del archivo de esa asistencia (o null). */
    public static function archivo(int $idAsistencia): ?string
    {
        try {
            $r = \Config\Database::connect()
                ->query('SELECT ruta FROM asistencias_evidencia WHERE id_asistencia = ?', [$idAsistencia])->getRowArray();
        } catch (\Throwable $e) { return null; }
        if (!$r) return null;
        $abs = self::base() . str_replace('/', DIRECTORY_SEPARATOR, $r['ruta']);
        return is_file($abs) ? $abs : null;
    }

    /** Borra las evidencias más viejas que $dias. Devuelve cuántas se eliminaron. */
    public static function purgar(?int $dias = null): int
    {
        $dias = $dias ?? max(1, (int)env('EVIDENCIA_DIAS', 30));
        $db   = \Config\Database::connect();
        $n    = 0;
        $rows = $db->query('SELECT id_asistencia, ruta FROM asistencias_evidencia WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 2000', [$dias])->getResultArray();
        foreach ($rows as $r) {
            $abs = self::base() . str_replace('/', DIRECTORY_SEPARATOR, $r['ruta']);
            if (is_file($abs)) @unlink($abs);
            $db->query('DELETE FROM asistencias_evidencia WHERE id_asistencia = ?', [(int)$r['id_asistencia']]);
            $n++;
        }
        return $n;
    }
}
