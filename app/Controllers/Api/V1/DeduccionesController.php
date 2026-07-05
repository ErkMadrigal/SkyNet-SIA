<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * DeduccionesController
 *
 * Carga los archivos xlsx de FONACOT, INFONAVIT y pensión alimenticia
 * a la tabla deducciones_empleado. Cada carga reemplaza las deducciones
 * del tipo correspondiente (desactiva las anteriores e inserta las nuevas).
 *
 * Rutas (prefijo /api/v1/deducciones):
 *   POST /fonacot     → carga xlsx de FONACOT
 *   POST /infonavit   → carga xlsx de INFONAVIT
 *   POST /pension     → carga xlsx de pensión alimenticia
 *   GET  /            → lista deducciones activas por empleado
 *   GET  /resumen     → resumen de totales por tipo
 *   DELETE /:id       → desactiva una deducción individual
 */
class DeduccionesController extends ResourceController
{
    protected $format = 'json';

    /**
     * POST /api/v1/deducciones/fonacot
     * Body (multipart): archivo=xlsx
     *
     * Columnas esperadas:
     *   col5=RFC, col6=NO_SS, col7=NOMBRE, col4=NO_FONACOT,
     *   col10=RETENCION_MENSUAL, col13=RETENCIO QUINCENAL, col14=ID EMPLEADO
     *   col2=ANIO_EMISION, col3=MES_EMISION
     */
    public function fonacot(): mixed
    {
        return $this->cargarDeducciones('fonacot', [
            'id_empleado'    => 14,
            'rfc'            => 5,
            'nss'            => 6,
            'nombre'         => 7,
            'no_credito'     => 4,
            'monto_mensual'  => 10,
            'monto_quincenal'=> 13,
            'anio_emision'   => 2,
            'mes_emision'    => 3,
        ]);
    }

    /**
     * POST /api/v1/deducciones/infonavit
     * Body (multipart): archivo=xlsx
     *
     * Columnas esperadas:
     *   col1=NSS, col2=NO_CREDITO, col4=NOMBRE,
     *   col8=DESCUENTO_MENSUAL, col10=QUINCENAL,
     *   col9=TIPO_DESCUENTO, col11=ID EMPLEADO
     */
    public function infonavit(): mixed
    {
        return $this->cargarDeducciones('infonavit', [
            'id_empleado'    => 11,
            'nss'            => 1,
            'no_credito'     => 2,
            'nombre'         => 4,
            'monto_mensual'  => 8,
            'monto_quincenal'=> 10,
            'tipo_descuento' => 9,
        ]);
    }

    /**
     * POST /api/v1/deducciones/pension
     * Body (multipart): archivo=xlsx
     * Columnas mínimas esperadas: id_empleado, nombre, monto_quincenal
     * (cuando tengamos el formato real del xlsx lo ajustamos)
     */
    public function pension(): mixed
    {
        return $this->cargarDeducciones('pension', [
            'id_empleado'    => 1,
            'nombre'         => 2,
            'no_credito'     => 3,  // número de expediente judicial
            'monto_mensual'  => 4,
            'monto_quincenal'=> 5,
        ]);
    }

