<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateJwtTokens extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `jwt_tokens` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_usuario`    INT(10)         NOT NULL,
                `jti`           CHAR(36)        NOT NULL COMMENT 'JWT ID único (UUID v4)',
                `refresh_token` VARCHAR(512)    NOT NULL,
                `ip`            VARCHAR(45)     DEFAULT NULL,
                `user_agent`    VARCHAR(255)    DEFAULT NULL,
                `expires_at`    DATETIME        NOT NULL COMMENT 'Expiración del refresh token (7 días)',
                `revocado`      TINYINT(1)      NOT NULL DEFAULT 0,
                `revocado_en`   DATETIME        DEFAULT NULL,
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_jti` (`jti`),
                INDEX `idx_jt_usuario`  (`id_usuario`),
                INDEX `idx_jt_refresh`  (`refresh_token`(64)),
                INDEX `idx_jt_revocado` (`revocado`),
                INDEX `idx_jt_expires`  (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Control de refresh tokens y blacklist de sesiones JWT';
        ");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `jwt_tokens`");
    }
}
