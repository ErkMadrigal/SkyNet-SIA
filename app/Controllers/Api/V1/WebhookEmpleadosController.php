<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Webhook: la plataforma de empleados avisa de altas y cambios.
 *
 *   POST /api/v1/webhooks/empleados        header X-API-KEY = WEBHOOK_EMPLEADOS_KEY (.env)
 *
 * Cuerpo JSON: un empleado, o {"empleados":[ ... ]} (máx. 500):
 *   {
 *     "evento":     "empleado.creado" | "empleado.actualizado"   (opcional; ambos son "crear o actualizar")
 *     "numero_empleado": "032808",       número de empleado que asigna la otra plataforma (6 dígitos o sin ceros). ES el id de SIAG.
 *     "id_externo": "ABC-123",          id interno (uuid) del empleado en la otra plataforma (opcional)
 *     "curp":       "XXXX000000HXXXXX00",  obligatorio, 18 caracteres
 *     "nombre":     "JUAN", "paterno": "PEREZ", "materno": "LOPEZ",
 *                   (o solo "nombre_completo": "PEREZ LOPEZ JUAN", en orden paterno, materno, nombre(s); mejor mandarlos separados)
 *     "turno":      "12x12"              texto del turno: 12x12, 12 X 24, 24x24, 07:00 a 19:00, 19:00 a 07:00 ...
 *                   (o "id_turno": 1367 si ya conocen el id del turno en SIAG)
 *   }
 *
 * Reglas:
 *  - Si viene numero_empleado, el empleado se crea con ESE id (no se usa el autoincremental). Si ese número ya
 *    existe con otra CURP, o la CURP ya existe con otro número, se rechaza con error (no se pisa nada).
 *  - Se busca por numero_empleado, luego id_externo y luego CURP. Si existe se actualizan SOLO nombre, apellidos y turno
 *    (si vienen). La ubicación, el estatus y el rostro enrolado se quedan en SIAG y nunca se tocan aquí.
 *  - Si no existe se crea activo y con el rostro SIN enrolar (la tablet lo manda a enrolarse).
 *  - Es idempotente: mandar el mismo aviso dos veces no duplica nada.
 *  - Cada aviso queda en la bitácora webhook_empleados_log.
 *
 *   GET  /api/v1/webhooks/empleados/estado?curp=A,B&numero=033063&id_externo=uuid   (hasta 100)
 *        ¿ya tiene rostro enrolado? Regresa rostro_enrolado, y si ya fue confirmado como migrado.
 *   POST /api/v1/webhooks/empleados/confirmar   {"empleados":[{"numero_empleado":"033063","curp":"..."}]}
 *        la otra plataforma confirma "ya lo tengo": SIAG marca la fecha (migrado_at). No borra nada.
 */
class WebhookEmpleadosController extends ResourceController
{
    protected $format = 'json';

    private function autorizado(): bool
    {
        $key = (string)env('WEBHOOK_EMPLEADOS_KEY', '');
        $rec = $this->request->getHeaderLine('X-API-KEY');
        return $key !== '' && $rec !== '' && hash_equals($key, $rec);
    }

