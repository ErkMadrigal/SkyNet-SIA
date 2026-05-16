<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

class AuditController extends ResourceController
{
    protected $format = 'json';

    /**
     * GET /api/v1/audit-log
     * Params: search, action, entity, user_id, date_from, date_to, page, limit
     */
    public function index(): mixed
    {
        $db = \Config\Database::connect();

        $search    = trim($this->request->getVar('search')    ?? '');
        $action    = trim($this->request->getVar('action')    ?? '');
        $entity    = trim($this->request->getVar('entity')    ?? '');
        $userId    = $this->request->getVar('user_id');
        $dateFrom  = trim($this->request->getVar('date_from') ?? '');
        $dateTo    = trim($this->request->getVar('date_to')   ?? '');
        $page      = max(1, (int)($this->request->getVar('page')  ?? 1));
        $limit     = max(1, min(200, (int)($this->request->getVar('limit') ?? 25)));
        $offset    = ($page - 1) * $limit;

        $builder = $db->table('audit_log al')
            ->select('
                al.id,
                al.created_at,
                al.actor_user_id,
                al.actor_name,
                al.action,
                al.entity,
                al.entity_id,
                al.message,
                al.endpoint,
                al.method,
                al.status_code,
                al.ip,
                al.user_agent,
                al.lat,
                al.lon,
                al.changes_json
            ');

        if ($search !== '') {
            $builder->groupStart()
                ->like('al.actor_name', $search)
                ->orLike('al.action',   $search)
                ->orLike('al.entity',   $search)
                ->orLike('al.message',  $search)
                ->orLike('al.ip',       $search)
                ->groupEnd();
        }

        if ($action !== '')  $builder->where('al.action',         $action);
        if ($entity !== '')  $builder->where('al.entity',         $entity);
        if ($userId !== null) $builder->where('al.actor_user_id', (int)$userId);

        if ($dateFrom !== '' && $dateTo !== '') {
            $builder->where('al.created_at >=', $dateFrom . ' 00:00:00')
                    ->where('al.created_at <=', $dateTo   . ' 23:59:59');
        } elseif ($dateFrom !== '') {
            $builder->where('al.created_at >=', $dateFrom . ' 00:00:00');
        } elseif ($dateTo !== '') {
            $builder->where('al.created_at <=', $dateTo   . ' 23:59:59');
        }

        // Total
        $total = $builder->countAllResults(false);

        // Datos
        $data = $builder
            ->orderBy('al.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        // Parsear changes_json
        foreach ($data as &$row) {
            if ($row['changes_json']) {
                $row['changes'] = json_decode($row['changes_json'], true);
            } else {
                $row['changes'] = null;
            }
            unset($row['changes_json']);
        }

        // Acciones únicas para el filtro
        $acciones = $db->query('SELECT DISTINCT action FROM audit_log ORDER BY action ASC')
            ->getResultArray();

        // Entidades únicas para el filtro
        $entidades = $db->query('SELECT DISTINCT entity FROM audit_log ORDER BY entity ASC')
            ->getResultArray();

        return $this->respond([
            'status' => 'ok',
            'data'   => $data,
            'meta'   => [
                'page'       => $page,
                'limit'      => $limit,
                'total'      => $total,
                'totalPages' => (int)ceil($total / max(1, $limit)),
                'filters'    => [
                    'acciones'  => array_column($acciones,  'action'),
                    'entidades' => array_column($entidades, 'entity'),
                ]
            ]
        ]);
    }
}