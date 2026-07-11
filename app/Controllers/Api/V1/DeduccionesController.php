<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * DeduccionesController
 *
 * Carga los archivos xlsx de FONACOT, INFONAVIT y pension alimenticia
 * a la tabla deducciones_empleado. Cada carga reemplaza las deducciones
 * del tipo correspondiente (desactiva las anteriores e inserta las nuevas).
 *
 * Las columnas del Excel se detectan por el TEXTO del header, no por
 * posicion fija -- no importa si "QUINCENAL" cae en la columna 10, 11
 * o 15, mientras el texto del encabezado coincida.
 *
 * El id_empleado NO viene en el Excel -- se resuelve buscando por RFC
 * (FONACOT) o NSS (INFONAVIT) contra la tabla empleados. Si una fila
 * no hace match, se omite (no se registra esa deduccion).
 *
 * Rutas (prefijo /api/v1/deducciones):
 *   POST /fonacot     -> carga xlsx de FONACOT
 *   POST /infonavit   -> carga xlsx de INFONAVIT
 *   POST /pension     -> carga xlsx de pension alimenticia
 *   GET  /            -> lista deducciones activas por empleado
 *   GET  /resumen     -> resumen de totales por tipo
 *   DELETE /:id       -> desactiva una deduccion individual
 */
class DeduccionesController extends ResourceController
{
    protected $format = 'json';

    /**
     * POST /api/v1/deducciones/fonacot
     * Body (multipart): archivo=xlsx
     * Busca id_empleado por RFC. Monto = columna que contenga "QUINCENAL".
     */
    public function fonacot(): mixed
    {
        return $this->cargarDeducciones('fonacot', [
            'campo_busqueda' => 'rfc',
            'headers' => [
                'clave_busqueda' => ['RFC'],
                'monto_quincenal'=> ['QUINCENAL'],
                'nss'            => ['SEGURIDAD SOCIAL', 'NO_SS', 'NO SS'],
                'nombre'         => ['NOMBRE'],
                'no_credito'     => ['FONACOT'],
                'anio_emision'   => ['ANIO'],
                'mes_emision'    => ['MES_EMISION', 'MES EMISION'],
            ],
        ]);
    }

    /**
     * POST /api/v1/deducciones/infonavit
     * Body (multipart): archivo=xlsx
     * Busca id_empleado por NSS. Monto = columna que contenga "QUINCENAL".
     */
    public function infonavit(): mixed
    {
        return $this->cargarDeducciones('infonavit', [
            'campo_busqueda' => 'nss',
            'headers' => [
                'clave_busqueda' => ['SEGURIDAD SOCIAL'],
                'monto_quincenal'=> ['QUINCENAL'],
                'no_credito'     => ['CREDITO'],
                'nombre'         => ['NOMBRE'],
                'tipo_descuento' => ['TIPO DE DESCUENTO', 'TIPO_DESCUENTO'],
            ],
        ]);
    }

    /**
     * POST /api/v1/deducciones/pension
     * Sin formato real confirmado -- sigue con columna fija hasta ver
     * el xlsx real (luego se puede cambiar al mismo patron de header).
     */
    public function pension(): mixed
    {
        return $this->cargarDeducciones('pension', [
            'campo_busqueda' => 'id_directo',
            'col_fija' => [
                'clave_busqueda'  => 1,
                'nombre'          => 2,
                'no_credito'      => 3,
                'monto_quincenal' => 5,
            ],
        ]);
    }

    /** Normaliza texto de header: mayusculas, sin acentos, espacios colapsados */
    private function normalizarHeader(string $texto): string
    {
        $texto = trim($texto);
        $texto = strtr($texto, [
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N',
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n',
        ]);
        $texto = strtoupper($texto);
        $texto = preg_replace('/\s+/', ' ', $texto);
        return trim($texto);
    }

    /**
     * Busca la fila de headers (primeras 8 filas) y detecta la columna de
     * cada campo por texto. Regresa ['headerRow' => int, 'cols' => [campo => colIndex]]
     * o null si no encontro los campos REQUERIDOS ('clave_busqueda' y 'monto_quincenal').
     */
    private function detectarColumnasPorHeader($sheet, array $mapaHeaders): ?array
    {
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $maxRowCheck = min(8, $sheet->getHighestRow());

        for ($r = 1; $r <= $maxRowCheck; $r++) {
            $colsEncontradas = [];

            for ($c = 1; $c <= $maxCol; $c++) {
                $valor = $this->normalizarHeader((string)($sheet->getCell([$c, $r])->getValue() ?? ''));
                if ($valor === '') continue;

                foreach ($mapaHeaders as $campo => $terminos) {
                    if (isset($colsEncontradas[$campo])) continue;
                    foreach ($terminos as $termino) {
                        if (str_contains($valor, $this->normalizarHeader($termino))) {
                            $colsEncontradas[$campo] = $c;
                            break;
                        }
                    }
                }
            }

            if (isset($colsEncontradas['clave_busqueda']) && isset($colsEncontradas['monto_quincenal'])) {
                return ['headerRow' => $r, 'cols' => $colsEncontradas];
            }
        }

        return null;
    }

