<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\UsuarioSistemaModel;
use App\Libraries\AuditLibrary;

/**
 * UsuariosSistemaController
 * Migración de users.php legacy — gestión de usuarios del sistema web.
 * Rutas base: /api/v1/usuarios
 *
 * GET  /usuarios              → listar todos (getUsers)
 * GET  /usuarios/{id}         → detalle con roles/permisos (getUserById)
 * POST /usuarios              → crear usuario (newUser) — genera password y username
 * POST /usuarios/{id}/roles   → asignar roles y permisos (setRoles)
 * GET  /usuarios/roles        → catálogo de roles (getRoles)
 */
class UsuariosSistemaController extends ResourceController
{
    protected $format = 'json';

    /* ── LISTADO ─────────────────────────────────────────── */

    /** GET /api/v1/usuarios */
    public function index(): mixed
    {
        return $this->respond((new UsuarioSistemaModel())->getAllUsers());
    }

    /** GET /api/v1/usuarios/{id} */
    public function show($id = null): mixed
    {
        if (!(int)$id) {
            return $this->respond(['status' => 'error', 'message' => 'ID requerido'], 400);
        }
        return $this->respond((new UsuarioSistemaModel())->getUserById((int)$id));
    }

    /** GET /api/v1/usuarios/roles */
    public function roles(): mixed
    {
        return $this->respond((new UsuarioSistemaModel())->getRoles());
    }

    /* ── REGISTRO ────────────────────────────────────────── */

    /**
     * POST /api/v1/usuarios
     * Body: { nombre, paterno, materno, correo }
     * Genera password numérico de 6 dígitos y username automático.
     * Devuelve password y userName en claro (para que el admin lo comunique).
     */
    public function create(): mixed
    {
        $actor = $this->request->jwtUser;

        $rules = [
            'nombre'  => 'required|max_length[255]',
            'paterno' => 'required|max_length[255]',
            'materno' => 'permit_empty|max_length[255]',
            'correo'  => 'required|valid_email|max_length[255]',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }

        $nombre  = trim($this->request->getVar('nombre'));
        $paterno = trim($this->request->getVar('paterno'));
        $materno = trim($this->request->getVar('materno') ?? '');
        $correo  = trim($this->request->getVar('correo'));

        $model    = new UsuarioSistemaModel();
        $userName = $model->generarUsername($nombre, $paterno, $materno);

        // Validar duplicados
        if (!empty($model->getUsr($userName)['data'])) {
            return $this->respond(['status' => 'error', 'message' => 'El nombre de usuario ya está registrado'], 409);
        }
        if (!empty($model->getCorreo($correo)['data'])) {
            return $this->respond(['status' => 'error', 'message' => 'El correo ya está registrado'], 409);
        }

        // Password temporal numérico de 6 dígitos
        $password = random_int(100000, 999999);

        $res = $model->registro(
            $nombre, $correo, $userName,
            $paterno, $materno,
            password_hash((string)$password, PASSWORD_ARGON2ID),
            1
        );

        if ($res['status'] !== 'ok') {
            return $this->respond($res, 500);
        }

        AuditLibrary::log((int)$actor->id, 'CREAR_USUARIO_SISTEMA', 'usuario', (string)($res['last_insert_id'] ?? ''), "Creó usuario {$userName}");

        return $this->respond([
            'status'   => 'ok',
            'mensaje'  => 'Usuario registrado correctamente',
            'userName' => $userName,
            'password' => $password, // en claro para que el admin lo comunique
            'data'     => ['id' => $res['last_insert_id']],
        ], 201);
    }

    /* ── ROLES Y PERMISOS ────────────────────────────────── */

    /**
     * POST /api/v1/usuarios/{id}/roles
     * Body: { roles: [{ id: X, permisos: ['Leer','Crear',...] }] }
     */
    public function asignarRoles($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $roles = $this->request->getVar('roles');

        
        if (!(int)$id) {
            return $this->respond(['status' => 'error', 'message' => 'ID de usuario requerido'], 400);
        }

        if (!is_array($roles) || count($roles) === 0) {
            return $this->respond(['status' => 'error', 'message' => 'roles[] es requerido'], 422);
        }

        $model = new UsuarioSistemaModel();
        $res   = $model->setRoles((int)$id, $roles);

        AuditLibrary::log((int)$actor->id, 'SET_ROLES', 'usuario', (string)$id, 'Roles asignados');

        return $this->respond($res);
    }
}
