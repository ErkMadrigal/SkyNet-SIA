<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\IncidenciaModel;
use App\Libraries\AuditLibrary;

/**
 * IncidenciasController
 *
 * Migración de confirmar_incidencia y aprobar_incidencia del legacy.
 * Rutas base: /api/v1/incidencias
 *
 * GET  /          → listar incidencias pendientes
 * POST /          → crear incidencia (confirmar_incidencia)
 * POST /{id}/aprobar → aprobar o rechazar (aprobar_incidencia)
 */
class IncidenciasController extends ResourceController
{
    protected $format = 'json';

    /**
     * GET /api/v1/incidencias
     */
    public function index(): mixed
    {
        $model = new IncidenciaModel();
        return $this->respond($model->listarPendientes());
    }

    /**
     * POST /api/v1/incidencias
     *
     * Crea una incidencia con comprobante opcional.
     * Multipart: { id, motivo, descripcion, servicio, fecha_inicio, fecha_final, comprobante? }
     */
    public function create(): mixed
    {
        $actor = $this->request->jwtUser;

        $idEmpleado  = $this->request->getVar('id');
        $motivo      = trim($this->request->getVar('motivo') ?? '');
        $descripcion = trim($this->request->getVar('descripcion') ?? '');
        $servicio    = $this->request->getVar('servicio');
        $fechaInicio = $this->validarFecha($this->request->getVar('fecha_inicio'));
        $fechaFinal  = $this->validarFecha($this->request->getVar('fecha_final'));

        if (!$idEmpleado || !$motivo || !$descripcion || !$fechaInicio || !$fechaFinal || !$servicio) {
            return $this->respond(['status' => 'error', 'message' => 'Faltan campos obligatorios'], 422);
        }

        if ($fechaInicio > $fechaFinal) {
            return $this->respond(['status' => 'error', 'message' => 'Fecha inicio no puede ser mayor que fecha final'], 422);
        }

        // Comprobante (opcional)
        $compruebaRuta   = null;
        $compruebaNombre = null;

        $archivo = $this->request->getFile('comprobante');

        if ($archivo && $archivo->isValid() && !$archivo->hasMoved()) {
            $resultadoSubida = $this->subirComprobante($archivo);
            if ($resultadoSubida['status'] !== 'ok') {
                return $this->respond($resultadoSubida, 422);
            }
            $compruebaRuta   = $resultadoSubida['ruta'];
            $compruebaNombre = $resultadoSubida['nombre_original'];
        }

        $model = new IncidenciaModel();
        $res   = $model->crear([
            'id_empleado'                => $idEmpleado,
            'id_tipo_incidencia'         => $motivo,
            'descripcion'                => $descripcion,
            'id_servicio'                => $servicio,
            'fecha_inicio'               => $fechaInicio,
            'fecha_final'                => $fechaFinal,
            'comprobante_ruta'           => $compruebaRuta,
            'comprobante_nombre_original' => $compruebaNombre,
            'created_by'                 => (int)$actor->id,
        ]);

        AuditLibrary::log((int)$actor->id, 'CREAR_INCIDENCIA', 'incidencias', (string)($res['id'] ?? ''), 'Incidencia registrada');

        return $this->respond($res, $res['status'] === 'ok' ? 201 : 500);
    }

    /**
     * POST /api/v1/incidencias/{id}/aprobar
     *
     * Body: { tipo: 1|2, comentario?: "..." }  (1=aprobar, 2=rechazar)
     */
    public function aprobar($id = null): mixed
    {
        $actor     = $this->request->jwtUser;
        $tipo      = (int)($this->request->getVar('tipo') ?? 0);
        $comentario = $this->request->getVar('comentario');

        if (!in_array($tipo, [1, 2], true)) {
            return $this->respond(['status' => 'error', 'message' => 'tipo debe ser 1 (aprobar) o 2 (rechazar)'], 422);
        }

        $model = new IncidenciaModel();
        $res   = $model->aprobar((int)$id, $tipo, $comentario, (int)$actor->id);

        AuditLibrary::log(
            (int)$actor->id,
            $tipo === 1 ? 'APROBAR_INCIDENCIA' : 'RECHAZAR_INCIDENCIA',
            'incidencias',
            (string)$id,
            $tipo === 1 ? 'Aprobada' : 'Rechazada'
        );

        return $this->respond($res);
    }

    /* ─────────────────────────────────────────
       HELPERS
    ───────────────────────────────────────── */

    private function subirComprobante($archivo): array
    {
        $ext = strtolower(pathinfo($archivo->getName(), PATHINFO_EXTENSION));
        $permitidos = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $permitidos, true)) {
            return ['status' => 'error', 'message' => 'Solo se permiten PDF o imágenes (JPG, JPEG, PNG, WEBP)'];
        }

        if ($archivo->getSize() > 5 * 1024 * 1024) {
            return ['status' => 'error', 'message' => 'El comprobante no puede ser mayor a 5 MB'];
        }

        $anio    = date('Y');
        $mes     = date('m');
        $baseDir = ROOTPATH . "../uploads/incidencias/{$anio}/{$mes}/";

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        $nombreNuevo = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!$archivo->move($baseDir, $nombreNuevo)) {
            return ['status' => 'error', 'message' => 'No se pudo guardar el comprobante'];
        }

        return [
            'status'          => 'ok',
            'ruta'            => "uploads/incidencias/{$anio}/{$mes}/{$nombreNuevo}",
            'nombre_original' => $archivo->getName(),
        ];
    }

    private function validarFecha(?string $fecha): ?string
    {
        if (empty($fecha) || $fecha === '0000-00-00') return null;

        $dt = \DateTime::createFromFormat('Y-m-d', $fecha);
        if ($dt === false) return null;

        $errors = \DateTime::getLastErrors();
        if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
        if ($dt->format('Y-m-d') !== $fecha) return null;

        return $fecha;
    }
}
