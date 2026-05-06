<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $apiKey      = defined('API_KEY') ? API_KEY : env('API_KEY', '');
        $keyRecibida = $request->getHeaderLine('X-API-KEY')
                    ?: ($request->getVar('api_key') ?? '');

        if (empty($keyRecibida) || $keyRecibida !== $apiKey) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => 'API Key inválida o no proporcionada',
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }
}
