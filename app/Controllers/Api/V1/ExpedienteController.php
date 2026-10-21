<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Expediente digital del empleado (evidencia de datos bancarios, etc.).
 *
 * Solo Administrador o superior (nivel <= 2). Los archivos se guardan FUERA
 * de public (writable/expedientes) y se sirven solo con JWT.
 *
 *   GET    empleados/(id)/documentos
 *   POST   empleados/(id)/documentos        multipart: archivo, tipo, clabe_detectada, ...
 *   GET    empleados/documentos/(docId)/archivo
 *   DELETE empleados/documentos/(docId)
 */
class ExpedienteController extends ResourceController
{
    protected $format = 'json';

    private const MIMES   = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_KB  = 10240;   // 10 MB
    private const TIPOS   = ['estado_cuenta', 'identificacion', 'comprobante_domicilio', 'otro'];

    private function soloAdmin(): ?object
    {
        $u = $this->request->jwtUser;
        if ((int)($u->nivel ?? 99) > 2) {
            return $this->respond(['status' => 'error', 'message' => 'Solo administradores pueden manejar el expediente digital'], 403);
        }
        return null;
    }

    private function base(): string
    {
        return WRITEPATH . 'expedientes/';
    }

    public function listar($id = null): mixed
    {
        if ($d = $this->soloAdmin()) return $d;
        $db = \Config\Database::connect();
        $rows = $db->query("
            SELECT d.id, d.tipo, d.estado, d.motivo_rechazo, d.nombre_original, d.mime, d.tamano,
                   d.clabe_detectada, d.titular_detectado, d.banco_detectado, d.coincide_titular,
                   d.created_at,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.nombre, u.paterno)), ''), TRIM(CONCAT_WS(' ', es.nombre, es.paterno))) AS subido_por
            FROM empleado_documentos d
            LEFT JOIN usuario u ON u.id = d.created_by
            LEFT JOIN empleados es ON es.id = d.id_empleado_subio
            WHERE d.id_empleado = ? AND d.is_active = 1
            ORDER BY d.id DESC
        ", [(int)$id])->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function subir($id = null): mixed
    {
        if ($d = $this->soloAdmin()) return $d;
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $emp = $db->table('empleados')->select('id')->where('id', (int)$id)->get()->getRowArray();
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

        $tipo = (string)($this->request->getVar('tipo') ?? 'estado_cuenta');
        if (!in_array($tipo, self::TIPOS, true)) $tipo = 'otro';

        $dir = $this->base() . (int)$id . '/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $nombre   = $file->getRandomName();
        $original = mb_substr($file->getClientName(), 0, 250);
        $tamano   = (int)$file->getSize();
        $file->move($dir, $nombre);

        $clabe = preg_replace('/\D/', '', (string)($this->request->getVar('clabe_detectada') ?? ''));
        $db->table('empleado_documentos')->insert([
            'id_empleado'       => (int)$id,
            'tipo'              => $tipo,
            'estado'            => 'aprobado',
            'archivo'           => (int)$id . '/' . $nombre,
            'nombre_original'   => $original,
            'mime'              => $mime,
            'tamano'            => $tamano,
            'clabe_detectada'   => strlen($clabe) === 18 ? $clabe : null,
            'titular_detectado' => mb_substr(trim((string)($this->request->getVar('titular_detectado') ?? '')), 0, 160) ?: null,
            'banco_detectado'   => mb_substr(trim((string)($this->request->getVar('banco_detectado') ?? '')), 0, 100) ?: null,
            'coincide_titular'  => $this->request->getVar('coincide_titular') === null ? null
                                    : (int)filter_var($this->request->getVar('coincide_titular'), FILTER_VALIDATE_BOOLEAN),
            'created_by'        => (int)$actor->id,
        ]);
        $docId = $db->insertID();

        AuditLibrary::log((int)$actor->id, 'SUBIR_DOCUMENTO_EXPEDIENTE', 'empleados', (string)$id, "Documento {$tipo}: {$original}");

        return $this->respond(['status' => 'ok', 'message' => 'Documento guardado en el expediente', 'data' => ['id' => $docId]], 201);
    }

    public function archivo($docId = null): mixed
    {
        if ($d = $this->soloAdmin()) return $d;
        $db  = \Config\Database::connect();
        $doc = $db->query('SELECT * FROM empleado_documentos WHERE id = ? AND is_active = 1', [(int)$docId])->getRowArray();
        if (!$doc) {
            return $this->respond(['status' => 'error', 'message' => 'Documento no encontrado'], 404);
        }
        $ruta = realpath($this->base() . $doc['archivo']);
        $raiz = realpath($this->base());
        if (!$ruta || !$raiz || strpos($ruta, $raiz) !== 0 || !is_file($ruta)) {
            return $this->respond(['status' => 'error', 'message' => 'Archivo no disponible'], 404);
        }

        AuditLibrary::log((int)$this->request->jwtUser->id, 'VER_DOCUMENTO_EXPEDIENTE', 'empleados', (string)$doc['id_empleado'], $doc['nombre_original']);

        return $this->response
            ->setHeader('Content-Type', $doc['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . str_replace('"', '', $doc['nombre_original']) . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody(file_get_contents($ruta));
    }

    public function eliminar($docId = null): mixed
    {
        if ($d = $this->soloAdmin()) return $d;
        $db = \Config\Database::connect();
        $doc = $db->query('SELECT id, id_empleado, nombre_original FROM empleado_documentos WHERE id = ? AND is_active = 1', [(int)$docId])->getRowArray();
        if (!$doc) {
            return $this->respond(['status' => 'error', 'message' => 'Documento no encontrado'], 404);
        }
        // Baja lógica: el archivo se conserva como evidencia
        $db->table('empleado_documentos')->where('id', (int)$docId)->update(['is_active' => 0]);
        AuditLibrary::log((int)$this->request->jwtUser->id, 'ELIMINAR_DOCUMENTO_EXPEDIENTE', 'empleados', (string)$doc['id_empleado'], $doc['nombre_original']);
        return $this->respond(['status' => 'ok', 'message' => 'Documento eliminado del expediente']);
    }

    public function aprobar($docId = null): mixed
    {
        return $this->revisar((int)$docId, 'aprobado', null);
    }

    public function rechazar($docId = null): mixed
    {
        $motivo = mb_substr(trim((string)($this->request->getVar('motivo') ?? '')), 0, 250);
        return $this->revisar((int)$docId, 'rechazado', $motivo ?: null);
    }

    private function revisar(int $docId, string $estado, ?string $motivo): mixed
    {
        if ($d = $this->soloAdmin()) return $d;
        $db  = \Config\Database::connect();
        $doc = $db->query('SELECT id, id_empleado, nombre_original FROM empleado_documentos WHERE id = ? AND is_active = 1', [$docId])->getRowArray();
        if (!$doc) {
            return $this->respond(['status' => 'error', 'message' => 'Documento no encontrado'], 404);
        }
        $actor = $this->request->jwtUser;
        $db->table('empleado_documentos')->where('id', $docId)->update([
            'estado' => $estado, 'motivo_rechazo' => $motivo,
            'revisado_por' => (int)$actor->id, 'revisado_at' => date('Y-m-d H:i:s'),
        ]);
        AuditLibrary::log((int)$actor->id, $estado === 'aprobado' ? 'APROBAR_DOCUMENTO_EXPEDIENTE' : 'RECHAZAR_DOCUMENTO_EXPEDIENTE', 'empleados', (string)$doc['id_empleado'], $doc['nombre_original']);
        return $this->respond(['status' => 'ok', 'message' => $estado === 'aprobado' ? 'Documento aprobado' : 'Documento rechazado']);
    }
}
