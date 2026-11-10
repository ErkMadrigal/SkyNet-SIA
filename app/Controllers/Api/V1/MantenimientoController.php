<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\EmpleadoModel;

/**
 * Tareas programadas (se llaman desde un cron de cPanel con curl).
 *
 *   GET /api/v1/mantenimiento/cerrar-entradas?key=XXXX
 *
 * Cierra las entradas que se quedaron sin salida (turno terminado hace más de 4 h) con
 * estado_salida = 'no_registrada'. Requiere CRON_KEY en el .env del servidor; sin ella
 * el endpoint queda desactivado.
 */
class MantenimientoController extends ResourceController
{
    protected $format = 'json';

    public function cerrarEntradas()
    {
        $esperada = (string) env('CRON_KEY', '');
        $recibida = (string) ($this->request->getGet('key') ?? '');

        if ($esperada === '' || !hash_equals($esperada, $recibida)) {
            return $this->respond(['status' => 'error', 'message' => 'No autorizado'], 403);
        }

        try {
            $n = (new EmpleadoModel())->cerrarEntradasAbiertas();
        } catch (\Throwable $e) {
            log_message('error', 'Cron cerrar-entradas: ' . $e->getMessage());
            return $this->respond(['status' => 'error', 'message' => 'Error al cerrar entradas'], 500);
        }

        return $this->respond(['status' => 'ok', 'cerradas' => $n]);
    }

    /** GET /api/v1/mantenimiento/purgar-evidencias?key=XXXX — borra evidencias vencidas (EVIDENCIA_DIAS, 30 por defecto). */
    public function purgarEvidencias()
    {
        $esperada = (string) env('CRON_KEY', '');
        $recibida = (string) ($this->request->getGet('key') ?? '');
        if ($esperada === '' || !hash_equals($esperada, $recibida)) {
            return $this->respond(['status' => 'error', 'message' => 'No autorizado'], 403);
        }
        try {
            return $this->respond(['status' => 'ok', 'eliminadas' => \App\Libraries\EvidenciaLibrary::purgar()]);
        } catch (\Throwable $e) {
            log_message('error', 'Cron purgar-evidencias: ' . $e->getMessage());
            return $this->respond(['status' => 'error', 'message' => 'Error al purgar'], 500);
        }
    }
}
