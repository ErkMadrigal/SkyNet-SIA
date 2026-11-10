<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Alcance por zona/cliente de un usuario del panel (solo administradores).
 *   GET  usuarios/(id)/alcance   -> { zonas:[ids], clientes:[ids], opciones:{zonas, clientes} }
 *   POST usuarios/(id)/alcance   -> body { zonas:[ids], clientes:[ids] }  (vacío = sin restricción)
 */
class AlcanceController extends ResourceController
{
    protected $format = 'json';

    public function ver($id = null)
    {
        $db = \Config\Database::connect();
        try {
            $rows = $db->query('SELECT tipo, id_ref FROM usuario_alcance WHERE id_usuario = ?', [(int)$id])->getResultArray();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }
        $z = $c = [];
        foreach ($rows as $r) { if ($r['tipo'] === 'zona') $z[] = (int)$r['id_ref']; else $c[] = (int)$r['id_ref']; }

        return $this->respond(['status' => 'ok', 'data' => [
            'zonas'    => $z,
            'clientes' => $c,
            'opciones' => [
                'zonas'    => $db->query('SELECT id, zona AS nombre FROM zonas ORDER BY zona')->getResultArray(),
                'clientes' => $db->query('SELECT id, nombre_corto AS nombre FROM clientes ORDER BY nombre_corto')->getResultArray(),
            ],
        ]]);
    }

    public function guardar($id = null)
    {
        $actor = $this->request->jwtUser;
        $in    = $this->request->getJSON(true) ?? [];
        $z = array_values(array_unique(array_filter(array_map('intval', (array)($in['zonas'] ?? [])), fn($x) => $x > 0)));
        $c = array_values(array_unique(array_filter(array_map('intval', (array)($in['clientes'] ?? [])), fn($x) => $x > 0)));
        $id = (int)$id;

        $db = \Config\Database::connect();
        try {
            $db->transStart();
            $db->query('DELETE FROM usuario_alcance WHERE id_usuario = ?', [$id]);
            foreach ($z as $x) $db->query('INSERT INTO usuario_alcance (id_usuario, tipo, id_ref) VALUES (?, "zona", ?)', [$id, $x]);
            foreach ($c as $x) $db->query('INSERT INTO usuario_alcance (id_usuario, tipo, id_ref) VALUES (?, "cliente", ?)', [$id, $x]);
            $db->transComplete();
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_paquetes_dispositivos.sql'], 500);
        }

        AuditLibrary::log((int)($actor->id ?? 0), 'ALCANCE_USUARIO', 'usuario', (string)$id,
            'Alcance: ' . (($z || $c) ? count($z) . ' zonas, ' . count($c) . ' clientes' : 'sin restricción'));
        return $this->respond(['status' => 'ok']);
    }
}
