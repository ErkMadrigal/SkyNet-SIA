<?php

namespace App\Models;

use CodeIgniter\Model;

class JwtTokenModel extends Model
{
    protected $table      = 'jwt_tokens';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_usuario', 'jti', 'refresh_token',
        'ip', 'user_agent', 'expires_at',
        'revocado', 'revocado_en',
    ];

    protected $useTimestamps = false;

    /**
     * Revoca un token por su JTI.
     */
    public function revocar(string $jti): bool
    {
        return $this->where('jti', $jti)
                    ->set([
                        'revocado'    => 1,
                        'revocado_en' => date('Y-m-d H:i:s'),
                    ])
                    ->update();
    }

    /**
     * Limpia tokens expirados o revocados (para un job programado).
     */
    public function limpiarExpirados(): int
    {
        return $this->db->table($this->table)
                        ->groupStart()
                            ->where('expires_at <', date('Y-m-d H:i:s'))
                            ->orWhere('revocado', 1)
                        ->groupEnd()
                        ->delete();
    }
}
