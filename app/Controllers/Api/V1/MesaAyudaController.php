<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;
use App\Libraries\PermisoLibrary;
use App\Libraries\GeoLibrary;

/**
 * MesaAyudaController -- help desk con georreferencia.
 *
 * Panel (jwt)  /mesa-ayuda:
 *   GET  categorias | usuarios | resumen
 *   GET  /                 listado (q, estatus, prioridad, id_categoria, id_asignado, estado)
 *   GET  (id)              detalle + bitácora
 *   POST /                 crear (multipart, foto opcional)
 *   POST (id)/seguimiento  comentario + cambio de estatus/prioridad/asignación (JSON)
 *
 * Kiosko (público, igual que biometrico/*) /mesa-ayuda-kiosko:
 *   GET  categorias
 *   GET  /?id_operador=N   tickets levantados por ese operador
 *   POST /                 crear (id_operador en el body)
 */
class MesaAyudaController extends ResourceController
{
    protected $format = 'json';

    private const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];
    private const ESTATUS     = ['abierto', 'en_proceso', 'resuelto', 'cerrado'];

    private string $fotoBasePath;
    private string $fotoBaseUrl;

    /**
     * Permisos del panel: solo aplican cuando la petición trae JWT
     * (el kiosko usa rutas públicas con id_operador y no pasa por aquí).
     * Devuelve la respuesta 403 si no tiene permiso, o null si puede.
     */
    private function sinPermiso(string $accion): mixed
    {
        $user = $this->request->jwtUser ?? null;
        if (!$user) return null;
        $err = PermisoLibrary::exigir($user, 'mesa_ayuda', $accion);
        return $err ? $this->respond($err, 403) : null;
    }

    public function __construct()
    {
        $this->fotoBasePath = env('AYUDA_PATH', FCPATH . 'ayuda/');
        $this->fotoBaseUrl  = env('AYUDA_URL',  base_url('ayuda/'));
    }

    /* ═══════════════ CATÁLOGOS ═══════════════ */

    public function categorias(): mixed
    {
        $db = \Config\Database::connect();
        $rows = $db->query('SELECT id, nombre FROM ayuda_categorias WHERE activo = 1 ORDER BY id')->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function usuarios(): mixed
    {
        $db = \Config\Database::connect();
        $rows = $db->query("
            SELECT id, TRIM(CONCAT_WS(' ', nombre, paterno)) AS nombre
            FROM usuario WHERE is_deleted = 0 ORDER BY nombre
        ")->getResultArray();
        return $this->respond(['status' => 'ok', 'data' => $rows]);
    }

    public function resumen(): mixed
    {
        if ($d = $this->sinPermiso('leer')) return $d;
        $db = \Config\Database::connect();

        $t = $db->query("
            SELECT COUNT(*) AS total,
                   SUM(estatus='abierto')    AS abiertos,
                   SUM(estatus='en_proceso') AS en_proceso,
                   SUM(estatus='resuelto')   AS resueltos,
                   SUM(estatus='cerrado')    AS cerrados,
                   SUM(prioridad='urgente' AND estatus IN ('abierto','en_proceso')) AS urgentes
            FROM ayuda_tickets
        ")->getRowArray();

        $cat = $db->query("
            SELECT c.nombre AS categoria, COUNT(*) AS total
            FROM ayuda_tickets t JOIN ayuda_categorias c ON c.id = t.id_categoria
            WHERE t.estatus IN ('abierto','en_proceso')
            GROUP BY c.id, c.nombre ORDER BY total DESC
        ")->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => [
            'tickets'    => array_map('intval', $t ?: []),
            'categorias' => $cat,
        ]]);
    }

    /* ═══════════════ TICKETS ═══════════════ */

    public function index(): mixed
    {
        if ($d = $this->sinPermiso('leer')) return $d;
        $db = \Config\Database::connect();

        $where  = ['1=1'];
        $params = [];

        $q = trim((string)$this->request->getGet('q'));
        if ($q !== '') {
            $where[]  = '(t.titulo LIKE ? OR t.folio LIKE ? OR t.descripcion LIKE ? OR t.direccion LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
        }

        $estatus = (string)$this->request->getGet('estatus');
        if ($estatus === 'activos') {
            $where[] = "t.estatus IN ('abierto','en_proceso')";
        } elseif (in_array($estatus, self::ESTATUS, true)) {
            $where[] = 't.estatus = ?'; $params[] = $estatus;
        }

        $prio = (string)$this->request->getGet('prioridad');
        if (in_array($prio, self::PRIORIDADES, true)) { $where[] = 't.prioridad = ?'; $params[] = $prio; }

        if ((int)$this->request->getGet('id_categoria') > 0) { $where[] = 't.id_categoria = ?'; $params[] = (int)$this->request->getGet('id_categoria'); }
        if ((int)$this->request->getGet('id_asignado') > 0)  { $where[] = 't.id_asignado = ?';  $params[] = (int)$this->request->getGet('id_asignado'); }

        $estado = trim((string)$this->request->getGet('estado'));
        if ($estado !== '') { $where[] = 't.estado = ?'; $params[] = $estado; }

        // Kiosko: solo los tickets del operador que consulta
        $op = (int)$this->request->getGet('id_operador');
        if ($op > 0) { $where[] = 't.id_operador = ?'; $params[] = $op; }

        $rows = $db->query("
            SELECT t.id, t.folio, t.titulo, t.descripcion, t.prioridad, t.estatus, t.latitud, t.longitud,
                   t.estado, t.direccion, t.id_camara, t.id_categoria, c.nombre AS categoria,
                   t.id_asignado, TRIM(CONCAT_WS(' ', ua.nombre, ua.paterno)) AS asignado_nombre,
                   t.created_at, t.resolved_at
            FROM ayuda_tickets t
            JOIN ayuda_categorias c ON c.id = t.id_categoria
            LEFT JOIN usuario ua ON ua.id = t.id_asignado
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(t.estatus,'abierto','en_proceso','resuelto','cerrado'),
                     FIELD(t.prioridad,'urgente','alta','media','baja'), t.id DESC
            LIMIT 1000
        ", $params)->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $rows, 'total' => count($rows)]);
    }

    public function show($id = null): mixed
    {
        if ($d = $this->sinPermiso('leer')) return $d;
        $db = \Config\Database::connect();

        $t = $db->query("
            SELECT t.*, c.nombre AS categoria, cam.nombre AS camara_nombre,
                   TRIM(CONCAT_WS(' ', ua.nombre, ua.paterno)) AS asignado_nombre,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', uc.nombre, uc.paterno)),''), TRIM(CONCAT_WS(' ', e.nombre, e.paterno))) AS creado_por_nombre
            FROM ayuda_tickets t
            JOIN ayuda_categorias c ON c.id = t.id_categoria
            LEFT JOIN camaras cam ON cam.id = t.id_camara
            LEFT JOIN usuario ua ON ua.id = t.id_asignado
            LEFT JOIN usuario uc ON uc.id = t.created_by
            LEFT JOIN empleados e ON e.id = t.id_operador
            WHERE t.id = ?
        ", [(int)$id])->getRowArray();

        if (!$t) {
            return $this->respond(['status' => 'error', 'message' => 'Ticket no encontrado'], 404);
        }
        $t['foto_url'] = $t['foto'] ? $this->fotoBaseUrl . $t['foto'] : null;

        $bitacora = $db->query("
            SELECT k.id, k.comentario, k.cambio, k.created_at,
                   TRIM(CONCAT_WS(' ', u.nombre, u.paterno)) AS autor
            FROM ayuda_ticket_comentarios k
            LEFT JOIN usuario u ON u.id = k.created_by
            WHERE k.id_ticket = ?
            ORDER BY k.id ASC
        ", [(int)$id])->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $t, 'bitacora' => $bitacora]);
    }

    public function create(): mixed
    {
        if ($d = $this->sinPermiso('crear')) return $d;
        [$idUsuario, $idOperador, $idAudit] = $this->actor();
        if (!$idUsuario && !$idOperador) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no identificado'], 401);
        }

        $db = \Config\Database::connect();

        $idCat  = (int)$this->request->getVar('id_categoria');
        $titulo = trim((string)$this->request->getVar('titulo'));
        $prio   = (string)($this->request->getVar('prioridad') ?: 'media');

        if ($idCat <= 0 || $titulo === '') {
            return $this->respond(['status' => 'error', 'message' => 'Categoría y título son obligatorios'], 422);
        }
        if (!in_array($prio, self::PRIORIDADES, true)) {
            return $this->respond(['status' => 'error', 'message' => 'Prioridad inválida'], 422);
        }
        $cat = $db->query('SELECT id FROM ayuda_categorias WHERE id = ? AND activo = 1', [$idCat])->getRowArray();
        if (!$cat) {
            return $this->respond(['status' => 'error', 'message' => 'Categoría inválida'], 422);
        }

        // Ubicación (opcional)
        $lat = $this->request->getVar('latitud');
        $lon = $this->request->getVar('longitud');
        $tieneGeo = is_numeric($lat) && is_numeric($lon) && $lat !== '' && $lon !== '';
        if ($tieneGeo && ((float)$lat < 14 || (float)$lat > 33 || (float)$lon < -119 || (float)$lon > -86)) {
            return $this->respond(['status' => 'error', 'message' => 'Las coordenadas están fuera de México'], 422);
        }

        $estado    = trim((string)$this->request->getVar('estado'));
        $direccion = trim((string)$this->request->getVar('direccion'));
        if ($tieneGeo && ($estado === '' || $direccion === '')) {
            $geo = GeoLibrary::reverse((float)$lat, (float)$lon);
            if ($estado === '')    $estado    = $geo['estado']    ?? '';
            if ($direccion === '') $direccion = $geo['direccion'] ?? '';
        }

        $idCamara = (int)$this->request->getVar('id_camara');
        if ($idCamara > 0) {
            $c = $db->query('SELECT id FROM camaras WHERE id = ? AND is_deleted = 0', [$idCamara])->getRowArray();
            if (!$c) $idCamara = 0;
        }

        $foto = $this->guardarFoto($this->request->getFile('foto'));
        if ($foto === false) {
            return $this->respond(['status' => 'error', 'message' => 'Foto inválida (JPG/PNG, máx 5MB)'], 422);
        }

        $db->transStart();
        $db->table('ayuda_tickets')->insert([
            'id_categoria' => $idCat,
            'titulo'       => mb_substr($titulo, 0, 150),
            'descripcion'  => trim((string)$this->request->getVar('descripcion')) ?: null,
            'prioridad'    => $prio,
            'latitud'      => $tieneGeo ? (float)$lat : null,
            'longitud'     => $tieneGeo ? (float)$lon : null,
            'precision_m'  => is_numeric($this->request->getVar('precision_m')) ? (float)$this->request->getVar('precision_m') : null,
            'estado'       => $estado ?: null,
            'direccion'    => $direccion ?: null,
            'id_camara'    => $idCamara ?: null,
            'foto'         => $foto,
            'created_by'   => $idUsuario,
            'id_operador'  => $idOperador,
        ]);
        $id = (int)$db->insertID();
        $folio = 'MA-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
        $db->table('ayuda_tickets')->where('id', $id)->update(['folio' => $folio]);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo crear el ticket'], 500);
        }

        AuditLibrary::log($idAudit, 'CREAR_TICKET_AYUDA', 'ayuda_tickets', (string)$id, "Ticket {$folio}: {$titulo}");

        return $this->respond(['status' => 'ok', 'message' => 'Ticket creado', 'data' => ['id' => $id, 'folio' => $folio]], 201);
    }

    public function seguimiento($id = null): mixed
    {
        if ($d = $this->sinPermiso('actualizar')) return $d;
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $t = $db->query('SELECT * FROM ayuda_tickets WHERE id = ?', [(int)$id])->getRowArray();
        if (!$t) {
            return $this->respond(['status' => 'error', 'message' => 'Ticket no encontrado'], 404);
        }

        $body       = $this->request->getJSON(true) ?? [];
        $comentario = trim((string)($body['comentario'] ?? ''));
        $upd        = [];
        $cambios    = [];

        if (isset($body['estatus']) && $body['estatus'] !== $t['estatus']) {
            if (!in_array($body['estatus'], self::ESTATUS, true)) {
                return $this->respond(['status' => 'error', 'message' => 'Estatus inválido'], 422);
            }
            $upd['estatus'] = $body['estatus'];
            $upd['resolved_at'] = in_array($body['estatus'], ['resuelto', 'cerrado'], true)
                ? ($t['resolved_at'] ?: date('Y-m-d H:i:s')) : null;
            $cambios[] = "Estatus: {$t['estatus']} → {$body['estatus']}";
        }

        if (isset($body['prioridad']) && $body['prioridad'] !== $t['prioridad']) {
            if (!in_array($body['prioridad'], self::PRIORIDADES, true)) {
                return $this->respond(['status' => 'error', 'message' => 'Prioridad inválida'], 422);
            }
            $upd['prioridad'] = $body['prioridad'];
            $cambios[] = "Prioridad: {$t['prioridad']} → {$body['prioridad']}";
        }

        if (array_key_exists('id_asignado', $body)) {
            $nuevo = (int)$body['id_asignado'] ?: null;
            if ($nuevo !== ($t['id_asignado'] !== null ? (int)$t['id_asignado'] : null)) {
                if ($nuevo) {
                    $u = $db->query("SELECT TRIM(CONCAT_WS(' ', nombre, paterno)) AS n FROM usuario WHERE id = ? AND is_deleted = 0", [$nuevo])->getRowArray();
                    if (!$u) return $this->respond(['status' => 'error', 'message' => 'Usuario inválido'], 422);
                    $cambios[] = 'Asignado a: ' . $u['n'];
                } else {
                    $cambios[] = 'Asignación quitada';
                }
                $upd['id_asignado'] = $nuevo;
            }
        }

        if ($comentario === '' && !$cambios) {
            return $this->respond(['status' => 'error', 'message' => 'Escribe un comentario o cambia algo'], 422);
        }

        $db->transStart();
        if ($upd) $db->table('ayuda_tickets')->where('id', (int)$id)->update($upd);
        $db->table('ayuda_ticket_comentarios')->insert([
            'id_ticket'  => (int)$id,
            'comentario' => $comentario ?: null,
            'cambio'     => $cambios ? implode(' · ', $cambios) : null,
            'created_by' => (int)$actor->id,
        ]);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo guardar'], 500);
        }

        AuditLibrary::log((int)$actor->id, 'ACTUALIZAR_TICKET_AYUDA', 'ayuda_tickets', (string)$id,
            $cambios ? implode(' · ', $cambios) : 'Comentario agregado');

        return $this->respond(['status' => 'ok', 'message' => 'Ticket actualizado']);
    }

    /* ═══════════════ HELPERS ═══════════════ */

    /** [idUsuario|null, idOperador|null, idParaAuditoria] -- JWT (panel) u operador del kiosko */
    private function actor(): array
    {
        $u = $this->request->jwtUser ?? null;
        if ($u) return [(int)$u->id, null, (int)$u->id];

        $op = (int)$this->request->getVar('id_operador');
        return [null, $op > 0 ? $op : null, $op];
    }

    /** @return string|null|false  nombre de archivo, null si no hay foto, false si es inválida */
    private function guardarFoto($archivo)
    {
        if (!$archivo || $archivo->getError() === UPLOAD_ERR_NO_FILE) return null;
        if (!$archivo->isValid() || $archivo->hasMoved()) return false;
        if ($archivo->getSize() > 5 * 1024 * 1024) return false;
        if (!in_array($archivo->getMimeType(), ['image/jpeg', 'image/png'], true)) return false;

        if (!is_dir($this->fotoBasePath)) {
            @mkdir($this->fotoBasePath, 0755, true);
        }

        $ext    = $archivo->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $nombre = 'ma_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        return $archivo->move($this->fotoBasePath, $nombre) ? $nombre : false;
    }
}
