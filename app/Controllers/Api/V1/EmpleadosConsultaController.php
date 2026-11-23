<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Consulta de empleados (solo lectura). Sin login: requiere únicamente el header X-API-KEY.
 *
 *   GET empleados/consulta?q=...                 un solo cuadro: coincide con RFC, CURP o NSS
 *   GET empleados/consulta?rfc=&curp=&nss=       una, dos o las tres (todas las enviadas deben coincidir)
 *   GET empleados/consulta?numero=000178         por número de empleado
 *
 * Opcionales: limit (1-100, default 50), bajas=1 (incluye dados de baja).
 * Exacta si el dato viene completo, por prefijo (mín. 4 caracteres) si es parcial.
 */
class EmpleadosConsultaController extends ResourceController
{
    protected $format = 'json';

    public function consultar()
    {
        $g = fn($k) => trim((string)($this->request->getGet($k) ?? ''));
        $filtros = array_filter(['rfc' => $g('rfc'), 'curp' => $g('curp'), 'nss' => $g('nss')], fn($v) => $v !== '');
        if ($g('numero') !== '') return $this->buscar('id', $g('numero'));
        if ($filtros) return $this->buscar('combinado', '', $filtros);   // una, dos o las tres (todas deben coincidir)
        return $this->buscar('todos', $g('q'));                          // un solo cuadro: coincide con RFC, CURP o NSS
    }

    /** Condición indexable para un campo: exacta si viene completo, por prefijo si no. */
    private function cond(string $c, string $v, array &$par): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', urldecode($v)));
        $v = $c === 'nss' ? preg_replace('/\D/', '', $v) : preg_replace('/[^A-Z0-9Ñ&]/u', '', $v);
        if (strlen($v) < 4) return null;
        $len = ['rfc' => 12, 'curp' => 18, 'nss' => 11][$c];
        if (strlen($v) >= $len) { $par[] = $v; return "e.{$c} = ?"; }
        $par[] = $v . '%'; return "e.{$c} LIKE ?";
    }

    private function buscar(string $campo, string $valor, array $filtros = [])
    {
        $limit = max(1, min(100, (int)($this->request->getGet('limit') ?? 50)));
        $bajas = $this->request->getGet('bajas') === '1';
        $where = []; $par = [];
        $minimo = ['status' => 'error', 'message' => 'Escribe al menos 4 caracteres'];

        if ($campo === 'id') {
            $n = (int)ltrim(preg_replace('/\D/', '', $valor), '0');
            if ($n <= 0) return $this->respond(['status' => 'error', 'message' => 'Número de empleado inválido'], 422);
            $where[] = 'e.id = ?'; $par[] = $n;
        } elseif ($campo === 'todos') {
            $cs = [];
            foreach (['rfc', 'curp', 'nss'] as $c) {
                $x = $this->cond($c, $valor, $p2);
                if ($x !== null && !($c === 'nss' && !ctype_digit(preg_replace('/\s+/', '', $valor)))) { $cs[] = $x; $par[] = array_pop($p2); }
            }
            if (!$cs) return $this->respond($minimo, 422);
            $where[] = '(' . implode(' OR ', $cs) . ')';
        } elseif ($campo === 'combinado') {
            foreach ($filtros as $c => $v) {
                $x = $this->cond($c, $v, $par);
                if ($x === null) return $this->respond($minimo, 422);
                $where[] = $x;
            }
        } else {
            $x = $this->cond($campo, $valor, $par);
            if ($x === null) return $this->respond($minimo, 422);
            $where[] = $x;
        }
        if (!$bajas) $where[] = 'e.is_deleted = 0';

        $sql = "SELECT e.id, CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombreCompleto,
                       e.curp, e.rfc, e.nss, e.fotos, e.rostro_enrolado, e.alergias,
                       ms.valor AS tipoSangre, e.nombreEmergencia, e.telefonoEmergencia, mp.valor AS parentesco
                FROM empleados e
                LEFT JOIN multicatalogo ms ON ms.id = e.tipoSangre
                LEFT JOIN multicatalogo mp ON mp.id = e.parentesco
                WHERE " . implode(' AND ', $where) . "
                ORDER BY e.id LIMIT {$limit}";

        try {
            $rows = \Config\Database::connect()->query($sql, $par)->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', 'Consulta empleados: ' . $e->getMessage());
            return $this->respond(['status' => 'error', 'message' => 'No se pudo consultar'], 500);
        }

        $base = rtrim((string)env('FOTOS_URL', base_url('fotos/')), '/') . '/';
        $data = array_map(fn($r) => [
            'numero_empleado'    => str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT),
            'id'                 => (int)$r['id'],
            'nombreCompleto'     => $r['nombreCompleto'],
            'curp'               => $r['curp'],
            'rfc'                => $r['rfc'],
            'nss'                => $r['nss'],
            'foto'               => $r['fotos'] ? $base . ltrim((string)$r['fotos'], '/') : null,
            'rostro_enrolado'    => (int)$r['rostro_enrolado'] === 1,
            'alergias'           => $r['alergias'],
            'tipoSangre'         => $r['tipoSangre'],
            'nombreEmergencia'   => $r['nombreEmergencia'],
            'telefonoEmergencia' => $r['telefonoEmergencia'],
            'parentesco'         => $r['parentesco'],
        ], $rows);

        return $this->respond(['status' => 'ok', 'campo' => $campo, 'total' => count($data), 'data' => $data]);
    }
}