    /**
     * Lógica compartida de carga — lee el xlsx, desactiva deducciones
     * anteriores del mismo tipo y las reemplaza con las nuevas.
     */
    private function cargarDeducciones(string $tipo, array $mapaCols): mixed
    {
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

        $filas = [];
        $sinId = 0;

        for ($r = 2; $r <= $maxRow; $r++) {
            $idEmp = $sheet->getCell([$mapaCols['id_empleado'], $r])->getValue();
            if (!$idEmp || !is_numeric($idEmp) || (int)$idEmp <= 0) {
                $sinId++;
                continue;
            }

            $montoMensual  = (float)($sheet->getCell([$mapaCols['monto_mensual']  ?? 0, $r])->getValue() ?: 0);
            $montoQuincenal = (float)($sheet->getCell([$mapaCols['monto_quincenal'] ?? 0, $r])->getValue() ?: 0);

            // Si solo viene uno de los dos, calcular el otro
            if ($montoMensual > 0 && $montoQuincenal == 0) $montoQuincenal = round($montoMensual / 2, 2);
            if ($montoQuincenal > 0 && $montoMensual == 0) $montoMensual   = round($montoQuincenal * 2, 2);

            $fila = [
                'id_empleado'     => (int)$idEmp,
                'tipo'            => $tipo,
                'monto_mensual'   => $montoMensual,
                'monto_quincenal' => $montoQuincenal,
                'no_credito'      => isset($mapaCols['no_credito'])
                    ? trim((string)($sheet->getCell([$mapaCols['no_credito'], $r])->getValue() ?? ''))
                    : null,
                'tipo_descuento'  => isset($mapaCols['tipo_descuento'])
                    ? trim((string)($sheet->getCell([$mapaCols['tipo_descuento'], $r])->getValue() ?? ''))
                    : null,
                'nss'             => isset($mapaCols['nss'])
                    ? trim((string)($sheet->getCell([$mapaCols['nss'], $r])->getValue() ?? ''))
                    : null,
                'rfc'             => isset($mapaCols['rfc'])
                    ? strtoupper(trim((string)($sheet->getCell([$mapaCols['rfc'], $r])->getValue() ?? '')))
                    : null,
                'nombre'          => isset($mapaCols['nombre'])
                    ? strtoupper(trim((string)($sheet->getCell([$mapaCols['nombre'], $r])->getValue() ?? '')))
                    : null,
                'anio_emision'    => isset($mapaCols['anio_emision'])
                    ? (int)($sheet->getCell([$mapaCols['anio_emision'], $r])->getValue() ?: 0) ?: null
                    : null,
                'mes_emision'     => isset($mapaCols['mes_emision'])
                    ? (int)($sheet->getCell([$mapaCols['mes_emision'], $r])->getValue() ?: 0) ?: null
                    : null,
                'archivo_origen'  => $archivo->getClientName(),
                'estatus'         => 1,
                'created_by'      => (int)$actor->id,
            ];

            if ($fila['monto_quincenal'] <= 0) continue; // sin monto = no tiene deducción
            $filas[] = $fila;
        }

        @unlink($tmpPath);

        if (empty($filas)) {
            return $this->respond(['status' => 'error', 'message' => "No se encontraron registros de {$tipo} válidos en el archivo"], 422);
        }

        $db = \Config\Database::connect();

        // Desactivar deducciones anteriores del mismo tipo
        $db->table('deducciones_empleado')
            ->where('tipo', $tipo)
            ->where('estatus', 1)
            ->update(['estatus' => 0]);

        // Insertar las nuevas en batch
        $db->table('deducciones_empleado')->insertBatch($filas);

        $insertados = count($filas);

        AuditLibrary::log(
            (int)$actor->id,
            'CARGAR_DEDUCCIONES',
            'deducciones_empleado',
            $tipo,
            "Cargó {$insertados} deducciones de {$tipo} desde {$archivo->getClientName()}"
        );

        return $this->respond([
            'status'  => 'ok',
            'message' => "Se cargaron {$insertados} deducciones de " . strtoupper($tipo),
            'data'    => [
                'tipo'       => $tipo,
                'insertados' => $insertados,
                'sin_id'     => $sinId,
                'archivo'    => $archivo->getClientName(),
            ],
        ], 201);
    }

    /**
     * GET /api/v1/deducciones
     * Lista todas las deducciones activas opcionalmente filtradas por empleado
     */
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

    /**
     * GET /api/v1/deducciones/resumen
     * Totales por tipo para saber cuánto se va a descontar en la próxima nómina
     */
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

    /**
     * DELETE /api/v1/deducciones/:id
     * Desactiva una deducción individual
     */
    public function delete($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $db = \Config\Database::connect();

        $ded = $db->table('deducciones_empleado')->where('id', (int)$id)->get()->getRowArray();
        if (!$ded) {
            return $this->respond(['status' => 'error', 'message' => 'Deducción no encontrada'], 404);
        }

        $db->table('deducciones_empleado')->where('id', (int)$id)->update(['estatus' => 0]);

        AuditLibrary::log((int)$actor->id, 'ELIMINAR_DEDUCCION', 'deducciones_empleado', (string)$id,
            "Desactivó deducción {$ded['tipo']} del empleado {$ded['id_empleado']}");

        return $this->respond(['status' => 'ok', 'message' => 'Deducción desactivada']);
    }
}