<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * UsuarioSistemaModel
 * Migración de ConsultasUsuarios + ControllerUsuarios legacy.
 * Maneja usuarios del sistema web: listado, roles, permisos, registro.
 *
 * OJO: No confundir con UsuarioModel (que maneja auth/JWT).
 * Este modelo es para el CRUD de usuarios desde el panel admin.
 */
class UsuarioSistemaModel extends Model
{
    protected $table      = 'usuario';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    private function ok($data = [], array $extra = []): array
    {
        return array_merge(['status' => 'ok', 'data' => $data], $extra);
    }

    private function fail(string $msg): array
    {
        return ['status' => 'error', 'data' => [], 'mensaje' => $msg];
    }

    /* ── LECTURA ─────────────────────────────────────────── */

    /** Lista todos los usuarios con sus roles y permisos agrupados. */
    public function getAllUsers(): array
    {
        try {
            $rows = $this->db->query("
                SELECT
                    u.id,
                    CONCAT_WS(' ', u.nombre, u.paterno, u.materno) AS nombre_completo,
                    u.name_user, u.correo, u.estatus,
                    COALESCE(GROUP_CONCAT(DISTINCT r.tipo ORDER BY r.tipo SEPARATOR ', '), '') AS roles,
                    COALESCE(GROUP_CONCAT(DISTINCT p.permiso ORDER BY p.permiso SEPARATOR ', '), '') AS permisos,
                    COALESCE((
                        SELECT CONCAT('[', GROUP_CONCAT(DISTINCT CONCAT(
                            '{\"id_rol\":', rX.id, ',\"rol\":\"', REPLACE(rX.tipo,'\"','\\\\\"'), '\",\"permisos\":[',
                            IFNULL((
                                SELECT GROUP_CONCAT(DISTINCT CONCAT('\"', REPLACE(pX.permiso,'\"','\\\\\"'), '\"') ORDER BY pX.permiso SEPARATOR ',')
                                FROM permiso_rol_empleados preX2
                                INNER JOIN permisos pX ON pX.id = preX2.id_permiso
                                WHERE preX2.id_empleado = u.id AND preX2.id_rol = rX.id
                            ), ''), ']}'
                        ) ORDER BY rX.tipo SEPARATOR ','), ']')
                        FROM permiso_rol_empleados preX
                        INNER JOIN roles rX ON rX.id = preX.id_rol
                        WHERE preX.id_empleado = u.id
                    ), '[]') AS roles_permisos
                FROM usuario u
                LEFT JOIN permiso_rol_empleados pre ON pre.id_empleado = u.id
                LEFT JOIN roles r ON r.id = pre.id_rol
                LEFT JOIN permisos p ON p.id = pre.id_permiso
                GROUP BY u.id, u.nombre, u.paterno, u.materno, u.name_user, u.correo, u.estatus
                ORDER BY u.id DESC
            ")->getResultArray();

            // Decodificar roles_permisos JSON string → array
            foreach ($rows as &$row) {
                $decoded = json_decode($row['roles_permisos'] ?? '[]', true);
                $row['roles_permisos'] = is_array($decoded) ? $decoded : [];
            }
            unset($row);

            return ['status' => 'ok', 'data' => $rows];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Detalle de un usuario con roles_permisos como array. */
    public function getUserById(int $id): array
    {
        try {
            $row = $this->db->query("
                SELECT
                    u.id,
                    CONCAT_WS(' ', u.nombre, u.paterno, u.materno) AS nombre_completo,
                    u.name_user, u.correo, u.estatus,
                    COALESCE(GROUP_CONCAT(DISTINCT r.tipo ORDER BY r.tipo SEPARATOR ', '), '') AS roles,
                    COALESCE(GROUP_CONCAT(DISTINCT p.permiso ORDER BY p.permiso SEPARATOR ', '), '') AS permisos,
                    COALESCE((
                        SELECT CONCAT('[', GROUP_CONCAT(DISTINCT CONCAT(
                            '{\"id_rol\":', rX.id, ',\"rol\":\"', REPLACE(rX.tipo,'\"','\\\\\"'), '\",\"permisos\":[',
                            IFNULL((
                                SELECT GROUP_CONCAT(DISTINCT CONCAT('\"', REPLACE(pX.permiso,'\"','\\\\\"'), '\"') ORDER BY pX.permiso SEPARATOR ',')
                                FROM permiso_rol_empleados preX2
                                INNER JOIN permisos pX ON pX.id = preX2.id_permiso
                                WHERE preX2.id_empleado = u.id AND preX2.id_rol = rX.id
                            ), ''), ']}'
                        ) ORDER BY rX.tipo SEPARATOR ','), ']')
                        FROM permiso_rol_empleados preX
                        INNER JOIN roles rX ON rX.id = preX.id_rol
                        WHERE preX.id_empleado = u.id
                    ), '[]') AS roles_permisos
                FROM usuario u
                LEFT JOIN permiso_rol_empleados pre ON pre.id_empleado = u.id
                LEFT JOIN roles r ON r.id = pre.id_rol
                LEFT JOIN permisos p ON p.id = pre.id_permiso
                WHERE u.id = ?
                GROUP BY u.id, u.nombre, u.paterno, u.materno, u.name_user, u.correo, u.estatus
                LIMIT 1
            ", [$id])->getRowArray();

            if (!$row) return $this->fail('Usuario no encontrado');

            $decoded = json_decode($row['roles_permisos'] ?? '[]', true);
            $row['roles_permisos'] = is_array($decoded) ? $decoded : [];

            return $this->ok($row);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Catálogo de roles disponibles. */
    public function getRoles(): array
    {
        try {
            $rows = $this->db->table('roles')->orderBy('id', 'ASC')->get()->getResultArray();
            return $this->ok($rows);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Busca usuario por name_user (para validar duplicados en registro). */
    public function getUsr(string $nameUser): array
    {
        try {
            $row = $this->db->query("
                SELECT u.id, u.password, u.name_user, u.estatus, u.correo,
                    COALESCE(JSON_ARRAYAGG(DISTINCT er.id_rol), JSON_ARRAY()) AS roles,
                    COALESCE(JSON_ARRAYAGG(DISTINCT er.id_permiso), JSON_ARRAY()) AS permisos
                FROM usuario u
                LEFT JOIN permiso_rol_empleados er ON u.id = er.id_empleado
                WHERE u.name_user = ?
                GROUP BY u.id, u.password, u.name_user, u.estatus, u.correo
            ", [$nameUser])->getRowArray();

            return $this->ok($row ?: []);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /** Busca usuario por correo (para validar duplicados). */
    public function getCorreo(string $correo): array
    {
        try {
            $row = $this->db->table('usuario')->where('correo', $correo)->get()->getRowArray();
            return $this->ok($row ?: []);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /* ── ESCRITURA ───────────────────────────────────────── */

    /**
     * Genera username a partir del nombre: inicial.paterno.inicial_materno
     * Equivalente a generateUsername() del legacy.
     */
    public function generarUsername(string $nombre, string $paterno, string $materno): string
    {
        $n = iconv('UTF-8', 'ASCII//TRANSLIT', strtolower(trim($nombre)));
        $p = iconv('UTF-8', 'ASCII//TRANSLIT', strtolower(trim($paterno)));
        $m = iconv('UTF-8', 'ASCII//TRANSLIT', strtolower(trim($materno)));
        return substr($n, 0, 1) . '.' . $p . '.' . substr($m, 0, 1);
    }

    /** Registra un nuevo usuario del sistema. */
    public function registro(string $nombre, string $correo, string $nameUser, string $paterno, string $materno, string $passwordHash, int $estatus): array
    {
        try {
            $this->db->table('usuario')->insert([
                'nombre'    => $nombre,
                'correo'    => $correo,
                'name_user' => $nameUser,
                'paterno'   => $paterno,
                'materno'   => $materno,
                'password'  => $passwordHash,
                'estatus'   => $estatus,
            ]);
            return ['status' => 'ok', 'mensaje' => 'Registro exitoso', 'last_insert_id' => $this->db->insertID()];
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }

    /**
     * Asigna roles y permisos a un usuario (DELETE + INSERT en transacción).
     * $roles = [{ id: X, permisos: ['Leer','Crear',...] }, ...]
     */
    public function setRoles(int $idUsuario, array $roles): array
    {
        $permisosMap = ['Actualizar' => 1, 'Eliminar' => 2, 'Crear' => 3, 'Leer' => 4];

        $this->db->transStart();
        try {
            // Limpiar permisos actuales
            $this->db->table('permiso_rol_empleados')->where('id_empleado', $idUsuario)->delete();

            foreach ($roles as $rol) {
                if (!is_array($rol) || !isset($rol['id'])) continue;
                $permisos = $rol['permisos'] ?? [];
                if (!is_array($permisos) || count($permisos) === 0) continue;

                foreach ($permisos as $permiso) {
                    if (!isset($permisosMap[$permiso])) {
                        throw new \Exception("Permiso no válido: {$permiso}");
                    }
                    $this->db->table('permiso_rol_empleados')->insert([
                        'id_permiso' => $permisosMap[$permiso],
                        'id_rol'     => (int)$rol['id'],
                        'id_empleado' => $idUsuario,
                    ]);
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return $this->fail('Error en la transacción');
            }

            return ['status' => 'ok', 'mensaje' => 'Permisos asignados correctamente'];
        } catch (\Exception $e) {
            $this->db->transRollback();
            return $this->fail($e->getMessage());
        }
    }
}
