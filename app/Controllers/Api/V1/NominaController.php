<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

class NominaController extends ResourceController
{
    protected $format = 'json';

    /* ═══════════════════════════════════════════════════
       GET /api/v1/nomina/preview
    ═══════════════════════════════════════════════════ */
    public function preview(): mixed
    {
        $idEmpleado  = (int) $this->request->getVar('id_empleado');
        $idUbicacion = (int) $this->request->getVar('id_ubicacion');
        $fechaInicio = trim($this->request->getVar('fecha_inicio') ?? '');
        $fechaFin    = trim($this->request->getVar('fecha_fin')    ?? '');

        if (!$idEmpleado || !$idUbicacion || !$fechaInicio || !$fechaFin) {
            return $this->fail('Parámetros requeridos: id_empleado, id_ubicacion, fecha_inicio, fecha_fin', 400);
        }

        $db = \Config\Database::connect();

        // ── 1. Datos del empleado ─────────────────────
        $empleado = $db->query("
            SELECT e.id, e.rfc, e.curp, e.nss, e.fecha_ingreso AS fecha_alta,
                   CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombreCompleto,
                   e.clave_interbancaria, e.estatus,
                   mt.valor  AS turno,        mt.id  AS id_turno,
                   mp.valor  AS puesto,       mp.id  AS id_puesto,
                   mpe.valor AS periodicidad, mpe.id AS id_periodicidad
            FROM empleados e
            LEFT JOIN multicatalogo mt  ON e.id_turno      = mt.id
            LEFT JOIN multicatalogo mp  ON e.id_puesto     = mp.id
            LEFT JOIN multicatalogo mpe ON e.id_periocidad = mpe.id
            WHERE e.id = ?
        ", [$idEmpleado])->getRowArray();

        if (!$empleado) {
            return $this->failNotFound('Empleado no encontrado');
        }

        // ── 2. Datos del servicio/ubicación ───────────
        $servicio = $db->query("
            SELECT em.empresa, cl.nombre_corto, p.partida,
                   z.zona, z.id AS id_zona,
                   s.servicio, s.elementos, s.ubicacion, s.cp, s.latitud, s.longitud
            FROM servicios s
            LEFT JOIN empresas  em ON s.id_empresa = em.id
            LEFT JOIN clientes  cl ON s.id_cliente = cl.id
            LEFT JOIN partidas  p  ON s.id_partida = p.id
            LEFT JOIN zonas     z  ON s.id_zona    = z.id
            WHERE s.id = ?
        ", [$idUbicacion])->getRowArray();

        if (!$servicio) {
            return $this->failNotFound('Ubicación no encontrada');
        }

        // ── 3. Tabulador de salarios ──────────────────
        $tabulador = $db->query("
            SELECT ts.nombre, tsd.sueldo, tsd.bono, tsd.descuento
            FROM tabulador_salarios_detalle tsd
            LEFT JOIN tabulador_salarios ts ON tsd.id_tabulador = ts.id
            WHERE tsd.id_puesto = ? AND ts.id_zona = ? AND ts.estatus = 1
            ORDER BY ts.id DESC
            LIMIT 1
        ", [$empleado['id_puesto'], $servicio['id_zona']])->getRowArray();

        if (!$tabulador) {
            // Devolver resultado vacío en lugar de error para no romper cálculo grupal
            return $this->respond([
                'status' => 'sin_tabulador',
                'data'   => null,
                'message' => "Sin tabulador activo para puesto {$empleado['puesto']} en zona {$servicio['zona']}"
            ]);
        }

        $sueldoMensual = (float) $tabulador['sueldo'];  // sueldo quincenal base
        $bonoMensual   = (float) $tabulador['bono'];    // bono quincenal
        $descuento     = (float) $tabulador['descuento']; // descuento POR cada día de falta

        // ── 4. Detectar tipo de turno ─────────────────
        $turnoRaw     = strtoupper(trim($empleado['turno'] ?? ''));
        $tipoTurno    = $this->detectarTipoTurno($turnoRaw);
        $sueldoDiario = $this->calcularSueldoDiario($sueldoMensual, $tipoTurno);

        // ── 5. Registros biométricos ──────────────────
        // Buffer de 2 días antes para capturar entrada de turno 24x24
        $fechaInicioBuffer = (new \DateTime($fechaInicio))->modify('-2 days')->format('Y-m-d');
        $fechaFinBuffer    = (new \DateTime($fechaFin))->modify('+1 day')->format('Y-m-d');

        $registros = $db->query("
            SELECT a.fecha, a.hora,
                   IF(a.id_status = 1, 'entrada', 'salida') AS tipo_registro,
                   s.servicio, a.id_ubicacion
            FROM asistencias a
            JOIN servicios s ON a.id_ubicacion = s.id
            WHERE a.id_empleado = ?
              AND s.id_zona = (SELECT s2.id_zona FROM servicios s2 WHERE s2.id = ? LIMIT 1)
              AND a.fecha >= ? AND a.fecha <= ?
            ORDER BY a.fecha ASC, a.hora ASC
        ", [$idEmpleado, $idUbicacion, $fechaInicioBuffer, $fechaFinBuffer])->getResultArray();

        // Agrupar por fecha — primera entrada y última salida del día
        $asistenciasPorFecha = [];
        foreach ($registros as $r) {
            $f = $r['fecha'];
            if (!isset($asistenciasPorFecha[$f])) {
                $asistenciasPorFecha[$f] = [
                    'entrada'  => null,
                    'salida'   => null,
                    'servicio' => $r['servicio'],
                ];
            }
            if ($r['tipo_registro'] === 'entrada' && !$asistenciasPorFecha[$f]['entrada']) {
                $asistenciasPorFecha[$f]['entrada']  = $r['hora'];
                $asistenciasPorFecha[$f]['servicio'] = $r['servicio'];
            }
            if ($r['tipo_registro'] === 'salida') {
                $asistenciasPorFecha[$f]['salida'] = $r['hora'];
            }
        }

        // ── 6. Construir turnos completos (entrada + salida) ──────────
        // Regla: un turno VÁLIDO requiere entrada Y salida
        // Para 24x24: entrada día X, salida puede ser día X o día X+1
        $turnosCompletos = [];

        if ($tipoTurno === '24x24') {
            $fechasOrdenadas = array_keys($asistenciasPorFecha);
            sort($fechasOrdenadas);

            foreach ($fechasOrdenadas as $fecha) {
                $data = $asistenciasPorFecha[$fecha];
                if (!$data['entrada']) continue;

                $salida      = $data['salida'];
                $servicioDia = $data['servicio'];

                // Buscar salida en el día siguiente si no la hay hoy
                if (!$salida) {
                    $manana = (new \DateTime($fecha))->modify('+1 day')->format('Y-m-d');
                    if (isset($asistenciasPorFecha[$manana]) && $asistenciasPorFecha[$manana]['salida']) {
                        $salida = $asistenciasPorFecha[$manana]['salida'];
                    }
                }

                // Solo válido si tiene AMBAS: entrada Y salida
                if ($salida) {
                    $turnosCompletos[$fecha] = [
                        'entrada'  => $data['entrada'],
                        'salida'   => $salida,
                        'servicio' => $servicioDia,
                    ];
                }
            }
        } else {
            // Para 12x12 y normal: entrada + salida el mismo día
            foreach ($asistenciasPorFecha as $fecha => $data) {
                if ($data['entrada'] && $data['salida']) {
                    $turnosCompletos[$fecha] = $data;
                }
            }
        }

        // ── 7. Detectar anchor del ciclo 24x24/12x12 ─────────────────
        $fechaAltaCiclo = !empty($empleado['fecha_alta'])
            ? new \DateTime($empleado['fecha_alta'])
            : new \DateTime($fechaInicio);

        if ($tipoTurno === '24x24' || $tipoTurno === '12x12') {
            foreach ($turnosCompletos as $fecha => $t) {
                if ($fecha >= $fechaInicioBuffer) {
                    $fechaAltaCiclo = new \DateTime($fecha);
                    break;
                }
            }
        }

        // ── 8. Incidencias aprobadas ──────────────────
        $incidencias = $db->query("
            SELECT m.valor AS tipo, i.fecha_inicio, i.fecha_final, i.activo
            FROM incidencias i
            LEFT JOIN multicatalogo m ON i.id_tipo_incidencia = m.id
            WHERE i.id_empleado = ? AND i.activo = 1
              AND i.fecha_inicio <= ? AND i.fecha_final >= ?
        ", [$idEmpleado, $fechaFin, $fechaInicio])->getResultArray();

        $incidenciasPorFecha = [];
        foreach ($incidencias as $inc) {
            $cursor = new \DateTime($inc['fecha_inicio']);
            $finInc = new \DateTime($inc['fecha_final']);
            while ($cursor <= $finInc) {
                $key = $cursor->format('Y-m-d');
                $incidenciasPorFecha[$key][] = $inc['tipo'];
                $cursor->modify('+1 day');
            }
        }

        // ── 9. Días festivos ──────────────────────────
        $festivos = $this->getDiasFestivos(
            (int) substr($fechaInicio, 0, 4),
            (int) substr($fechaFin,    0, 4)
        );

        // ── 10. Calcular periodo día a día ────────────
        $diasDetalle             = [];
        $cursor                  = new \DateTime($fechaInicio);
        $fin                     = new \DateTime($fechaFin);
        $totalTrabajados         = 0;
        $totalFaltas             = 0;
        $totalDescansos          = 0;
        $totalFestivos           = 0;
        $totalIncidencias        = 0;
        $tieneFaltaSinJustificar = false;

        while ($cursor <= $fin) {
            $fecha     = $cursor->format('Y-m-d');
            $diaSemana = (int) $cursor->format('N');

            $esFestivo       = in_array($fecha, $festivos);
            $turnoCompleto   = $turnosCompletos[$fecha] ?? null;
            $incsDia         = $incidenciasPorFecha[$fecha] ?? [];
            $tieneIncidencia = !empty($incsDia);
            $esDiaTrabajo    = $this->esDiaTrabajo($tipoTurno, $cursor, $fechaAltaCiclo, $diaSemana);

            // Para 24x24: si tiene turno completo pero el anchor dice descanso, corregir
            if (($tipoTurno === '24x24' || $tipoTurno === '12x12') && !$esDiaTrabajo && $turnoCompleto) {
                $esDiaTrabajo = true;
            }

            $tipoDia    = 'descanso';
            $pagoDia    = 0;
            $aplicaBono = false;

            if ($esFestivo && $esDiaTrabajo) {
                // ── Festivo trabajado → doble pago (extra encima del sueldo base)
                if ($turnoCompleto) {
                    $tipoDia = 'festivo_trabajado';
                    $pagoDia = $sueldoDiario; // el EXTRA (el día base ya está en sueldo quincenal)
                    $totalFestivos++;
                    $totalTrabajados++;
                    $aplicaBono = true;
                } elseif ($tieneIncidencia) {
                    $tipoDia = 'festivo_incidencia';
                    $pagoDia = 0;
                    $totalFestivos++;
                    $totalIncidencias++;
                } else {
                    $tipoDia = 'festivo_falta';
                    $pagoDia = -$descuento; // descuento completo
                    $totalFaltas++;
                    $tieneFaltaSinJustificar = true;
                }

            } elseif ($esFestivo && !$esDiaTrabajo) {
                // ── Festivo en día de descanso → se paga (ya está en sueldo base)
                $tipoDia = 'festivo_descanso';
                $pagoDia = 0;
                $totalFestivos++;

            } elseif ($esDiaTrabajo) {
                if ($turnoCompleto) {
                    // ── Trabajo normal → ya está en sueldo quincenal
                    $tipoDia = 'trabajo';
                    $pagoDia = 0;
                    $totalTrabajados++;
                    $aplicaBono = true;
                } elseif ($tieneIncidencia) {
                    // ── Incidencia aprobada → no descuenta, no afecta bono
                    $tipoDia = 'incidencia';
                    $pagoDia = 0;
                    $totalIncidencias++;
                    $aplicaBono = false;
                } else {
                    // ── Falta → descuento completo
                    $tipoDia = 'falta';
                    $pagoDia = -$descuento;
                    $totalFaltas++;
                    $tieneFaltaSinJustificar = true;
                }

            } else {
                // ── Descanso → ya está en sueldo quincenal
                $tipoDia = 'descanso';
                $pagoDia = 0;
                $totalDescansos++;
            }

            $diasDetalle[] = [
                'fecha'       => $fecha,
                'dia_semana'  => $this->nombreDia($diaSemana),
                'tipo'        => $tipoDia,
                'entrada'     => $turnoCompleto['entrada']  ?? ($asistenciasPorFecha[$fecha]['entrada']  ?? null),
                'salida'      => $turnoCompleto['salida']   ?? ($asistenciasPorFecha[$fecha]['salida']   ?? null),
                'servicio'    => $turnoCompleto['servicio'] ?? ($asistenciasPorFecha[$fecha]['servicio'] ?? null),
                'es_festivo'  => $esFestivo,
                'incidencias' => $incsDia,
                'aplica_bono' => $aplicaBono,
                'pago_dia'    => round($pagoDia, 2),
            ];

            $cursor->modify('+1 day');
        }

        // ── 11. Totales ───────────────────────────────
        // Sueldo quincenal FIJO
        // Descuento = $descuento (del tabulador) × número de faltas
        // Bono = $bonoMensual si no hay faltas sin justificar
        // Extra festivo = $sueldoDiario por cada festivo trabajado

        $totalDescuentoFaltas = $descuento * $totalFaltas;

        $montoFestivosExtra = 0;
        foreach ($diasDetalle as $d) {
            if ($d['tipo'] === 'festivo_trabajado') {
                $montoFestivosExtra += $sueldoDiario;
            }
        }

        $bonoFinal = $tieneFaltaSinJustificar ? 0 : $bonoMensual;

        $total = $sueldoMensual              // sueldo quincenal base (incluye trabajo + descansos)
               - $totalDescuentoFaltas       // menos descuento por cada falta
               + $bonoFinal                  // más bono si no hay faltas
               + $montoFestivosExtra;        // más extra por festivos trabajados

        if ($total < 0) $total = 0;

        return $this->respond([
            'status' => 'ok',
            'data'   => [
                'empleado'  => $empleado,
                'servicio'  => $servicio,
                'tabulador' => $tabulador,
                'periodo'   => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin'    => $fechaFin,
                    'turno'        => $empleado['turno'],
                    'tipo_turno'   => $tipoTurno,
                    'periodicidad' => $empleado['periodicidad'],
                ],
                'resumen' => [
                    'dias_trabajados'            => $totalTrabajados,
                    'dias_falta'                 => $totalFaltas,
                    'dias_descanso'              => $totalDescansos,
                    'dias_festivo'               => $totalFestivos,
                    'dias_incidencia'            => $totalIncidencias,
                    'tiene_falta_sin_justificar' => $tieneFaltaSinJustificar,
                    'sueldo_quincenal'           => round($sueldoMensual, 2),
                    'sueldo_diario'              => round($sueldoDiario, 2),
                    'descuento_por_falta'        => round($descuento, 2),
                    'total_descuento_faltas'     => round($totalDescuentoFaltas, 2),
                    'festivos_extra'             => round($montoFestivosExtra, 2),
                    'bono'                       => round($bonoFinal, 2),
                    'total'                      => round($total, 2),
                ],
                'detalle' => $diasDetalle,
            ]
        ]);
    }

    /* ═══════════════════════════════════════════════════
       HELPERS
    ═══════════════════════════════════════════════════ */

    private function detectarTipoTurno(string $turno): string
    {
        if (str_contains($turno, '24')) return '24x24';
        if (str_contains($turno, '12')) return '12x12';
        return 'normal';
    }

    private function esDiaTrabajo(string $tipo, \DateTime $fecha, \DateTime $cicloInicio, int $diaSemana): bool
    {
        switch ($tipo) {
            case '24x24':
            case '12x12':
                $diff = (int) $cicloInicio->diff($fecha)->days;
                return ($diff % 2) === 0;
            case 'normal':
            default:
                return $diaSemana <= 5;
        }
    }

    private function calcularSueldoDiario(float $sueldoMensual, string $tipoTurno): float
    {
        switch ($tipoTurno) {
            case '24x24': return $sueldoMensual / 15;
            case '12x12': return $sueldoMensual / 15;
            case 'normal': return $sueldoMensual / 30;
            default:       return $sueldoMensual / 30;
        }
    }

    private function getDiasFestivos(int $anioInicio, int $anioFin): array
    {
        $festivos = [];
        for ($anio = $anioInicio; $anio <= $anioFin; $anio++) {
            $fijos = [
                "$anio-01-01",
                $this->primerLunesDe($anio, 2),
                $this->tercerLunesDe($anio, 3),
                "$anio-05-01",
                "$anio-09-16",
                $this->tercerLunesDe($anio, 11),
                "$anio-12-25",
            ];

            $pascua  = $this->calcularPascua($anio);
            $jueves  = (clone $pascua)->modify('-3 days')->format('Y-m-d');
            $viernes = (clone $pascua)->modify('-2 days')->format('Y-m-d');

            $festivos = array_merge($festivos, $fijos, [$jueves, $viernes]);

            if (($anio - 2024) % 6 === 0) {
                $festivos[] = "$anio-06-02";
            }
        }
        return array_unique($festivos);
    }

    private function primerLunesDe(int $anio, int $mes): string
    {
        $fecha = new \DateTime("$anio-$mes-01");
        $dow   = (int) $fecha->format('N');
        $diff  = ($dow === 1) ? 0 : (8 - $dow);
        $fecha->modify("+$diff days");
        return $fecha->format('Y-m-d');
    }

    private function tercerLunesDe(int $anio, int $mes): string
    {
        $fecha = new \DateTime($this->primerLunesDe($anio, $mes));
        $fecha->modify('+14 days');
        return $fecha->format('Y-m-d');
    }

    private function calcularPascua(int $anio): \DateTime
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTime("$anio-$mes-$dia");
    }

    private function nombreDia(int $n): string
    {
        return ['', 'Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'][$n] ?? '';
    }

    /* ═══════════════════════════════════════════════════
       GET /api/v1/nomina/empleados-servicio
    ═══════════════════════════════════════════════════ */
    public function empleadosPorServicio(): mixed
    {
        $idServicio = (int) $this->request->getVar('id_servicio');
        if (!$idServicio) return $this->fail('id_servicio requerido', 400);

        $db = \Config\Database::connect();

        $empleados = $db->query("
            SELECT DISTINCT e.id,
                   CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombreCompleto,
                   e.curp, e.rfc,
                   mp.valor AS puesto,
                   mt.valor AS turno
            FROM asistencias a
            JOIN empleados e ON a.id_empleado = e.id
            LEFT JOIN multicatalogo mp ON e.id_puesto = mp.id
            LEFT JOIN multicatalogo mt ON e.id_turno  = mt.id
            WHERE a.id_ubicacion = ? AND e.estatus = 1 AND e.is_deleted = 0
            ORDER BY e.paterno ASC
        ", [$idServicio])->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $empleados]);
    }

    /* ═══════════════════════════════════════════════════
       GET /api/v1/nomina/empleados-zona
    ═══════════════════════════════════════════════════ */
    public function empleadosPorZona(): mixed
    {
        $idZona = (int) $this->request->getVar('id_zona');
        if (!$idZona) return $this->fail('id_zona requerido', 400);

        $db = \Config\Database::connect();

        $empleados = $db->query("
            SELECT
                e.id,
                CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombreCompleto,
                e.curp, e.rfc,
                mp.valor AS puesto,
                mt.valor AS turno,
                e.id_puesto,
                s.id     AS id_servicio,
                s.servicio
            FROM empleados e
            JOIN (
                SELECT a.id_empleado, a.id_ubicacion, COUNT(*) AS total
                FROM asistencias a
                JOIN servicios s ON a.id_ubicacion = s.id
                WHERE s.id_zona = ?
                GROUP BY a.id_empleado, a.id_ubicacion
                ORDER BY total DESC
            ) top ON top.id_empleado = e.id
            JOIN servicios s ON s.id = top.id_ubicacion
            LEFT JOIN multicatalogo mp ON e.id_puesto = mp.id
            LEFT JOIN multicatalogo mt ON e.id_turno  = mt.id
            WHERE e.estatus = 1 AND e.is_deleted = 0
            GROUP BY e.id
            ORDER BY e.paterno ASC, e.nombre ASC
        ", [$idZona])->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $empleados]);
    }
}