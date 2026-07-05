<?php

namespace App\Libraries;

/**
 * FestivosLibrary
 *
 * Calcula los días festivos oficiales de México conforme al
 * Artículo 74 de la Ley Federal del Trabajo (LFT).
 *
 * Festivos fijos:
 *   1 Enero   — Año Nuevo
 *   1 Mayo    — Día del Trabajo
 *   16 Sep    — Independencia de México
 *   25 Dic    — Navidad
 *
 * Festivos con "Regla del Lunes" (se trasladan al lunes más cercano):
 *   5 Feb     — Constitución Mexicana  → primer lunes de febrero
 *   21 Mar    — Natalicio de Juárez    → tercer lunes de marzo
 *   20 Nov    — Revolución Mexicana    → tercer lunes de noviembre
 *
 * Festivos especiales:
 *   Primer lunes de febrero (ya contemplado arriba)
 *   1 Oct cada 6 años — Transmisión del Poder Ejecutivo Federal
 *     (años: 2024, 2030, 2036...)
 *
 * Festivos electorales (cuando aplique — art. 74 fracción VIII):
 *   Se omiten porque son variables y requieren decreto del INE.
 *
 * USO:
 *   $festivos = FestivosLibrary::getAnio(2026);
 *   // ['2026-01-01', '2026-02-02', '2026-03-16', ...]
 *
 *   $esFestivo = FestivosLibrary::esFestivo('2026-02-02');
 *   // true
 *
 *   $festivos = FestivosLibrary::getRango('2026-06-13', '2026-06-27');
 *   // ['2026-06-XX'] si hubiera alguno en ese rango
 */
class FestivosLibrary
{
    /**
     * Obtiene todos los festivos LFT del año dado.
     * @return array<string> Fechas en formato Y-m-d
     */
    public static function getAnio(int $anio): array
    {
        $festivos = [];

        // ── Fijos ─────────────────────────────────────────────────────
        $festivos[] = "{$anio}-01-01"; // Año Nuevo
        $festivos[] = "{$anio}-05-01"; // Día del Trabajo
        $festivos[] = "{$anio}-09-16"; // Independencia
        $festivos[] = "{$anio}-12-25"; // Navidad

        // ── Regla del Lunes (LFT art. 74, decreto 2006) ───────────────
        // 5 Feb → primer lunes de febrero
        $festivos[] = self::primerLunes($anio, 2);

        // 21 Mar → tercer lunes de marzo
        $festivos[] = self::tercerLunes($anio, 3);

        // 20 Nov → tercer lunes de noviembre
        $festivos[] = self::tercerLunes($anio, 11);

        // ── Transmisión del Poder Ejecutivo ────────────────────────────
        // Cada 6 años a partir de 2024 (años: 2024, 2030, 2036...)
        if ($anio >= 2024 && ($anio - 2024) % 6 === 0) {
            $festivos[] = "{$anio}-10-01";
        }

        sort($festivos);

        return array_values($festivos);
    }

    /**
     * Verifica si una fecha específica es festivo LFT.
     * @param string $fecha Formato Y-m-d
     */
    public static function esFestivo(string $fecha): bool
    {
        $anio = (int)date('Y', strtotime($fecha));
        return in_array($fecha, self::getAnio($anio), true);
    }

    /**
     * Devuelve los festivos que caen dentro de un rango de fechas.
     * @param string $inicio Y-m-d
     * @param string $fin    Y-m-d
     * @return array<string>
     */
    public static function getRango(string $inicio, string $fin): array
    {
        $anioInicio = (int)date('Y', strtotime($inicio));
        $anioFin    = (int)date('Y', strtotime($fin));

        $todosFestivos = [];
        for ($a = $anioInicio; $a <= $anioFin; $a++) {
            $todosFestivos = array_merge($todosFestivos, self::getAnio($a));
        }

        return array_values(array_filter($todosFestivos, fn($f) => $f >= $inicio && $f <= $fin));
    }

    /**
     * Cuenta cuántos festivos LFT caen en un rango de fechas.
     */
    public static function contarEnRango(string $inicio, string $fin): int
    {
        return count(self::getRango($inicio, $fin));
    }

