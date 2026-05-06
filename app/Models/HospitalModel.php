<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * HospitalModel
 * Migración de ConsultasHospitals.php legacy.
 * Maneja hospitales, productos, recepciones, salidas e inventario.
 */
class HospitalModel extends Model
{
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    private function ok(array $data = [], array $extra = []): array
    {
        return array_merge(['status' => 'ok', 'data' => $data], $extra);
    }

    private function fail(string $msg): array
    {
        return ['status' => 'error', 'data' => [], 'mensaje' => $msg];
    }

    /* ── HOSPITALES ──────────────────────────────────────── */

    public function listarHospitales(): array
    {
        try {
            $rows = $this->db->table('hospitales')->orderBy('unidad_medica', 'ASC')->get()->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function getHospital(int $id): array
    {
        try {
            $row = $this->db->table('hospitales')->where('id', $id)->get()->getRowArray();
            return $row ? $this->ok($row) : $this->fail('Hospital no encontrado');
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function actualizarHospitalEmpleado(int $idEmpleado, int $idHospital): array
    {
        try {
            $this->db->table('empleados')->where('id', $idEmpleado)->update(['id_hospital' => $idHospital]);
            return ['status' => 'ok', 'mensaje' => 'Hospital actualizado'];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ── PRODUCTOS ───────────────────────────────────────── */

    public function listarProductos(): array
    {
        try {
            $rows = $this->db->table('producto')->where('activo', 1)->orderBy('nombre_base', 'ASC')->get()->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function getProducto(int $id): array
    {
        try {
            $row = $this->db->table('producto')->where('id', $id)->where('activo', 1)->get()->getRowArray();
            return $row ? $this->ok($row) : $this->fail('Producto no encontrado');
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ── RECEPCIONES (ENTRADAS) ──────────────────────────── */

    public function listarRecepciones(int $idHospital = 0): array
    {
        try {
            $builder = $this->db->table('recepcion r')
                ->select('r.*, p.nombre_base, p.codigo, CONCAT(e.nombre," ",e.paterno) AS empleado, r.id_hospital')
                ->join('producto p',  'p.id = r.id_producto')
                ->join('empleados e', 'e.id = r.id_empleado')
                ->orderBy('r.fecha_ingreso', 'DESC');

            if ($idHospital > 0) $builder->where('r.id_hospital', $idHospital);

            return $this->ok($builder->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function registrarRecepcion(int $idEmpleado, int $idProducto, float $cantidad, int $idHospital, string $observaciones = ''): array
    {
        try {
            $this->db->table('recepcion')->insert([
                'id_empleado'   => $idEmpleado,
                'id_producto'   => $idProducto,
                'cantidad'      => $cantidad,
                'id_hospital'   => $idHospital,
                'observaciones' => $observaciones,
                'fecha_ingreso' => date('Y-m-d H:i:s'),
            ]);
            return ['status' => 'ok', 'mensaje' => 'Entrada registrada correctamente', 'last_insert_id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ── SALIDAS ─────────────────────────────────────────── */

    public function listarSalidas(int $idHospital = 0): array
    {
        try {
            $builder = $this->db->table('salida s')
                ->select('s.*, p.nombre_base, p.codigo, CONCAT(e.nombre," ",e.paterno) AS empleado, s.id_hospital')
                ->join('producto p',  'p.id = s.id_producto')
                ->join('empleados e', 'e.id = s.id_empleado')
                ->orderBy('s.fecha_salida', 'DESC');

            if ($idHospital > 0) $builder->where('s.id_hospital', $idHospital);

            return $this->ok($builder->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function registrarSalida(int $idEmpleado, int $idProducto, float $cantidad, int $idHospital, string $motivo = ''): array
    {
        try {
            // Validar stock antes de registrar
            $stock = $this->getStockProducto($idProducto, $idHospital);
            if ($stock['status'] === 'ok' && (float)($stock['data']['stock_actual'] ?? 0) < $cantidad) {
                return ['status' => 'error', 'mensaje' => 'Stock insuficiente'];
            }

            $this->db->table('salida')->insert([
                'id_empleado' => $idEmpleado,
                'id_producto' => $idProducto,
                'cantidad'    => $cantidad,
                'id_hospital' => $idHospital,
                'motivo'      => $motivo,
                'fecha_salida' => date('Y-m-d H:i:s'),
            ]);
            return ['status' => 'ok', 'mensaje' => 'Salida registrada correctamente', 'last_insert_id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ── INVENTARIO ──────────────────────────────────────── */

    public function getInventario(int $idHospital = 0): array
    {
        try {
            $builder = $this->db->table('inventario')->orderBy('nombre_base', 'ASC');
            if ($idHospital > 0) $builder->where('id_hospital', $idHospital);
            return $this->ok($builder->get()->getResultArray());
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    public function getStockProducto(int $idProducto, int $idHospital = 0): array
    {
        try {
            $builder = $this->db->table('inventario')->where('id_producto', $idProducto);
            if ($idHospital > 0) $builder->where('id_hospital', $idHospital);
            $row = $builder->get()->getRowArray();
            return $this->ok($row ?: ['stock_actual' => 0]);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
