<?php

namespace App\Libraries;

/**
 * Alcance por zona/cliente (roles finos). Un usuario con filas en usuario_alcance solo ve
 * la información de los servicios que pertenecen a esas zonas o clientes.
 * Sin filas (o nivel 1-2) = sin restricción.
 */
class AlcanceLibrary
{
    /** null = sin restricción; si no, ['zonas' => [ids], 'clientes' => [ids]]. */
    public static function de(?object $user): ?array
    {
        if (!$user || (int)($user->nivel ?? 99) <= 2) return null;
        try {
            $rows = \Config\Database::connect()
                ->query('SELECT tipo, id_ref FROM usuario_alcance WHERE id_usuario = ?', [(int)($user->id ?? 0)])
                ->getResultArray();
        } catch (\Throwable $e) {
            return null; // tabla aún no existe
        }
        if (!$rows) return null;
        $a = ['zonas' => [], 'clientes' => []];
        foreach ($rows as $r) {
            if ($r['tipo'] === 'zona')    $a['zonas'][]    = (int)$r['id_ref'];
            if ($r['tipo'] === 'cliente') $a['clientes'][] = (int)$r['id_ref'];
        }
        return $a;
    }

    /** Condición SQL (ids ya saneados como enteros) sobre un alias de `servicios`. '' si no hay restricción. */
    public static function condicion(?array $a, string $alias = 's'): string
    {
        if (!$a) return '';
        $p = [];
        if ($a['zonas'])    $p[] = "{$alias}.id_zona IN (" . implode(',', array_map('intval', $a['zonas'])) . ')';
        if ($a['clientes']) $p[] = "{$alias}.id_cliente IN (" . implode(',', array_map('intval', $a['clientes'])) . ')';
        return $p ? '(' . implode(' OR ', $p) . ')' : '';
    }

    /** ¿Este servicio cae dentro del alcance? */
    public static function permiteServicio(?array $a, ?int $idServicio): bool
    {
        if (!$a) return true;
        if (!$idServicio) return false;
        $c = self::condicion($a, 's');
        if ($c === '') return true;
        return (bool)\Config\Database::connect()
            ->query("SELECT 1 FROM servicios s WHERE s.id = ? AND {$c}", [$idServicio])->getRowArray();
    }
}
