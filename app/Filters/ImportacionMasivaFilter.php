<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Filtro de seguridad extra para las cargas masivas (10,000+ registros).
 *
 * Exige una clave adicional (distinta del login normal) en el header
 * 'X-Import-Key' o en el campo del body 'clave_acceso'. Sin esa clave,
 * ni con sesión válida se puede acceder.
 *
 * La clave se guarda en .env como IMPORT_MASIVA_PASSWORD.
 */
class ImportacionMasivaFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $claveEsperada = getenv('IMPORT_MASIVA_PASSWORD');

        if (!$claveEsperada) {
            return service('response')
                ->setStatusCode(500)
                ->setJSON(['status' => 'error', 'message' => 'Carga masiva no configurada -- falta IMPORT_MASIVA_PASSWORD en .env']);
        }

        $claveRecibida = $request->getHeaderLine('X-Import-Key')
            ?: (string)($request->getVar('clave_acceso') ?? '');

        if (!$claveRecibida || !hash_equals($claveEsperada, $claveRecibida)) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['status' => 'error', 'message' => 'Clave de acceso a carga masiva incorrecta o faltante']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada que hacer después
    }
}