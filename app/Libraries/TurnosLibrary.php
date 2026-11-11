<?php

namespace App\Libraries;

/**
 * Duración de los turnos (ids de multicatalogo, catálogo 7).
 *
 * El cierre automático se basa en la DURACIÓN del turno contando desde la hora real de la
 * entrada (no en una hora fija del reloj): entró a las 6 pm con turno "12 X 12" -> termina a las
 * 6 am. La duración sale del texto del catálogo:
 *   "07:00 a 19:00", "9:00 a 19:00 hrs", "19:00 a 07:00"  -> diferencia entre horas
 *   "12 X 12", "12 X 24", "24 x 24", "48 X 48"            -> el primer número son las horas de trabajo
 *   "8" (9:00 a 18:00 con 1 h de comida)                  -> dura 9 h y se cierra a las 14 h de la entrada
 */
class TurnosLibrary
{
    /** Respaldo si el texto del catálogo no se entiende: ventana fija de los ids conocidos. */
    public const CONFIG = [
        1367 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true],
        1382 => ['entrada' => '08:00', 'salida' => '20:00', 'siguiente_dia' => false],
        1387 => ['entrada' => '09:00', 'salida' => '19:00', 'siguiente_dia' => false],
        1398 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true],
        1399 => ['entrada' => '07:00', 'salida' => '19:00', 'siguiente_dia' => false],
        1400 => ['entrada' => '19:00', 'salida' => '07:00', 'siguiente_dia' => true],
    ];

    /** @var array<int, ?float> */
    private static array $cache = [];

    /** Interpreta el texto de un turno y devuelve las horas de duración, o null si no se entiende. */
    public static function parsearDuracion(string $texto): ?float
    {
        $t = strtolower(trim($texto));
        if ($t === '') return null;

        // "07:00 a 19:00" / "9:00 a 19:00 hrs" / "19:00 a 07:00"
        if (preg_match('/(\d{1,2})\s*[:.]?\s*(\d{2})?\s*(?:hrs?|horas?)?\s*(?:a|-|al)\s*(\d{1,2})\s*[:.]?\s*(\d{2})?/', $t, $m)
            && (str_contains($t, ':') || str_contains($t, ' a '))) {
            $ini = (int)$m[1] * 60 + (int)($m[2] ?? 0);
            $fin = (int)$m[3] * 60 + (int)($m[4] ?? 0);
            $dif = $fin - $ini;
            if ($dif <= 0) $dif += 24 * 60;
            return $dif / 60;
        }
        // "12 X 12", "12 x 24", "24 x 24", "48 X 48": trabaja el primer número
        if (preg_match('/^(\d{1,2})\s*x\s*(\d{1,2})/', $t, $m)) {
            return (float)$m[1];
        }
        // "8" / "8 hrs": 8 h de trabajo + 1 h de comida (9:00 a 18:00)
        if (preg_match('/^(\d{1,2})\s*(?:hrs?|horas?)?$/', $t, $m)) {
            return (float)$m[1] + 1;
        }
        return null;
    }

    /**
     * Horas desde la entrada a partir de las cuales se cierra sola, si el turno tiene un límite propio
     * ("8": 14 h, o sea a las 11 pm si entró a las 9 am). null = se usa duración + tolerancia general.
     */
    public static function limiteHoras(int $idTurno): ?float
    {
        try {
            $row = \Config\Database::connect()->query('SELECT valor FROM multicatalogo WHERE id = ?', [$idTurno])->getRowArray();
            if ($row && preg_match('/^\s*(\d{1,2})\s*(?:hrs?|horas?)?\s*$/i', (string)$row['valor'], $m)) {
                return (float)$m[1] + 6;
            }
        } catch (\Throwable $e) { /* sin catálogo */ }
        return null;
    }

    /** Momento a partir del cual una entrada sin salida debe cerrarse. */
    public static function horaLimite(int $idTurno, \DateTime $entrada, int $graciaMin): ?\DateTime
    {
        $fin = self::horaSalida($idTurno, $entrada);
        if (!$fin) return null;
        $lim = self::limiteHoras($idTurno);
        if ($lim !== null) {
            $l = clone $entrada;
            $l->modify('+' . (int)round($lim * 60) . ' minutes');
            return $l < $fin ? $fin : $l;
        }
        return (clone $fin)->modify("+{$graciaMin} minutes");
    }

    /** Horas de duración del turno (lee el texto del catálogo; respaldo: ventana fija conocida). */
    public static function duracionHoras(int $idTurno): ?float
    {
        if (array_key_exists($idTurno, self::$cache)) return self::$cache[$idTurno];

        $h = null;
        try {
            $row = \Config\Database::connect()->query('SELECT valor FROM multicatalogo WHERE id = ?', [$idTurno])->getRowArray();
            if ($row) $h = self::parsearDuracion((string)$row['valor']);
        } catch (\Throwable $e) { /* sin catálogo: se usa el respaldo */ }

        if ($h === null && isset(self::CONFIG[$idTurno])) {
            $c = self::CONFIG[$idTurno];
            [$h1, $m1] = array_map('intval', explode(':', $c['entrada']));
            [$h2, $m2] = array_map('intval', explode(':', $c['salida']));
            $dif = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
            if ($dif <= 0) $dif += 24 * 60;
            $h = $dif / 60;
        }
        if ($h !== null && ($h <= 0 || $h > 96)) $h = null;
        return self::$cache[$idTurno] = $h;
    }

    /** Fecha/hora en que termina el turno contando desde la entrada real, o null si no se conoce la duración. */
    public static function horaSalida(int $idTurno, \DateTime $entrada): ?\DateTime
    {
        $h = self::duracionHoras($idTurno);
        if ($h === null) return null;
        $s = clone $entrada;
        $s->modify('+' . (int)round($h * 60) . ' minutes');
        return $s;
    }
}
