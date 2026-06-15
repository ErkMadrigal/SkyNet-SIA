<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table         = 'usuario';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useSoftDeletes = false; // manejamos is_deleted manualmente

    protected $allowedFields = [
        'nombre', 'correo', 'name_user', 'paterno', 'materno',
        'password', 'estatus', 'id_rol_sistema', 'id_empresa_default',
        'ultimo_login', 'updated_at', 'deleted_at', 'is_deleted',
    ];

    protected $useTimestamps = false; // columnas ya están en allowedFields

    /* ─────────────────────────────────────────
       SCOPES / BUILDERS
    ───────────────────────────────────────── */

    /**
     * Join con rol_sistema para traer slug y nivel en una sola consulta.
     */
    public function conRol(): static
    {
        return $this->select('usuario.*, rol_sistema.slug AS rol_slug, rol_sistema.nivel')
                    ->join('rol_sistema', 'rol_sistema.id = usuario.id_rol_sistema', 'left');
    }

    /**
     * Solo usuarios activos y no borrados.
     */
    public function activos(): static
    {
        return $this->where('usuario.estatus', 1)
                    ->where('usuario.is_deleted', 0);
    }

    /* ─────────────────────────────────────────
       AUTENTICACIÓN
    ───────────────────────────────────────── */

    /**
     * Busca un usuario por name_user o correo (activo, no borrado)
     * y verifica su contraseña Argon2id.
     *
     * @return array|null  Fila del usuario con rol_slug y nivel, o null
     */
    public function autenticar(string $identifier, string $password): ?array
    {
        $usuario = $this->conRol()
                        ->activos()
                        ->groupStart()
                            ->where('usuario.name_user', $identifier)
                            ->orWhere('usuario.correo', $identifier)
                        ->groupEnd()
                        ->first();

        if (! $usuario) {
            return null;
        }

        if (! password_verify($password, $usuario['password'])) {
            return null;
        }

        // Actualizar último login
        $this->update($usuario['id'], ['ultimo_login' => date('Y-m-d H:i:s')]);

        return $usuario;
    }

    /* ─────────────────────────────────────────
       UTILIDADES
    ───────────────────────────────────────── */

    /**
     * Verifica si un name_user ya existe (para registro).
     */
    public function existeUsername(string $nameUser, ?int $excludeId = null): bool
    {
        $builder = $this->where('name_user', $nameUser)->where('is_deleted', 0);

        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Verifica si un correo ya existe.
     */
    public function existeCorreo(string $correo, ?int $excludeId = null): bool
    {
        $builder = $this->where('correo', $correo)->where('is_deleted', 0);

        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Soft delete de usuario.
     */
    public function softDelete(int $id, int $deletedBy): bool
    {
        return $this->update($id, [
            'is_deleted' => 1,
            'deleted_at' => date('Y-m-d H:i:s'),
            'estatus'    => 0,
        ]);
    }

    /**
     * Devuelve los IDs de empresas asignadas a un usuario.
     *
     * @return int[]
     */
    public function empresasAsignadas(int $idUsuario): array
    {
        $rows = $this->db->table('empresas_usuario')
                         ->select('id_empresa')
                         ->where('id_usuario', $idUsuario)
                         ->where('activo', 1)
                         ->get()
                         ->getResultArray();

        return array_column($rows, 'id_empresa');
    }

    /**
     * Devuelve los slugs de vistas que puede acceder un rol.
     *
     * @return string[]
     */
    public function vistasDelRol(int $idRol): array
    {
        $rows = $this->db->table('vista_permisos vp')
                         ->select('v.slug')
                         ->join('vistas v', 'v.id = vp.id_vista')
                         ->where('vp.id_rol', $idRol)
                         ->where('vp.activo', 1)
                         ->where('v.activo', 1)
                         ->get()
                         ->getResultArray();

        return array_column($rows, 'slug');
    }


    public function vistasDelUsuario(int $idUsuario, int $idRol): array
    {
        // Vistas base del rol
        $vistasRol = $this->db->query("
            SELECT v.slug FROM vista_permisos vp
            JOIN vistas v ON v.id = vp.id_vista
            WHERE vp.id_rol = ? AND vp.activo = 1 AND v.activo = 1
        ", [$idRol])->getResultArray();

        // Permisos individuales — JOIN directo con vistas por módulo + permiso
        $vistasIndividuales = $this->db->query("
            SELECT v.slug
            FROM permiso_rol_empleados pre
            JOIN roles r ON r.id = pre.id_rol
            JOIN vistas v ON v.modulo = r.tipo AND v.id_permiso = pre.id_permiso
            WHERE pre.id_empleado = ? AND v.activo = 1
        ", [$idUsuario])->getResultArray();

        $slugs = array_merge(
            array_column($vistasRol, 'slug'),
            array_column($vistasIndividuales, 'slug')
        );

        return array_values(array_unique($slugs));
    }
}
