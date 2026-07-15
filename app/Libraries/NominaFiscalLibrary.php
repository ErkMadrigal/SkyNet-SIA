<?php

namespace App\Libraries;

/**
 * NominaFiscalLibrary
 *
 * Calcula la nómina fiscal completa conforme a la legislación mexicana.
 * Validado contra Excel maestro BASE_DE_NOMINA IMSS Bienestar Morelos.
 *
 * FÓRMULA EXACTA (validada a centavo):
 *
 *  exencion_prevision = $4.00 por quincena (LISR Art. 93 f.VI, validado contra Excel)
 *  sueldo_gravado     = sueldo_tabulador - exencion_prevision   (4750 - 4 = 4746)
 *  SD                 = sueldo_gravado / 15                      (4746/15 = 316.40)
 *  SDI                = SD * 1.0493                              (316.40*1.0493 = 331.9985)
 *  BI                 = SD * dias_laborados                      (base gravable proporcional)
 *  Base_ISR_mensual   = SD * 30.4                               (siempre fija = 9618.56)
 *  ISR_periodo        = isr_tabla(Base_ISR) * (dias/30.4)
 *  Subsidio_periodo   = sub_tabla(Base_ISR) * (dias/30.4)
 *  ISR_neto           = max(0, ISR - Subsidio)
 *  IMSS_obrero        = f(SDI, dias)
 *  Neto_fiscal        = max(0, BI - IMSS - ISR_neto)
 *  IAS                = sueldo_neto_pagar - Neto_fiscal
 *  Total_dispersion   = sueldo_neto_pagar
 *
 * VALORES 2026:
 *  UMA diaria: $113.14
 *  Factor SDI: 1.0493
 *  Tablas ISR y Subsidio: RMF 2026 Anexo 8
 */
class NominaFiscalLibrary
{
    private const FACTOR_SDI             = 1.0493;
    // private const UMA_DIARIA             = 113.14;
    private const UMA_DIARIA             = 117.14;
    private const EXENCION_PREVISION_SOC = 4.00;

        // ── Sueldo fiscal FIJO — validado contra Excel maestro ──────────────
    // TODOS los empleados usan este sueldo de referencia para el cálculo
    // fiscal (SD, SDI, ISR, IMSS, Subsidio), sin importar su sueldo tabulador
    // real por puesto/zona. La diferencia entre el pago real y el neto
    // fiscal se absorbe en la IAS.
    public const SUELDO_FISCAL_FIJO = 4750.00; //316.40
    public const SUELDO_FISCAL_FIJO_FRONTERA = 6622.60; //441.24


    // Tasas IMSS obreras (LSS)
    private const IMSS_EXCEDENTE_OBR        = 0.004;
    private const IMSS_PRESTACIONES_DIN_OBR = 0.0025;
    private const IMSS_GASTOS_MED_OBR       = 0.00375;
    private const IMSS_INVALIDEZ_VIDA_OBR   = 0.00625;
    private const IMSS_CESANTIA_VEJEZ_OBR   = 0.01125;
    

    // Tabla ISR mensual 2026 — RMF 2026 Anexo 8
    private const TABLA_ISR = [
        [0.01,        0.00,       0.0192],
        [844.60,      16.22,      0.0640],
        [7168.52,     420.95,     0.1088],
        [12598.03,    1011.68,    0.1600],
        [14644.65,    1339.14,    0.1792],
        [17533.65,    1856.84,    0.2136],
        [35362.84,    5665.16,    0.2352],
        [55736.69,    10457.09,   0.3000],
        [106410.51,   25659.23,   0.3200],
        [141880.67,   37009.69,   0.3400],
        [425642.00,   133488.54,  0.3500],
    ];

    // Tabla Subsidio al Empleo mensual 2026 — RMF 2026 Anexo 8
    private const TABLA_SUBSIDIO = [
        [0.01,       536.21],
        [11492.66,   0.00],
    ];

