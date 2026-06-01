<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\EmpleadoModel;
use App\Models\TokenQrModel;
use App\Libraries\AuditLibrary;

/**
 * BiometricoController
 *
 * Migración de las acciones biométricas del employees.php legacy.
 * Rutas base: /api/v1/biometrico
 *
 * POST /buscar                → getEmpleadoBiometrico (buscar empleado en biométrico)
 * POST /registro              → registroEmpleadoBiometrico (registrar entrada/salida)
 * POST /qr/generar            → getQr (generar token QR)
 * POST /qr/usar               → setQr (usar token QR para asistencia)
 * GET  /registros             → registros_biometrico (historial con búsqueda)
 */
class BiometricoController extends ResourceController
{
    protected $format = 'json';

    /**
     * POST /api/v1/biometrico/buscar-login
     * Busca empleado sin validar asistencia — solo para login del kiosko.
     */

    public function buscarLogin(): mixed
    {
        $model = new EmpleadoModel();
        $query = trim($this->request->getVar('query') ?? '');

        if ($query === '') {
            return $this->respond(['status' => 'error', 'message' => 'Se requiere un identificador'], 400);
        }

        $len = strlen($query);
        if (!in_array($len, [6, 11, 13, 18], true) && !ctype_digit($query)) {
            return $this->respond(['status' => 'error', 'message' => 'Identificador inválido'], 422);
        }

        $existe = $model->buscarBiometrico($query, $len);
        if (!$existe) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado, verifica tu identificador'], 404);
        }

