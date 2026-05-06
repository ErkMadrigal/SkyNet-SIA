<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CatalogoModel;

/**
 * CatalogosController
 *
 * Migración de catalogs.php legacy — 18 acciones en endpoints REST.
 * Rutas base: /api/v1/catalogos
 *
 * ── Lectura general ──────────────────────────────────────────────
 * GET  /catalogos/tipos                → getTipoCatalogos
 * GET  /catalogos/{id}                 → getCatalogos (por id_catalogo)
 * GET  /catalogos/{id}/buscar          → getCatalogosName (LIKE)
 * GET  /catalogos/{id}/select          → getCatalogosSelect (con status)
 * GET  /catalogos/banco/{clabe}        → getInstitucionBancaria
 * GET  /catalogos/regionales           → getRegionales
 * GET  /catalogos/servicios/select     → getServiciosSelect (autocomplete)
 *
 * ── Multicatálogo (admin) ────────────────────────────────────────
 * POST /catalogos/tipos                → newCatalogo (tipo catalogo)
 * POST /catalogos/{id}/items           → newMultiCatalogo (ítem)
 * PUT  /catalogos/items/{id}           → updateMultiCatalogo
 * DELETE /catalogos/items/{id}         → deleteMultiCatalogo
 *
 * ── Entidades CRUD (admin) ───────────────────────────────────────
 * Cada entidad: GET / POST / PUT /{id} / DELETE /{id}
 *   /catalogos/empresas
 *   /catalogos/partidas
 *   /catalogos/zonas
 *   /catalogos/regiones
 *   /catalogos/areas-geograficas
 *   /catalogos/clientes
 *   /catalogos/servicios
 */
class CatalogosController extends ResourceController
{
    protected $format = 'json';

    /* ═══════════════════════════════════════════════════════════════
       MULTICATÁLOGO — LECTURA
    ═══════════════════════════════════════════════════════════════ */

    /** GET /api/v1/catalogos/tipos */
    public function tipos(): mixed
    {
        return $this->respond((new CatalogoModel())->getTipoCatalogos());
    }

