<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\AuditLibrary;

/**
 * CamarasController -- cámaras georreferenciadas + tickets de falla.
 *
 * Cámaras:
 *   GET    /camaras                  listado (filtros: q, estado, estatus)
 *   GET    /camaras/resumen          contadores para el panel
 *   GET    /camaras/(id)             detalle + tickets
 *   POST   /camaras                  alta (multipart, foto opcional)
 *   POST   /camaras/(id)             editar (multipart, foto opcional)
 *   DELETE /camaras/(id)             baja lógica
 *
 * Tickets:
 *   GET    /camaras/tickets          listado (filtros: estatus, id_camara)
 *   POST   /camaras/(id)/tickets     levantar ticket (multipart, foto opcional)
 *   GET    /camaras/tickets/(id)     detalle + bitácora
 *   POST   /camaras/tickets/(id)/comentarios   comentario y/o cambio de estatus
 */
class CamarasController extends ResourceController
{
    protected $format = 'json';

    private const ESTATUS_CAMARA = ['activa', 'con_falla', 'inactiva'];
    private const ESTATUS_TICKET = ['abierto', 'en_proceso', 'resuelto'];
    private const TIPOS_FALLA    = ['sin_senal', 'intermitencia', 'imagen_borrosa', 'danio_fisico', 'otro'];

    private string $fotoBasePath;
    private string $fotoBaseUrl;

    public function __construct()
    {
        $this->fotoBasePath = env('CAMARAS_PATH', FCPATH . 'camaras/');
        $this->fotoBaseUrl  = env('CAMARAS_URL',  base_url('camaras/'));
    }

    /* ═══════════════════ CÁMARAS ═══════════════════ */

