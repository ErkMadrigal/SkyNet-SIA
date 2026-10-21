<?php

namespace App\Controllers\Api\V1;

use App\Libraries\AuditLibrary;
use App\Models\CatalogoModel;
use App\Models\EmpleadoModel;

/**
 * Alta de empleados desde el kiosko (rutas públicas /empleados-kiosko, igual
 * que biometrico/*). Solo jefes de turno: id_operador debe ser un empleado con
 * usuario SIAG activo ligado.
 *
 *   GET  catalogos   turnos, puestos, periodicidad, parentesco, escolaridad, tipo de sangre
 *   POST /           crear empleado (multipart, foto opcional). RFC y CLABE opcionales.
 */
class EmpleadosKioskoController extends EmpleadosController
{
    public function catalogos(): mixed
    {
        $m = new CatalogoModel();
        $lista = fn(int $id) => ($m->getCatalogo($id)['data'] ?? []);
        return $this->respond(['status' => 'ok', 'data' => [
            'turno'        => $lista(7),
            'periodicidad' => $lista(9),
            'parentesco'   => $lista(17),
            'escolaridad'  => $lista(1),
            'tipoSangre'   => $lista(3),
        ]]);
    }

    /** Usuario SIAG activo ligado al empleado operador (jefe de turno), o null. */
    private function jefe(): ?array
    {
        $idOperador = (int)($this->request->getVar('id_operador') ?? 0);
        if ($idOperador <= 0) return null;
        return \Config\Database::connect()->query(
            'SELECT id FROM usuario WHERE id_empleado = ? AND estatus = 1 AND is_deleted = 0 LIMIT 1',
            [$idOperador]
        )->getRowArray() ?: null;
    }

    /** GET /empleados-kiosko/(:num)?id_operador=  -> datos personales y de contacto */
    public function detalle($id = null): mixed
    {
        if (!$this->jefe()) {
            return $this->respond(['status' => 'error', 'message' => 'Solo jefes de turno pueden editar empleados'], 403);
        }
        $e = (new EmpleadoModel())->find((int)$id);
        if (!$e) return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        $campos = ['id','nombre','paterno','materno','curp','rfc','nss','CP_fiscal','alergias','tipoSangre','escolaridad',
                   'nombreEmergencia','parentesco','telefonoEmergencia','fotos','rostro_enrolado'];
        return $this->respond(['status' => 'ok', 'data' => array_intersect_key($e, array_flip($campos))]);
    }

    /** POST /empleados-kiosko/(:num)/actualizar  -> solo datos personales y de contacto (sin banco ni sueldo) */
    public function actualizarKiosko($id = null): mixed
    {
        $jefe = $this->jefe();
        if (!$jefe) {
            return $this->respond(['status' => 'error', 'message' => 'Solo jefes de turno pueden editar empleados'], 403);
        }
        $model = new EmpleadoModel();
        if (!$model->find((int)$id)) {
            return $this->respond(['status' => 'error', 'message' => 'Empleado no encontrado'], 404);
        }

        $rules = [
            'curp'    => 'required|max_length[20]',
            'nss'     => 'required|exact_length[11]|numeric',
            'cp'      => 'required|exact_length[5]|numeric',
            'paterno' => 'required|max_length[255]',
            'materno' => 'permit_empty|max_length[255]',
            'nombre'  => 'required|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }

        $curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$this->request->getVar('curp')));
        $rfc  = strtoupper(trim((string)($this->request->getVar('rfc') ?? '')));
        $nss  = preg_replace('/\D/', '', (string)$this->request->getVar('nss'));

        $dup = [];
        foreach (['curp' => ['CURP', $curp], 'rfc' => ['RFC', $rfc], 'nss' => ['NSS', $nss]] as $campo => [$etq, $valor]) {
            $ex = $valor !== '' ? $model->buscarPorIdentidad($valor) : null;
            if ($ex && (int)$ex['id'] !== (int)$id) {
                $dup[$campo] = "Ya lo tiene otro empleado (ID {$ex['id']}) — {$etq}";
            }
        }
        if ($dup) {
            return $this->respond(['status' => 'error', 'message' => 'Dato duplicado', 'errors' => $dup], 409);
        }

