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

    public function version()
    {
        $dir  = FCPATH . 'app' . DIRECTORY_SEPARATOR;
        $json = is_file($dir . 'version.json') ? json_decode((string) file_get_contents($dir . 'version.json'), true) : null;
        $apk  = $dir . 'kiosko_SIAG.apk';

        if (! is_array($json) || ! is_file($apk)) {
            return $this->respond(['status' => 'error', 'message' => 'Sin versión publicada'], 404);
        }

        return $this->respond([
            'status' => 'success',
            'data'   => [
                'versionCode' => (int) ($json['versionCode'] ?? 1),
                'versionName' => (string) ($json['versionName'] ?? '1.0'),
                'notas'       => (string) ($json['notas'] ?? ''),
                'obligatoria' => (bool) ($json['obligatoria'] ?? false),
                'tamano'      => filesize($apk),
                'apk_url'     => site_url('api/v1/app/descargar'),
            ],
        ]);
    }

    public function descargar()
    {
        $apk = FCPATH . 'app' . DIRECTORY_SEPARATOR . 'kiosko_SIAG.apk';
        if (! is_file($apk)) {
            return $this->respond(['status' => 'error', 'message' => 'APK no disponible'], 404);
        }
        return $this->response
            ->setHeader('Content-Type', 'application/vnd.android.package-archive')
            ->setHeader('Content-Disposition', 'attachment; filename="kiosko_SIAG.apk"')
            ->setHeader('Content-Length', (string) filesize($apk))
            ->setBody(file_get_contents($apk));
    }
}
