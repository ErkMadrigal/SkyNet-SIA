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
        $actor = $this->request->jwtUser;
        $model = new EmpleadoModel();

        $rules = [
            'id_empleado' => 'required|integer|greater_than[0]',
            'lat'         => 'required|decimal',
            'lon'         => 'required|decimal',
            'ip'          => 'required|valid_ip',
        ];

        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }

        $idEmpleado  = (int)$this->request->getVar('id_empleado');
        $lat         = (float)$this->request->getVar('lat');
        $lon         = (float)$this->request->getVar('lon');
        $ip          = trim($this->request->getVar('ip'));
        $salida      = (bool)($this->request->getVar('salida') ?? false);
        $nuevoStatus = $salida ? 2 : 1;

        // Validar doble entrada/salida
        $ultima     = $model->ultimaAsistencia($idEmpleado);
        $lastStatus = (int)(($ultima['data'] ?? [])['id_status'] ?? 0);

        if ($nuevoStatus === 2 && $lastStatus !== 1) {
            return $this->respond(['status' => 'error', 'message' => 'No puedes registrar doble salida: sin entrada previa'], 409);
        }

        if ($nuevoStatus === 1 && $lastStatus === 1) {
            return $this->respond(['status' => 'error', 'message' => 'No puedes registrar doble entrada: ya tiene entrada sin salida'], 409);
        }

        // Buscar servicio por coordenadas (radio 500 m)
        $servicio   = $model->servicioMasCercano($lat, $lon, 500);
        $idServicio = (int)(($servicio['data'] ?? [])['id'] ?? 0);

        // Registrar asistencia
        $asistencia = $model->registrarAsistencia(
            $idEmpleado, $lat, $lon, $ip,
            $nuevoStatus,
            (int)$actor->id,
            $idServicio
        );

        if ($asistencia['status'] !== 'ok') {
            return $this->respond($asistencia, 500);
        }

        // Actualizar estado_actual del empleado
        $model->actualizarActividad($idEmpleado, $nuevoStatus);

        AuditLibrary::log(
            (int)$actor->id,
            $nuevoStatus === 1 ? 'BIOMETRICO_ENTRADA' : 'BIOMETRICO_SALIDA',
            'asistencias',
            (string)$idEmpleado,
            ($nuevoStatus === 1 ? 'Entrada' : 'Salida') . " registrada — IP: {$ip}"
        );

        return $this->respond($asistencia);
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