    public function index(): mixed
    {
        $db = \Config\Database::connect();
        $q       = trim((string)$this->request->getGet('q'));
        $estado  = trim((string)$this->request->getGet('estado'));
        $estatus = trim((string)$this->request->getGet('estatus'));

        $where  = ['c.is_deleted = 0'];
        $params = [];
        if ($q !== '') {
            $where[]  = '(c.nombre LIKE ? OR c.direccion LIKE ?)';
            $params[] = "%{$q}%";
            $params[] = "%{$q}%";
        }
        if ($estado !== '') {
            $where[]  = 'c.estado = ?';
            $params[] = $estado;
        }
        if (in_array($estatus, self::ESTATUS_CAMARA, true)) {
            $where[]  = 'c.estatus = ?';
            $params[] = $estatus;
        }

        $rows = $db->query("
            SELECT c.id, c.nombre, c.latitud, c.longitud, c.precision_m, c.estado, c.direccion,
                   c.foto, c.notas, c.estatus, c.created_at,
                   (SELECT COUNT(*) FROM camara_tickets t
                     WHERE t.id_camara = c.id AND t.estatus <> 'resuelto') AS tickets_abiertos
            FROM camaras c
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.nombre ASC
        ", $params)->getResultArray();

        foreach ($rows as &$r) {
            $r['foto_url'] = $r['foto'] ? $this->fotoBaseUrl . $r['foto'] : null;
        }

        return $this->respond(['status' => 'ok', 'data' => $rows, 'total' => count($rows)]);
    }

    public function resumen(): mixed
    {
        $db = \Config\Database::connect();

        $c = $db->query("
            SELECT COUNT(*) AS total,
                   SUM(estatus='activa')    AS activas,
                   SUM(estatus='con_falla') AS con_falla,
                   SUM(estatus='inactiva')  AS inactivas
            FROM camaras WHERE is_deleted = 0
        ")->getRowArray();

        $t = $db->query("
            SELECT SUM(estatus='abierto')    AS abiertos,
                   SUM(estatus='en_proceso') AS en_proceso,
                   SUM(estatus='resuelto')   AS resueltos
            FROM camara_tickets
        ")->getRowArray();

        $estados = $db->query("
            SELECT COALESCE(NULLIF(estado,''),'Sin estado') AS estado, COUNT(*) AS total
            FROM camaras WHERE is_deleted = 0 GROUP BY 1 ORDER BY total DESC
        ")->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => [
            'camaras' => array_map('intval', $c ?: []),
            'tickets' => array_map('intval', $t ?: []),
            'estados' => $estados,
        ]]);
    }

    public function show($id = null): mixed
    {
        $db  = \Config\Database::connect();
        $cam = $db->query('SELECT * FROM camaras WHERE id = ? AND is_deleted = 0', [(int)$id])->getRowArray();
        if (!$cam) {
            return $this->respond(['status' => 'error', 'message' => 'Cámara no encontrada'], 404);
        }
        $cam['foto_url'] = $cam['foto'] ? $this->fotoBaseUrl . $cam['foto'] : null;

        $tickets = $db->query("
            SELECT t.*, COALESCE(NULLIF(CONCAT_WS(' ', u.nombre, u.paterno),''), CONCAT_WS(' ', e.nombre, e.paterno)) AS creado_por_nombre
            FROM camara_tickets t
            LEFT JOIN usuario u ON u.id = t.created_by
            LEFT JOIN empleados e ON e.id = t.id_operador
            WHERE t.id_camara = ?
            ORDER BY t.id DESC
        ", [(int)$id])->getResultArray();
        foreach ($tickets as &$t) {
            $t['foto_url'] = $t['foto'] ? $this->fotoBaseUrl . $t['foto'] : null;
        }

        return $this->respond(['status' => 'ok', 'data' => $cam, 'tickets' => $tickets]);
    }

    public function create(): mixed
    {
        [$idUsuario, $idOperador, $idAudit] = $this->actor();
        if (!$idUsuario && !$idOperador) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no identificado'], 401);
        }

        $nombre = trim((string)$this->request->getVar('nombre'));
        $lat    = $this->request->getVar('latitud');
        $lon    = $this->request->getVar('longitud');

        if ($nombre === '' || !is_numeric($lat) || !is_numeric($lon)) {
            return $this->respond(['status' => 'error', 'message' => 'Nombre y coordenadas son obligatorios'], 422);
        }
        if ((float)$lat < 14 || (float)$lat > 33 || (float)$lon < -119 || (float)$lon > -86) {
            return $this->respond(['status' => 'error', 'message' => 'Las coordenadas están fuera de México'], 422);
        }

        $db = \Config\Database::connect();
        $dup = $db->query('SELECT id FROM camaras WHERE nombre = ? AND is_deleted = 0 LIMIT 1', [$nombre])->getRowArray();
        if ($dup) {
            return $this->respond(['status' => 'error', 'message' => 'Ya existe una cámara con ese nombre'], 409);
        }

        $foto = $this->guardarFoto($this->request->getFile('foto'), 'cam');
        if ($foto === false) {
            return $this->respond(['status' => 'error', 'message' => 'Foto inválida (JPG/PNG, máx 5MB)'], 422);
        }

        // Si no mandaron estado/dirección (o los dejaron vacíos), se detectan
        // solos a partir de las coordenadas.
        $estado    = trim((string)$this->request->getVar('estado'));
        $direccion = trim((string)$this->request->getVar('direccion'));
        if ($estado === '' || $direccion === '') {
            $geo = $this->geocodificarInterno((float)$lat, (float)$lon);
            if ($estado === '')    $estado    = $geo['estado'] ?? '';
            if ($direccion === '') $direccion = $geo['direccion'] ?? '';
        }

        $db->table('camaras')->insert([
            'nombre'      => $nombre,
            'latitud'     => (float)$lat,
            'longitud'    => (float)$lon,
            'precision_m' => is_numeric($this->request->getVar('precision_m')) ? (float)$this->request->getVar('precision_m') : null,
            'estado'      => $estado ?: null,
            'direccion'   => $direccion ?: null,
            'notas'       => trim((string)$this->request->getVar('notas')) ?: null,
            'foto'        => $foto,
            'created_by'  => $idUsuario,
            'id_operador' => $idOperador,
        ]);
        $id = (int)$db->insertID();

        AuditLibrary::log($idAudit, 'CREAR_CAMARA', 'camaras', (string)$id, "Cámara '{$nombre}' dada de alta");

        return $this->respond(['status' => 'ok', 'message' => 'Cámara registrada', 'data' => ['id' => $id]], 201);
    }

    /**
     * GET /camaras/geocodificar?lat=..&lon=..  (también en /camaras-kiosko)
     * Devuelve { estado, direccion } detectados a partir de las coordenadas,
     * para pre-llenar el formulario de alta.
     */
    public function geocodificar(): mixed
    {
        $lat = $this->request->getGet('lat');
        $lon = $this->request->getGet('lon');
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return $this->respond(['status' => 'error', 'message' => 'Coordenadas inválidas'], 422);
        }

        $geo = $this->geocodificarInterno((float)$lat, (float)$lon);
        return $this->respond(['status' => 'ok', 'data' => [
            'estado'    => $geo['estado']    ?? null,
            'direccion' => $geo['direccion'] ?? null,
        ]]);
    }