    public function recibir()
    {
        if (!$this->autorizado()) {
            return $this->respond(['status' => 'error', 'message' => 'API Key inválida o no proporcionada'], 401);
        }

        $json = $this->request->getJSON(true);
        if (!is_array($json) || !$json) {
            return $this->respond(['status' => 'error', 'message' => 'Se esperaba un JSON con el empleado o con "empleados": [...]'], 422);
        }
        $items = isset($json['empleados']) && is_array($json['empleados']) ? $json['empleados'] : [$json];
        if (count($items) > 500) {
            return $this->respond(['status' => 'error', 'message' => 'Máximo 500 empleados por aviso'], 422);
        }

        $db   = \Config\Database::connect();
        $ip   = $this->request->getIPAddress();
        $hoy  = (new \DateTime('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $admin = $db->query(
            'SELECT u.id FROM usuario u INNER JOIN rol_sistema rs ON rs.id = u.id_rol_sistema
             WHERE u.estatus = 1 AND u.is_deleted = 0 ORDER BY rs.nivel ASC, u.id ASC LIMIT 1'
        )->getRowArray();
        $creadoPor = (int)($admin['id'] ?? 0);

        $res = []; $resumen = ['creados' => 0, 'actualizados' => 0, 'errores' => 0];
        foreach ($items as $i => $it) {
            $r = is_array($it) ? $this->procesar($db, $it, $hoy, $creadoPor) : ['resultado' => 'error', 'mensaje' => 'Elemento inválido'];
            $r['indice'] = $i;
            if (is_array($it) && isset($it['id_externo'])) $r['id_externo'] = (string)$it['id_externo'];
            $this->bitacora($db, $ip, is_array($it) ? $it : [], $r);
            if ($r['resultado'] === 'creado') $resumen['creados']++;
            elseif ($r['resultado'] === 'actualizado') $resumen['actualizados']++;
            else $resumen['errores']++;
            $res[] = $r;
        }

        return $this->respond(['status' => 'ok', 'resumen' => $resumen, 'data' => $res]);
    }

    private function procesar($db, array $it, string $hoy, int $creadoPor): array
    {
        try {
            $curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($it['curp'] ?? '')));
            if (strlen($curp) !== 18) return ['resultado' => 'error', 'mensaje' => 'CURP inválida (debe tener 18 caracteres)'];

            $idExt   = isset($it['id_externo']) && $it['id_externo'] !== '' ? mb_substr((string)$it['id_externo'], 0, 60) : null;
            $nombre  = trim((string)($it['nombre'] ?? ''));
            $paterno = trim((string)($it['paterno'] ?? ''));
            $materno = trim((string)($it['materno'] ?? ''));
            if ($paterno === '' && $materno === '' && trim((string)($it['nombre_completo'] ?? '')) !== '') {
                [$nombre, $paterno, $materno] = $this->partirNombre((string)$it['nombre_completo']);
            }

            $aviso = null;
            $turno = isset($it['id_turno']) && (int)$it['id_turno'] > 0 ? (int)$it['id_turno'] : null;
            if ($turno === null && trim((string)($it['turno'] ?? '')) !== '') {
                $turno = $this->turnoPorTexto($db, (string)$it['turno']);
                if ($turno === null) $aviso = 'Turno no reconocido: "' . mb_substr((string)$it['turno'], 0, 40) . '". Se guardó sin turno.';
            }

            // Número de empleado que asigna la otra plataforma (acepta "032808", "32808" o 32808)
            $num = null;
            $numRaw = $it['numero_empleado'] ?? ($it['numero'] ?? null);
            if ($numRaw !== null && trim((string)$numRaw) !== '') {
                $d = preg_replace('/\D/', '', (string)$numRaw);
                $num = $d !== '' ? (int)$d : 0;
                if ($num <= 0) return ['resultado' => 'error', 'mensaje' => 'numero_empleado inválido'];
            }

            $emp = null;
            if ($num !== null) $emp = $db->query('SELECT id, curp, id_externo FROM empleados WHERE id = ? LIMIT 1', [$num])->getRowArray();
            if (!$emp && $idExt !== null) $emp = $db->query('SELECT id, curp, id_externo FROM empleados WHERE id_externo = ? LIMIT 1', [$idExt])->getRowArray();
            if (!$emp) $emp = $db->query('SELECT id, curp, id_externo FROM empleados WHERE curp = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1', [$curp])->getRowArray();

            if ($emp) {
                if (strtoupper((string)$emp['curp']) !== $curp) {
                    return ['resultado' => 'error', 'mensaje' => 'Ese número o id_externo ya pertenece a otro empleado con distinta CURP', 'id' => (int)$emp['id']];
                }
                if ($num !== null && (int)$emp['id'] !== $num) {
                    return ['resultado' => 'error', 'mensaje' => 'La CURP ya existe en SIAG con el número ' . str_pad((string)$emp['id'], 6, '0', STR_PAD_LEFT) . ', distinto al enviado', 'id' => (int)$emp['id']];
                }
                $set = ['updated_at' => date('Y-m-d H:i:s')];
                if ($nombre !== '')  $set['nombre']  = $nombre;
                if ($paterno !== '') $set['paterno'] = $paterno;
                if ($materno !== '') $set['materno'] = $materno;
                if ($turno !== null) $set['id_turno'] = $turno;
                if ($idExt !== null && ($emp['id_externo'] === null || $emp['id_externo'] === '')) $set['id_externo'] = $idExt;
                $db->table('empleados')->where('id', (int)$emp['id'])->update($set);
                return $this->ficha($db, (int)$emp['id'], 'actualizado', $aviso);
            }

            if ($nombre === '' || $paterno === '') return ['resultado' => 'error', 'mensaje' => 'Para crear un empleado se requiere nombre y paterno'];

            $fila = [
                'nombre' => $nombre, 'paterno' => $paterno, 'materno' => $materno, 'curp' => $curp,
                'rfc' => '', 'nss' => '', 'CP_fiscal' => '00000', 'alergias' => 'N/A',
                'telefonoEmergencia' => '', 'nombreEmergencia' => '',
                'id_turno' => $turno, 'fecha_ingreso' => $hoy, 'fecha_efectiva' => $hoy,
                'estatus' => 1, 'acceso_biometrico' => 1, 'is_deleted' => 0,
                'created_by' => $creadoPor, 'id_externo' => $idExt,
            ];
            if ($num !== null) $fila['id'] = $num;   // el número lo asigna la otra plataforma
            $db->table('empleados')->insert($fila);
            $id = $num !== null ? $num : (int)$db->insertID();
            if ($id <= 0) return ['resultado' => 'error', 'mensaje' => 'No se pudo crear el empleado'];
            return $this->ficha($db, $id, 'creado', $aviso);
        } catch (\Throwable $e) {
            log_message('error', 'Webhook empleados: ' . $e->getMessage());
            return ['resultado' => 'error', 'mensaje' => 'Error al guardar (¿falta correr migracion_webhook_empleados.sql?)'];
        }
    }


    /** Lee listas separadas por comas del query string. */
    private function lista(string $k): array
    {
        $v = trim((string)($this->request->getGet($k) ?? ''));
        return $v === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
    }

    /** GET estado: ¿ya tiene rostro enrolado? (por CURP, número de empleado o id_externo; una o varias) */
    public function estado()
    {
        if (!$this->autorizado()) return $this->respond(['status' => 'error', 'message' => 'API Key inválida o no proporcionada'], 401);

        $curps = array_map(fn($c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)), $this->lista('curp'));
        $nums  = array_values(array_filter(array_map(fn($n) => (int)preg_replace('/\D/', '', $n), $this->lista('numero'))));
        $exts  = $this->lista('id_externo');
        if (!$curps && !$nums && !$exts) {
            return $this->respond(['status' => 'error', 'message' => 'Manda curp, numero o id_externo (uno o varios separados por coma)'], 422);
        }
        if (count($curps) + count($nums) + count($exts) > 100) {
            return $this->respond(['status' => 'error', 'message' => 'Máximo 100 por consulta'], 422);
        }

        try {
            $db = \Config\Database::connect();
            $b = $db->table('empleados e')
                ->select("e.id, e.curp, e.id_externo, CONCAT_WS(' ', e.paterno, e.materno, e.nombre) AS nombre_completo, e.rostro_enrolado, e.migrado_at", false)
                ->where('e.is_deleted', 0)->groupStart();
            $primero = true;
            foreach ([['e.id', $nums], ['e.curp', $curps], ['e.id_externo', $exts]] as [$col, $vals]) {
                if (!$vals) continue;
                $primero ? $b->whereIn($col, $vals) : $b->orWhereIn($col, $vals);
                $primero = false;
            }
            $rows = $b->groupEnd()->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Webhook estado: ' . $e->getMessage());
            return $this->respond(['status' => 'error', 'message' => 'No se pudo consultar (¿falta correr migracion_webhook_empleados_2.sql?)'], 500);
        }

        $hallados = []; $data = [];
        foreach ($rows as $r) {
            $hallados['n:' . (int)$r['id']] = 1; $hallados['c:' . strtoupper((string)$r['curp'])] = 1;
            if ($r['id_externo'] !== null) $hallados['e:' . $r['id_externo']] = 1;
            $data[] = [
                'numero_empleado' => str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT),
                'id_externo'      => $r['id_externo'],
                'curp'            => $r['curp'],
                'nombre_completo' => $r['nombre_completo'],
                'rostro_enrolado' => (int)$r['rostro_enrolado'] === 1,
                'migrado'         => !empty($r['migrado_at']),
                'migrado_at'      => $r['migrado_at'],
            ];
        }
        $no = [];
        foreach ($nums as $n)   if (!isset($hallados['n:' . $n])) $no[] = str_pad((string)$n, 6, '0', STR_PAD_LEFT);
        foreach ($curps as $c)  if (!isset($hallados['c:' . $c])) $no[] = $c;
        foreach ($exts as $x)   if (!isset($hallados['e:' . $x])) $no[] = $x;

        return $this->respond(['status' => 'ok', 'total' => count($data), 'data' => $data, 'no_encontrados' => $no]);
    }

    /** POST confirmar: la otra plataforma avisa "ya tengo los datos de este empleado". No borra nada en SIAG. */
    public function confirmar()
    {
        if (!$this->autorizado()) return $this->respond(['status' => 'error', 'message' => 'API Key inválida o no proporcionada'], 401);

        $json = $this->request->getJSON(true);
        if (!is_array($json) || !$json) return $this->respond(['status' => 'error', 'message' => 'Se esperaba un JSON con el empleado o con "empleados": [...]'], 422);
        $items = isset($json['empleados']) && is_array($json['empleados']) ? $json['empleados'] : [$json];
        if (count($items) > 500) return $this->respond(['status' => 'error', 'message' => 'Máximo 500 por aviso'], 422);

        $db = \Config\Database::connect(); $ip = $this->request->getIPAddress();
        $res = []; $resumen = ['confirmados' => 0, 'ya_confirmados' => 0, 'errores' => 0];
        foreach ($items as $i => $it) {
            $r = is_array($it) ? $this->confirmarUno($db, $it) : ['resultado' => 'error', 'mensaje' => 'Elemento inválido'];
            $r['indice'] = $i;
            $this->bitacora($db, $ip, is_array($it) ? $it + ['evento' => 'confirmar'] : [], $r);
            if ($r['resultado'] === 'confirmado') $resumen['confirmados']++;
            elseif ($r['resultado'] === 'ya_confirmado') $resumen['ya_confirmados']++;
            else $resumen['errores']++;
            $res[] = $r;
        }
        return $this->respond(['status' => 'ok', 'resumen' => $resumen, 'data' => $res]);
    }

    private function confirmarUno($db, array $it): array
    {
        try {
            $curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($it['curp'] ?? '')));
            $num  = (int)preg_replace('/\D/', '', (string)($it['numero_empleado'] ?? ($it['numero'] ?? '')));
            $ext  = isset($it['id_externo']) ? trim((string)$it['id_externo']) : '';
            if ($curp === '' && $num <= 0 && $ext === '') return ['resultado' => 'error', 'mensaje' => 'Manda numero_empleado, curp o id_externo'];

            $emp = null;
            if ($num > 0)  $emp = $db->query('SELECT id, curp, id_externo, migrado_at FROM empleados WHERE id = ? AND is_deleted = 0', [$num])->getRowArray();
            if (!$emp && $ext !== '')  $emp = $db->query('SELECT id, curp, id_externo, migrado_at FROM empleados WHERE id_externo = ? AND is_deleted = 0', [$ext])->getRowArray();
            if (!$emp && $curp !== '') $emp = $db->query('SELECT id, curp, id_externo, migrado_at FROM empleados WHERE curp = ? AND is_deleted = 0 ORDER BY id DESC LIMIT 1', [$curp])->getRowArray();
            if (!$emp) return ['resultado' => 'no_encontrado', 'mensaje' => 'No existe ese empleado en SIAG'];
            if ($curp !== '' && strtoupper((string)$emp['curp']) !== $curp) return ['resultado' => 'error', 'mensaje' => 'Los datos enviados apuntan a empleados distintos', 'id' => (int)$emp['id']];

            $base = ['numero_empleado' => str_pad((string)$emp['id'], 6, '0', STR_PAD_LEFT)];
            if (!empty($emp['migrado_at'])) return $base + ['resultado' => 'ya_confirmado', 'migrado_at' => $emp['migrado_at'], 'id' => (int)$emp['id']];

            $ahora = date('Y-m-d H:i:s');
            $db->table('empleados')->where('id', (int)$emp['id'])->update(['migrado_at' => $ahora]);
            return $base + ['resultado' => 'confirmado', 'migrado_at' => $ahora, 'id' => (int)$emp['id']];
        } catch (\Throwable $e) {
            log_message('error', 'Webhook confirmar: ' . $e->getMessage());
            return ['resultado' => 'error', 'mensaje' => 'Error al guardar (¿falta correr migracion_webhook_empleados_2.sql?)'];
        }
    }

    /** "12x12", "12 X 12", "24*24", "07:00 a 19:00"... -> id del turno en el catálogo (o null si no coincide). */
    private function turnoPorTexto($db, string $texto): ?int
    {
        $norm = fn(string $t) => preg_replace('/[^A-Z0-9:]/', '', str_replace('*', 'X', strtoupper($t)));
        $buscado = $norm($texto);
        if ($buscado === '') return null;
        try {
            foreach ($db->query('SELECT id, valor FROM multicatalogo WHERE id_catalogo = 7')->getResultArray() as $r) {
                if ($norm((string)$r['valor']) === $buscado) return (int)$r['id'];
            }
        } catch (\Throwable $e) { /* sin catálogo: se usa el respaldo */ }
        $respaldo = ['12X12' => 1382, '12X24' => 1398, '24X24' => 1367, '07:00A19:00' => 1399, '19:00A07:00' => 1400];
        return $respaldo[$buscado] ?? null;
    }

    /**
     * nombre_completo viene como "APELLIDO PATERNO, APELLIDO MATERNO, NOMBRE(S)" (con o sin comas).
     * Con comas se respeta cada parte; sin comas: 1.ª palabra = paterno, 2.ª = materno, el resto = nombre(s).
     * Aproximado con apellidos compuestos ("DE LA CRUZ"): para esos casos conviene mandar los campos separados.
     */
    private function partirNombre(string $completo): array
    {
        if (str_contains($completo, ',')) {
            $p = array_map('trim', explode(',', $completo));
            if (count($p) >= 3) return [implode(' ', array_slice($p, 2)), $p[0], $p[1]];   // [nombre, paterno, materno]
            if (count($p) === 2) return [$p[1], $p[0], ''];
        }
        $t = preg_split('/\s+/', trim(str_replace(',', ' ', $completo)), -1, PREG_SPLIT_NO_EMPTY);
        $n = count($t);
        if ($n >= 3) return [implode(' ', array_slice($t, 2)), $t[0], $t[1]];
        if ($n === 2) return [$t[1], $t[0], ''];
        return ['', $t[0] ?? '', ''];
    }

    private function ficha($db, int $id, string $resultado, ?string $aviso = null): array
    {
        $e = $db->query('SELECT id, rostro_enrolado FROM empleados WHERE id = ?', [$id])->getRowArray();
        return [
            'resultado'       => $resultado,
            'aviso'           => $aviso,
            'id'              => $id,
            'numero_empleado' => str_pad((string)$id, 6, '0', STR_PAD_LEFT),
            'rostro_enrolado' => (int)($e['rostro_enrolado'] ?? 0) === 1,
        ];
    }

    private function bitacora($db, string $ip, array $it, array $r): void
    {
        try {
            $db->table('webhook_empleados_log')->insert([
                'ip' => $ip, 'evento' => mb_substr((string)($it['evento'] ?? ''), 0, 40),
                'id_externo' => isset($it['id_externo']) ? mb_substr((string)$it['id_externo'], 0, 60) : null,
                'curp' => isset($it['curp']) ? mb_substr((string)$it['curp'], 0, 20) : null,
                'resultado' => $r['resultado'], 'id_empleado' => $r['id'] ?? null,
                'mensaje' => $r['mensaje'] ?? null, 'payload' => json_encode($it, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* bitácora aún sin migrar: no se pierde el aviso */ }
    }
}