        $datos = [
            'curp'               => $curp,
            'rfc'                => $rfc,
            'nss'                => $nss,
            'CP_fiscal'          => preg_replace('/\D/', '', (string)$this->request->getVar('cp')),
            'paterno'            => trim((string)$this->request->getVar('paterno')),
            'materno'            => trim((string)($this->request->getVar('materno') ?? '')),
            'nombre'             => trim((string)$this->request->getVar('nombre')),
            'alergias'           => $this->request->getVar('alergias') ?: 'N/A',
            'tipoSangre'         => $this->request->getVar('tipoSangre') ?: null,
            'escolaridad'        => $this->request->getVar('escolaridad') ?: null,
            'parentesco'         => $this->request->getVar('parentesco') ?: null,
            'nombreEmergencia'   => $this->request->getVar('nombreEmergencia') ?: null,
            'telefonoEmergencia' => $this->request->getVar('telefonoEmergencia') ?: null,
            'updated_by'         => (int)$jefe['id'],
        ];

        $archivo = $this->request->getFile('fotoEmpleado');
        if ($archivo && $archivo->isValid() && !$archivo->hasMoved()) {
            $r = $this->subirFoto($archivo, $curp);
            if ($r['status'] !== 'ok') return $this->respond($r, 422);
            $datos['fotos'] = $r['url'];
        }

