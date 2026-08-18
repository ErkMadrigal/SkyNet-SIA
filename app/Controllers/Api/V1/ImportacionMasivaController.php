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


    public function altasXlsx(): mixed
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '1024M');

        $actor = $this->request->jwtUser;
        if (!$actor) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo identificar al usuario'], 401);
        }

        $origen    = trim((string)($this->request->getVar('origen') ?? '')) ?: null;
        $idCliente = (int)($this->request->getVar('id_cliente') ?? 0);
        if ($idCliente <= 0) $idCliente = 100; // default acordado para esta carga

        // Id de lote -- lo genera el front (crypto.randomUUID()) una sola
        // vez por sesión de envío y lo manda igual en cada archivo. Sirve
        // para poder deshacer TODA la sesión con un solo DELETE si el
        // usuario da "no" en el botón de confirmar/rollback.
        $loteId = trim((string)($this->request->getVar('lote_id') ?? '')) ?: null;

        // El usuario decide con un botón en el front si el sueldo que trae
        // el xlsx es quincenal (se multiplica x2 para sacar salario_mensual)
        // o ya viene mensual (se usa tal cual). Default = quincenal (x2)
        // para no romper el comportamiento que ya tenías si el front viejo
        // no manda este campo.
        $duplicarSueldoRaw = $this->request->getVar('duplicar_sueldo');
        $duplicarSueldo    = $duplicarSueldoRaw === null ? true : in_array((string)$duplicarSueldoRaw, ['1', 'true', 'on'], true);

        // Acepta varios archivos (archivos[]) o uno solo (archivo) -- tu
        // frontend manda uno por request (mismo patrón que ya usas en
        // enviar()), pero se deja abierto por si algún día mandas varios
        // juntos.
        $archivos = $this->request->getFileMultiple('archivos') ?: [];
        if (empty($archivos)) {
            $unico = $this->request->getFile('archivo');
            if ($unico && $unico->isValid()) $archivos = [$unico];
        }
        if (empty($archivos)) {
            return $this->respond(['status' => 'error', 'message' => 'No se recibió ningún archivo'], 400);
        }

        $db = \Config\Database::connect();

        $totalGlobal       = 0;
        $insertadosGlobal  = 0;
        $erroresGlobal     = 0;
        $detalleGlobal     = [];
        $resumenPorArchivo = [];

        foreach ($archivos as $archivo) {
            if (!$archivo || !$archivo->isValid()) {
                $detalleGlobal[] = [
                    'archivo' => $archivo?->getClientName() ?? '(desconocido)',
                    'fila'    => '—',
                    'mensaje' => 'Archivo inválido o corrupto',
                ];
                continue;
            }

            $nombreArchivo = $archivo->getClientName();
            $tmpPath       = WRITEPATH . 'uploads/' . $archivo->getRandomName();
            $archivo->move(WRITEPATH . 'uploads', basename($tmpPath));

            $totalArchivo      = 0;
            $insertadosArchivo = 0;
            $erroresArchivo    = 0;

            try {
                $reader       = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmpPath);
                $nombresHojas = $reader->listWorksheetNames($tmpPath);

                $hojaAltas = null;
                foreach ($nombresHojas as $h) {
                    if (strcasecmp(trim($h), 'Altas') === 0) { $hojaAltas = $h; break; }
                }
                if (!$hojaAltas) {
                    @unlink($tmpPath);
                    $detalleGlobal[]    = ['archivo' => $nombreArchivo, 'fila' => '—', 'mensaje' => 'No se encontró la hoja "Altas" en este archivo'];
                    $resumenPorArchivo[] = ['archivo' => $nombreArchivo, 'total' => 0, 'insertados' => 0, 'errores' => 1];
                    continue;
                }

                $reader->setLoadSheetsOnly([$hojaAltas]);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($tmpPath);
                $sheet       = $spreadsheet->getSheetByName($hojaAltas);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                $detalleGlobal[]    = ['archivo' => $nombreArchivo, 'fila' => '—', 'mensaje' => 'Error leyendo el archivo: ' . $e->getMessage()];
                $resumenPorArchivo[] = ['archivo' => $nombreArchivo, 'total' => 0, 'insertados' => 0, 'errores' => 1];
                continue;
            }

            // ── Fila de encabezados: busca "Nombre" -- se amplía a las
            // primeras 60 filas (antes solo 5) porque algunas plantillas
            // traen un bloque de filas OCULTAS con notas/instrucciones
            // antes de la fila real de encabezados. PhpSpreadsheet lee el
            // valor de una celda igual esté oculta o no la fila -- ocultar
            // una fila es solo un atributo visual, no borra el dato -- así
            // que esto no afecta la lectura de las filas de datos, solo
            // había que ampliar dónde se busca el encabezado.
            $maxCol       = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
            $headerRow    = null;
            $limiteHeader = min(60, $sheet->getHighestRow());
            for ($r = 1; $r <= $limiteHeader; $r++) {
                for ($c = 1; $c <= $maxCol; $c++) {
                    $v = trim((string)($sheet->getCell([$c, $r])->getValue() ?? ''));
                    if (strcasecmp($v, 'Nombre') === 0) { $headerRow = $r; break 2; }
                }
            }
            if ($headerRow === null) {
                @unlink($tmpPath);
                $detalleGlobal[]    = ['archivo' => $nombreArchivo, 'fila' => '—', 'mensaje' => 'No se encontró la columna "Nombre" en las primeras filas de "Altas"'];
                $resumenPorArchivo[] = ['archivo' => $nombreArchivo, 'total' => 0, 'insertados' => 0, 'errores' => 1];
                continue;
            }

            // Headers normalizados (mayúsculas, sin espacios, sin acentos --
            // mismo helper que ya usa ubicaciones()) para que dé igual si el
            // xlsx trae "Sueldo", "SUELDO", "sueldo " o "Sueldo Quincenal".
            $headers = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $v = trim((string)($sheet->getCell([$c, $headerRow])->getValue() ?? ''));
                if ($v !== '') $headers[$this->normalizarHeader($v)] = $c;
            }

            // El sueldo puede venir como "SUELDO", "SUELDO QUINCENAL" o
            // "SUELDO MENSUAL" (en cualquier combinación de mayúsculas/
            // minúsculas/espacios -- ya normalizado arriba). Sea cual sea
            // la columna que traiga el archivo, el x2 o no lo decide el
            // botón del front ($duplicarSueldo), no el nombre de la columna.
            $colSueldo = $headers['SUELDOMENSUAL'] ?? $headers['SUELDOQUINCENAL'] ?? $headers['SUELDO'] ?? null;

            $leer = function ($col, $r) use ($sheet) {
                if (!$col) return '';
                return trim((string)($sheet->getCell([$col, $r])->getValue() ?? ''));
            };

            $batch      = [];
            $highestRow = $sheet->getHighestRow();

            for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
                // Fila totalmente vacía -- se ignora sin contar como error.
                $filaVacia = true;
                for ($c = 1; $c <= $maxCol; $c++) {
                    if (trim((string)($sheet->getCell([$c, $r])->getValue() ?? '')) !== '') { $filaVacia = false; break; }
                }
                if ($filaVacia) continue;

                $totalArchivo++;

                $nombre = $leer($headers['NOMBRE'] ?? null, $r);
                $rfc    = strtoupper($leer($headers['RFC'] ?? null, $r));

                if ($nombre === '') {
                    $motivo = $rfc === '' ? 'Sin nombre ni RFC' : 'Sin nombre';
                    $detalleGlobal[] = ['archivo' => $nombreArchivo, 'fila' => $r, 'mensaje' => "Fila omitida: {$motivo}"];
                    $erroresArchivo++;
                    continue;
                }

                // salario_mensual = sueldo capturado x2 (si es quincenal) o
                // tal cual (si ya es mensual) -- lo decide $duplicarSueldo,
                // que viene del botón del front para todo el lote.
                $sueldoRaw      = $colSueldo ? preg_replace('/[^\d.]/', '', $leer($colSueldo, $r)) : '';
                $salarioMensual = $sueldoRaw !== '' ? round(((float)$sueldoRaw) * ($duplicarSueldo ? 2 : 1), 2) : null;

                // Fecha_Alta -- puede venir como fecha real de Excel (numérica)
                // o como texto ya formateado.
                $fechaEfectiva = null;
                $colFecha = $headers['FECHA_ALTA'] ?? null;
                if ($colFecha) {
                    $valorCelda = $sheet->getCell([$colFecha, $r])->getValue();
                    if (is_numeric($valorCelda)) {
                        try {
                            $fechaEfectiva = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valorCelda)->format('Y-m-d');
                        } catch (\Throwable $e) {
                            $fechaEfectiva = null;
                        }
                    } else {
                        $txt = trim((string)$valorCelda);
                        $ts  = $txt !== '' ? strtotime($txt) : false;
                        $fechaEfectiva = $ts ? date('Y-m-d', $ts) : null;
                    }
                }

                $idOrNull = fn($v) => ((int)$v > 0) ? (int)$v : null;

                $batch[] = [
                    'nombre'                => $nombre,
                    'paterno'               => $leer($headers['PATERNO'] ?? null, $r),
                    'materno'               => $leer($headers['MATERNO'] ?? null, $r),
                    // NULL en vez de '' cuando vienen vacíos -- si CURP/RFC/NSS
                    // tienen algún índice UNIQUE en la tabla, dos filas con ''
                    // chocan como si fueran duplicadas y tiran TODO el lote de
                    // insertBatch sin avisar. NULL nunca choca contra NULL.
                    'curp'                  => strtoupper($leer($headers['CURP'] ?? null, $r)) ?: null,
                    'rfc'                   => $rfc !== '' ? $rfc : null,
                    'nss'                   => $leer($headers['NSS'] ?? null, $r) ?: null,
                    'CP_fiscal'             => $leer($headers['CP_FISCAL'] ?? null, $r),
                    'alergias'              => $leer($headers['ALERGIA'] ?? null, $r) ?: 'N/A',
                    'escolaridad'           => $idOrNull($leer($headers['ID_ESCOLARIDAD'] ?? null, $r)),
                    'tipoSangre'            => $idOrNull($leer($headers['ID_TIPOSANGRE'] ?? null, $r)),
                    'telefonoEmergencia'    => $leer($headers['TELEFONO_EMERGENCIA'] ?? null, $r),
                    'nombreEmergencia'      => $leer($headers['NOMBRE_EMERGENCIA'] ?? null, $r),
                    'parentesco'            => $idOrNull($leer($headers['ID_PARENTESCO'] ?? null, $r)),
                    'id_turno'              => $idOrNull($leer($headers['ID_TURNO'] ?? null, $r)),
                    'id_puesto'             => $idOrNull($leer($headers['ID_PUESTO'] ?? null, $r)),
                    'id_periocidad'         => $idOrNull($leer($headers['ID_PERIODICIDAD'] ?? null, $r)),
                    'fecha_efectiva'        => $fechaEfectiva,
                    'clave_interbancaria'   => $leer($headers['CLABE_INTERBANCARIA'] ?? null, $r) ?: null,
                    'salario_mensual'       => $salarioMensual,
                    'modo_sueldo'           => 'salario',
                    'id_cliente'            => $idCliente,
                    'carga_masiva'          => 1,
                    'origen_carga_temporal' => $origen,
                    'lote_importacion'      => $loteId,
                    'created_at'            => date('Y-m-d H:i:s'),
                ];

                if (count($batch) >= 500) {
                    $insertadosArchivo += $this->insertarLoteAltas($db, $batch, $nombreArchivo, $detalleGlobal, $erroresArchivo);
                    $batch = [];
                }
            }

            if ($batch) {
                $insertadosArchivo += $this->insertarLoteAltas($db, $batch, $nombreArchivo, $detalleGlobal, $erroresArchivo);
            }

            @unlink($tmpPath);

            $resumenPorArchivo[] = [
                'archivo'    => $nombreArchivo,
                'total'      => $totalArchivo,
                'insertados' => $insertadosArchivo,
                'errores'    => $erroresArchivo,
            ];

            $totalGlobal      += $totalArchivo;
            $insertadosGlobal += $insertadosArchivo;
            $erroresGlobal    += $erroresArchivo;
        }

        \App\Libraries\AuditLibrary::log(
            (int)$actor->id,
            'CARGA_MASIVA_ALTAS',
            'empleados',
            null,
            "Carga masiva de altas (origen: " . ($origen ?? 'sin especificar') . ") -- "
                . count($archivos) . " archivo(s), {$insertadosGlobal} insertados, {$erroresGlobal} errores"
        );

        return $this->respond([
            'status'      => 'ok',
            'total'       => $totalGlobal,
            'insertados'  => $insertadosGlobal,
            'duplicados'  => 0, // a propósito no se hace match/dedup aquí -- ver conversación
            'errores'     => $erroresGlobal,
            'detalle'     => $detalleGlobal,
            'por_archivo' => $resumenPorArchivo,
        ]);
    }

    /**
     * Inserta un lote de altas con fallback fila-por-fila.
     *
     * insertBatch() manda TODAS las filas del lote en una sola query --
     * si UNA sola choca contra un UNIQUE (ej. dos filas con el mismo
     * curp, o si dbDebug está apagado y falla en silencio), se pierden
     * las 500 filas completas sin que nadie se entere. Aquí, si el
     * batch completo falla, se reintenta insertando fila por fila para
     * salvar las que sí sirven y reportar con precisión cuál y por qué
     * falló cada una que no.
     */
    private function insertarLoteAltas($db, array $batch, string $nombreArchivo, array &$detalleGlobal, int &$erroresArchivo): int
    {
        try {
            if ($db->table('empleados')->insertBatch($batch)) {
                return count($batch);
            }
        } catch (\Throwable $e) {
            // cae al modo fila-por-fila de abajo
        }

        $insertadosOk = 0;
        foreach ($batch as $fila) {
            try {
                if ($db->table('empleados')->insert($fila)) {
                    $insertadosOk++;
                    continue;
                }
                $erroresArchivo++;
                $detalleGlobal[] = [
                    'archivo' => $nombreArchivo,
                    'fila'    => '—',
                    'mensaje' => "No se pudo insertar a \"{$fila['nombre']}\": " . ($db->error()['message'] ?? 'error desconocido'),
                ];
            } catch (\Throwable $e) {
                $erroresArchivo++;
                $detalleGlobal[] = [
                    'archivo' => $nombreArchivo,
                    'fila'    => '—',
                    'mensaje' => "No se pudo insertar a \"{$fila['nombre']}\": " . $e->getMessage(),
                ];
            }
        }

        return $insertadosOk;
    }

    /**
     * POST /api/v1/importacion-masiva/altas-xlsx/confirmar
     * Body: lote_id
     *
     * No borra ni modifica nada -- los registros ya quedaron insertados
     * desde altasXlsx(). Esto solo dejA constancia en el log de auditoría
     * de que el usuario revisó el resultado y decidió quedarse con el
     * lote (para trazabilidad/legal, igual que el resto del sistema).
     */
    public function confirmarLote(): mixed
    {
        $actor = $this->request->jwtUser;
        if (!$actor) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo identificar al usuario'], 401);
        }

        $loteId = trim((string)($this->request->getVar('lote_id') ?? ''));
        if ($loteId === '') {
            return $this->respond(['status' => 'error', 'message' => 'Falta lote_id'], 400);
        }

        $db = \Config\Database::connect();
        $count = $db->table('empleados')
            ->where('lote_importacion', $loteId)
            ->where('carga_masiva', 1)
            ->countAllResults();

        \App\Libraries\AuditLibrary::log(
            (int)$actor->id,
            'CARGA_MASIVA_ALTAS_CONFIRMADA',
            'empleados',
            null,
            "Lote {$loteId} confirmado por el usuario -- {$count} empleados quedan definitivos"
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => "Lote confirmado ({$count} empleados)",
            'total'   => $count,
        ]);
    }

    /**
     * POST /api/v1/importacion-masiva/altas-xlsx/rollback
     * Body: lote_id
     *
     * Borra (DELETE físico) SOLO las filas de empleados que pertenecen a
     * ese lote_id específico -- es decir, solo lo que se insertó en esa
     * sesión de subida de archivos. No toca ninguna otra carga_masiva de
     * otro día/otro lote. Doble candado: carga_masiva=1 además de
     * lote_importacion, para nunca poder rozar un empleado que no vino
     * de esta carga.
     */
    public function rollbackLote(): mixed
    {
        $actor = $this->request->jwtUser;
        if (!$actor) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo identificar al usuario'], 401);
        }

        $loteId = trim((string)($this->request->getVar('lote_id') ?? ''));
        if ($loteId === '') {
            return $this->respond(['status' => 'error', 'message' => 'Falta lote_id'], 400);
        }

        $db = \Config\Database::connect();

        $count = $db->table('empleados')
            ->where('lote_importacion', $loteId)
            ->where('carga_masiva', 1)
            ->countAllResults();

        if ($count === 0) {
            return $this->respond([
                'status'     => 'ok',
                'message'    => 'No había registros para ese lote (0 filas) -- nada que borrar',
                'eliminados' => 0,
            ]);
        }

        $db->table('empleados')
            ->where('lote_importacion', $loteId)
            ->where('carga_masiva', 1)
            ->delete();

        \App\Libraries\AuditLibrary::log(
            (int)$actor->id,
            'CARGA_MASIVA_ALTAS_ROLLBACK',
            'empleados',
            null,
            "Rollback de lote {$loteId} -- {$count} empleados eliminados (borrado físico)"
        );

        return $this->respond([
            'status'     => 'ok',
            'message'    => "Se eliminaron {$count} empleados del lote",
            'eliminados' => $count,
        ]);
    }
}