<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class ErrorController extends Controller
{
    public function notFound(): void
    {
        $this->response
            ->setStatusCode(404)
            ->setContentType('application/json')
            ->setBody(json_encode([
                'status'  => 'error',
                'message' => 'Endpoint no encontrado',
            ], JSON_UNESCAPED_UNICODE))
            ->send();
        exit();
    }
}