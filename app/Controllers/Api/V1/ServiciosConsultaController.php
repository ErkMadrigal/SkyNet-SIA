<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Consulta de servicios/ubicaciones y coordenadas (solo lectura, header X-API-KEY = CONSULTA_API_KEY).
 *
 *   GET servicios/coordenadas?zona=3            servicios de una zona (por id)
 *   GET servicios/coordenadas?zona=TAMPICO      ... o por nombre (parcial)
 *   GET servicios/coordenadas?q=CICATA          búsqueda por nombre del servicio
 *   GET servicios/coordenadas?zona=3&solo_con_coordenadas=1
 *   GET servicios/zonas                          todos los servicios con zona, coordenadas, cliente y empresa
 *
 * Opcionales: limit (hasta 10000; 500 en coordenadas, 5000 en zonas), todos=1 (incluye servicios inactivos).
 */
class ServiciosConsultaController extends ResourceController
{
    protected $format = 'json';

    public function coordenadas() { return $this->listar(true, 500); }

    /** Todos los servicios con sus datos (opcionalmente filtrados con zona / q / solo_con_coordenadas). */
    public function zonas() { return $this->listar(false, 5000); }

    private function listar(bool $exigirFiltro, int $limitDefault)
    {
        $zona  = trim(preg_replace('/[\s_]+/', ' ', (string)($this->request->getGet('zona') ?? '')));  // acepta MEXIBUS_LINEA_3, MEXIBUS+LINEA+3 o %20
        $q     = trim((string)($this->request->getGet('q') ?? ''));
        $limit = max(1, min(10000, (int)($this->request->getGet('limit') ?? $limitDefault)));
        if ($exigirFiltro && $zona === '' && $q === '') {
            return $this->respond(['status' => 'error', 'message' => 'Manda zona (id o nombre) o q (nombre del servicio)'], 422);
        }

        $where = []; $par = [];
        if ($zona !== '') {
            if (ctype_digit($zona)) { $where[] = 's.id_zona = ?'; $par[] = (int)$zona; }
            else { $where[] = 'z.zona LIKE ?'; $par[] = '%' . $zona . '%'; }
        }
        if ($q !== '') { $where[] = 's.servicio LIKE ?'; $par[] = $q . '%'; }
        if ($this->request->getGet('todos') !== '1') $where[] = 's.estatus = 1';
        if ($this->request->getGet('solo_con_coordenadas') === '1') {
            $where[] = "s.latitud IS NOT NULL AND s.longitud IS NOT NULL AND s.latitud <> '' AND s.longitud <> ''";
        }

        $sql = "SELECT s.id, s.servicio, s.latitud, s.longitud, z.zona,
                       c.nombre_corto, c.razon_social, e.empresa
                FROM servicios s
                LEFT JOIN zonas z    ON z.id = s.id_zona
                LEFT JOIN clientes c ON c.id = s.id_cliente
                LEFT JOIN empresas e ON e.id = s.id_empresa
                " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
                ORDER BY z.zona, s.servicio LIMIT {$limit}";

        try {
            $rows = \Config\Database::connect()->query($sql, $par)->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Consulta servicios: ' . $e->getMessage());
            return $this->respond(['status' => 'error', 'message' => 'No se pudo consultar'], 500);
        }

        $data = array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'servicio'     => $r['servicio'],
            'latitud'      => $r['latitud'] !== null && $r['latitud'] !== '' ? (float)$r['latitud'] : null,
            'longitud'     => $r['longitud'] !== null && $r['longitud'] !== '' ? (float)$r['longitud'] : null,
            'zona'         => $r['zona'],
            'nombre_corto' => $r['nombre_corto'],
            'razon_social' => $r['razon_social'],
            'empresa'      => $r['empresa'],
        ], $rows);

        return $this->respond(['status' => 'ok', 'total' => count($data), 'data' => $data]);
    }
}
