<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * ImportacionMasivaController
 *
 * Cargas masivas de 10,000+ registros de un solo golpe. Protegido por
 * el filtro 'importClave' (clave extra además del JWT normal -- ver
 * ImportacionMasivaFilter).
 *
 * Rutas (prefijo /api/v1/importacion-masiva):
 *   POST /empleados     -> carga masiva de empleados nuevos
 *   POST /ubicaciones    -> carga masiva de servicios/ubicaciones
 */
class ImportacionMasivaController extends ResourceController
{
    protected $format = 'json';

    /** Tamaño de lote para insertBatch -- balance entre velocidad y tamaño de query */
    private const CHUNK_SIZE = 500;

    private const BANCO_MAP = [
        '002' => 1227, '012' => 1230, '014' => 1231, '021' => 1233,
        '030' => 1234, '036' => 1236, '044' => 1239, '072' => 1244,
        '127' => 1255, '137' => 1265, '145' => 1271,
    ];

    /**
     * POST /api/v1/importacion-masiva/empleados
     * Body (multipart): archivo=xlsx
     *
     * Columnas esperadas (24, en este orden):
     *   Nombre, Paterno, Materno, CURP, RFC, NSS, CP_Fiscal, Alergia,
     *   Escolaridad, id_escolaridad, Tipo_sangre, id_tiposangre,
     *   Telefono_Emergencia, Nombre_Emergencia, Parentesco, id_parentesco,
     *   Turno, id_turno, Puesto, id_puesto, Periodicidad de pago,
     *   id_periodicidad, Fecha_Alta, Clabe_Interbancaria, Foto
     *
     * Usa INSERT IGNORE (respeta el UNIQUE de curp) -- CURPs duplicados
     * se saltan sin tronar, no se sobreescribe a nadie existente.
     */
    public function empleados(): mixed
    {
        @set_time_limit(0); // sin límite -- 10k+ filas puede tardar
        @ini_set('memory_limit', '1024M');

        $actor = $this->request->jwtUser;

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->respond(['status' => 'error', 'message' => 'Debes subir un archivo .xlsx válido'], 400);
        }