        return $this->respond(['status' => 'ok', 'data' => $existe]);
    }

    /**
     * POST /api/v1/biometrico/registro
     *
     * Registra entrada o salida del biométrico.
     * Body: { id_empleado, lat, lon, ip, salida:bool }
     */
    public function registro(): mixed
    {
        $actor        = $this->request->jwtUser ?? null;
        $idCapturista = (int)($this->request->getVar('id_capturista') ?? ($actor ? $actor->id : 0));

        $model = new EmpleadoModel();

        $rules = [
            'id_empleado' => 'required|integer|greater_than[0]',
            'lat'         => 'required|decimal',
            'lon'         => 'required|decimal',
            'ip'          => 'required|valid_ip',
        ];

        if (!$this->validate($rules)) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'Datos inválidos',
                'errors'  => $this->validator->getErrors(),
            ], 422);
        }

        $idEmpleado  = (int)$this->request->getVar('id_empleado');
        $lat         = (float)$this->request->getVar('lat');
        $lon         = (float)$this->request->getVar('lon');
        $ip          = trim($this->request->getVar('ip'));
        $salida      = filter_var($this->request->getVar('salida'), FILTER_VALIDATE_BOOLEAN);
        $nuevoStatus = $salida ? 2 : 1;

        // ── Obtener turno del empleado ────────────────────────────
        $db    = \Config\Database::connect();
        $turno = $db->query(
            'SELECT e.id_turno, mt.valor AS turno_valor
             FROM empleados e
             LEFT JOIN multicatalogo mt ON e.id_turno = mt.id
             WHERE e.id = ?',
            [$idEmpleado]
        )->getRowArray();

        $idTurno = (int)($turno['id_turno'] ?? 0);

        $configTurnos = [
            1367 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true,  'tipo' => '24x24'],
            1382 => ['entrada' => '08:00', 'salida' => '20:00', 'siguiente_dia' => false, 'tipo' => '12x12'],
            1387 => ['entrada' => '09:00', 'salida' => '19:00', 'siguiente_dia' => false, 'tipo' => 'admin'],
            1398 => ['entrada' => '08:00', 'salida' => '08:00', 'siguiente_dia' => true,  'tipo' => '12x24'],
            1399 => ['entrada' => '07:00', 'salida' => '19:00', 'siguiente_dia' => false, 'tipo' => 'diurno'],
            1400 => ['entrada' => '19:00', 'salida' => '07:00', 'siguiente_dia' => true,  'tipo' => 'nocturno'],
        ];

        $configTurno = $configTurnos[$idTurno] ?? null;
        $ahora       = new \DateTime('now', new \DateTimeZone('America/Mexico_City'));

        // ── Validar doble entrada/salida ──────────────────────────
        $ultima     = $model->ultimaAsistencia($idEmpleado);
        $lastStatus = (int)(($ultima['data'] ?? [])['id_status'] ?? 0);

        if ($nuevoStatus === 2 && $lastStatus !== 1) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'No puedes registrar salida: sin entrada previa',
            ], 409);
        }

        if ($nuevoStatus === 1 && $lastStatus === 1) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'No puedes registrar doble entrada: ya tienes una entrada activa',
            ], 409);
        }

        // ── Lógica de ENTRADA ─────────────────────────────────────
        $estadoEntrada  = null;
        $minutosRetardo = 0;

        if ($nuevoStatus === 1 && $configTurno) {
            $horaEntrada = new \DateTime(
                $ahora->format('Y-m-d') . ' ' . $configTurno['entrada'] . ':00',
                new \DateTimeZone('America/Mexico_City')
            );
            $diffMinutos = ($ahora->getTimestamp() - $horaEntrada->getTimestamp()) / 60;

            if ($diffMinutos <= 0) {
                $estadoEntrada = 'puntual';
            } elseif ($diffMinutos <= 15) {
                $estadoEntrada  = 'retardo_leve';
                $minutosRetardo = (int)$diffMinutos;
            } else {
                $estadoEntrada  = 'retardo_grave';
                $minutosRetardo = (int)$diffMinutos;
            }
        }

        // ── Incidencia retardo grave ──────────────────────────────
        if ($estadoEntrada === 'retardo_grave') {
            try {
                $db->table('incidencias')->insert([
                    'id_empleado' => $idEmpleado,
                    'tipo'        => 'retardo',
                    'descripcion' => "Retardo de {$minutosRetardo} minutos en entrada",
                    'fecha'       => $ahora->format('Y-m-d'),
                    'hora'        => $ahora->format('H:i:s'),
                    'estatus'     => 0,
                    'activo'      => 1,
                    'created_at'  => $ahora->format('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                log_message('error', 'Error incidencia retardo: ' . $e->getMessage());
            }
        }

        // ── Lógica de SALIDA — bloquear si es anticipada ──────────
        $ultimaEntrada = null;
        if ($nuevoStatus === 2 && $configTurno) {
            $ultimaEntrada = $db->query(
                "SELECT id, fecha, hora FROM asistencias
                 WHERE id_empleado = ? AND id_status = 1
                 ORDER BY id DESC LIMIT 1",
                [$idEmpleado]
            )->getRowArray();

            if ($ultimaEntrada) {
                $fechaEntrada = new \DateTime(
                    $ultimaEntrada['fecha'] . ' ' . $ultimaEntrada['hora'],
                    new \DateTimeZone('America/Mexico_City')
                );

                $fechaSalida = clone $fechaEntrada;
                if ($configTurno['siguiente_dia']) {
                    $fechaSalida->modify('+1 day');
                }
                [$hSal, $mSal] = explode(':', $configTurno['salida']);
                $fechaSalida->setTime((int)$hSal, (int)$mSal, 0);

                $ventanaMinima = clone $fechaSalida;
                $ventanaMinima->modify('-15 minutes');

                if ($ahora->getTimestamp() < $ventanaMinima->getTimestamp()) {
                    $minutosRestantes = (int)(($ventanaMinima->getTimestamp() - $ahora->getTimestamp()) / 60);
                    $horas            = floor($minutosRestantes / 60);
                    $mins             = $minutosRestantes % 60;

                    // Incidencia salida anticipada
                    try {
                        $db->table('incidencias')->insert([
                            'id_empleado' => $idEmpleado,
                            'tipo'        => 'salida_anticipada',
                            'descripcion' => "Intento de salida {$minutosRestantes} min antes del horario",
                            'fecha'       => $ahora->format('Y-m-d'),
                            'hora'        => $ahora->format('H:i:s'),
                            'estatus'     => 0,
                            'activo'      => 1,
                            'created_at'  => $ahora->format('Y-m-d H:i:s'),
                        ]);
                    } catch (\Exception $e) {
                        log_message('error', 'Error incidencia salida anticipada: ' . $e->getMessage());
                    }

                    return $this->respond([
                        'status'  => 'bloqueado',
                        'message' => 'No puedes registrar tu salida todavía',
                        'data'    => [
                            'hora_salida_valida' => $ventanaMinima->format('H:i') . ' del ' . $fechaSalida->format('d/m/Y'),
                            'tiempo_restante'    => "{$horas}h {$mins}min",
                            'minutos_restantes'  => $minutosRestantes,
                        ],
                    ], 423);
                }
            }
        }

        // ── Registrar asistencia ──────────────────────────────────
        $servicio   = $model->servicioMasCercano($lat, $lon, 500);
        $idServicio = (int)(($servicio['data'] ?? [])['id'] ?? 0);

        $asistencia = $model->registrarAsistencia(
            $idEmpleado, $lat, $lon, $ip,
            $nuevoStatus,
            $idCapturista,
            $idServicio,
            [
                'id_turno'              => $idTurno,
                'estado_entrada'        => $estadoEntrada,
                'minutos_retardo'       => $minutosRetardo,
                'id_asistencia_entrada' => $nuevoStatus === 2 ? ($ultimaEntrada['id'] ?? null) : null,
            ]
        );

        if ($asistencia['status'] !== 'ok') {
            return $this->respond($asistencia, 500);
        }

        $model->actualizarActividad($idEmpleado, $nuevoStatus);

        AuditLibrary::log(
            $idCapturista,
            $nuevoStatus === 1 ? 'BIOMETRICO_ENTRADA' : 'BIOMETRICO_SALIDA',
            'asistencias',
            (string)$idEmpleado,
            ($nuevoStatus === 1 ? 'Entrada' : 'Salida') . " — turno: {$idTurno} — IP: {$ip}"
        );

        // ── Respuesta enriquecida ─────────────────────────────────
        $response = [
            'status'  => 'ok',
            'tipo'    => $nuevoStatus === 1 ? 'entrada' : 'salida',
            'mensaje' => $nuevoStatus === 1 ? 'Entrada registrada' : 'Salida registrada',
            'data'    => [
                'turno'           => $configTurno['tipo'] ?? 'desconocido',
                'estado_entrada'  => $estadoEntrada,
                'minutos_retardo' => $minutosRetardo,
            ],
        ];

        if ($nuevoStatus === 1 && $configTurno) {
            $horaSalida = clone $ahora;
            if ($configTurno['siguiente_dia']) $horaSalida->modify('+1 day');
            [$h, $m] = explode(':', $configTurno['salida']);
            $horaSalida->setTime((int)$h, (int)$m, 0);
            $response['data']['hora_salida_esperada'] = $horaSalida->format('H:i \d\e\l d/m/Y');
        }

        return $this->respond($response);
    }


    /**
     * GET /api/v1/biometrico/estado/:id?salida=0|1
     * Verifica si el empleado puede registrar entrada/salida SIN registrar nada.
     */
    public function estado(int $idEmpleado): mixed
    {
        $esSalida = (bool)($this->request->getGet('salida') ?? false);
        $model    = new EmpleadoModel();
        $db       = \Config\Database::connect();

        $ultima     = $model->ultimaAsistencia($idEmpleado);
        $lastStatus = (int)(($ultima['data'] ?? [])['id_status'] ?? 0);

        if (!$esSalida && $lastStatus === 1) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'No puedes registrar doble entrada: ya tienes una entrada activa',
            ], 409);
        }

        if ($esSalida && $lastStatus !== 1) {
            return $this->respond([
                'status'  => 'error',
                'message' => 'No puedes registrar salida: sin entrada previa',
            ], 409);
        }

        if ($esSalida) {
            $turno = $db->query(
                'SELECT e.id_turno FROM empleados e WHERE e.id = ?', [$idEmpleado]
            )->getRowArray();

            $idTurno      = (int)($turno['id_turno'] ?? 0);
            $configTurnos = [
                1367 => ['salida' => '08:00', 'siguiente_dia' => true],
                1382 => ['salida' => '20:00', 'siguiente_dia' => false],
                1387 => ['salida' => '19:00', 'siguiente_dia' => false],
                1398 => ['salida' => '08:00', 'siguiente_dia' => true],
                1399 => ['salida' => '19:00', 'siguiente_dia' => false],
                1400 => ['salida' => '07:00', 'siguiente_dia' => true],
            ];

            $config = $configTurnos[$idTurno] ?? null;
            if ($config) {
                $ultimaEntrada = $db->query(
                    "SELECT fecha, hora FROM asistencias
                     WHERE id_empleado = ? AND id_status = 1
                     ORDER BY id DESC LIMIT 1",
                    [$idEmpleado]
                )->getRowArray();

                if ($ultimaEntrada) {
                    $ahora        = new \DateTime('now', new \DateTimeZone('America/Mexico_City'));
                    $fechaEntrada = new \DateTime(
                        $ultimaEntrada['fecha'] . ' ' . $ultimaEntrada['hora'],
                        new \DateTimeZone('America/Mexico_City')
                    );
                    $fechaSalida = clone $fechaEntrada;
                    if ($config['siguiente_dia']) $fechaSalida->modify('+1 day');
                    [$h, $m] = explode(':', $config['salida']);
                    $fechaSalida->setTime((int)$h, (int)$m, 0);

                    $ventana = clone $fechaSalida;
                    $ventana->modify('-15 minutes');

                    if ($ahora->getTimestamp() < $ventana->getTimestamp()) {
                        $mins  = (int)(($ventana->getTimestamp() - $ahora->getTimestamp()) / 60);
                        $horas = floor($mins / 60);
                        $minsR = $mins % 60;
                        return $this->respond([
                            'status'  => 'bloqueado',
                            'message' => 'No puedes registrar tu salida todavía',
                            'data'    => [
                                'hora_salida_valida' => $ventana->format('H:i') . ' del ' . $fechaSalida->format('d/m/Y'),
                                'tiempo_restante'    => "{$horas}h {$minsR}min",
                                'minutos_restantes'  => $mins,
                            ],
                        ], 423);
                    }
                }
            }
        }

        return $this->respond(['status' => 'ok', 'message' => 'Puede registrar']);
    }
    /**
     * POST /api/v1/biometrico/qr/generar
     *
     * Genera un token QR para un empleado (válido 5 minutos).
     * Body: { id_empleado }
     */
    public function qrGenerar(): mixed
    {
        $idEmpleado = (int)($this->request->getVar('id_empleado') ?? 0);

        if ($idEmpleado <= 0) {
            return $this->respond(['status' => 'error', 'message' => 'id_empleado requerido'], 400);
        }

        $tokenModel = new TokenQrModel();
        $token      = $tokenModel->generarToken($idEmpleado);

        return $this->respond(['status' => 'ok', 'qr_token' => $token]);
    }

    /**
     * POST /api/v1/biometrico/qr/usar
     *
     * Usa un token QR para registrar asistencia (un solo uso, 5 min de vida).
     * Body: { token }
     */
    public function qrUsar(): mixed
    {
        $token = trim($this->request->getVar('token') ?? '');

        if ($token === '') {
            return $this->respond(['status' => 'error', 'message' => 'Token QR no proporcionado'], 400);
        }

        $tokenModel = new TokenQrModel();
        $result     = $tokenModel->usarToken($token);

        return $this->respond($result, $result['status'] === 'ok' ? 200 : 403);
    }

    /**
     * GET /api/v1/biometrico/registros
     * Parámetros: search, date_from, date_to, page, pageSize
     */
    public function registros(): mixed
    {
        $model    = new \App\Models\IncidenciaModel();
        $search   = trim($this->request->getVar('search')    ?? '');
        $dateFrom = trim($this->request->getVar('date_from') ?? '');
        $dateTo   = trim($this->request->getVar('date_to')   ?? '');
        $page     = (int)($this->request->getVar('page')     ?? 1);
        $pageSize = (int)($this->request->getVar('pageSize') ?? 25);

        return $this->respond(
            $model->registrosBiometrico($search, $dateFrom, $dateTo, $page, $pageSize)
        );
    }
}
