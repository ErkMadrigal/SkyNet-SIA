<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVistaPermisos extends Migration
{
    public function up(): void
    {
        // Catálogo de vistas del sistema
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `vistas` (
                `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug`        VARCHAR(60)  NOT NULL COMMENT 'empleados.listar | empleados.crear | etc.',
                `nombre`      VARCHAR(100) NOT NULL,
                `modulo`      VARCHAR(60)  NOT NULL COMMENT 'empleados | usuarios | asistencias | ...',
                `activo`      TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_vista_slug` (`slug`),
                INDEX `idx_vistas_modulo` (`modulo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Catálogo de vistas/pantallas del sistema';
        ");

        // Vistas iniciales del módulo auth + empleados
        $this->db->query("
            INSERT INTO `vistas` (`slug`, `nombre`, `modulo`) VALUES
            ('empleados.listar',  'Ver listado de empleados',    'empleados'),
            ('empleados.crear',   'Registrar nuevo empleado',    'empleados'),
            ('empleados.editar',  'Editar datos de empleado',    'empleados'),
            ('empleados.eliminar','Dar de baja empleado',        'empleados'),
            ('usuarios.listar',   'Ver usuarios del sistema',    'usuarios'),
            ('usuarios.crear',    'Registrar usuario sistema',   'usuarios'),
            ('usuarios.editar',   'Editar usuario sistema',      'usuarios'),
            ('usuarios.eliminar', 'Eliminar usuario sistema',    'usuarios'),
            ('asistencias.ver',   'Ver registros de asistencia', 'asistencias'),
            ('incidencias.ver',   'Ver incidencias',             'incidencias'),
            ('incidencias.gestionar','Gestionar incidencias',    'incidencias'),
            ('biometrico.registros','Ver registros biométricos', 'biometrico'),
            ('empresas.listar',   'Ver empresas',                'empresas'),
            ('empresas.crear',    'Crear empresa',               'empresas'),
            ('reportes.ver',      'Ver reportes',                'reportes')
            ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
        ");

        // Asignación rol → vista
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `vista_permisos` (
                `id`          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
                `id_rol`      TINYINT UNSIGNED  NOT NULL COMMENT 'FK rol_sistema.id',
                `id_vista`    SMALLINT UNSIGNED NOT NULL COMMENT 'FK vistas.id',
                `activo`      TINYINT(1)        NOT NULL DEFAULT 1,
                `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_rol_vista` (`id_rol`, `id_vista`),
                INDEX `idx_vp_rol`   (`id_rol`),
                INDEX `idx_vp_vista` (`id_vista`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Qué vistas puede acceder cada rol del sistema';
        ");

        // Permisos default: Admin tiene todo excepto gestión de empresas global
        $this->db->query("
            INSERT INTO `vista_permisos` (`id_rol`, `id_vista`)
            SELECT 2, id FROM `vistas`
            ON DUPLICATE KEY UPDATE `activo` = 1;
        ");

        // Operador: empleados (ver/crear/editar), asistencias, incidencias ver
        $this->db->query("
            INSERT INTO `vista_permisos` (`id_rol`, `id_vista`)
            SELECT 3, id FROM `vistas`
            WHERE `slug` IN (
                'empleados.listar','empleados.crear','empleados.editar',
                'asistencias.ver','incidencias.ver','biometrico.registros'
            )
            ON DUPLICATE KEY UPDATE `activo` = 1;
        ");

        // Viewer: solo lectura
        $this->db->query("
            INSERT INTO `vista_permisos` (`id_rol`, `id_vista`)
            SELECT 4, id FROM `vistas`
            WHERE `slug` IN (
                'empleados.listar','asistencias.ver',
                'incidencias.ver','reportes.ver'
            )
            ON DUPLICATE KEY UPDATE `activo` = 1;
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `vista_permisos`");
        $this->db->query("DROP TABLE IF EXISTS `vistas`");
    }
}