        $tmpPath = WRITEPATH . 'uploads/' . $archivo->getRandomName();
        $archivo->move(WRITEPATH . 'uploads', basename($tmpPath));

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $maxRow = $sheet->getHighestRow();
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            return $this->respond(['status' => 'error', 'message' => 'Error leyendo el Excel: ' . $e->getMessage()], 422);
        }

        // Lee celda: si es fórmula, usa el valor cacheado; si no, valor plano
        $leer = function (int $col, int $row) use ($sheet) {
            $v = $sheet->getCell([$col, $row])->getValue();
            if (is_string($v) && str_starts_with(trim($v), '=')) {
                $v = $sheet->getCell([$col, $row])->getOldCalculatedValue();
            }
            return $v;
        };

        $db = \Config\Database::connect();
        $batch = [];
        $procesadas = 0;
        $omitidas = 0;
        $curpsVistos = [];
        $duplicadosInternos = 0;

        for ($r = 2; $r <= $maxRow; $r++) {
            $nombre = trim((string)($leer(1, $r) ?? ''));
            $curp   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($leer(4, $r) ?? '')));

            if (!$nombre || !$curp) { $omitidas++; continue; }
            if (isset($curpsVistos[$curp])) { $duplicadosInternos++; continue; }
            $curpsVistos[$curp] = true;

            $clabe = preg_replace('/\D/', '', (string)($leer(24, $r) ?? '')); // col24 = Clabe_Interbancaria
            $codBanco = substr($clabe, 0, 3);
            $idBanco = self::BANCO_MAP[$codBanco] ?? null;

            // Fecha_Alta (col 23)
            $fechaRaw = $leer(23, $r);
            $fecha = '2026-01-01';
            if ($fechaRaw instanceof \DateTime) {
                $fecha = $fechaRaw->format('Y-m-d');
            } elseif ($fechaRaw && is_numeric($fechaRaw) && $fechaRaw > 0 && $fechaRaw < 60000) {
                try {
                    $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($fechaRaw)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $fecha = '2026-01-01';
                }
            } elseif (is_string($fechaRaw) && trim($fechaRaw) !== '') {
                $ts = strtotime($fechaRaw);
                if ($ts !== false) $fecha = date('Y-m-d', $ts);
            }

            $idEscolaridad = (int)($leer(10, $r) ?: 0) ?: null;
            $idTipoSangre  = (int)($leer(12, $r) ?: 0) ?: null;
            $idParentesco  = (int)($leer(16, $r) ?: 0) ?: null;
            $idTurno       = (int)($leer(18, $r) ?: 0) ?: null;
            $idPuesto      = (int)($leer(20, $r) ?: 0) ?: null;
            $idPeriocidad  = (int)($leer(22, $r) ?: 0) ?: null;

            $batch[] = [
                'nombre'              => $nombre,
                'paterno'             => trim((string)($leer(2, $r) ?? '')),
                'materno'             => trim((string)($leer(3, $r) ?? '')),
                'curp'                => $curp,
                'rfc'                 => strtoupper(trim((string)($leer(5, $r) ?? ''))),
                'nss'                 => preg_replace('/\D/', '', (string)($leer(6, $r) ?? '')),
                'CP_fiscal'           => str_pad(preg_replace('/\D/', '', (string)($leer(7, $r) ?? '')), 5, '0', STR_PAD_LEFT) ?: '00000',
                'alergias'            => strtoupper(trim((string)($leer(8, $r) ?? 'NINGUNA'))),
                'escolaridad'         => $idEscolaridad,
                'tipoSangre'          => $idTipoSangre,
                'telefonoEmergencia'  => preg_replace('/\D/', '', (string)($leer(13, $r) ?? '')),
                'nombreEmergencia'    => strtoupper(trim((string)($leer(14, $r) ?? ''))),
                'parentesco'          => $idParentesco,
                'id_turno'            => $idTurno,
                'id_puesto'           => $idPuesto,
                'id_periocidad'       => $idPeriocidad,
                'fecha_ingreso'       => $fecha,
                'fecha_efectiva'      => $fecha,
                'clave_interbancaria' => $clabe ?: null,
                'id_banco'            => $idBanco,
            ];
            $procesadas++;

            if (count($batch) >= self::CHUNK_SIZE) {
                $this->insertarLoteEmpleados($db, $batch, (int)$actor->id);
                $batch = [];
            }
        }

        if ($batch) {
            $this->insertarLoteEmpleados($db, $batch, (int)$actor->id);
        }

        @unlink($tmpPath);

        AuditLibrary::log((int)$actor->id, 'IMPORTACION_MASIVA_EMPLEADOS', 'empleados', '-',
            "Importó {$procesadas} empleados desde {$archivo->getClientName()} ({$omitidas} omitidas, {$duplicadosInternos} duplicados en el mismo archivo)");

        return $this->respond([
            'status'  => 'ok',
            'message' => "Se procesaron {$procesadas} empleados",
            'data'    => [
                'procesadas'          => $procesadas,
                'omitidas'            => $omitidas,
                'duplicados_internos' => $duplicadosInternos,
                'archivo'             => $archivo->getClientName(),
            ],
        ], 201);
    }

    /** INSERT IGNORE en batch -- respeta UNIQUE(curp), nunca sobreescribe */
    private function insertarLoteEmpleados($db, array $batch, int $creadoPor): void
    {
        $valores = array_map(function ($e) use ($creadoPor) {
            return "('{$e['nombre']}','{$e['paterno']}','{$e['materno']}','{$e['curp']}','{$e['rfc']}','{$e['nss']}',".
                "'{$e['CP_fiscal']}','{$e['alergias']}'," .
                ($e['escolaridad'] ?? 'NULL') . "," . ($e['tipoSangre'] ?? 'NULL') . "," .
                "'{$e['telefonoEmergencia']}','{$e['nombreEmergencia']}'," .
                ($e['parentesco'] ?? 'NULL') . "," . ($e['id_turno'] ?? 'NULL') . "," .
                ($e['id_puesto'] ?? 'NULL') . "," . ($e['id_periocidad'] ?? 'NULL') . "," .
                "'{$e['fecha_ingreso']}','{$e['fecha_efectiva']}'," .
                ($e['clave_interbancaria'] ? "'{$e['clave_interbancaria']}'" : 'NULL') . "," .
                ($e['id_banco'] ?? 'NULL') . ",1,1,0,{$creadoPor})";
        }, $batch);

        $db->query(
            "INSERT IGNORE INTO empleados (nombre,paterno,materno,curp,rfc,nss,CP_fiscal,alergias,escolaridad,tipoSangre,telefonoEmergencia,nombreEmergencia,parentesco,id_turno,id_puesto,id_periocidad,fecha_ingreso,fecha_efectiva,clave_interbancaria,id_banco,estatus,acceso_biometrico,is_deleted,created_by) VALUES " .
            implode(',', $valores)
        );
    }

    /** Normaliza texto de header: sin acentos, sin espacios, mayúsculas -- para matchear por texto, no posición */
    private function normalizarHeader(string $texto): string
    {
        $texto = trim($texto);
        $texto = strtr($texto, [
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
        ]);
        return strtoupper(preg_replace('/\s+/', '', $texto));
    }

    /**
     * POST /api/v1/importacion-masiva/ubicaciones
     * Body (multipart): archivo=xlsx
     *
     * Columnas detectadas por HEADER (normalizado -- sin acentos/espacios/mayúsculas),
     * no por posición fija. 'ubicacion' y 'Ubicación' normalizan igual, así que se
     * tratan como UNA sola columna (se usa la primera que aparezca, sin duplicar).
     *
     * NOTA: trae 'id' explícito -- usa INSERT IGNORE por PRIMARY KEY.
     * Si el id ya existe, esa fila se salta (no se actualiza). Si quieres
     * que SÍ actualice los existentes, dime y lo cambio a
     * ON DUPLICATE KEY UPDATE.
     */
    public function ubicaciones(): mixed
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $actor = $this->request->jwtUser;

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->respond(['status' => 'error', 'message' => 'Debes subir un archivo .xlsx válido'], 400);
        }

        $tmpPath = WRITEPATH . 'uploads/' . $archivo->getRandomName();
        $archivo->move(WRITEPATH . 'uploads', basename($tmpPath));

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $maxRow = $sheet->getHighestRow();
            $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            return $this->respond(['status' => 'error', 'message' => 'Error leyendo el Excel: ' . $e->getMessage()], 422);
        }

        // ── Detectar columnas por header normalizado (fila 1) ──────────────
        $mapaTerminos = [
            'id'         => ['ID'],
            'servicio'   => ['SERVICIO'],
            'elementos'  => ['ELEMENTOS'],
            'ubicacion'  => ['UBICACION'], // matchea 'ubicacion' Y 'Ubicación' -- se queda con la primera
            'cp'         => ['CP'],
            'latitud'    => ['LATITUD'],
            'longitud'   => ['LONGITUD'],
            'id_cliente' => ['IDCLIENTE'],
            'id_empresa' => ['IDEMPRESA'],
            'id_partida' => ['IDPARTIDA'],
            'id_zona'    => ['IDZONA'],
            'estatus'    => ['ESTATUS'],
        ];

        $cols = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $header = $this->normalizarHeader((string)($sheet->getCell([$c, 1])->getValue() ?? ''));
            if ($header === '') continue;
            foreach ($mapaTerminos as $campo => $terminos) {
                if (isset($cols[$campo])) continue; // ya encontrada -- no sobreescribir (así ubicacion/Ubicación no duplican)
                if (in_array($header, $terminos, true)) {
                    $cols[$campo] = $c;
                    break;
                }
            }
        }

        $faltantes = array_diff(['id', 'servicio'], array_keys($cols));
        if ($faltantes) {
            @unlink($tmpPath);
            return $this->respond([
                'status' => 'error',
                'message' => 'No se encontraron las columnas requeridas: ' . implode(', ', $faltantes),
            ], 422);
        }

        $leer = fn(int $col, int $row) => trim((string)($sheet->getCell([$col, $row])->getValue() ?? ''));
        $col  = fn(string $campo, int $row) => isset($cols[$campo]) ? $leer($cols[$campo], $row) : '';

        $db = \Config\Database::connect();
        $batch = [];
        $procesadas = 0;
        $omitidas = 0;

        for ($r = 2; $r <= $maxRow; $r++) {
            $id       = $col('id', $r);
            $servicio = $col('servicio', $r);

            if ($id === '' || !is_numeric($id) || $servicio === '') { $omitidas++; continue; }

            $lat = $col('latitud', $r);
            $lng = $col('longitud', $r);

            $batch[] = [
                'id'          => (int)$id,
                'servicio'    => $servicio,
                'elementos'   => (int)($col('elementos', $r) ?: 0),
                'ubicacion'   => $col('ubicacion', $r),
                'cp'          => str_pad(preg_replace('/\D/', '', $col('cp', $r)), 5, '0', STR_PAD_LEFT) ?: '00000',
                'latitud'     => is_numeric($lat) ? $lat : '0',
                'longitud'    => is_numeric($lng) ? $lng : '0',
                'id_cliente'  => (int)($col('id_cliente', $r) ?: 0) ?: null,
                'id_empresa'  => (int)($col('id_empresa', $r) ?: 0) ?: null,
                'id_partida'  => (int)($col('id_partida', $r) ?: 0) ?: null,
                'id_zona'     => (int)($col('id_zona', $r) ?: 0) ?: null,
                'estatus'     => (int)($col('estatus', $r) ?: 1),
            ];
            $procesadas++;

            if (count($batch) >= self::CHUNK_SIZE) {
                $this->insertarLoteUbicaciones($db, $batch);
                $batch = [];
            }
        }

        if ($batch) {
            $this->insertarLoteUbicaciones($db, $batch);
        }

        @unlink($tmpPath);

        AuditLibrary::log((int)$actor->id, 'IMPORTACION_MASIVA_UBICACIONES', 'servicios', '-',
            "Importó {$procesadas} ubicaciones desde {$archivo->getClientName()} ({$omitidas} omitidas)");

        return $this->respond([
            'status'  => 'ok',
            'message' => "Se procesaron {$procesadas} ubicaciones",
            'data'    => ['procesadas' => $procesadas, 'omitidas' => $omitidas, 'archivo' => $archivo->getClientName()],
        ], 201);
    }

    private function insertarLoteUbicaciones($db, array $batch): void
    {
        $esc = fn($v) => str_replace("'", "''", (string)$v);

        $valores = array_map(function ($u) use ($esc) {
            return "({$u['id']},'{$esc($u['servicio'])}',{$u['elementos']},'{$esc($u['ubicacion'])}'," .
                "'{$u['cp']}',{$u['latitud']},{$u['longitud']}," .
                ($u['id_cliente'] ?? 'NULL') . "," . ($u['id_empresa'] ?? 'NULL') . "," .
                ($u['id_partida'] ?? 'NULL') . "," . ($u['id_zona'] ?? 'NULL') . "," .
                "{$u['estatus']}, NOW(), NOW())";
        }, $batch);

        $db->query(
            "INSERT IGNORE INTO servicios (id,servicio,elementos,ubicacion,cp,latitud,longitud,id_cliente,id_empresa,id_partida,id_zona,estatus,created_at,updated_at) VALUES " .
            implode(',', $valores)
        );
    }
}