<?php

namespace App\Models;

use CodeIgniter\Model;

class NominaFatigaModel extends Model
{
    protected $table         = 'nomina_fatiga';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'nombre', 'periodo_inicio', 'periodo_fin', 'archivo_original',
        'total_empleados', 'total_pagar', 'estatus', 'comentario_revision',
        'created_by', 'created_at', 'aprobado_by', 'aprobado_at',
        'dispersado_by', 'dispersado_at', 'is_deleted',
    ];
}