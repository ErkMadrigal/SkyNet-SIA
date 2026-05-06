<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterUsuarioAddRolEmpresa extends Migration
{
    public function up(): void
    {
        // Agregar columnas a la tabla usuario existente (no la destruye)
        $this->db->query("
            ALTER TABLE `usuario`
                ADD COLUMN IF NOT EXISTS `id_rol_sistema`  TINYINT UNSIGNED NOT NULL DEFAULT 3
                    COMMENT 'FK a rol_sistema' AFTER `estatus`,
                ADD COLUMN IF NOT EXISTS `id_empresa_default` INT(11) DEFAULT NULL
                    COMMENT 'Empresa principal (superadmin = NULL)' AFTER `id_rol_sistema`,
                ADD COLUMN IF NOT EXISTS `ultimo_login`     DATETIME DEFAULT NULL AFTER `id_empresa_default`,
                ADD COLUMN IF NOT EXISTS `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `ultimo_login`,
                ADD COLUMN IF NOT EXISTS `updated_at`       DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
                ADD COLUMN IF NOT EXISTS `deleted_at`       DATETIME DEFAULT NULL AFTER `updated_at`,
                ADD COLUMN IF NOT EXISTS `is_deleted`       TINYINT(1) NOT NULL DEFAULT 0 AFTER `deleted_at`;
        ");

        // El usuario 1 (SuperAdmin existente) sube a nivel 1
        $this->db->query("
            UPDATE `usuario` SET `id_rol_sistema` = 1 WHERE `id` = 1;
        ");

        // FK suave (sin constraint para no romper data antigua)
        $this->db->query("
            ALTER TABLE `usuario`
                ADD INDEX IF NOT EXISTS `idx_usuario_rol` (`id_rol_sistema`),
                ADD INDEX IF NOT EXISTS `idx_usuario_empresa` (`id_empresa_default`),
                ADD INDEX IF NOT EXISTS `idx_usuario_deleted` (`is_deleted`);
        ");
    }

    public function down(): void
    {
        $this->db->query("
            ALTER TABLE `usuario`
                DROP COLUMN IF EXISTS `id_rol_sistema`,
                DROP COLUMN IF EXISTS `id_empresa_default`,
                DROP COLUMN IF EXISTS `ultimo_login`,
                DROP COLUMN IF EXISTS `created_at`,
                DROP COLUMN IF EXISTS `updated_at`,
                DROP COLUMN IF EXISTS `deleted_at`,
                DROP COLUMN IF EXISTS `is_deleted`;
        ");
    }
}
