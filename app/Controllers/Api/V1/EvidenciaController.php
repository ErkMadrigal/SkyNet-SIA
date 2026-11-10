<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\EvidenciaLibrary;

/** GET asistencias/(id)/evidencia  (JWT) — devuelve la miniatura del registro. */
class EvidenciaController extends ResourceController
{
    public function ver($id = null)
    {
        $abs = EvidenciaLibrary::archivo((int)$id);
        if (!$abs) {
            return $this->failNotFound('Sin evidencia (no se guardó o ya venció)');
        }
        return $this->response
            ->setContentType('image/jpeg')
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setBody(file_get_contents($abs));
    }
}
