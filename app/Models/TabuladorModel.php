<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TabuladorModel
 *
 * Migración de TabuladorSalarios.php legacy.
 * Maneja el tabulador de salarios por zona y puesto.
 */
class TabuladorModel extends Model
{
    protected $table      = 'tabulador_salarios';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = ['id_zona','nombre','vigencia_inicio','vigencia_fin','estatus'];
    protected $useTimestamps = false;

    public function getZonas(): array
    {
        $rows = $this->db->table('zonas')->where('estatus', 1)->orderBy('zona')->get()->getResultArray();
        return ['status' => 'ok', 'data' => $rows];
    }

    public function getPuestos(): array
    {
        // Puestos del multicatalogo que aplican a tabulador (ajustar id_catalogo según tu BD)
        $rows = $this->db->table('multicatalogo')->where('status', 1)->orderBy('descripcion')->get()->getResultArray();
        return ['status' => 'ok', 'data' => $rows];
    }

    public function listar(): array
    {
        $rows = $this->db->query("
            SELECT t.*, z.zona
            FROM tabulador_salarios t
            LEFT JOIN zonas z ON z.id = t.id_zona
            ORDER BY t.id DESC
        ")->getResultArray();
        return ['status' => 'ok', 'data' => $rows];
    }

    public function crear(int $idZona, string $nombre, string $vigenciaInicio, ?string $vigenciaFin): array
    {
        try {
            $id = $this->insert([
                'id_zona'         => $idZona,
                'nombre'          => $nombre,
                'vigencia_inicio' => $vigenciaInicio,
                'vigencia_fin'    => $vigenciaFin,
                'estatus'         => 1,
            ], true);
            return ['status' => 'ok', 'mensaje' => 'Tabulador creado', 'id' => $id];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    public function getDetalle(int $id): array
    {
        $tabulador = $this->find($id);
        if (!$tabulador) return ['status' => 'error', 'mensaje' => 'Tabulador no encontrado'];

        $detalle = $this->db->query("
            SELECT tsd.*, mc.descripcion AS puesto
            FROM tabulador_salarios_detalle tsd
            LEFT JOIN multicatalogo mc ON mc.id = tsd.id_puesto
            WHERE tsd.id_tabulador = ? AND tsd.estatus = 1
            ORDER BY mc.descripcion
        ", [$id])->getResultArray();

        return ['status' => 'ok', 'data' => array_merge($tabulador, ['detalle' => $detalle])];
    }

    /**
     * Inserta o actualiza un ítem del tabulador (upsert por id_tabulador + id_puesto).
     */
    public function upsertItem(int $idTabulador, int $idPuesto, float $sueldo, float $bono, float $descuento): array
    {
        try {
            $existe = $this->db->table('tabulador_salarios_detalle')
                ->where('id_tabulador', $idTabulador)
                ->where('id_puesto', $idPuesto)
                ->get()->getRowArray();

            if ($existe) {
                $this->db->table('tabulador_salarios_detalle')
                    ->where('id', $existe['id'])
                    ->update([
                        'sueldo'    => $sueldo,
                        'bono'      => $bono,
                        'descuento' => $descuento,
                        'estatus'   => 1,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                return ['status' => 'ok', 'mensaje' => 'Ítem actualizado', 'id' => $existe['id']];
            }

            $id = $this->db->table('tabulador_salarios_detalle')->insert([
                'id_tabulador' => $idTabulador,
                'id_puesto'    => $idPuesto,
                'sueldo'       => $sueldo,
                'bono'         => $bono,
                'descuento'    => $descuento,
                'estatus'      => 1,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Ítem creado', 'id' => $this->db->insertID()];

        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    public function deshabilitarItem(int $idItem): array
    {
        try {
            $this->db->table('tabulador_salarios_detalle')
                ->where('id', $idItem)
                ->update(['estatus' => 0]);
            return ['status' => 'ok', 'mensaje' => 'Ítem deshabilitado'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }

    public function setEstatus(int $id, int $estatus): array
    {
        try {
            $this->update($id, ['estatus' => $estatus]);
            return ['status' => 'ok', 'mensaje' => 'Estatus actualizado'];
        } catch (\Exception $e) {
            return ['status' => 'error', 'mensaje' => $e->getMessage()];
        }
    }
}
