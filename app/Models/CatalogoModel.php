<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * CatalogoModel
 *
 * Migración de ConsultasCatalogos + ControllerCatalogos legacy.
 * Maneja multicatalogo, catálogo, empresas, partidas, zonas,
 * regiones, áreas geográficas, clientes y servicios.
 */
class CatalogoModel extends Model
{
    protected $returnType = 'array';
    protected $useTimestamps = false;

    /* ─────────────────────────────────────────
       HELPERS
    ───────────────────────────────────────── */

    private function ok(array $data = [], array $extra = []): array
    {
        return array_merge(['status' => 'ok', 'data' => $data], $extra);
    }

    private function fail(string $msg): array
    {
        return ['status' => 'error', 'data' => [], 'mensaje' => $msg];
    }

    /* ═══════════════════════════════════════════════════════════════
       MULTICATÁLOGO
    ═══════════════════════════════════════════════════════════════ */

    /** Obtiene ítems de un catálogo por id. */
    public function getCatalogo(int $idCatalogo): array
    {
        try {
            $rows = $this->db->query("
                SELECT m.id, m.valor, m.descripcion, m.status, c.descripcion AS catalogo
                FROM multicatalogo m
                LEFT JOIN catalogo c ON m.id_catalogo = c.id
                WHERE c.id = ?
            ", [$idCatalogo])->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Obtiene ítems de un catálogo filtrando por nombre (LIKE). */
    public function getCatalogoName(int $idCatalogo, string $name): array
    {
        try {
            $rows = $this->db->query("
                SELECT m.id, m.valor, m.descripcion, c.descripcion AS catalogo
                FROM multicatalogo m
                LEFT JOIN catalogo c ON m.id_catalogo = c.id
                WHERE c.id = ? AND m.valor LIKE ?
            ", [$idCatalogo, '%'.$name.'%'])->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Busca institución bancaria por clave CLABE (3 dígitos del banco). */
    public function getInstitucionBancaria(string $clabe): array
    {
        try {
            $row = $this->db->query("
                SELECT m.id, m.valor, m.descripcion, c.descripcion AS catalogo
                FROM multicatalogo m
                LEFT JOIN catalogo c ON m.id_catalogo = c.id
                WHERE c.id = 15 AND m.descripcion = ?
            ", [$clabe])->getRowArray();
            return ['status' => 'ok', 'data' => $row ?: []];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Lista todos los tipos de catálogo (tabla `catalogo`). */
    public function getTipoCatalogos(): array
    {
        try {
            $rows = $this->db->table('catalogo')->get()->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Ítems de un catálogo incluyendo su status (para admin de catálogos). */
    public function getCatalogosSelect(int $idCatalogo): array
    {
        try {
            $rows = $this->db->query("
                SELECT m.id, m.valor, m.descripcion, c.descripcion AS catalogo, m.status
                FROM multicatalogo m
                LEFT JOIN catalogo c ON c.id = m.id_catalogo
                WHERE c.id = ?
            ", [$idCatalogo])->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Crea un nuevo tipo de catálogo (tabla `catalogo`). */
    public function newCatalogo(string $descripcion): array
    {
        try {
            $this->db->table('catalogo')->insert(['descripcion' => $descripcion]);
            return ['status' => 'ok', 'mensaje' => 'Registro exitoso', 'last_insert_id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Crea un ítem en multicatálogo. */
    public function newMultiCatalogo(int $idCatalogo, string $valor, string $descripcion): array
    {
        try {
            $this->db->table('multicatalogo')->insert([
                'id_catalogo' => $idCatalogo,
                'valor'       => $valor,
                'descripcion' => $descripcion,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Registro exitoso', 'last_insert_id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Actualiza un ítem del multicatálogo. */
    public function updateMultiCatalogo(int $id, int $status, string $valor, string $descripcion): array
    {
        try {
            $this->db->table('multicatalogo')->where('id', $id)->update([
                'status'      => $status,
                'valor'       => $valor,
                'descripcion' => $descripcion,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Dato actualizado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Soft-delete de un ítem del multicatálogo (status=0). */
    public function deleteMultiCatalogo(int $id): array
    {
        try {
            $this->db->table('multicatalogo')->where('id', $id)->update(['status' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Dato eliminado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       REGIONALES
    ═══════════════════════════════════════════════════════════════ */

    /** Lista empleados asignados como regionales. */
    public function getRegionales(): array
    {
        try {
            $rows = $this->db->query("
                SELECT e.id, CONCAT(e.nombre,' ',e.paterno,' ',e.materno) AS valor
                FROM regional r
                LEFT JOIN empleados e ON r.id_empleado = e.id
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       EMPRESAS
    ═══════════════════════════════════════════════════════════════ */

    public function selectEmpresas(): array
    {
        try {
            return $this->ok($this->db->table('empresas')->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertEmpresa(string $empresa): array
    {
        try {
            $this->db->table('empresas')->insert(['empresa' => $empresa, 'estatus' => 1]);
            return ['status' => 'ok', 'mensaje' => 'Empresa creada correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateEmpresa(int $id, string $empresa, int $estatus): array
    {
        try {
            $this->db->table('empresas')->where('id', $id)->update(['empresa' => $empresa, 'estatus' => $estatus]);
            return ['status' => 'ok', 'mensaje' => 'Empresa actualizada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deleteEmpresa(int $id): array
    {
        try {
            $this->db->table('empresas')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Empresa eliminada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       PARTIDAS
    ═══════════════════════════════════════════════════════════════ */

    public function selectPartidas(): array
    {
        try {
            return $this->ok($this->db->table('partidas')->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertPartida(string $partida): array
    {
        try {
            $this->db->table('partidas')->insert(['partida' => $partida, 'estatus' => 1]);
            return ['status' => 'ok', 'mensaje' => 'Partida creada correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updatePartida(int $id, string $partida, int $estatus): array
    {
        try {
            $this->db->table('partidas')->where('id', $id)->update(['partida' => $partida, 'estatus' => $estatus]);
            return ['status' => 'ok', 'mensaje' => 'Partida actualizada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deletePartida(int $id): array
    {
        try {
            $this->db->table('partidas')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Partida eliminada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       ZONAS
    ═══════════════════════════════════════════════════════════════ */

    public function selectZonas(): array
    {
        try {
            return $this->ok($this->db->table('zonas')->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertZona(string $zona): array
    {
        try {
            $this->db->table('zonas')->insert(['zona' => $zona, 'estatus' => 1]);
            return ['status' => 'ok', 'mensaje' => 'Zona creada correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateZona(int $id, string $zona, int $estatus): array
    {
        try {
            $this->db->table('zonas')->where('id', $id)->update(['zona' => $zona, 'estatus' => $estatus]);
            return ['status' => 'ok', 'mensaje' => 'Zona actualizada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deleteZona(int $id): array
    {
        try {
            $this->db->table('zonas')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Zona eliminada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       REGIONES
    ═══════════════════════════════════════════════════════════════ */

    public function selectRegiones(): array
    {
        try {
            $rows = $this->db->query("
                SELECT r.id, ag.id AS id_region, r.estado, r.estatus, ag.region
                FROM regiones r
                LEFT JOIN areas_geograficas ag ON r.id_area_geografica = ag.id
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertRegion(string $estado, int $idAreaGeografica): array
    {
        try {
            $this->db->table('regiones')->insert([
                'estado'             => $estado,
                'id_area_geografica' => $idAreaGeografica,
                'estatus'            => 1,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Región creada correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateRegion(int $id, string $estado, int $idAreaGeografica, int $estatus): array
    {
        try {
            $this->db->table('regiones')->where('id', $id)->update([
                'estado'             => $estado,
                'id_area_geografica' => $idAreaGeografica,
                'estatus'            => $estatus,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Región actualizada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deleteRegion(int $id): array
    {
        try {
            $this->db->table('regiones')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Región eliminada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       ÁREAS GEOGRÁFICAS
    ═══════════════════════════════════════════════════════════════ */

    public function selectAreaGeografica(): array
    {
        try {
            $rows = $this->db->query("
                SELECT ag.*, CONCAT_WS(' ',TRIM(e.nombre),TRIM(e.paterno),TRIM(e.materno)) AS nombre
                FROM areas_geograficas ag
                LEFT JOIN empleados e ON ag.id_regional = e.id
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateAreaGeografica(int $id, int $idRegional, string $region, int $estatus): array
    {
        try {
            $this->db->table('areas_geograficas')->where('id', $id)->update([
                'region'      => $region,
                'id_regional' => $idRegional,
                'estatus'     => $estatus,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Región actualizada correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Empleados con flag gerente=1 (para asignar como regionales). */
    public function selectRegionalesGerentes(): array
    {
        try {
            $rows = $this->db->query("
                SELECT id, CONCAT_WS(' ',TRIM(nombre),TRIM(paterno),TRIM(materno)) AS nombre
                FROM empleados WHERE gerente = 1
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       CLIENTES
    ═══════════════════════════════════════════════════════════════ */

    public function selectClientes(): array
    {
        try {
            $rows = $this->db->query("
                SELECT c.*, e.empresa, p.partida
                FROM clientes c
                LEFT JOIN empresas e ON e.id = c.id_empresa
                LEFT JOIN partidas p ON p.id = c.id_partida
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertCliente(string $razonSocial, string $nombreCorto, int $idEmpresa, int $idPartida): array
    {
        try {
            $this->db->table('clientes')->insert([
                'razon_social' => $razonSocial,
                'nombre_corto' => $nombreCorto,
                'id_empresa'   => $idEmpresa,
                'id_partida'   => $idPartida,
                'estatus'      => 1,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Cliente creado correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateCliente(int $id, string $razonSocial, string $nombreCorto, int $idEmpresa, int $idPartida, int $estatus): array
    {
        try {
            $this->db->table('clientes')->where('id', $id)->update([
                'razon_social' => $razonSocial,
                'nombre_corto' => $nombreCorto,
                'id_empresa'   => $idEmpresa,
                'id_partida'   => $idPartida,
                'estatus'      => $estatus,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Cliente actualizado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deleteCliente(int $id): array
    {
        try {
            $this->db->table('clientes')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Cliente eliminado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ═══════════════════════════════════════════════════════════════
       SERVICIOS
    ═══════════════════════════════════════════════════════════════ */

    public function selectServicios(): array
    {
        try {
            $rows = $this->db->query("
                SELECT s.*, c.nombre_corto, e.empresa, p.partida, z.zona,
                    cp.CP, cp.Asentamiento, cp.Municipio, cp.Estado, cp.Ciudad,
                    r.estado, ag.region,
                    CONCAT_WS(' ',TRIM(em.nombre),TRIM(em.paterno),TRIM(em.materno)) AS nombre
                FROM servicios s
                LEFT JOIN clientes c         ON c.id  = s.id_cliente
                LEFT JOIN empresas e         ON e.id  = s.id_empresa
                LEFT JOIN partidas p         ON p.id  = s.id_partida
                LEFT JOIN zonas z            ON z.id  = s.id_zona
                LEFT JOIN codigos_postales cp ON s.cp = cp.CP
                LEFT JOIN areas_geograficas ag ON cp.region = ag.id
                LEFT JOIN regiones r         ON ag.id = r.id_area_geografica
                LEFT JOIN empleados em       ON ag.id_regional = em.id
                GROUP BY s.id
            ")->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function insertServicio(array $d): array
    {
        try {
            $elementos  = ($d['elementos']  === '' || $d['elementos']  === null) ? null : (int)$d['elementos'];
            $idPartida  = ($d['id_partida'] === '' || $d['id_partida'] === null) ? null : (int)$d['id_partida'];
            $idZona     = ($d['id_zona']    === '' || $d['id_zona']    === null) ? null : (int)$d['id_zona'];

            $this->db->table('servicios')->insert([
                'servicio'   => $d['servicio'],
                'elementos'  => $elementos,
                'ubicacion'  => $d['ubicacion'],
                'cp'         => $d['cp'],
                'latitud'    => $d['latitud'],
                'longitud'   => $d['longitud'],
                'id_cliente' => (int)$d['id_cliente'],
                'id_empresa' => (int)$d['id_empresa'],
                'id_partida' => $idPartida,
                'id_zona'    => $idZona,
                'estatus'    => 1,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Servicio creado correctamente', 'id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function updateServicio(int $id, array $d): array
    {
        try {
            $elementos  = ($d['elementos']  === '' || $d['elementos']  === null) ? null : (int)$d['elementos'];
            $idPartida  = ($d['id_partida'] === '' || $d['id_partida'] === null) ? null : (int)$d['id_partida'];
            $idZona     = ($d['id_zona']    === '' || $d['id_zona']    === null) ? null : (int)$d['id_zona'];

            $this->db->table('servicios')->where('id', $id)->update([
                'servicio'   => $d['servicio'],
                'elementos'  => $elementos,
                'ubicacion'  => $d['ubicacion'],
                'cp'         => $d['cp'],
                'latitud'    => $d['latitud'],
                'longitud'   => $d['longitud'],
                'id_cliente' => (int)$d['id_cliente'],
                'id_empresa' => (int)$d['id_empresa'],
                'id_partida' => $idPartida,
                'id_zona'    => $idZona,
                'estatus'    => (int)($d['status'] ?? 1),
            ]);
            return ['status' => 'ok', 'mensaje' => 'Servicio actualizado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function deleteServicio(int $id): array
    {
        try {
            $this->db->table('servicios')->where('id', $id)->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Servicio eliminado correctamente'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /**
     * Búsqueda paginada de servicios para selects del frontend.
     * Devuelve id + texto concatenado (servicio | ubicación | cliente).
     */
    public function getServiciosSelect(string $query, int $limit = 20, int $page = 1): array
    {
        if (mb_strlen($query) < 2) return $this->ok([]);

        try {
            $limit  = max(1, min(100, $limit));
            $offset = ($page - 1) * $limit;
            $like   = '%' . $query . '%';

            $rows = $this->db->query("
                SELECT s.id,
                    CONCAT(s.servicio, ' | ', IFNULL(s.ubicacion,''), ' | ', IFNULL(c.nombre_corto,'')) AS text
                FROM servicios s
                LEFT JOIN clientes c ON s.id_cliente = c.id
                WHERE s.servicio LIKE ? OR s.ubicacion LIKE ? OR c.nombre_corto LIKE ?
                ORDER BY s.servicio
                LIMIT ? OFFSET ?
            ", [$like, $like, $like, $limit, $offset])->getResultArray();

            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