    /**
     * Calcula la nómina fiscal completa.
     *
     * @param float $sueldoTabulador  Sueldo quincenal completo del tabulador (ej: 4750)
     * @param float $sueldoNetoPagar  Neto real a pagar (con faltas/extras/deducciones)
     * @param int   $diasLaborados    Días efectivos del periodo (1-15)
     */
    public static function calcular(
        float $sueldoTabulador,   
        float $sueldoNetoPagar,
        int   $diasLaborados   = 15,
        float $descInfonavit   = 0.0,
        float $descFonacot     = 0.0,
        float $descPension     = 0.0
    ): array
    {
        // El SD/SDI se calculan sobre el sueldo fiscal FIJO que se le pase
        // (normal $4,750 o fronterizo $6,622.60), nunca sobre el sueldo
        // tabulador real de asistencia del empleado.
        [$sd, $sdi] = self::calcularSdSdi($sueldoTabulador);

        $bi = round($sd * $diasLaborados, 2);
        $baseMensualIsr = $sd * 30.4;

        $isr  = self::calcularIsr($baseMensualIsr, $diasLaborados);
        $imss = self::calcularImssObrero($sdi, $diasLaborados);

        $netoFiscal = max(0, round(
            $bi - $imss['total'] - $isr['isr_neto']
                - $descInfonavit - $descFonacot - $descPension
        , 2));

        $ias             = round($sueldoNetoPagar - $netoFiscal, 2);
        $totalDispersion = round($sueldoNetoPagar, 2);

        return [
            'sd'                => round($sd, 2),
            'sdi'               => round($sdi, 2),
            'dias_laborados'    => $diasLaborados,
            'ingreso_quincenal' => $bi,
            'imss_obrero'       => $imss['total'],
            'detalle_imss'      => $imss,
            'base_mensual_isr'  => round($baseMensualIsr, 4),
            'isr_bruto'         => $isr['isr_bruto'],
            'subsidio_empleo'   => $isr['subsidio'],
            'isr_neto'          => $isr['isr_neto'],
            'desc_infonavit'    => round($descInfonavit, 2),
            'desc_fonacot'      => round($descFonacot, 2),
            'desc_pension'      => round($descPension, 2),
            'neto_fiscal'       => $netoFiscal,
            'ias'               => $ias,
            'total_dispersion'  => $totalDispersion,
        ];
    }
    /**
     * Calcula el componente de incapacidad — validado contra Excel maestro
     * "CALCULO NOMINA" (empleados con código 'I' en el calendario).
     *
     * incapacidad_100  = dias_I * (sueldo_tabulador/15) * 0.6  (nominal, sin integración)
     * incapacidad_imss = dias_I * SDI * 0.6                    (lo que realmente cubre el IMSS)
     * incapacidad_empresa = incapacidad_100 - incapacidad_imss (ajuste; puede salir negativo
     *   porque el SDI integrado suele ser MAYOR al sueldo/15 nominal)
     *
     * @param float $sueldoTabulador Sueldo quincenal completo del tabulador
     * @param int   $diasIncapacidad Días con código 'I' en el calendario (0-15)
     */
    public static function calcularIncapacidad(float $sueldoTabulador, int $diasIncapacidad): array
    {
        if ($diasIncapacidad <= 0) {
            return ['incapacidad_100' => 0.0, 'incapacidad_imss' => 0.0, 'incapacidad_empresa' => 0.0];
        }

        [, $sdi] = self::calcularSdSdi($sueldoTabulador);
        $salarioDiarioNominal = $sueldoTabulador / 15;

        $incapacidad100  = round($diasIncapacidad * $salarioDiarioNominal * 0.6, 2);
        $incapacidadImss = round($diasIncapacidad * $sdi * 0.6, 2);
        $incapacidadEmpresa = round($incapacidad100 - $incapacidadImss, 2);

        return [
            'incapacidad_100'     => $incapacidad100,
            'incapacidad_imss'    => $incapacidadImss,
            'incapacidad_empresa' => $incapacidadEmpresa,
        ];
    }

    /**
     * Prima vacacional 25% sobre el salario diario del tabulador (LFT Art. 80).
     *
     * @param int $diasVacaciones Días con código 'V' en el calendario
     */
    public static function calcularPrimaVacacional(float $salarioDiario, int $diasVacaciones): float
    {
        if ($diasVacaciones <= 0) return 0.0;
        return round($diasVacaciones * $salarioDiario * 0.25, 2);
    }

    /** SD y SDI a partir del sueldo tabulador — misma fórmula que usa calcular() */
     private static function calcularSdSdi(float $sueldoTabulador): array
    {
        $sueldoGravado = $sueldoTabulador - self::EXENCION_PREVISION_SOC;
        $sd  = $sueldoGravado / 15;
        $sdi = $sd * self::FACTOR_SDI;
        return [$sd, $sdi];
    }

    public static function calcularImssObrero(float $sdi, int $dias): array
    {
        $limite3Uma   = 3 * self::UMA_DIARIA;
        $excedente    = max(0, $sdi - $limite3Uma) * $dias * self::IMSS_EXCEDENTE_OBR;
        $prestaciones = $sdi * $dias * self::IMSS_PRESTACIONES_DIN_OBR;
        $gastosMed    = $sdi * $dias * self::IMSS_GASTOS_MED_OBR;
        $invalidez    = $sdi * $dias * self::IMSS_INVALIDEZ_VIDA_OBR;
        $cesantia     = $sdi * $dias * self::IMSS_CESANTIA_VEJEZ_OBR;
        $total        = round($excedente + $prestaciones + $gastosMed + $invalidez + $cesantia, 2);
        return [
            'excedente'           => round($excedente, 2),
            'prestaciones_dinero' => round($prestaciones, 2),
            'gastos_medicos'      => round($gastosMed, 2),
            'invalidez_vida'      => round($invalidez, 2),
            'cesantia_vejez'      => round($cesantia, 2),
            'total'               => $total,
        ];
    }

    public static function calcularIsr(float $baseMensual, int $dias = 15): array
    {
        $isrMes      = self::aplicarTablaIsr($baseMensual);
        $subsidioMes = self::aplicarTablaSubsidio($baseMensual);
        $factor      = $dias / 30.4;
        $isrBruto    = round($isrMes * $factor, 2);
        $subsidio    = round($subsidioMes * $factor, 2);
        $isrNeto     = max(0, round($isrBruto - $subsidio, 2)); // nunca negativo
        return [
            'isr_mensual'  => round($isrMes, 4),
            'isr_bruto'    => $isrBruto,
            'subsidio_mes' => round($subsidioMes, 4),
            'subsidio'     => $subsidio,
            'isr_neto'     => $isrNeto,
        ];
    }

    private static function aplicarTablaIsr(float $base): float
    {
        $fila = self::TABLA_ISR[0];
        foreach (self::TABLA_ISR as $t) {
            if ($base >= $t[0]) $fila = $t;
            else break;
        }
        return max(0, $fila[1] + ($base - $fila[0]) * $fila[2]);
    }

    private static function aplicarTablaSubsidio(float $base): float
    {
        $sub = 0.0;
        foreach (self::TABLA_SUBSIDIO as $t) {
            if ($base >= $t[0]) $sub = $t[1];
            else break;
        }
        return $sub;
    }
}