        $res = $model->actualizarPersonal((int)$id, $datos);
        AuditLibrary::log((int)$jefe['id'], 'ACTUALIZAR_EMPLEADO', 'empleados', (string)$id, 'Edición desde el kiosko');
        return $this->respond($res, ($res['status'] ?? '') === 'ok' ? 200 : 500);
    }

    public function crearKiosko(): mixed
    {
        $db   = \Config\Database::connect();
        $jefe = $this->jefe();
        if (!$jefe) {
            return $this->respond(['status' => 'error', 'message' => 'Solo jefes de turno pueden dar de alta empleados'], 403);
        }

        $rfc   = strtoupper(trim((string)($this->request->getVar('rfc') ?? '')));

        $rules = [
            'curp'         => 'required|max_length[20]',
            'nss'          => 'required|exact_length[11]|numeric',
            'cp'           => 'required|exact_length[5]|numeric',
            'paterno'      => 'required|max_length[255]',
            'materno'      => 'permit_empty|max_length[255]',
            'nombre'       => 'required|max_length[255]',
            'turno'        => 'required',
            'periodicidad' => 'required',
        ];
        $mensajes = [
            'curp'         => ['required' => 'El CURP es obligatorio', 'max_length' => 'El CURP es demasiado largo (máx. 20)'],
            'nss'          => ['required' => 'El NSS es obligatorio', 'exact_length' => 'El NSS debe tener 11 dígitos', 'numeric' => 'El NSS solo lleva números'],
            'cp'           => ['required' => 'El código postal es obligatorio', 'exact_length' => 'El código postal debe tener 5 dígitos', 'numeric' => 'El código postal solo lleva números'],
            'paterno'      => ['required' => 'El apellido paterno es obligatorio'],
            'nombre'       => ['required' => 'El nombre es obligatorio'],
            'turno'        => ['required' => 'Selecciona un turno'],
            'periodicidad' => ['required' => 'Selecciona la periodicidad de pago'],
        ];
        if (!$this->validate($rules, $mensajes)) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => $this->validator->getErrors()], 422);
        }
        if (!$this->request->getFile('fotoEmpleado') || !$this->request->getFile('fotoEmpleado')->isValid()) {
            return $this->respond(['status' => 'error', 'message' => 'Datos inválidos', 'errors' => ['fotoEmpleado' => 'La foto es obligatoria (hombros a cabeza)']], 422);
        }

        // Puesto por defecto: GUARDIA
        $puestoRow = $db->query("SELECT m.id FROM multicatalogo m WHERE m.id_catalogo = 10 AND UPPER(m.valor) LIKE 'GUARDIA%' ORDER BY m.id LIMIT 1")->getRowArray();
        if (!$puestoRow) {
            return $this->respond(['status' => 'error', 'message' => 'No existe el puesto GUARDIA en el catálogo'], 500);
        }

        $model = new EmpleadoModel();
        $curp  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$this->request->getVar('curp')));
        $nss   = preg_replace('/\D/', '', (string)$this->request->getVar('nss'));

        $erroresDup = [];
        foreach (['curp' => ['CURP', $curp], 'rfc' => ['RFC', $rfc], 'nss' => ['NSS', $nss]] as $campo => [$etq, $valor]) {
            $ex = $valor !== '' ? $model->buscarPorIdentidad($valor) : null;
            if ($ex) {
                $quien = trim(($ex['nombre'] ?? '') . ' ' . ($ex['paterno'] ?? '') . ' ' . ($ex['materno'] ?? ''));
                $erroresDup[$campo] = "Ya existe un empleado con este {$etq} (ID " . ($ex['id'] ?? '?') . ($quien !== '' ? ": {$quien}" : '') . ')';
            }
        }
        if ($erroresDup) {
            return $this->respond(['status' => 'error', 'message' => 'Ya existe un empleado con ese dato', 'errors' => $erroresDup], 409);
        }

        $fotoUrl = $this->fotoBaseUrl . 'default.png';
        $archivo = $this->request->getFile('fotoEmpleado');
        if ($archivo && $archivo->isValid() && !$archivo->hasMoved()) {
            $r = $this->subirFoto($archivo, $curp);
            if ($r['status'] !== 'ok') {
                return $this->respond($r, 422);
            }
            $fotoUrl = $r['url'];
        }

        $hoy = (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $datos = [
            'curp'                => $curp,
            'rfc'                 => $rfc,
            'nss'                 => $nss,
            'CP_fiscal'           => preg_replace('/\D/', '', (string)$this->request->getVar('cp')),
            'fecha_ingreso'       => $hoy,
            'fecha_efectiva'      => $this->request->getVar('fecha_efectiva') ?: $hoy,
            'paterno'             => trim((string)$this->request->getVar('paterno')),
            'materno'             => trim((string)($this->request->getVar('materno') ?? '')),
            'nombre'              => trim((string)$this->request->getVar('nombre')),
            'id_turno'            => $this->request->getVar('turno'),
            'id_puesto'           => (int)$puestoRow['id'],
            'id_periocidad'       => $this->request->getVar('periodicidad'),
            'estatus'             => 1,
            'alergias'            => $this->request->getVar('alergias') ?: 'N/A',
            'fotos'               => $fotoUrl,
            'tipoSangre'          => $this->request->getVar('tipoSangre') ?: null,
            'escolaridad'         => $this->request->getVar('escolaridad') ?: null,
            'parentesco'          => $this->request->getVar('parentesco') ?: null,
            'nombreEmergencia'    => $this->request->getVar('nombreEmergencia') ?: null,
            'telefonoEmergencia'  => $this->request->getVar('telefonoEmergencia') ?: null,
            'created_by'          => (int)$jefe['id'],
        ];

        $res = $model->registrar($datos);

        AuditLibrary::log((int)$jefe['id'], 'CREAR_EMPLEADO', 'empleados', (string)($res['last_insert_id'] ?? ''), 'Alta desde el kiosko');

        if (($res['status'] ?? '') === 'ok') {
            $res['numero'] = str_pad((string)$res['last_insert_id'], 6, '0', STR_PAD_LEFT);
            $res['empleado'] = ['id' => (int)$res['last_insert_id'], 'nombreCompleto' => trim($datos['nombre'] . ' ' . $datos['paterno'] . ' ' . $datos['materno']), 'curp' => $curp, 'rfc' => $rfc, 'fotos' => $fotoUrl, 'rostro_enrolado' => 0];
        }
        return $this->respond($res, ($res['status'] ?? '') === 'ok' ? 201 : 500);
    }
}
