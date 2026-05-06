<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TokenQrModel
 * Maneja tokens_qr para el sistema de asistencia por QR.
 */
class TokenQrModel extends Model
{
    protected $table      = 'tokens_qr';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = ['token', 'id_empleado', 'creado_en', 'usado'];
    protected $useTimestamps = false;

    /**
     * Genera y guarda un nuevo token QR para un empleado.
     * El token es: {id_empleado}-{timestamp}
     */
    public function generarToken(int $idEmpleado): string
    {
        $token = $idEmpleado . '-' . time();

        $this->insert([
            'token'       => $token,
            'id_empleado' => $idEmpleado,
            'creado_en'   => date('Y-m-d H:i:s'),
            'usado'       => 0,
        ]);

        return $token;
    }

    /**
     * Valida y usa un token QR (válido 5 minutos, un solo uso).
     * Registra la asistencia al validar.
     */
    public function usarToken(string $token): array
    {
        $tokenData = $this->where('token', $token)
                          ->where('usado', 0)
                          ->where('NOW() <=', 'DATE_ADD(creado_en, INTERVAL 5 MINUTE)', false)
                          ->first();

        if (!$tokenData) {
            return ['status' => 'error', 'mensaje' => 'Token inválido o expirado'];
        }

        // Registrar asistencia
        $this->db->table('asistencias')->insert([
            'id_empleado' => $tokenData['id_empleado'],
            'id_token'    => $tokenData['id'],
            'fecha'       => date('Y-m-d'),
            'hora'        => date('H:i:s'),
            'id_status'   => 1,
        ]);

        // Marcar como usado
        $this->update($tokenData['id'], ['usado' => 1]);

        return ['status' => 'ok', 'mensaje' => 'Asistencia registrada', 'data' => $tokenData];
    }

    /**
     * Valida biométrico: verifica token sin consumirlo (para la app).
     */
    public function validarBiometrico(int $idEmpleado, string $token): bool
    {
        return (bool)$this->where('id_empleado', $idEmpleado)
                          ->where('token', $token)
                          ->where('usado', 0)
                          ->first();
    }

    /**
     * Marca un token biométrico como usado.
     */
    public function marcarUsado(int $idEmpleado, string $token): void
    {
        $this->where('id_empleado', $idEmpleado)
             ->where('token', $token)
             ->set('usado', 1)
             ->update();
    }
}
