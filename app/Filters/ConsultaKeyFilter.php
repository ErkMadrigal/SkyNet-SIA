<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Protege las consultas de solo lectura para sistemas externos.
 * Header X-API-KEY = CONSULTA_API_KEY (.env). Opcional: CONSULTA_IPS=ip1,ip2.
 * Si CONSULTA_API_KEY no existe, rechaza todo.
 */
class ConsultaKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $key = (string)env('CONSULTA_API_KEY', '');
        $rec = $request->getHeaderLine('X-API-KEY');
        $ips = array_filter(array_map('trim', explode(',', (string)env('CONSULTA_IPS', ''))));

        if ($key === '' || $rec === '' || !hash_equals($key, $rec) || ($ips && !in_array($request->getIPAddress(), $ips, true))) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'API Key inválida o no proporcionada'], JSON_UNESCAPED_UNICODE);
            exit();
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
