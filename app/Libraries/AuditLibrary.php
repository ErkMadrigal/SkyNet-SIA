<?php

namespace App\Libraries;

/**
 * AuditLibrary
 *
 * Wrapper elegante sobre la tabla audit_log existente.
 * El esquema ya está perfecto (before_json, after_json, changes_json,
 * coordenadas, ip, user_agent, etc.) — solo lo encapsulamos.
 */
class AuditLibrary
{
    /**
     * Registra un evento en el audit_log.
     *
     * @param int         $actorId        ID del usuario que realiza la acción (0 = sistema/anónimo)
     * @param string      $action         Verbo en mayúsculas: 'LOGIN', 'CREAR_USUARIO', 'EDITAR_EMPLEADO', etc.
     * @param string      $entity         Nombre de la entidad afectada: 'usuario', 'empleado', 'empresa'
     * @param string|null $entityId       ID del registro afectado
     * @param string|null $message        Mensaje legible para auditoría
     * @param array|null  $before         Estado anterior (para ediciones)
     * @param array|null  $after          Estado nuevo (para ediciones)
     * @param int|null    $statusCode     Código HTTP de la respuesta
     */
    public static function log(
        int     $actorId,
        string  $action,
        string  $entity,
        ?string $entityId   = null,
        ?string $message    = null,
        ?array  $before     = null,
        ?array  $after      = null,
        int     $statusCode = 200
    ): void {
        try {
            $request = service('request');
            $db      = \Config\Database::connect();

            // Calcular diff de cambios si hay before y after
            $changes = null;
            if ($before !== null && $after !== null) {
                $changes = self::diff($before, $after);
            }

            // Intentar obtener coordenadas del header (app móvil las puede enviar)
            $lat = null;
            $lon = null;
            $acc = null;
            $geoHeader = $request->getHeaderLine('X-Geo-Location');
            if ($geoHeader) {
                $parts = explode(',', $geoHeader);
                $lat   = isset($parts[0]) ? (float)$parts[0] : null;
                $lon   = isset($parts[1]) ? (float)$parts[1] : null;
                $acc   = isset($parts[2]) ? (int)$parts[2]   : null;
            }

            $db->table('audit_log')->insert([
                'created_at'       => date('Y-m-d H:i:s'),
                'actor_user_id'    => $actorId,
                'actor_name'       => self::resolverNombre($actorId),
                'action'           => strtoupper(substr($action, 0, 40)),
                'entity'           => substr($entity, 0, 60),
                'entity_id'        => $entityId !== null ? substr($entityId, 0, 64) : null,
                'message'          => $message   !== null ? substr($message, 0, 255) : null,
                'endpoint'         => substr($request->getUri()->getPath(), 0, 160),
                'method'           => $request->getMethod(),
                'status_code'      => $statusCode,
                'response_message' => $message   !== null ? substr($message, 0, 255) : null,
                'request_id'       => self::requestId(),
                'ip'               => $request->getIPAddress(),
                'user_agent'       => substr($request->getUserAgent()->getAgentString(), 0, 255),
                'lat'              => $lat,
                'lon'              => $lon,
                'accuracy_m'       => $acc,
                'changes_json'     => $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'before_json'      => $before  !== null ? json_encode($before,  JSON_UNESCAPED_UNICODE) : null,
                'after_json'       => $after   !== null ? json_encode($after,   JSON_UNESCAPED_UNICODE) : null,
            ]);

        } catch (\Exception $e) {
            // El audit nunca debe matar el flujo principal
            log_message('error', '[AuditLibrary] Fallo al registrar: ' . $e->getMessage());
        }
    }

    /* ─────────────────────────────────────────
       HELPERS PRIVADOS
    ───────────────────────────────────────── */

    /**
     * Calcula los campos que cambiaron entre dos estados.
     *
     * @return array{campo: array{antes: mixed, despues: mixed}}
     */
    private static function diff(array $before, array $after): array
    {
        $changes = [];
        $campos  = array_unique(array_merge(array_keys($before), array_keys($after)));

        // Campos sensibles que nunca se loguean en claro
        $excluir = ['password', 'token', 'refresh_token', 'jti'];

        foreach ($campos as $campo) {
            if (in_array($campo, $excluir, true)) {
                continue;
            }

            $anterior = $before[$campo]  ?? null;
            $nuevo    = $after[$campo]   ?? null;

            if ($anterior !== $nuevo) {
                $changes[$campo] = [
                    'antes'   => $anterior,
                    'despues' => $nuevo,
                ];
            }
        }

        return $changes;
    }

    /**
     * Genera o reutiliza el request_id del ciclo actual.
     */
    private static function requestId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
        return $id;
    }

    /**
     * Resuelve el nombre del actor para el log (sin query si es 0).
     */
    private static function resolverNombre(int $actorId): ?string
    {
        if ($actorId === 0) {
            return 'Sistema';
        }

        try {
            $db  = \Config\Database::connect();
            $row = $db->table('usuario')
                      ->select('CONCAT(nombre, " ", paterno) AS nombre_completo')
                      ->where('id', $actorId)
                      ->get()
                      ->getRowArray();

            return $row['nombre_completo'] ?? null;
        } catch (\Exception) {
            return null;
        }
    }
}