    public function actualizar($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $cam = $db->query('SELECT * FROM camaras WHERE id = ? AND is_deleted = 0', [(int)$id])->getRowArray();
        if (!$cam) {
            return $this->respond(['status' => 'error', 'message' => 'Cámara no encontrada'], 404);
        }

        $data = [];
        foreach (['nombre', 'estado', 'direccion', 'notas'] as $campo) {
            $v = $this->request->getVar($campo);
            if ($v !== null) $data[$campo] = trim((string)$v) ?: null;
        }
        if (isset($data['nombre']) && $data['nombre'] === null) {
            return $this->respond(['status' => 'error', 'message' => 'El nombre no puede quedar vacío'], 422);
        }

        $lat = $this->request->getVar('latitud');
        $lon = $this->request->getVar('longitud');
        if ($lat !== null && $lon !== null && is_numeric($lat) && is_numeric($lon)) {
            $data['latitud']  = (float)$lat;
            $data['longitud'] = (float)$lon;
        }

        $est = $this->request->getVar('estatus');
        if ($est !== null && in_array($est, self::ESTATUS_CAMARA, true)) {
            $data['estatus'] = $est;
        }

        $foto = $this->guardarFoto($this->request->getFile('foto'), 'cam');
        if ($foto === false) {
            return $this->respond(['status' => 'error', 'message' => 'Foto inválida (JPG/PNG, máx 5MB)'], 422);
        }
        if ($foto) $data['foto'] = $foto;

        if (!$data) {
            return $this->respond(['status' => 'error', 'message' => 'Nada que actualizar'], 422);
        }

        $db->table('camaras')->where('id', (int)$id)->update($data);
        AuditLibrary::log((int)$actor->id, 'EDITAR_CAMARA', 'camaras', (string)$id, 'Cámara actualizada');

        return $this->respond(['status' => 'ok', 'message' => 'Cámara actualizada']);
    }

    public function delete($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();
        $db->table('camaras')->where('id', (int)$id)->update(['is_deleted' => 1]);
        AuditLibrary::log((int)$actor->id, 'ELIMINAR_CAMARA', 'camaras', (string)$id, 'Cámara dada de baja');
        return $this->respond(['status' => 'ok', 'message' => 'Cámara eliminada']);
    }

    /* ═══════════════════ TICKETS ═══════════════════ */

