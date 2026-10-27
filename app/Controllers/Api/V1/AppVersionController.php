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