    /** GET /api/v1/catalogos/{id} */
    public function show($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->getCatalogo((int)$id));
    }

    /** GET /api/v1/catalogos/{id}/buscar?name=... */
    public function buscarPorNombre($id = null): mixed
    {
        $name = trim($this->request->getVar('name') ?? '');
        if ($name === '') {
            return $this->respond(['status' => 'error', 'message' => 'name es requerido'], 400);
        }
        return $this->respond((new CatalogoModel())->getCatalogoName((int)$id, $name));
    }

    /** GET /api/v1/catalogos/{id}/select */
    public function catalogosSelect($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->getCatalogosSelect((int)$id));
    }

    /** GET /api/v1/catalogos/banco/{clabe} */
    public function banco($clabe = null): mixed
    {
        if (!$clabe) {
            return $this->respond(['status' => 'error', 'message' => 'clabe es requerida'], 400);
        }
        return $this->respond((new CatalogoModel())->getInstitucionBancaria($clabe));
    }

    /** GET /api/v1/catalogos/regionales */
    public function regionales(): mixed
    {
        return $this->respond((new CatalogoModel())->getRegionales());
    }

    /** GET /api/v1/catalogos/servicios/select?query=&limit=&page= */
    public function serviciosSelect(): mixed
    {
        $query = trim($this->request->getVar('query') ?? '');
        $limit = (int)($this->request->getVar('limit') ?? 20);
        $page  = (int)($this->request->getVar('page')  ?? 1);

        $limit = max(1, min(100, $limit));
        $page  = max(1, $page);

        if (mb_strlen($query) < 2) {
            return $this->respond(['status' => 'ok', 'data' => []]);
        }

        return $this->respond((new CatalogoModel())->getServiciosSelect($query, $limit, $page));
    }

    /* ═══════════════════════════════════════════════════════════════
       MULTICATÁLOGO — ESCRITURA (admin)
    ═══════════════════════════════════════════════════════════════ */

    /**
     * POST /api/v1/catalogos/tipos
     * Body: { descripcion }
     */
    public function crearTipo(): mixed
    {
        $desc = trim($this->request->getVar('descripcion') ?? '');
        if (!$desc) return $this->respond(['status' => 'error', 'message' => 'descripcion es requerida'], 422);
        return $this->respond((new CatalogoModel())->newCatalogo($desc), 201);
    }

    /**
     * POST /api/v1/catalogos/{id}/items
     * Body: { valor, descripcion }
     */
    public function crearItem($id = null): mixed
    {
        $valor = trim($this->request->getVar('valor')       ?? '');
        $desc  = trim($this->request->getVar('descripcion') ?? '');
        if (!$valor) return $this->respond(['status' => 'error', 'message' => 'valor es requerido'], 422);
        return $this->respond((new CatalogoModel())->newMultiCatalogo((int)$id, $valor, $desc), 201);
    }

    /**
     * PUT /api/v1/catalogos/items/{id}
     * Body: { status, valor, descripcion }
     */
    public function actualizarItem($id = null): mixed
    {
        $model = new CatalogoModel();
        return $this->respond($model->updateMultiCatalogo(
            (int)$id,
            (int)($this->request->getVar('status')      ?? 1),
            trim($this->request->getVar('valor')        ?? ''),
            trim($this->request->getVar('descripcion')  ?? '')
        ));
    }

    /**
     * DELETE /api/v1/catalogos/items/{id}
     */
    public function eliminarItem($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteMultiCatalogo((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       EMPRESAS
    ═══════════════════════════════════════════════════════════════ */

    /** GET /api/v1/catalogos/empresas */
    public function empresas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectEmpresas());
    }

    /** POST /api/v1/catalogos/empresas — Body: { empresa } */
    public function crearEmpresa(): mixed
    {
        $empresa = trim($this->request->getVar('empresa') ?? '');
        if (!$empresa) return $this->respond(['status' => 'error', 'message' => 'empresa es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertEmpresa($empresa), 201);
    }

    /** PUT /api/v1/catalogos/empresas/{id} — Body: { empresa, status? } */
    public function actualizarEmpresa($id = null): mixed
    {
        $model = new CatalogoModel();
        return $this->respond($model->updateEmpresa(
            (int)$id,
            trim($this->request->getVar('empresa') ?? ''),
            (int)($this->request->getVar('status') ?? $this->request->getVar('estatus') ?? 1)
        ));
    }

    /** DELETE /api/v1/catalogos/empresas/{id} */
    public function eliminarEmpresa($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteEmpresa((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       PARTIDAS
    ═══════════════════════════════════════════════════════════════ */

    public function partidas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectPartidas());
    }

    public function crearPartida(): mixed
    {
        $partida = trim($this->request->getVar('partida') ?? '');
        if (!$partida) return $this->respond(['status' => 'error', 'message' => 'partida es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertPartida($partida), 201);
    }

    public function actualizarPartida($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updatePartida(
            (int)$id,
            trim($this->request->getVar('partida') ?? ''),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarPartida($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deletePartida((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       ZONAS
    ═══════════════════════════════════════════════════════════════ */

    public function zonas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectZonas());
    }

    public function crearZona(): mixed
    {
        $zona = trim($this->request->getVar('zona') ?? '');
        if (!$zona) return $this->respond(['status' => 'error', 'message' => 'zona es requerida'], 422);
        return $this->respond((new CatalogoModel())->insertZona($zona), 201);
    }

    public function actualizarZona($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateZona(
            (int)$id,
            trim($this->request->getVar('zona') ?? ''),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarZona($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteZona((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       REGIONES
    ═══════════════════════════════════════════════════════════════ */

    public function regionesList(): mixed
    {
        return $this->respond((new CatalogoModel())->selectRegiones());
    }

    public function crearRegion(): mixed
    {
        $estado = trim($this->request->getVar('estado')            ?? '');
        $area   = (int)($this->request->getVar('id_area_geografica') ?? 0);
        if (!$estado || !$area) return $this->respond(['status' => 'error', 'message' => 'estado e id_area_geografica son requeridos'], 422);
        return $this->respond((new CatalogoModel())->insertRegion($estado, $area), 201);
    }

    public function actualizarRegion($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateRegion(
            (int)$id,
            trim($this->request->getVar('estado') ?? ''),
            (int)($this->request->getVar('id_area_geografica') ?? 0),
            (int)($this->request->getVar('status') ?? 1)
        ));
    }

    public function eliminarRegion($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteRegion((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       ÁREAS GEOGRÁFICAS
    ═══════════════════════════════════════════════════════════════ */

    public function areasGeograficas(): mixed
    {
        return $this->respond((new CatalogoModel())->selectAreaGeografica());
    }

    public function regionalesGerentes(): mixed
    {
        return $this->respond((new CatalogoModel())->selectRegionalesGerentes());
    }

    public function actualizarArea($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateAreaGeografica(
            (int)$id,
            (int)($this->request->getVar('id_regional') ?? 0),
            trim($this->request->getVar('region')       ?? ''),
            (int)($this->request->getVar('status')      ?? 1)
        ));
    }

    /* ═══════════════════════════════════════════════════════════════
       CLIENTES
    ═══════════════════════════════════════════════════════════════ */

    public function clientes(): mixed
    {
        return $this->respond((new CatalogoModel())->selectClientes());
    }

    public function crearCliente(): mixed
    {
        $rules = [
            'razon_social' => 'required|max_length[255]',
            'nombre_corto' => 'required|max_length[120]',
            'id_empresa'   => 'required|integer',
            'id_partida'   => 'required|integer',
        ];
        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }
        return $this->respond((new CatalogoModel())->insertCliente(
            $this->request->getVar('razon_social'),
            $this->request->getVar('nombre_corto'),
            (int)$this->request->getVar('id_empresa'),
            (int)$this->request->getVar('id_partida')
        ), 201);
    }

    public function actualizarCliente($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateCliente(
            (int)$id,
            trim($this->request->getVar('razon_social') ?? ''),
            trim($this->request->getVar('nombre_corto') ?? ''),
            (int)($this->request->getVar('id_empresa')  ?? 0),
            (int)($this->request->getVar('id_partida')  ?? 0),
            (int)($this->request->getVar('status')      ?? 1)
        ));
    }

    public function eliminarCliente($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteCliente((int)$id));
    }

    /* ═══════════════════════════════════════════════════════════════
       SERVICIOS
    ═══════════════════════════════════════════════════════════════ */

    public function servicios(): mixed
    {
        return $this->respond((new CatalogoModel())->selectServicios());
    }

    public function crearServicio(): mixed
    {
        $rules = [
            'servicio'   => 'required|max_length[255]',
            'ubicacion'  => 'required|max_length[255]',
            'cp'         => 'required|exact_length[5]',
            'latitud'    => 'required|decimal',
            'longitud'   => 'required|decimal',
            'id_cliente' => 'required|integer',
            'id_empresa' => 'required|integer',
        ];
        if (!$this->validate($rules)) {
            return $this->respond(['status' => 'error', 'errors' => $this->validator->getErrors()], 422);
        }
        return $this->respond((new CatalogoModel())->insertServicio($this->request->getVar() ?: []), 201);
    }

    public function actualizarServicio($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->updateServicio((int)$id, $this->request->getVar() ?: []));
    }

    public function eliminarServicio($id = null): mixed
    {
        return $this->respond((new CatalogoModel())->deleteServicio((int)$id));
    }
}
