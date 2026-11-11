<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\CronEstadoLibrary;

/**
 * Estado del sistema para el panel (solo administradores):
 *   GET sistema/estado → crons (última y próxima ejecución) y versión de la app publicada.
 */
class SistemaController extends ResourceController
{
    protected $format = 'json';

    /** Tareas programadas en el cPanel (la programación real vive allá; aquí se describe). */
    private const TAREAS = [
        ['clave' => 'cerrar_entradas',   'nombre' => 'Cierre automático de entradas', 'detalle' => 'Cierra las entradas sin salida cuando pasó la duración del turno + tolerancia',
         'minutos' => '0,30', 'horas' => '*', 'texto' => 'Cada 30 minutos (minutos 0 y 30)', 'cada_seg' => 1800],
        ['clave' => 'purgar_evidencias', 'nombre' => 'Limpieza de evidencias',        'detalle' => 'Borra las fotos de evidencia que ya cumplieron su retención',
         'minutos' => '15', 'horas' => '3', 'texto' => 'Todos los días a las 3:15', 'cada_seg' => 86400],
    ];

    public function estado()
    {
        $u = $this->request->jwtUser ?? null;
        if (!$u || (int)($u->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        }

        $mx     = new \DateTimeZone('America/Mexico_City');
        $cronTz = new \DateTimeZone((string) env('CRON_TZ', date_default_timezone_get()));
        $ahora  = time();
        $latido = CronEstadoLibrary::leer();

        $tareas = [];
        foreach (self::TAREAS as $t) {
            $l      = $latido[$t['clave']] ?? null;
            $prox   = CronEstadoLibrary::proxima($t['minutos'], $t['horas'], $cronTz, $ahora);
            $estado = 'nunca';
            if ($l) {
                $estado = !$l['ok'] ? 'error' : (($ahora - (int)$l['ts']) > $t['cada_seg'] * 2 + 300 ? 'atrasado' : 'ok');
            }
            $tareas[] = [
                'clave'        => $t['clave'],
                'nombre'       => $t['nombre'],
                'detalle'      => $t['detalle'],
                'programacion' => $t['texto'],
                'ultima'       => $l ? [
                    'fecha'   => (new \DateTime('@' . (int)$l['ts']))->setTimezone($mx)->format('Y-m-d H:i:s'),
                    'hace_min'=> (int) floor(($ahora - (int)$l['ts']) / 60),
                    'ok'      => (bool)$l['ok'],
                    'detalle' => $l['detalle'] ?? '',
                ] : null,
                'proxima'      => $prox ? (new \DateTime('@' . $prox))->setTimezone($mx)->format('Y-m-d H:i:s') : null,
                'proxima_en_min' => $prox ? (int) ceil(($prox - $ahora) / 60) : null,
                'estado'       => $estado,
            ];
        }

        return $this->respond([
            'status' => 'ok',
            'data'   => [
                'hora_servidor' => (new \DateTime('now', $mx))->format('Y-m-d H:i:s'),
                'tareas'        => $tareas,
                'version'       => $this->version(),
            ],
        ]);
    }

    /** Versión publicada de la app y si el APK realmente está descargable. */
    private function version(): array
    {
        $f = FCPATH . 'app/version.json';
        if (!is_file($f)) return ['publicada' => false];
        $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($f)), true);
        if (!is_array($j)) return ['publicada' => false, 'error' => 'version.json con formato inválido'];

        $mx  = new \DateTimeZone('America/Mexico_City');
        $url = (string) ($j['apk_url'] ?? '');
        return [
            'publicada'      => true,
            'versionCode'    => (int) ($j['versionCode'] ?? 0),
            'versionName'    => (string) ($j['versionName'] ?? ''),
            'notas'          => (string) ($j['notas'] ?? ''),
            'obligatoria'    => (bool) ($j['obligatoria'] ?? false),
            'tamano'         => (int) ($j['tamano'] ?? 0),
            'apk_url'        => $url,
            'actualizado_en' => (new \DateTime('@' . filemtime($f)))->setTimezone($mx)->format('Y-m-d H:i:s'),
            'apk_disponible' => $url !== '' ? $this->apkDisponible($url) : null,
        ];
    }

    private function apkDisponible(string $url): ?bool
    {
        if (!function_exists('curl_init')) return null;
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_RETURNTRANSFER => true]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 0) return null;
            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) { return null; }
    }
}