    public function tickets(): mixed
    {
        $db      = \Config\Database::connect();
        $estatus = trim((string)$this->request->getGet('estatus'));
        $idCam   = (int)$this->request->getGet('id_camara');

        $where  = ['c.is_deleted = 0'];
        $params = [];
        if (in_array($estatus, self::ESTATUS_TICKET, true)) {
            $where[]  = 't.estatus = ?';
            $params[] = $estatus;
        }
        if ($idCam > 0) {
            $where[]  = 't.id_camara = ?';
            $params[] = $idCam;
        }

        $rows = $db->query("
            SELECT t.id, t.id_camara, c.nombre AS camara, c.estado, t.tipo_falla, t.descripcion,
                   t.estatus, t.created_at, t.resolved_at,
                   COALESCE(NULLIF(CONCAT_WS(' ', u.nombre, u.paterno),''), CONCAT_WS(' ', e.nombre, e.paterno)) AS creado_por_nombre
            FROM camara_tickets t
            JOIN camaras c ON c.id = t.id_camara
            LEFT JOIN usuario u ON u.id = t.created_by
            LEFT JOIN empleados e ON e.id = t.id_operador
            WHERE " . implode(' AND ', $where) . "
            ORDER BY FIELD(t.estatus,'abierto','en_proceso','resuelto'), t.id DESC
            LIMIT 500
        ", $params)->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $rows, 'total' => count($rows)]);
    }

    public function crearTicket($idCamara = null): mixed
    {
        [$idUsuario, $idOperador, $idAudit] = $this->actor();
        if (!$idUsuario && !$idOperador) {
            return $this->respond(['status' => 'error', 'message' => 'Operador no identificado'], 401);
        }
        $db    = \Config\Database::connect();

        $cam = $db->query('SELECT id, nombre FROM camaras WHERE id = ? AND is_deleted = 0', [(int)$idCamara])->getRowArray();
        if (!$cam) {
            return $this->respond(['status' => 'error', 'message' => 'Cámara no encontrada'], 404);
        }

        $tipo = (string)$this->request->getVar('tipo_falla');
        if (!in_array($tipo, self::TIPOS_FALLA, true)) {
            return $this->respond(['status' => 'error', 'message' => 'Tipo de falla inválido'], 422);
        }

        $foto = $this->guardarFoto($this->request->getFile('foto'), 'tkt');
        if ($foto === false) {
            return $this->respond(['status' => 'error', 'message' => 'Foto inválida (JPG/PNG, máx 5MB)'], 422);
        }

        $db->transStart();
        $db->table('camara_tickets')->insert([
            'id_camara'   => (int)$idCamara,
            'tipo_falla'  => $tipo,
            'descripcion' => trim((string)$this->request->getVar('descripcion')) ?: null,
            'foto'        => $foto,
            'created_by'  => $idUsuario,
            'id_operador' => $idOperador,
        ]);
        $idTicket = (int)$db->insertID();
        $db->table('camaras')->where('id', (int)$idCamara)->update(['estatus' => 'con_falla']);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo crear el ticket'], 500);
        }

        AuditLibrary::log($idAudit, 'CREAR_TICKET_CAMARA', 'camara_tickets', (string)$idTicket,
            "Ticket de falla ({$tipo}) para cámara '{$cam['nombre']}'");

        return $this->respond(['status' => 'ok', 'message' => 'Ticket creado', 'data' => ['id' => $idTicket]], 201);
    }

