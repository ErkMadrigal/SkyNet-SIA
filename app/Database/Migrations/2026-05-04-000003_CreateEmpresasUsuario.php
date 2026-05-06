<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEmpresasUsuario extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `empresas_usuario` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_usuario`  INT(10)      NOT NULL,
                `id_empresa`  INT(11)      NOT NULL,
                `creado_por`  INT(10)      NOT NULL COMMENT 'SuperAdmin o Admin que asignó',
                `activo`      TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `deleted_at`  DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_usuario_empresa` (`id_usuario`, `id_empresa`),
                INDEX `idx_eu_usuario`  (`id_usuario`),
                INDEX `idx_eu_empresa`  (`id_empresa`),
                INDEX `idx_eu_activo`   (`activo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Qué empresas puede ver/gestionar cada admin o usuario';
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `empresas_usuario`");
    }
}
