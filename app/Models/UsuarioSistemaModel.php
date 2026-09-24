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
        // 1) Usuarios base -- sin joins, lo más barato posible.
        $usuarios = $this->db->query("
            SELECT
                u.id,
                CONCAT_WS(' ', u.nombre, u.paterno, u.materno) AS nombre_completo,
                u.name_user, u.correo, u.estatus
            FROM usuario u
            ORDER BY u.id DESC
        ")->getResultArray();

        if (!$usuarios) {
            return ['status' => 'ok', 'data' => []];
        }

        // 2) Roles + permisos de TODOS los usuarios en UNA sola query
        // plana -- nada de subqueries por fila. Trae una fila por cada
        // combinación (empleado, rol, permiso).
        $filas = $this->db->query("
            SELECT
                pre.id_empleado,
                r.id   AS id_rol,
                r.tipo AS rol,
                p.permiso
            FROM permiso_rol_empleados pre
            INNER JOIN roles r     ON r.id = pre.id_rol
            LEFT JOIN  permisos p  ON p.id = pre.id_permiso
        ")->getResultArray();

        // 3) Arma el árbol id_empleado -> { roles, permisos, roles_permisos }
        // en memoria -- esto es lo que antes hacían las subqueries
        // correlacionadas, pero aquí es un solo recorrido O(n).
        $porEmpleado = [];
        foreach ($filas as $f) {
            $idEmp = $f['id_empleado'];
            if (!isset($porEmpleado[$idEmp])) {
                $porEmpleado[$idEmp] = ['roles' => [], 'permisos' => [], 'rolesMap' => []];
            }

            $porEmpleado[$idEmp]['roles'][$f['rol']] = true;
            if ($f['permiso'] !== null) {
                $porEmpleado[$idEmp]['permisos'][$f['permiso']] = true;
            }

            $idRol = $f['id_rol'];
            if (!isset($porEmpleado[$idEmp]['rolesMap'][$idRol])) {
                $porEmpleado[$idEmp]['rolesMap'][$idRol] = [
                    'id_rol'   => (int)$idRol,
                    'rol'      => $f['rol'],
                    'permisos' => [],
                ];
            }
            if ($f['permiso'] !== null) {
                $porEmpleado[$idEmp]['rolesMap'][$idRol]['permisos'][$f['permiso']] = true;
            }
        }

        // 4) Combina con los usuarios base -- mismas 3 llaves que ya
        // esperaba tu frontend: roles (string), permisos (string),
        // roles_permisos (array de {id_rol, rol, permisos:[]}).
        foreach ($usuarios as &$u) {
            $info = $porEmpleado[$u['id']] ?? null;

            if (!$info) {
                $u['roles']          = '';
                $u['permisos']       = '';
                $u['roles_permisos'] = [];
                continue;
            }

            $rolesUnicos = array_keys($info['roles']);
            sort($rolesUnicos);
            $u['roles'] = implode(', ', $rolesUnicos);

            $permisosUnicos = array_keys($info['permisos']);
            sort($permisosUnicos);
            $u['permisos'] = implode(', ', $permisosUnicos);

            $rolesPermisos = [];
            foreach ($info['rolesMap'] as $rp) {
                $permisosArr = array_keys($rp['permisos']);
                sort($permisosArr);
                $rolesPermisos[] = [
                    'id_rol'   => $rp['id_rol'],
                    'rol'      => $rp['rol'],
                    'permisos' => $permisosArr,
                ];
            }
            usort($rolesPermisos, fn($a, $b) => strcmp((string)$a['rol'], (string)$b['rol']));
            $u['roles_permisos'] = $rolesPermisos;
        }
        unset($u);

        return ['status' => 'ok', 'data' => $usuarios];
    } catch (\Exception $e) {
        return $this->fail($e->getMessage());
    }
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
        $normalize = fn($s) => preg_replace('/[^a-z.]/', '', 
            iconv('UTF-8', 'ASCII//TRANSLIT', strtolower(trim($s)))
        );

        $partesNombre = array_filter(explode(' ', $normalize($nombre)));
        $p = $normalize($paterno);
        $m = $normalize($materno);

        // Filtra artículos comunes
        $articulos = ['de', 'del', 'la', 'los', 'las', 'el', 'y'];
        $partesNombre = array_values(array_filter($partesNombre, fn($w) => !in_array($w, $articulos)));

        // Construye: inicial_1 + todas_las_partes_del_nombre + paterno + inicial_materno
        $inicialPrimero = substr($partesNombre[0], 0, 1);
        $restoNombre    = array_slice($partesNombre, 1); // ["lourdes", "perla"]

        $partes = [$inicialPrimero];
        foreach ($restoNombre as $parte) {
            $partes[] = $parte;
        }
        $partes[] = $p;
        $partes[] = substr($m, 0, 1);

        $username = implode('.', $partes);

        // Si aún existe, agrega inicial del materno completo
        if (!empty($this->getUsr($username)['data'])) {
            $username = implode('.', array_merge(
                array_slice($partes, 0, -1), [$m]
            ));
        }

        return $username;
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
        $db = \Config\Database::connect();

        $db->query("DELETE FROM permiso_rol_empleados WHERE id_empleado = ?", [$idUsuario]);

        $valores = [];
        $params  = [];

        foreach ($roles as $rol) {
            // Convertir stdClass a array si es necesario
            if (is_object($rol)) $rol = (array) $rol;
            if (!is_array($rol) || !isset($rol['id'])) continue;

            $permisos = $rol['permisos'] ?? [];
            // Convertir permisos si también son objetos
            if (is_object($permisos)) $permisos = (array) $permisos;
            if (!is_array($permisos) || count($permisos) === 0) continue;

            foreach ($permisos as $permiso) {
                $permiso = trim((string) $permiso);
                if (!isset($permisosMap[$permiso])) continue;
                $valores[] = "(?, ?, ?)";
                $params[]  = $permisosMap[$permiso];
                $params[]  = (int)$rol['id'];
                $params[]  = $idUsuario;
            }
        }

        if (!empty($valores)) {
            $sql = "INSERT INTO permiso_rol_empleados (id_permiso, id_rol, id_empleado) VALUES " . implode(', ', $valores);
            $db->query($sql, $params);
        }

        return [
            'status'  => 'ok',
            'mensaje' => 'Permisos asignados correctamente',
        ];
    }

    public function resetPassword(int $id, string $hash): void
    {
        $this->db->table('usuario')
            ->where('id', $id)
            ->update(['password' => $hash]);
    }

    public function toggleEstatus(int $id): array
    {
        $actual = $this->db->table('usuario')
            ->select('estatus')
            ->where('id', $id)
            ->get()->getRowArray();

        $nuevo = $actual['estatus'] ? 0 : 1;

        $this->db->table('usuario')
            ->where('id', $id)
            ->update(['estatus' => $nuevo]);

        return ['estatus' => $nuevo];
    }
}
