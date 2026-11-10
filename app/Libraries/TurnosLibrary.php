<?php

namespace App\Libraries;

/**
 * Horarios de los turnos (ids de multicatalogo, catálogo 7).
 * Solo lo usa el código nuevo (cierre automático); el resto sigue con su copia local.
 */
class TurnosLibrary
{
    public const CONFIG = [
        1367 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true],
        1382 => ['entrada' => '08:00', 'salida' => '20:00', 'siguiente_dia' => false],
        1387 => ['entrada' => '09:00', 'salida' => '19:00', 'siguiente_dia' => false],
        1398 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true],
        1399 => ['entrada' => '07:00', 'salida' => '19:00', 'siguiente_dia' => false],
        1400 => ['entrada' => '19:00', 'salida' => '07:00', 'siguiente_dia' => true],
    ];

    /** Fecha/hora en que termina el turno para una entrada dada, o null si el turno no tiene horario. */
    public static function horaSalida(int $idTurno, \DateTime $entrada): ?\DateTime
    {
        $cfg = self::CONFIG[$idTurno] ?? null;
        if (!$cfg) return null;
        $s = clone $entrada;
        if ($cfg['siguiente_dia']) $s->modify('+1 day');
        [$h, $m] = explode(':', $cfg['salida']);
        $s->setTime((int)$h, (int)$m, 0);
        return $s;
    }
}
