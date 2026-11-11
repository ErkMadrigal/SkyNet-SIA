<?php

namespace App\Libraries;

/**
 * Latido de los crons: cada endpoint de mantenimiento anota cuándo corrió y cómo le fue.
 * Lo lee la vista "Sistema" del panel. Se guarda en writable/cron_estado.json.
 */
class CronEstadoLibrary
{
    private static function archivo(): string
    {
        return WRITEPATH . 'cron_estado.json';
    }

    public static function leer(): array
    {
        $f = self::archivo();
        if (!is_file($f)) return [];
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    public static function registrar(string $clave, bool $ok, string $detalle = ''): void
    {
        try {
            $datos = self::leer();
            $datos[$clave] = [
                'ts'      => time(),
                'ok'      => $ok,
                'detalle' => mb_substr($detalle, 0, 200),
            ];
            file_put_contents(self::archivo(), json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } catch (\Throwable $e) { /* el latido es opcional */ }
    }

    /**
     * Próxima ejecución (timestamp) de una programación simple de cron: minuto y hora como
     * "*", número o lista ("0,30"); día/mes/día de la semana en "*".
     */
    public static function proxima(string $minutos, string $horas, \DateTimeZone $tz, ?int $desde = null): ?int
    {
        $lista = function (string $c, int $max): array {
            if ($c === '*') return range(0, $max);
            $o = [];
            foreach (explode(',', $c) as $x) { $x = (int) trim($x); if ($x >= 0 && $x <= $max) $o[] = $x; }
            return $o ?: range(0, $max);
        };
        $ms = $lista($minutos, 59);
        $hs = $lista($horas, 23);
        $t  = (new \DateTime('@' . ($desde ?? time())))->setTimezone($tz);
        $t->setTime((int) $t->format('H'), (int) $t->format('i'), 0)->modify('+1 minute');
        for ($i = 0; $i < 60 * 48; $i++) {
            if (in_array((int) $t->format('i'), $ms, true) && in_array((int) $t->format('G'), $hs, true)) {
                return $t->getTimestamp();
            }
            $t->modify('+1 minute');
        }
        return null;
    }
}