    /**
     * Dado un array de códigos de día del calendario [numDia => codigo],
     * detecta cuáles días son festivos dentro del periodo y devuelve
     * los números de día que son festivos.
     *
     * @param array  $diasCalendario [1 => 'D', 2 => '24', 3 => 'F', ...]
     * @param string $periodoInicio  Y-m-d — fecha del día 1 del periodo
     * @return array<int>            Números de día que son festivos
     */
    public static function diasFestivosEnCalendario(array $diasCalendario, string $periodoInicio): array
    {
        $festivosDelPeriodo = [];
        $tsInicio = strtotime($periodoInicio);
        $diaInicio = (int)date('j', $tsInicio);

        foreach ($diasCalendario as $numDia => $codigo) {
            // Calcular la fecha real de este número de día
            $offsetDias = (int)$numDia - $diaInicio;
            $fechaDia   = date('Y-m-d', strtotime("{$periodoInicio} +{$offsetDias} days"));

            if (self::esFestivo($fechaDia)) {
                $festivosDelPeriodo[] = (int)$numDia;
            }
        }

        return $festivosDelPeriodo;
    }

    /**
     * Detecta dobletes en el calendario:
     * Un doblete ocurre cuando un empleado trabaja un turno extra
     * consecutivo (2 turnos sin descanso).
     *
     * Criterio:
     *   - Turno 24x24: si hay 2 códigos '24' consecutivos → doblete 24
     *   - Turno 12x12: si hay 2 códigos '12' consecutivos → doblete 12
     *
     * @param array $diasCalendario [numDia => codigo]
     * @param string $turnoEmpleado '24' o '12'
     * @return array ['count' => int, 'dias' => [numDia], 'monto_extra' => float]
     */
    public static function detectarDobletes(array $diasCalendario, string $turnoEmpleado, float $salarioDiario): array
    {
        $diasOrdenados = $diasCalendario;
        ksort($diasOrdenados);

        $codigoTurno = $turnoEmpleado === '24' ? '24' : '12';
        $multiplicador = $turnoEmpleado === '24' ? 2 : 1; // 24x24: SD*2, 12x12: SD*1

        $dobletes    = [];
        $diasArr     = array_keys($diasOrdenados);
        $codigosArr  = array_values($diasOrdenados);
        $count       = count($diasArr);

        for ($i = 0; $i < $count - 1; $i++) {
            $diaActual   = (int)$diasArr[$i];
            $diaSiguiente = (int)$diasArr[$i + 1];
            $codActual    = strtoupper(trim($codigosArr[$i]));
            $codSiguiente = strtoupper(trim($codigosArr[$i + 1]));

            // Consecutivos: el siguiente día es el inmediato posterior
            $sonConsecutivos = ($diaSiguiente === $diaActual + 1);

            if ($sonConsecutivos && $codActual === $codigoTurno && $codSiguiente === $codigoTurno) {
                $dobletes[] = $diaActual;
                $i++; // saltar el siguiente para no contar el mismo doblete dos veces
            }
        }

        $montoExtra = count($dobletes) * $salarioDiario * $multiplicador;

        return [
            'count'       => count($dobletes),
            'dias'        => $dobletes,
            'monto_extra' => round($montoExtra, 2),
        ];
    }

    // ── Helpers internos ────────────────────────────────────────────────

    /**
     * Primer lunes del mes dado.
     * Ejemplo: primer lunes de febrero 2026 → 2026-02-02
     */
    private static function primerLunes(int $anio, int $mes): string
    {
        $fecha = new \DateTime("{$anio}-{$mes}-01");
        $dow   = (int)$fecha->format('N'); // 1=lunes ... 7=domingo
        if ($dow > 1) {
            $fecha->modify('+' . (8 - $dow) . ' days');
        }
        return $fecha->format('Y-m-d');
    }

    /**
     * N-ésimo lunes del mes dado (1=primero, 2=segundo, 3=tercero...)
     */
    private static function nesimoLunes(int $anio, int $mes, int $n): string
    {
        $fecha = new \DateTime("{$anio}-{$mes}-01");
        $dow   = (int)$fecha->format('N');
        if ($dow > 1) {
            $fecha->modify('+' . (8 - $dow) . ' days');
        }
        // Ya tenemos el primer lunes, sumar semanas
        if ($n > 1) {
            $fecha->modify('+' . (($n - 1) * 7) . ' days');
        }
        return $fecha->format('Y-m-d');
    }

    private static function tercerLunes(int $anio, int $mes): string
    {
        return self::nesimoLunes($anio, $mes, 3);
    }
}