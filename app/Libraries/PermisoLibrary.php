<?php

namespace App\Libraries;

/**
 * Permisos granulares por módulo (leer / crear / actualizar / eliminar).
 *
 * Los slugs viajan en el JWT (`vistas`) como "<modulo>.<accion>".
 * También se aceptan los slugs legacy (listar, ver, editar, gestionar...).
 * SuperAdmin y Administrador (nivel 1-2) siempre pueden.
 */
class PermisoLibrary
{
    private const LEGACY = [
        'leer'       => ['leer', 'listar', 'ver'],
        'crear'      => ['crear', 'gestionar'],
        'actualizar' => ['actualizar', 'editar', 'gestionar'],
        'eliminar'   => ['eliminar'],
    ];

    public static function tiene(object $user, string $modulo, string $accion): bool
    {
        if ((int)($user->nivel ?? 99) <= 2) return true;

        $vistas = json_decode(json_encode($user->vistas ?? []), true) ?: [];
        foreach (self::LEGACY[$accion] ?? [$accion] as $a) {
            if (in_array($modulo . '.' . $a, $vistas, true)) return true;
        }
        return false;
    }

    /** Devuelve la respuesta 403 estándar o null si tiene permiso. */
    public static function exigir(object $user, string $modulo, string $accion): ?array
    {
        return self::tiene($user, $modulo, $accion)
            ? null
            : ['status' => 'error', 'message' => "No tienes permiso para {$accion} en {$modulo}"];
    }
}
