<?php

namespace App\Controllers\Api\V1;

use App\Libraries\AuditLibrary;
use App\Models\IncidenciaModel;
use App\Models\CatalogoModel;

/**
 * Incidencias desde el kiosko (rutas públicas /incidencias-kiosko, igual que
 * biometrico/*): el operador se identifica con id_operador en el body.
 *
 *   GET  motivos                   catálogo de tipos de incidencia (18)
 *   GET  servicios?query=          búsqueda de servicios
 *   POST /                         crear (multipart, comprobante PDF/imagen opcional)
 *
 * Reutiliza subirComprobante()/validarFecha() de IncidenciasController.
 */
class IncidenciasKioskoController extends IncidenciasController
{
    public function motivos(): mixed
    {
        return $this->respond((new CatalogoModel())->getCatalogo(18));
    }

    public function servicios(): mixed
    {
        $q = trim((string)($this->request->getVar('query') ?? ''));
        if (mb_strlen($q) < 2) {
            return $this->respond(['status' => 'ok', 'data' => []]);
        }
        return $this->respond((new CatalogoModel())->getServiciosSelect($q, 15, 1));
    }

    public function crearKiosko(): mixed
    {
        $db         = \Config\Database::connect();
        $idOperador = (int)($this->request->getVar('id_operador') ?? 0);

        $op = $idOperador > 0
            ? $db->table('empleados')->select('id')->where('id', $idOperador)->where('estatus', 1)->get()->getRowArray()
            : null;
        if (!$op) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no identificado'], 401);
        }

        // Solo jefes de turno: el operador debe tener un usuario SIAG activo ligado
        $jefe = $db->query(
            'SELECT id FROM usuario WHERE id_empleado = ? AND estatus = 1 AND is_deleted = 0 LIMIT 1',
            [$idOperador]
        )->getRowArray();
        if (!$jefe) {
            return $this->respond(['status' => 'error', 'message' => 'Solo jefes de turno pueden subir incidencias'], 403);
        }

        $idEmpleado  = (int)($this->request->getVar('id') ?? 0);
        $motivo      = trim((string)($this->request->getVar('motivo') ?? ''));
        $descripcion = trim((string)($this->request->getVar('descripcion') ?? ''));
        $servicio    = (int)($this->request->getVar('servicio') ?? 0);
        $fechaInicio = $this->validarFecha($this->request->getVar('fecha_inicio'));
        $fechaFinal  = $this->validarFecha($this->request->getVar('fecha_final')) ?: $fechaInicio;

        $faltan = [];
        if ($idEmpleado <= 0)  $faltan[] = 'empleado';
        if ($motivo === '')    $faltan[] = 'motivo';
        if ($descripcion === '') $faltan[] = 'descripción';
        if (!$fechaInicio)     $faltan[] = 'fecha de inicio';
        if ($servicio <= 0)    $faltan[] = 'servicio';
        if ($faltan) {
            return $this->respond(['status' => 'error', 'message' => 'Falta: ' . implode(', ', $faltan)], 422);
        }
        if ($fechaInicio > $fechaFinal) {
            return $this->respond(['status' => 'error', 'message' => 'La fecha de inicio no puede ser mayor que la final'], 422);
        }

        $emp = $db->table('empleados')->select('id')->where('id', $idEmpleado)->where('estatus', 1)->get()->getRowArray();
        if (!$emp) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $ruta = null; $nombreOriginal = null;
        $archivo = $this->request->getFile('comprobante');
        if ($archivo && $archivo->isValid() && !$archivo->hasMoved()) {
            $r = $this->subirComprobante($archivo);
            if ($r['status'] !== 'ok') {
                return $this->respond($r, 422);
            }
            $ruta = $r['ruta']; $nombreOriginal = $r['nombre_original'];
        }

        $res = (new IncidenciaModel())->crear([
            'id_empleado'                 => $idEmpleado,
            'id_tipo_incidencia'          => $motivo,
            'descripcion'                 => $descripcion,
            'id_servicio'                 => $servicio,
            'fecha_inicio'                => $fechaInicio,
            'fecha_final'                 => $fechaFinal,
            'comprobante_ruta'            => $ruta,
            'comprobante_nombre_original' => $nombreOriginal,
            'created_by'                  => $idOperador,
        ]);

        AuditLibrary::log($idOperador, 'CREAR_INCIDENCIA', 'incidencias', (string)($res['id'] ?? ''), 'Incidencia registrada desde el kiosko');

        return $this->respond($res, ($res['status'] ?? '') === 'ok' ? 201 : 500);
    }
}
