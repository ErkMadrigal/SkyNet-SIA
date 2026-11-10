<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Versionamiento de la app del kiosko (público).
 *  GET /api/v1/app/version  → datos de la última versión publicada
 *  GET /api/v1/app/descargar → descarga directa del APK
 *
 * Publicar una versión nueva: subir public/app/kiosko_SIAG.apk y editar
 * public/app/version.json  {"versionCode":2,"versionName":"1.1","notas":"...","obligatoria":false}
 *
 * PRUEBA PILOTO: una versión nueva solo para algunos equipos (el resto sigue con la estable).
 * Se agrega un bloque "piloto" al mismo version.json:
 *   "piloto": { "versionCode": 3, "versionName": "1.2", "notas": "...", "tamano": 0,
 *               "apk_url": "https://.../kiosko_SIAG_1.2.apk",
 *               "dispositivos": ["id-del-equipo-1", "id-del-equipo-2"],   // equipos elegidos (ver panel > Dispositivos)
 *               "porcentaje": 10 }                                         // y/o un % de equipos al azar (estable por equipo)
 * El kiosko manda ?device_id=... y solo los elegidos reciben la versión piloto.
 * Para liberarla a todos: pasa sus datos al bloque principal y quita "piloto".
 */
class AppVersionController extends ResourceController
{
    protected $format = 'json';

    /** Carpetas donde puede estar "app/" (según cómo esté montado el hosting). */
    private function carpetas(): array
    {
        $ds = DIRECTORY_SEPARATOR;
        return array_values(array_unique(array_filter([
            rtrim(FCPATH, '/\\') . $ds . 'app' . $ds,
            rtrim(ROOTPATH, '/\\') . $ds . 'public' . $ds . 'app' . $ds,
            rtrim(ROOTPATH, '/\\') . $ds . 'app_publica' . $ds,
            isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $ds . 'app' . $ds : null,
        ])));
    }

    /** ¿Este equipo participa en la prueba piloto? (por id o por porcentaje, estable por equipo). */
    public static function enPiloto($piloto, string $deviceId): bool
    {
        if (!is_array($piloto) || $deviceId === '') return false;
        if (in_array($deviceId, array_map('strval', (array)($piloto['dispositivos'] ?? [])), true)) return true;
        $pct = (int)($piloto['porcentaje'] ?? 0);
        return $pct > 0 && (crc32($deviceId) % 100) < $pct;
    }

    public static function leerJson(): ?array
    {
        $ds = DIRECTORY_SEPARATOR;
        foreach ([rtrim(FCPATH, '/\\') . $ds . 'app' . $ds, rtrim(ROOTPATH, '/\\') . $ds . 'public' . $ds . 'app' . $ds] as $d) {
            if (is_file($d . 'version.json')) {
                $j = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($d . 'version.json')), true);
                if (is_array($j)) return $j;
            }
        }
        return null;
    }

    public function version()
    {
        $json = null;
        $dir  = null;
        foreach ($this->carpetas() as $d) {
            if (is_file($d . 'version.json')) {
                $raw  = (string) file_get_contents($d . 'version.json');
                $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // quita BOM
                $json = json_decode($raw, true);
                $dir  = $d;
                if (is_array($json)) break;
            }
        }
        $urlExterna = is_array($json) ? trim((string) ($json['apk_url'] ?? '')) : '';
        $apk        = $dir ? $dir . 'kiosko_SIAG.apk' : '';

        if (! is_array($json) || ($urlExterna === '' && ! is_file($apk))) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Sin versión publicada',
                'buscado' => $this->carpetas(),                       // diagnóstico
                'json_ok' => is_array($json),
                'json_error' => $dir ? json_last_error_msg() : 'version.json no encontrado',
            ], 404);
        }

        // Prueba piloto: solo para los equipos elegidos
        $piloto   = $json['piloto'] ?? null;
        $deviceId = trim((string) ($this->request->getGet('device_id') ?? ''));
        if (is_array($piloto)
            && (int)($piloto['versionCode'] ?? 0) > (int)($json['versionCode'] ?? 0)
            && trim((string)($piloto['apk_url'] ?? '')) !== ''
            && self::enPiloto($piloto, $deviceId)) {
            return $this->respond([
                'status' => 'success',
                'data'   => [
                    'versionCode' => (int) $piloto['versionCode'],
                    'versionName' => (string) ($piloto['versionName'] ?? ''),
                    'notas'       => (string) ($piloto['notas'] ?? 'Versión de prueba'),
                    'obligatoria' => (bool) ($piloto['obligatoria'] ?? false),
                    'tamano'      => (int) ($piloto['tamano'] ?? 0),
                    'apk_url'     => trim((string) $piloto['apk_url']),
                    'piloto'      => true,
                ],
            ]);
        }

        return $this->respond([
            'status' => 'success',
            'data'   => [
                'versionCode' => (int) ($json['versionCode'] ?? 1),
                'versionName' => (string) ($json['versionName'] ?? '1.0'),
                'notas'       => (string) ($json['notas'] ?? ''),
                'obligatoria' => (bool) ($json['obligatoria'] ?? false),
                'tamano'      => (int) ($json['tamano'] ?? (is_file($apk) ? filesize($apk) : 0)),
                'apk_url'     => $urlExterna !== '' ? $urlExterna : site_url('api/v1/app/descargar'),
            ],
        ]);
    }

    public function descargar()
    {
        $apk = '';
        foreach ($this->carpetas() as $d) { if (is_file($d . 'kiosko_SIAG.apk')) { $apk = $d . 'kiosko_SIAG.apk'; break; } }
        if ($apk === '') {
            return $this->respond(['status' => 'error', 'message' => 'APK no disponible'], 404);
        }
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.android.package-archive')
            ->setHeader('Content-Disposition', 'attachment; filename="kiosko_SIAG.apk"')
            ->setHeader('Content-Length', (string) filesize($apk))
            ->setBody(file_get_contents($apk));
    }
}
