<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Expediente digital desde el kiosko (rutas públicas /expediente-kiosko).
 * Cualquier empleado activo identificado como operador (id_operador) puede subir
 * documentos de sí mismo o de otro empleado; quedan PENDIENTES hasta que un
 * administrador los apruebe en el panel.
 *
 *   POST /                 multipart: id_operador, id_empleado (opcional), tipo, archivo
 *   GET  mios?id_operador  documentos propios y su estado
 */
class ExpedienteKioskoController extends ResourceController
{
    protected $format = 'json';

    private const MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_KB = 10240;
    private const TIPOS = ['identificacion', 'comprobante_domicilio', 'estado_cuenta', 'otro'];

    private function operador(): ?array
    {
        $id = (int)($this->request->getVar('id_operador') ?? 0);
        if ($id <= 0) return null;
        return \Config\Database::connect()->query(
            'SELECT id, TRIM(CONCAT_WS(" ", nombre, paterno, materno)) AS nombre FROM empleados WHERE id = ? AND estatus = 1 LIMIT 1',
            [$id]
        )->getRowArray() ?: null;
    }

    public function subir(): mixed
    {
        $op = $this->operador();
        if (!$op) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no válido'], 403);
        }
        $db = \Config\Database::connect();

        $idEmp = (int)($this->request->getVar('id_empleado') ?? 0) ?: (int)$op['id'];
        $emp = $db->query('SELECT id FROM empleados WHERE id = ? AND estatus = 1 LIMIT 1', [$idEmp])->getRowArray();
        if (!$emp) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $file = $this->request->getFile('archivo');
        if (!$file || !$file->isValid() || $file->hasMoved()) {
            return $this->respond(['status' => 'error', 'message' => 'Archivo no recibido o inválido'], 400);
        }
        if ($file->getSizeByUnit('kb') > self::MAX_KB) {
            return $this->respond(['status' => 'error', 'message' => 'El archivo supera 10 MB'], 422);
        }
        $mime = $file->getMimeType();
        if (!in_array($mime, self::MIMES, true)) {
            return $this->respond(['status' => 'error', 'message' => 'Solo PDF, JPG o PNG'], 422);
        }
        $tipo = (string)($this->request->getVar('tipo') ?? 'otro');
        if (!in_array($tipo, self::TIPOS, true)) $tipo = 'otro';

        $dir = WRITEPATH . 'expedientes/' . $idEmp . '/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $nombre   = $file->getRandomName();
        $original = mb_substr($file->getClientName(), 0, 250);
        $tamano   = (int)$file->getSize();
        $file->move($dir, $nombre);

        // Si el operador es jefe (tiene usuario SIAG) se registra su usuario; si no, 0
        $u = $db->query('SELECT id FROM usuario WHERE id_empleado = ? AND is_deleted = 0 LIMIT 1', [(int)$op['id']])->getRowArray();

        $db->table('empleado_documentos')->insert([
            'id_empleado'       => $idEmp,
            'tipo'              => $tipo,
            'estado'            => 'pendiente',
            'archivo'           => $idEmp . '/' . $nombre,
            'nombre_original'   => $original,
            'mime'              => $mime,
            'tamano'            => $tamano,
            'id_empleado_subio' => (int)$op['id'],
            'created_by'        => (int)($u['id'] ?? 0),
        ]);

        AuditLibrary::log((int)($u['id'] ?? 0), 'SUBIR_DOCUMENTO_KIOSKO', 'empleados', (string)$idEmp, "Pendiente {$tipo}: {$original} (subió empleado {$op['id']})");

        return $this->respond(['status' => 'ok', 'message' => 'Documento enviado; queda pendiente de aprobación', 'data' => ['id' => $db->insertID()]], 201);
    }

    public function mios(): mixed
    {
        $op = $this->operador();
        if (!$op) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no válido'], 403);
        }
        $rows = \Config\Database::connect()->query(
            'SELECT id, tipo, estado, nombre_original, motivo_rechazo, created_at
             FROM empleado_documentos WHERE id_empleado = ? AND is_active = 1 ORDER BY id DESC LIMIT 50',
            [(int)$op['id']]
        )->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }
}
