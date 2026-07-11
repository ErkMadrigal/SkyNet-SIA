<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * ImportacionesController
 *
 * Historial de cargas masivas -- una fila por CADA carga completa (no por
 * lote individual). El frontend llama a registrar() UNA vez al terminar
 * todos los lotes de un archivo, con los totales ya acumulados.
 *
 * Rutas (prefijo /api/v1/importaciones):
 *   POST /historial  -> registra una carga completa
 *   GET  /historial   -> lista las últimas cargas (para la tabla de la vista)
 */
class ImportacionesController extends ResourceController
{
    protected $format = 'json';

    /**
     * POST /api/v1/importaciones/historial
     * Body: { tipo, tabla_destino, archivo, total, insertados, duplicados,
     *         errores, validate_only, ok }
     */
    public function registrarHistorial(): mixed
    {
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $tipo         = trim((string)($this->request->getVar('tipo') ?? ''));
        $tablaDestino = trim((string)($this->request->getVar('tabla_destino') ?? ''));

        if ($tipo === '' || $tablaDestino === '') {
            return $this->respond(['status' => 'error', 'message' => "Faltan 'tipo' o 'tabla_destino'"], 400);
        }

        $db->table('importaciones_historial')->insert([
            'tipo'          => $tipo,
            'tabla_destino' => $tablaDestino,
            'archivo'       => trim((string)($this->request->getVar('archivo') ?? '')) ?: null,
            'total'         => (int)($this->request->getVar('total')      ?? 0),
            'insertados'    => (int)($this->request->getVar('insertados') ?? 0),
            'duplicados'    => (int)($this->request->getVar('duplicados') ?? 0),
            'errores'       => (int)($this->request->getVar('errores')    ?? 0),
            'validate_only' => (bool)($this->request->getVar('validate_only') ?? false) ? 1 : 0,
            'ok'            => (bool)($this->request->getVar('ok') ?? true) ? 1 : 0,
            'created_by'    => (int)$actor->id,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return $this->respond(['status' => 'ok', 'id' => $db->insertID()], 201);
    }

    /**
     * GET /api/v1/importaciones/historial?limit=50
     * Últimas cargas, con el nombre del usuario que las hizo.
     */
    public function listadoHistorial(): mixed
    {
        $db    = \Config\Database::connect();
        $limit = (int)($this->request->getVar('limit') ?? 50);
        if ($limit <= 0 || $limit > 200) $limit = 50;

        $rows = $db->query("
            SELECT
                h.id, h.tipo, h.tabla_destino, h.archivo,
                h.total, h.insertados, h.duplicados, h.errores,
                h.validate_only, h.ok, h.created_at,
                CONCAT_WS(' ', u.name_user) AS usuario_nombre
            FROM importaciones_historial h
            LEFT JOIN usuario u ON u.id = h.created_by
            ORDER BY h.created_at DESC
            LIMIT ?
        ", [$limit])->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }
}