<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use App\Models\JwtTokenModel;
use Ramsey\Uuid\Uuid;

/**
 * JwtLibrary
 * 
 * Maneja la generación, validación y revocación de tokens JWT
 * para el sistema SkyNet-SIA.
 *
 * Access token : corta vida (15 min), sin persistencia en BD
 * Refresh token: larga vida (7 días), persiste en jwt_tokens
 */
class JwtLibrary
{
    private string $secret;
    private string $algorithm = 'HS256';

    /** Duración del access token en segundos */
    private int $accessTtl  = 900;    // 15 minutos

    /** Duración del refresh token en segundos */
    private int $refreshTtl = 604800; // 7 días

    public function __construct()
    {
        $this->secret = env('JWT_SECRET', '');

        if (empty($this->secret)) {
            throw new \RuntimeException('JWT_SECRET no está configurado en .env');
        }

        $this->accessTtl  = (int) env('JWT_ACCESS_TTL',  3600);
        $this->refreshTtl = (int) env('JWT_REFRESH_TTL', 604800);
    }

    /* ─────────────────────────────────────────
       GENERACIÓN
    ───────────────────────────────────────── */

    /**
     * Genera el par access_token + refresh_token para un usuario autenticado.
     *
     * @param  array $usuario  Fila del modelo UsuarioModel
     * @param  array $empresas IDs de empresas que puede ver este usuario
     * @return array{access_token:string, refresh_token:string, expires_in:int, token_type:string}
     */
    public function generarPar(array $usuario, array $empresas = [], array $vistas = []): array
    {
        $jti = Uuid::uuid4()->toString();
        $now = time();

        // ── Obtener nivel real del rol ────────────────────────────────
        $db    = \Config\Database::connect();
        $rol = $db->query('SELECT nivel, slug FROM rol_sistema WHERE id = ?', [(int)$usuario['id_rol_sistema']])->getRowArray();
        $nivel = (int)($rol['nivel'] ?? 3);
        $slug  = $rol['slug'] ?? 'operador';

        // ── Access Token ──────────────────────────────────────────────
        $accessPayload = [
            'iss'  => env('APP_BASEURL', 'SkyNet-SIA'),
            'iat'  => $now,
            'exp'  => $now + $this->accessTtl,
            'jti'  => $jti,
            'sub'  => (int) $usuario['id'],
            'data' => [
                'id'              => (int) $usuario['id'],
                'name_user'       => $usuario['name_user'],
                'nombre'          => trim("{$usuario['nombre']} {$usuario['paterno']} {$usuario['materno']}"),
                'correo'          => $usuario['correo'],
                'rol'             => (int) $usuario['id_rol_sistema'],
                'rol_slug'        => $slug,
                'nivel'           => $nivel,
                'empresa_default' => $usuario['id_empresa_default'] ?? null,
                'empresas'        => $empresas,
                'vistas'    => $vistas,
            ],
        ];

        $accessToken = JWT::encode($accessPayload, $this->secret, $this->algorithm);

        // ── Refresh Token ─────────────────────────────────────────────
        $refreshPayload = [
            'iss'        => env('APP_BASEURL', 'SkyNet-SIA'),
            'iat'        => $now,
            'exp'        => $now + $this->refreshTtl,
            'jti'        => $jti,
            'sub'        => (int) $usuario['id'],
            'token_type' => 'refresh',
        ];

        $refreshToken = JWT::encode($refreshPayload, $this->secret, $this->algorithm);

        // ── Persistir refresh token en BD ─────────────────────────────
        $model = new JwtTokenModel();
        $model->insert([
            'id_usuario'    => (int) $usuario['id'],
            'jti'           => $jti,
            'refresh_token' => $refreshToken,
            'ip'            => service('request')->getIPAddress(),
            'user_agent'    => service('request')->getUserAgent()->getAgentString(),
            'expires_at'    => date('Y-m-d H:i:s', $now + $this->refreshTtl),
        ]);

        return [
            'token_type'    => 'Bearer',
            'access_token'  => $accessToken,
            'expires_in'    => $this->accessTtl,
            'refresh_token' => $refreshToken,
        ];
    }

    /* ─────────────────────────────────────────
       VALIDACIÓN
    ───────────────────────────────────────── */

    /**
     * Decodifica y valida un access token.
     *
     * @throws \RuntimeException con mensaje legible para el cliente
     */
    public function validarAccess(string $token): object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));

            if (($decoded->token_type ?? 'access') !== 'access'
                && isset($decoded->token_type)) {
                throw new \RuntimeException('Tipo de token inválido');
            }

            return $decoded;

        } catch (ExpiredException) {
            throw new \RuntimeException('Token expirado');
        } catch (SignatureInvalidException) {
            throw new \RuntimeException('Firma inválida');
        } catch (\Exception $e) {
            throw new \RuntimeException('Token inválido: ' . $e->getMessage());
        }
    }

    /**
     * Renueva el access token usando un refresh token válido.
     *
     * @return array El nuevo par de tokens
     * @throws \RuntimeException
     */
    public function renovar(string $refreshToken, array $empresas = []): array
    {
        // 1. Decodificar y verificar firma
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException) {
            throw new \RuntimeException('Refresh token expirado, inicia sesión nuevamente');
        } catch (\Exception) {
            throw new \RuntimeException('Refresh token inválido');
        }

        // 2. Verificar que sea tipo refresh
        if (($decoded->token_type ?? '') !== 'refresh') {
            throw new \RuntimeException('Token no es del tipo refresh');
        }

        // 3. Verificar en BD que no esté revocado
        $model  = new JwtTokenModel();
        $record = $model->where('jti', $decoded->jti)
                        ->where('revocado', 0)
                        ->where('expires_at >', date('Y-m-d H:i:s'))
                        ->first();

        if (! $record) {
            throw new \RuntimeException('Refresh token revocado o inválido');
        }

        // 4. Revocar el token anterior (rotación)
        $model->revocar($decoded->jti);

        // 5. Cargar datos frescos del usuario
        $usuarioModel = new \App\Models\UsuarioModel();
        $usuario = $usuarioModel->conRol()->find((int) $decoded->sub);

        if (! $usuario || $usuario['estatus'] != 1 || $usuario['is_deleted']) {
            throw new \RuntimeException('Usuario no disponible');
        }

        return $this->generarPar($usuario, $empresas);
    }

    /* ─────────────────────────────────────────
       REVOCACIÓN
    ───────────────────────────────────────── */

    /**
     * Revoca todos los refresh tokens de un usuario (logout de todas las sesiones).
     */
    public function revocarSesiones(int $idUsuario): void
    {
        $model = new JwtTokenModel();
        $model->where('id_usuario', $idUsuario)
              ->where('revocado', 0)
              ->set([
                  'revocado'    => 1,
                  'revocado_en' => date('Y-m-d H:i:s'),
              ])
              ->update();
    }

    /**
     * Revoca un único refresh token por JTI (logout de una sola sesión).
     */
    public function revocarPorJti(string $jti): void
    {
        $model = new JwtTokenModel();
        $model->revocar($jti);
    }

    /* ─────────────────────────────────────────
       UTILIDADES
    ───────────────────────────────────────── */

    /**
     * Extrae el Bearer token del header Authorization.
     */
    public static function extraerBearer(): ?string
    {
        $header = service('request')->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
