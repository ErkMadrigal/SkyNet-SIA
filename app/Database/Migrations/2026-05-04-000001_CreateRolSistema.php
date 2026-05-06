<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRolSistema extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `rol_sistema` (
                `id`          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `slug`        VARCHAR(30)  NOT NULL COMMENT 'superadmin | admin | operador | viewer',
                `nombre`      VARCHAR(80)  NOT NULL,
                `descripcion` VARCHAR(255) DEFAULT NULL,
                `nivel`       TINYINT UNSIGNED NOT NULL COMMENT '1=SuperAdmin 2=Admin 3=Operador 4=Viewer',
                `activo`      TINYINT(1)  NOT NULL DEFAULT 1,
                `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Roles del sistema web SIA (no confundir con roles de empleados)';
        ");

        // Datos iniciales
        $this->db->query("
            INSERT INTO `rol_sistema` (`slug`, `nombre`, `descripcion`, `nivel`) VALUES
            ('superadmin', 'Super Administrador', 'Acceso total al sistema, todas las empresas y usuarios', 1),
            ('admin',      'Administrador',       'Gestión de empresas asignadas y sus usuarios',           2),
            ('operador',   'Operador',            'Acceso a vistas asignadas dentro de su empresa',         3),
            ('viewer',     'Solo lectura',        'Consulta sin modificaciones',                            4)
            ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `rol_sistema`");
    }
}
