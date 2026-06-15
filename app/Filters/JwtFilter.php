<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\JwtLibrary;

class JwtFilter implements FilterInterface
{
    private array $niveles = [
        'superadmin' => 1,
        'admin'      => 2,
        'operador'   => 3,
        'viewer'     => 4,
    ];

    public function before(RequestInterface $request, $arguments = null): mixed
    {
        $jwt = JwtLibrary::extraerBearer();

        if (! $jwt) {
            $this->jsonExit(401, 'Token no proporcionado');
        }

        try {
            $lib     = new JwtLibrary();
            $decoded = $lib->validarAccess($jwt);
        } catch (\RuntimeException $e) {
            $this->jsonExit(401, $e->getMessage());
        }

        if (! empty($arguments)) {
            $rolRequerido = strtolower($arguments[0] ?? '');
            $nivelMin     = $this->niveles[$rolRequerido] ?? 99;
            $nivelUsuario = (int) ($decoded->data->nivel ?? 99);

            if ($nivelUsuario > $nivelMin) {
                $vistas = json_decode(json_encode($decoded->data->vistas ?? []), true);
                $uri    = service('request')->getUri()->getPath();
                $partes = explode('/', trim($uri, '/'));

                $modulo = '';
                foreach ($partes as $i => $parte) {
                    if ($parte === 'v1') {
                        if (isset($partes[$i + 1]) && $partes[$i + 1] !== 'index.php') {
                            $modulo = $partes[$i + 1];
                        }
                    }
                }

                $tienePermiso = array_filter($vistas, fn($v) => str_starts_with($v, $modulo . '.'));

                if (empty($tienePermiso)) {
                    $this->jsonExit(403, 'No tienes permisos para este recurso');
                }
            }
        }

        $request->jwtUser = $decoded->data;
        $request->jwtJti  = $decoded->jti ?? null;

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): mixed
    {
        return null;
    }

    private function jsonExit(int $code, string $message): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
