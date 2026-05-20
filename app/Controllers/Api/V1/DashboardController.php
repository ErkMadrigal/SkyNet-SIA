<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\DashboardModel;

class DashboardController extends ResourceController
{
    protected $format = 'json';

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/resumen
    ───────────────────────────────────────── */
    public function resumen(): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->resumen($hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status' => 'ok',
            'fecha'  => $hoy,
            'data'   => $result['data'],
        ]);
    }

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/zona/:id
    ───────────────────────────────────────── */
    public function zona(int $idZona): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->zona($idZona, $hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status'    => 'ok',
            'fecha'     => $hoy,
            'zona'      => $result['zona'],
            'id_zona'   => $result['id_zona'],
            'servicios' => $result['data'],
        ]);
    }

    /* ─────────────────────────────────────────
       GET /api/v1/dashboard/control-area
    ───────────────────────────────────────── */
    public function controlArea(): mixed
    {
        $hoy    = date('Y-m-d');
        $model  = new DashboardModel();
        $result = $model->controlArea($hoy);

        if ($result['status'] !== 'ok') {
            return $this->respond($result, 500);
        }

        return $this->respond([
            'status' => 'ok',
            'fecha'  => $hoy,
            'data'   => $result['data'],
        ]);
    }
}