    /**
     * Logica compartida de carga -- lee el xlsx (detectando columnas por
     * header, o usando columnas fijas si col_fija viene dado), resuelve
     * id_empleado por RFC/NSS, desactiva deducciones anteriores del mismo
     * tipo y las reemplaza con las nuevas.
     */
    private function cargarDeducciones(string $tipo, array $config): mixed
    {
        $actor = $this->request->jwtUser;

        $archivo = $this->request->getFile('archivo');
        if (!$archivo || !$archivo->isValid()) {
            return $this->respond(['status' => 'error', 'message' => 'Debes subir un archivo .xlsx valido'], 400);
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

        $campoBusqueda = $config['campo_busqueda'] ?? 'id_directo';

        if (isset($config['col_fija'])) {
            $cols = $config['col_fija'];
            $headerRow = 1;
        } else {
            $deteccion = $this->detectarColumnasPorHeader($sheet, $config['headers']);
            if ($deteccion === null) {
                @unlink($tmpPath);
                $requeridos = $campoBusqueda === 'rfc' ? 'RFC' : 'Numero de Seguridad Social';
                return $this->respond([
                    'status'  => 'error',
                    'message' => "No se encontraron las columnas requeridas ('{$requeridos}' y una columna que contenga 'QUINCENAL') en las primeras 8 filas del archivo.",
                ], 422);
            }
            $cols = $deteccion['cols'];
            $headerRow = $deteccion['headerRow'];
        }


        $celda = function (int $col, int $r) use ($sheet): string {
            $v = $sheet->getCell([$col, $r])->getValue();
            if (is_string($v) && str_starts_with(trim($v), '=')) {
                // Es una fórmula — usa el valor que Excel ya calculó y guardó al último save
                $v = $sheet->getCell([$col, $r])->getOldCalculatedValue();
            }
            return trim((string)($v ?? ''));
        };

        $filasRaw = [];
        for ($r = $headerRow + 1; $r <= $maxRow; $r++) {
            $montoQuincenalRaw = $celda($cols['monto_quincenal'], $r);
            $montoQuincenal = is_numeric($montoQuincenalRaw)
                ? (float)$montoQuincenalRaw
                : (float)str_replace([',', '$', ' '], '', $montoQuincenalRaw);

            if ($montoQuincenal <= 0) continue;

            $claveBusqueda = null;
            if ($campoBusqueda === 'id_directo') {
                $raw = $celda($cols['clave_busqueda'], $r);
                $claveBusqueda = (is_numeric($raw) && (int)$raw > 0) ? (int)$raw : null;
            } else {
                $raw = $celda($cols['clave_busqueda'], $r);
                $claveBusqueda = $raw !== '' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw)) : null;
            }

            if ($claveBusqueda === null) continue;

            $filasRaw[] = [
                'clave_busqueda'  => $claveBusqueda,
                'monto_mensual'   => round($montoQuincenal * 2, 2),
                'monto_quincenal' => round($montoQuincenal, 2),
                'no_credito'      => isset($cols['no_credito']) ? $celda($cols['no_credito'], $r) : null,
                'tipo_descuento'  => isset($cols['tipo_descuento']) ? $celda($cols['tipo_descuento'], $r) : null,
                'nss'             => isset($cols['nss']) ? $celda($cols['nss'], $r) : null,
                'rfc'             => $campoBusqueda === 'rfc' ? strtoupper($celda($cols['clave_busqueda'], $r)) : null,
                'nombre'          => isset($cols['nombre']) ? strtoupper($celda($cols['nombre'], $r)) : null,
                'anio_emision'    => isset($cols['anio_emision']) ? ((int)$celda($cols['anio_emision'], $r) ?: null) : null,
                'mes_emision'     => isset($cols['mes_emision']) ? ((int)$celda($cols['mes_emision'], $r) ?: null) : null,
            ];
        }

        @unlink($tmpPath);



        if (empty($filasRaw)) {
            return $this->respond(['status' => 'error', 'message' => "No se encontraron registros de {$tipo} validos en el archivo"], 422);
        }

        $db = \Config\Database::connect();

