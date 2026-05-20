<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UsuarioModel;
use App\Models\JwtTokenModel;
use App\Libraries\JwtLibrary;
use App\Libraries\AuditLibrary;

/**
 * AuthController
 *
 * Equivalente CI4 del auth.php legacy.
 * Rutas base: /v1/auth
 *
 * POST /v1/auth/login          → login del sistema (usuarios con acceso web)
 * POST /v1/auth/refresh        → renovar access token con refresh token
 * POST /v1/auth/logout         → revocar sesión actual
 * POST /v1/auth/logout-all     → revocar TODAS las sesiones del usuario
 * GET  /v1/auth/me             → info del usuario autenticado (desde JWT)
 *
 * Biométrico (sin JWT del sistema, usa API Key):
 * POST /v1/auth/biometrico/buscar   → searchUser del legacy
 * POST /v1/auth/biometrico/login    → biometrico_login del legacy
 *
 * SuperAdmin:
 * POST /v1/auth/registro            → registrar usuario del sistema
 */
class AuthController extends ResourceController
{
    protected $format = 'json';

    /** Puestos permitidos para el acceso biométrico (del legacy) */
    private array $puestosPermitidos = [1364, 1365, 1381, 1383, 1366, 1384, 1385];

    /* ═══════════════════════════════════════════════════════════════
       SISTEMA WEB
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /v1/auth/login
     *
     * Body: { "name_user": "...", "password": "..." }
     */
    public function login(): mixed
    {
        $rules = [
            'name_user' => 'required|min_length[3]|max_length[100]',
            'password'  => 'required|min_length[6]',
        ];

        if (! $this->validate($rules)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Datos inválidos',
                'errors'  => $this->validator->getErrors(),
            ], 422);
        }

        $nameUser = $this->request->getVar('name_user');
        $password = $this->request->getVar('password');

        $model   = new UsuarioModel();
        $usuario = $model->autenticar($nameUser, $password);

        if (! $usuario) {
            // Log intento fallido
            AuditLibrary::log(0, 'LOGIN_FALLIDO', 'usuario', null, "Intento fallido para: {$nameUser}");

            return $this->respond([
                'status'  => 'error',
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        // Empresas asignadas (SuperAdmin ve todas, los demás solo las suyas)
        $empresas = [];
        if ((int)$usuario['nivel'] > 1) {
            $empresas = $model->empresasAsignadas((int)$usuario['id']);
        }

        // Vistas permitidas para el frontend
        $vistas = $model->vistasDelRol((int)$usuario['id_rol_sistema']);

        // Generar par de tokens
        $jwt    = new JwtLibrary();
        $tokens = $jwt->generarPar($usuario, $empresas);

        // Audit log
        AuditLibrary::log(
            (int)$usuario['id'],
            'LOGIN',
            'usuario',
            (string)$usuario['id'],
            'Inicio de sesión exitoso'
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => '¡Bienvenido, ' . $usuario['nombre'] . '!',
            'data'    => [
                'usuario' => [
                    'id'       => (int)$usuario['id'],
                    'nombre'   => trim("{$usuario['nombre']} {$usuario['paterno']} {$usuario['materno']}"),
                    'correo'   => $usuario['correo'],
                    'username' => $usuario['name_user'],
                    'rol'      => $usuario['rol_slug'],
                    'nivel'    => (int)$usuario['nivel'],
                    'empresas' => $empresas,
                    'vistas'   => $vistas,
                ],
                'tokens'  => $tokens,
            ],
        ], 200);
    }

    /**
     * POST /v1/auth/refresh
     *
     * Header: Authorization: Bearer <refresh_token>
     */
    public function refresh(): mixed
    {
        $refreshToken = JwtLibrary::extraerBearer();

        if (! $refreshToken) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Refresh token no proporcionado',
            ], 401);
        }

        try {
            $jwt    = new JwtLibrary();
            $tokens = $jwt->renovar($refreshToken);

            return $this->respond([
                'status' => 'ok',
                'data'   => $tokens,
            ], 200);

        } catch (\RuntimeException $e) {
            return $this->respond([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * POST /v1/auth/logout
     *
     * Revoca el refresh token de la sesión actual.
     * El access token expirará solo (vida corta, 15 min).
     *
     * Header: Authorization: Bearer <access_token>
     * Body:   { "refresh_token": "..." }  (opcional, para revocar exactamente este)
     */
    public function logout(): mixed
    {
        // El usuario ya fue verificado por JwtFilter
        $usuario = $this->request->jwtUser;

        $refreshToken = $this->request->getVar('refresh_token');

        $jwt = new JwtLibrary();

        if ($refreshToken) {
            // Revocar solo esta sesión
            try {
                $decoded = \Firebase\JWT\JWT::decode(
                    $refreshToken,
                    new \Firebase\JWT\Key(env('JWT_SECRET'), 'HS256')
                );
                $jwt->revocarPorJti($decoded->jti);
            } catch (\Exception) {
                // Token ya inválido, no importa
            }
        } else {
            // Revocar todas las sesiones del usuario
            $jwt->revocarSesiones((int)$usuario->id);
        }

        AuditLibrary::log(
            (int)$usuario->id,
            'LOGOUT',
            'usuario',
            (string)$usuario->id,
            'Cierre de sesión'
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => 'Sesión cerrada correctamente',
        ], 200);
    }

    /**
     * POST /v1/auth/logout-all
     *
     * Revoca TODAS las sesiones activas del usuario.
     */
    public function logoutAll(): mixed
    {
        $usuario = $this->request->jwtUser;

        $jwt = new JwtLibrary();
        $jwt->revocarSesiones((int)$usuario->id);

        AuditLibrary::log(
            (int)$usuario->id,
            'LOGOUT_ALL',
            'usuario',
            (string)$usuario->id,
            'Cierre de todas las sesiones'
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => 'Todas las sesiones han sido cerradas',
        ], 200);
    }

    /**
     * GET /v1/auth/me
     *
     * Devuelve los datos del usuario autenticado desde el token.
     */
    public function me(): mixed
    {
        $usuario = $this->request->jwtUser;

        // Refrescar vistas y empresas desde BD
        $model    = new UsuarioModel();
        $empresas = [];

        if ((int)($usuario->nivel ?? 99) > 1) {
            $empresas = $model->empresasAsignadas((int)$usuario->id);
        }

        $vistas = $model->vistasDelRol((int)$usuario->rol);

        return $this->respond([
            'status' => 'ok',
            'data'   => [
                'id'       => $usuario->id,
                'nombre'   => $usuario->nombre,
                'correo'   => $usuario->correo,
                'username' => $usuario->name_user,
                'rol'      => $usuario->rol_slug,
                'nivel'    => $usuario->nivel,
                'empresas' => $usuario->empresas ?? $empresas,
                'vistas'   => $vistas,
            ],
        ], 200);
    }

    /* ═══════════════════════════════════════════════════════════════
       REGISTRO DE USUARIOS (SuperAdmin / Admin)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /v1/auth/registro
     *
     * Solo accesible con rol superadmin o admin (JwtFilter lo valida).
     * Un admin solo puede crear usuarios con nivel >= suyo.
     */
    public function registro(): mixed
    {
        $actor = $this->request->jwtUser;

        $rules = [
            'nombre'          => 'required|max_length[255]',
            'paterno'         => 'required|max_length[255]',
            'materno'         => 'permit_empty|max_length[255]',
            'name_user'       => 'required|min_length[3]|max_length[50]|alpha_dash',
            'correo'          => 'required|valid_email|max_length[255]',
            'password'        => 'required|min_length[8]',
            'id_rol_sistema'  => 'required|integer|in_list[1,2,3,4]',
            'id_empresa_default' => 'permit_empty|integer',
        ];

        if (! $this->validate($rules)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Datos inválidos',
                'errors'  => $this->validator->getErrors(),
            ], 422);
        }

        $idRolNuevo = (int) $this->request->getVar('id_rol_sistema');

        // Un admin (nivel 2) no puede crear SuperAdmin (nivel 1)
        if ((int)$actor->nivel > 1 && $idRolNuevo < (int)$actor->nivel) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'No puedes crear un usuario con mayor jerarquía que la tuya',
            ], 403);
        }

        $model    = new UsuarioModel();
        $nameUser = $this->request->getVar('name_user');
        $correo   = $this->request->getVar('correo');

        if ($model->existeUsername($nameUser)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'El nombre de usuario ya está registrado',
            ], 409);
        }

        if ($model->existeCorreo($correo)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'El correo ya está registrado',
            ], 409);
        }

        $idEmpresa = $this->request->getVar('id_empresa_default');

        // Si el actor es admin, la empresa debe ser una de las suyas
        if ((int)$actor->nivel === 2 && $idEmpresa) {
            $empresasActor = $model->empresasAsignadas((int)$actor->id);
            if (! in_array((int)$idEmpresa, $empresasActor, true)) {
                return $this->respond([
                    'status'  => 'error',
                    'message' => 'No puedes asignar una empresa que no te pertenece',
                ], 403);
            }
        }

        $nuevoId = $model->insert([
            'nombre'              => $this->request->getVar('nombre'),
            'paterno'             => $this->request->getVar('paterno'),
            'materno'             => $this->request->getVar('materno') ?? '',
            'name_user'           => $nameUser,
            'correo'              => $correo,
            'password'            => password_hash(
                                        $this->request->getVar('password'),
                                        PASSWORD_ARGON2ID
                                     ),
            'estatus'             => 1,
            'id_rol_sistema'      => $idRolNuevo,
            'id_empresa_default'  => $idEmpresa ?? null,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        AuditLibrary::log(
            (int)$actor->id,
            'CREAR_USUARIO',
            'usuario',
            (string)$nuevoId,
            "Usuario {$nameUser} registrado por {$actor->name_user}"
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => 'Usuario registrado correctamente',
            'data'    => ['id' => $nuevoId],
        ], 201);
    }

    /* ═══════════════════════════════════════════════════════════════
       BIOMÉTRICO (equivalente a searchUser + biometrico_login)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /v1/auth/biometrico/buscar
     *
     * Equivalente a case "searchUser" del legacy.
     * Busca un guardia por CURP (18), RFC (13) o NSS (11).
     *
     * Body: { "query": "..." }
     */
    public function biometricoBuscar(): mixed
    {
        $query = trim($this->request->getVar('query') ?? '');

        if (empty($query)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Se requiere un identificador de empleado',
            ], 400);
        }

        $len = strlen($query);
        $longitunesValidas = [6, 11, 13, 18]; // NSS corto, NSS, RFC, CURP

        if (! in_array($len, $longitunesValidas, true)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Parámetros inválidos, verifique el identificador',
            ], 422);
        }

        $empleadoModel = new \App\Models\EmpleadoModel();
        $empleado      = $empleadoModel->buscarBiometrico($query, $len);

        if (! $empleado) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'El usuario no tiene permitido el acceso',
            ], 404);
        }

        // Validar puesto permitido
        $idPuesto = (int)($empleado['id_puesto'] ?? 0);

        if (! in_array($idPuesto, $this->puestosPermitidos, true)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'El usuario no tiene permitido el acceso',
            ], 403);
        }

        return $this->respond([
            'status' => 'ok',
            'data'   => $empleado,
        ], 200);
    }

    /**
     * POST /v1/auth/biometrico/login
     *
     * Equivalente a case "biometrico_login" del legacy.
     * Autentica al guardia usando su id + token biométrico.
     *
     * Body: { "id": 123, "biometrico_token": "..." }
     */
    public function biometricoLogin(): mixed
    {
        $rules = [
            'id'               => 'required|integer',
            'biometrico_token' => 'required|min_length[10]',
        ];

        if (! $this->validate($rules)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Datos inválidos',
                'errors'  => $this->validator->getErrors(),
            ], 422);
        }

        $id    = (int)$this->request->getVar('id');
        $token = $this->request->getVar('biometrico_token');

        $tokenModel = new \App\Models\TokenQrModel();
        $valido     = $tokenModel->validarBiometrico($id, $token);

        if (! $valido) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Token biométrico inválido o expirado',
            ], 401);
        }

        $empleadoModel = new \App\Models\EmpleadoModel();
        $empleado      = $empleadoModel->find($id);

        if (! $empleado) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        // Marcar token como usado
        $tokenModel->marcarUsado($id, $token);

        AuditLibrary::log(
            $id,
            'BIOMETRICO_LOGIN',
            'empleado',
            (string)$id,
            'Login biométrico exitoso'
        );

        return $this->respond([
            'status' => 'ok',
            'data'   => [
                'id'       => $empleado['id'],
                'nombre'   => trim("{$empleado['nombre']} {$empleado['paterno']} {$empleado['materno']}"),
                'id_puesto' => $empleado['id_puesto'],
            ],
        ], 200);
    }

    /**
     * PUT /api/v1/auth/me
     * Actualiza nombre y correo del usuario autenticado.
     */
    public function updateMe(): mixed
    {
        $actor  = $this->request->jwtUser;
        $nombre = trim($this->request->getVar('nombre') ?? '');
        $correo = trim($this->request->getVar('correo') ?? '');

        if (!$nombre || !$correo) {
            return $this->respond(['status' => 'error', 'message' => 'Nombre y correo son requeridos'], 422);
        }

        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->respond(['status' => 'error', 'message' => 'Correo inválido'], 422);
        }

        $model = new \App\Models\UsuarioModel();

        // Verificar que el correo no lo use otro usuario
        $existente = $model->where('correo', $correo)
                        ->where('id !=', (int)$actor->id)
                        ->first();
        if ($existente) {
            return $this->respond(['status' => 'error', 'message' => 'El correo ya está en uso'], 409);
        }

        $model->update((int)$actor->id, [
            'nombre' => $nombre,
            'correo' => $correo,
        ]);

        AuditLibrary::log((int)$actor->id, 'UPDATE_PERFIL', 'usuario', (string)$actor->id, 'Actualizó su perfil');

        return $this->respond(['status' => 'ok', 'message' => 'Perfil actualizado correctamente']);
    }

    /**
     * POST /api/v1/auth/cambiar-password
     * Cambia la contraseña del usuario autenticado.
     * Body: { password_actual, password_nuevo }
     */
    public function cambiarPassword(): mixed
    {
        $actor           = $this->request->jwtUser;
        $passwordActual  = $this->request->getVar('password_actual') ?? '';
        $passwordNuevo   = $this->request->getVar('password_nuevo')  ?? '';

        if (!$passwordActual || !$passwordNuevo) {
            return $this->respond(['status' => 'error', 'message' => 'Ambas contraseñas son requeridas'], 422);
        }

        if (strlen($passwordNuevo) < 6) {
            return $this->respond(['status' => 'error', 'message' => 'La nueva contraseña debe tener al menos 6 caracteres'], 422);
        }

        $model   = new \App\Models\UsuarioModel();
        $usuario = $model->find((int)$actor->id);

        if (!$usuario || !password_verify($passwordActual, $usuario['password'])) {
            return $this->respond(['status' => 'error', 'message' => 'La contraseña actual es incorrecta'], 401);
        }

        $model->update((int)$actor->id, [
            'password' => password_hash($passwordNuevo, PASSWORD_ARGON2ID),
        ]);

        AuditLibrary::log((int)$actor->id, 'CAMBIO_PASSWORD', 'usuario', (string)$actor->id, 'Cambió su contraseña');

        return $this->respond(['status' => 'ok', 'message' => 'Contraseña actualizada correctamente']);
    }
}