    public function ticketDetalle($id = null): mixed
    {
        $db = \Config\Database::connect();

        $t = $db->query("
            SELECT t.*, c.nombre AS camara, c.latitud, c.longitud, c.estado,
                   COALESCE(NULLIF(CONCAT_WS(' ', u.nombre, u.paterno),''), CONCAT_WS(' ', e.nombre, e.paterno)) AS creado_por_nombre
            FROM camara_tickets t
            JOIN camaras c ON c.id = t.id_camara
            LEFT JOIN usuario u ON u.id = t.created_by
            LEFT JOIN empleados e ON e.id = t.id_operador
            WHERE t.id = ?
        ", [(int)$id])->getRowArray();
        if (!$t) {
            return $this->respond(['status' => 'error', 'message' => 'Ticket no encontrado'], 404);
        }
        $t['foto_url'] = $t['foto'] ? $this->fotoBaseUrl . $t['foto'] : null;

        $bitacora = $db->query("
            SELECT k.id, k.comentario, k.estatus_nuevo, k.created_at,
                   CONCAT_WS(' ', u.nombre, u.paterno) AS autor
            FROM camara_ticket_comentarios k
            LEFT JOIN usuario u ON u.id = k.created_by
            WHERE k.id_ticket = ?
            ORDER BY k.id ASC
        ", [(int)$id])->getResultArray();

        return $this->respond(['status' => 'ok', 'data' => $t, 'bitacora' => $bitacora]);
    }

    public function comentarTicket($id = null): mixed
    {
        $actor = $this->request->jwtUser;
        $db    = \Config\Database::connect();

        $t = $db->query('SELECT id, id_camara, estatus FROM camara_tickets WHERE id = ?', [(int)$id])->getRowArray();
        if (!$t) {
            return $this->respond(['status' => 'error', 'message' => 'Ticket no encontrado'], 404);
        }

        $body       = $this->request->getJSON(true) ?? [];
        $comentario = trim((string)($body['comentario'] ?? $this->request->getVar('comentario') ?? ''));
        $nuevo      = $body['estatus'] ?? $this->request->getVar('estatus');

        if ($nuevo !== null && !in_array($nuevo, self::ESTATUS_TICKET, true)) {
            return $this->respond(['status' => 'error', 'message' => 'Estatus inválido'], 422);
        }
        if ($comentario === '' && ($nuevo === null || $nuevo === $t['estatus'])) {
            return $this->respond(['status' => 'error', 'message' => 'Escribe un comentario o cambia el estatus'], 422);
        }

        $db->transStart();

        $cambio = ($nuevo !== null && $nuevo !== $t['estatus']) ? $nuevo : null;
        $db->table('camara_ticket_comentarios')->insert([
            'id_ticket'     => (int)$id,
            'comentario'    => $comentario ?: null,
            'estatus_nuevo' => $cambio,
            'created_by'    => (int)$actor->id,
        ]);

        if ($cambio) {
            $db->table('camara_tickets')->where('id', (int)$id)->update([
                'estatus'     => $cambio,
                'resolved_at' => $cambio === 'resuelto' ? date('Y-m-d H:i:s') : null,
            ]);

            // Si ya no quedan tickets sin resolver, la cámara vuelve a "activa"
            $pend = (int)$db->query(
                "SELECT COUNT(*) AS n FROM camara_tickets WHERE id_camara = ? AND estatus <> 'resuelto'",
                [(int)$t['id_camara']]
            )->getRowArray()['n'];
            $db->table('camaras')->where('id', (int)$t['id_camara'])
               ->where('estatus <>', 'inactiva')
               ->update(['estatus' => $pend > 0 ? 'con_falla' : 'activa']);
        }

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->respond(['status' => 'error', 'message' => 'No se pudo guardar'], 500);
        }

        AuditLibrary::log((int)$actor->id, 'ACTUALIZAR_TICKET_CAMARA', 'camara_tickets', (string)$id,
            $cambio ? "Ticket cambió a {$cambio}" : 'Comentario agregado');

        return $this->respond(['status' => 'ok', 'message' => 'Ticket actualizado']);
    }

    /* ═══════════════════ HELPERS ═══════════════════ */

    private const ESTADOS_MX = [
        'Ciudad de México', 'Aguascalientes', 'Baja California Sur', 'Baja California', 'Campeche', 'Chiapas',
        'Chihuahua', 'Coahuila', 'Colima', 'Durango', 'Guanajuato', 'Guerrero', 'Hidalgo', 'Jalisco',
        'Michoacán', 'Morelos', 'Nayarit', 'Nuevo León', 'Oaxaca', 'Puebla', 'Querétaro', 'Quintana Roo',
        'San Luis Potosí', 'Sinaloa', 'Sonora', 'Tabasco', 'Tamaulipas', 'Tlaxcala', 'Veracruz', 'Yucatán',
        'Zacatecas', 'México',
    ];

    /**
     * Reverse geocoding con Nominatim (OpenStreetMap). Es un servicio gratuito
     * que pide identificarse con User-Agent y no abusar (máx 1 petición/seg);
     * aquí solo se llama una vez por alta de cámara.
     * Si falla (sin internet, timeout) devuelve [] y el alta sigue sin esos datos.
     */
    private function geocodificarInterno(float $lat, float $lon): array
    {
        try {
            $client = \Config\Services::curlrequest(['timeout' => 6, 'http_errors' => false]);
            $resp   = $client->get('https://nominatim.openstreetmap.org/reverse', [
                'headers' => [
                    'User-Agent'      => 'SIAG-Camaras/1.0 (serprosep.vinculasag.com)',
                    'Accept-Language' => 'es',
                ],
                'query' => [
                    'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lon,
                    'zoom' => 18, 'addressdetails' => 1,
                ],
            ]);
            if ($resp->getStatusCode() !== 200) return [];

            $json = json_decode($resp->getBody(), true);
            $a    = $json['address'] ?? null;
            if (!$a) return [];

            // Estado de la república normalizado a nuestra lista
            // ("Estado de México" → "México", "Veracruz de Ignacio de la Llave" → "Veracruz"...)
            $estado = null;
            $crudo  = (string)($a['state'] ?? $a['region'] ?? '');
            if ($crudo !== '') {
                $estado = $crudo;
                foreach (self::ESTADOS_MX as $e) {
                    if (mb_stripos($crudo, $e) !== false) { $estado = $e; break; }
                }
                if (mb_stripos($crudo, 'Distrito Federal') !== false) $estado = 'Ciudad de México';
            }

            // Dirección corta: calle + número, colonia, municipio/ciudad
            $calle = trim(($a['road'] ?? $a['pedestrian'] ?? '') . ' ' . ($a['house_number'] ?? ''));
            $col   = $a['suburb'] ?? $a['neighbourhood'] ?? $a['quarter'] ?? '';
            $muni  = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? '';
            $partes = array_values(array_filter([$calle, $col, $muni], fn($p) => trim((string)$p) !== ''));
            $direccion = $partes ? implode(', ', $partes) : null;

            return ['estado' => $estado, 'direccion' => $direccion];
        } catch (\Throwable $e) {
            log_message('error', 'Geocodificación falló: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Quién hace la acción: usuario del panel (JWT) u operador del kiosko
     * (id_operador en el body, igual que id_capturista en biometrico/registro).
     * @return array [idUsuario|null, idOperador|null, idParaAuditoria]
     */
    private function actor(): array
    {
        $u = $this->request->jwtUser ?? null;
        if ($u) return [(int)$u->id, null, (int)$u->id];

        $op = (int)$this->request->getVar('id_operador');
        return [null, $op > 0 ? $op : null, $op];
    }

    /**
     * @return string|null|false  nombre del archivo, null si no se mandó foto, false si es inválida
     */
    private function guardarFoto($archivo, string $prefijo)
    {
        if (!$archivo || $archivo->getError() === UPLOAD_ERR_NO_FILE) return null;
        if (!$archivo->isValid() || $archivo->hasMoved()) return false;
        if ($archivo->getSize() > 5 * 1024 * 1024) return false;
        if (!in_array($archivo->getMimeType(), ['image/jpeg', 'image/png'], true)) return false;

        if (!is_dir($this->fotoBasePath)) {
            @mkdir($this->fotoBasePath, 0755, true);
        }

        $ext    = $archivo->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $nombre = $prefijo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        return $archivo->move($this->fotoBasePath, $nombre) ? $nombre : false;
    }
}