        $empleadoPorClave = [];
        if ($campoBusqueda !== 'id_directo') {
            $clavesUnicas = array_unique(array_column($filasRaw, 'clave_busqueda'));
            if ($clavesUnicas) {
                $campoDb = $campoBusqueda;
                $placeholders = implode(',', array_fill(0, count($clavesUnicas), '?'));
                $rows = $db->query("
                    SELECT id, {$campoDb} AS clave
                    FROM empleados
                    WHERE {$campoDb} IN ({$placeholders})
                      AND is_deleted = 0
                ", array_values($clavesUnicas))->getResultArray();

                foreach ($rows as $r) {
                    $empleadoPorClave[strtoupper(trim($r['clave']))] = (int)$r['id'];
                }
            }
        }

        $filas = [];
        $sinMatch = 0;

        foreach ($filasRaw as $fr) {
            if ($campoBusqueda === 'id_directo') {
                $idEmpleado = (int)$fr['clave_busqueda'];
            } else {
                $idEmpleado = $empleadoPorClave[$fr['clave_busqueda']] ?? null;
            }

            if (!$idEmpleado) {
                $sinMatch++;
                continue;
            }

            $filas[] = [
                'id_empleado'     => $idEmpleado,
                'tipo'            => $tipo,
                'monto_mensual'   => $fr['monto_mensual'],
                'monto_quincenal' => $fr['monto_quincenal'],
                'no_credito'      => $fr['no_credito'],
                'tipo_descuento'  => $fr['tipo_descuento'],
                'nss'             => $fr['nss'],
                'rfc'             => $fr['rfc'],
                'nombre'          => $fr['nombre'],
                'anio_emision'    => $fr['anio_emision'],
                'mes_emision'     => $fr['mes_emision'],
                'archivo_origen'  => $archivo->getClientName(),
                'estatus'         => 1,
                'created_by'      => (int)$actor->id,
            ];
        }

        if (empty($filas)) {
            return $this->respond([
                'status'  => 'error',
                'message' => "Ninguna fila hizo match contra empleados ({$sinMatch} sin match de {$campoBusqueda})",
            ], 422);
        }

        $db->table('deducciones_empleado')
            ->where('tipo', $tipo)
            ->where('estatus', 1)
            ->update(['estatus' => 0]);

        $db->table('deducciones_empleado')->insertBatch($filas);

        $insertados = count($filas);

        AuditLibrary::log(
            (int)$actor->id,
            'CARGAR_DEDUCCIONES',
            'deducciones_empleado',
            $tipo,
            "Cargo {$insertados} deducciones de {$tipo} desde {$archivo->getClientName()} ({$sinMatch} sin match)"
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => "Se cargaron {$insertados} deducciones de " . strtoupper($tipo),
            'data'    => [
                'tipo'       => $tipo,
                'insertados' => $insertados,
                'sin_match'  => $sinMatch,
                'archivo'    => $archivo->getClientName(),
            ],
        ], 201);
    }

    public function index(): mixed
    {
        $db = \Config\Database::connect();
        $idEmpleado = $this->request->getVar('id_empleado');

        $query = $db->table('deducciones_empleado d')
            ->select("d.*, CONCAT(e.paterno,' ',e.materno,' ',e.nombre) AS empleado_nombre")
            ->join('empleados e', 'e.id = d.id_empleado', 'left')
            ->where('d.estatus', 1)
            ->orderBy('d.tipo, e.paterno');

        if ($idEmpleado) {
            $query->where('d.id_empleado', (int)$idEmpleado);
        }

        $rows = $query->get()->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function resumen(): mixed
    {
        $db = \Config\Database::connect();
        $rows = $db->query("
            SELECT
                tipo,
                COUNT(*) AS empleados,
                SUM(monto_quincenal) AS total_quincenal,
                SUM(monto_mensual)   AS total_mensual,
                MAX(created_at)      AS ultima_carga
            FROM deducciones_empleado
            WHERE estatus = 1
            GROUP BY tipo
        ")->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function delete($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $db = \Config\Database::connect();

        $ded = $db->table('deducciones_empleado')->where('id', (int)$id)->get()->getRowArray();
        if (!$ded) {
            return $this->respond(['status' => 'error', 'message' => 'Deduccion no encontrada'], 404);
        }

        $db->table('deducciones_empleado')->where('id', (int)$id)->update(['estatus' => 0]);

        AuditLibrary::log((int)$actor->id, 'ELIMINAR_DEDUCCION', 'deducciones_empleado', (string)$id,
            "Desactivo deduccion {$ded['tipo']} del empleado {$ded['id_empleado']}");

        return $this->respond(['status' => 'ok', 'message' => 'Deduccion desactivada']);
    }
}