<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * Permisos del menú de la app Kiosko por empleado.
 * Base (siempre): Ajustes, Asistencias y Expediente digital propio.
 *
 *   GET  empleados/(id)/permisos-app   (admin)  → permisos del empleado
 *   POST empleados/(id)/permisos-app   (admin)  → guarda { permisos: [...] }
 *   GET  biometrico/permisos-app/(id)  (público, lo usa el kiosko para refrescar su menú)
 */
class AppPermisosController extends ResourceController
{
    protected $format = 'json';

    public const CLAVES = ['incidencias', 'alta_empleado', 'empleados', 'ticket', 'camaras', 'dispositivo'];

    /** Lee los permisos (array de claves válidas). Tolera que la tabla aún no exista. */
    public static function leer(int $idEmpleado): array
    {
        try {
            $row = \Config\Database::connect()
                ->query('SELECT permisos FROM empleado_app_permisos WHERE id_empleado = ?', [$idEmpleado])
                ->getRowArray();
            if (! $row) return [];
            $arr = json_decode((string) $row['permisos'], true);
            return is_array($arr) ? array_values(array_intersect(self::CLAVES, $arr)) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function soloAdmin(): ?object
    {
        $u = $this->request->jwtUser ?? null;
        if (! $u || (int) ($u->nivel ?? 99) > 2) {
            return null;
        }
        return $u;
    }

    public function ver($id = null)
    {
        if (! $this->soloAdmin()) {
            return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        }
        return $this->respond(['status' => 'ok', 'data' => ['claves' => self::CLAVES, 'permisos' => self::leer((int) $id)]]);
    }

    public function guardar($id = null)
    {
        $u = $this->soloAdmin();
        if (! $u) {
            return $this->respond(['status' => 'error', 'message' => 'Sin permiso'], 403);
        }
        $id  = (int) $id;
        $in  = $this->request->getJSON(true)['permisos'] ?? $this->request->getVar('permisos') ?? [];
        $in  = is_array($in) ? $in : [];
        $ok  = array_values(array_intersect(self::CLAVES, $in));

        try {
            \Config\Database::connect()->query(
                'INSERT INTO empleado_app_permisos (id_empleado, permisos, updated_by, updated_at) VALUES (?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE permisos = VALUES(permisos), updated_by = VALUES(updated_by), updated_at = NOW()',
                [$id, json_encode($ok), (int) ($u->id ?? 0)]
            );
        } catch (\Throwable $e) {
            return $this->respond(['status' => 'error', 'message' => 'Falta correr migracion_permisos_app.sql'], 500);
        }

        AuditLibrary::log((int) ($u->id ?? 0), 'APP_PERMISOS', 'empleados', (string) $id, 'Permisos app: ' . implode(',', $ok));
        return $this->respond(['status' => 'ok', 'data' => ['permisos' => $ok]]);
    }

    public function publico($id = null)
    {
        return $this->respond(['status' => 'ok', 'permisos' => self::leer((int) $id)]);
    }
}
