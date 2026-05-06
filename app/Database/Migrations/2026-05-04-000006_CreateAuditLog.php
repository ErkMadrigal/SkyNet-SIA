<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 006 — audit_log
 * La BD ya puede tenerla. Usamos IF NOT EXISTS para no romper nada.
 * Si existe con menos columnas, ALTER agrega las que falten.
 */
class CreateAuditLog extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `audit_log` (
                `id`               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `created_at`       DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `actor_user_id`    INT(10)           NOT NULL DEFAULT 0,
                `actor_name`       VARCHAR(255)      DEFAULT NULL,
                `action`           VARCHAR(40)       NOT NULL,
                `entity`           VARCHAR(60)       NOT NULL,
                `entity_id`        VARCHAR(64)       DEFAULT NULL,
                `message`          VARCHAR(255)      DEFAULT NULL,
                `endpoint`         VARCHAR(160)      DEFAULT NULL,
                `method`           VARCHAR(10)       DEFAULT NULL,
                `status_code`      SMALLINT UNSIGNED DEFAULT 200,
                `response_message` VARCHAR(255)      DEFAULT NULL,
                `request_id`       CHAR(36)          DEFAULT NULL,
                `ip`               VARCHAR(45)       DEFAULT NULL,
                `user_agent`       VARCHAR(255)      DEFAULT NULL,
                `lat`              DECIMAL(10,7)     DEFAULT NULL,
                `lon`              DECIMAL(10,7)     DEFAULT NULL,
                `accuracy_m`       INT               DEFAULT NULL,
                `changes_json`     JSON              DEFAULT NULL,
                `before_json`      JSON              DEFAULT NULL,
                `after_json`       JSON              DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_al_actor`    (`actor_user_id`),
                INDEX `idx_al_action`   (`action`),
                INDEX `idx_al_entity`   (`entity`, `entity_id`),
                INDEX `idx_al_created`  (`created_at`),
                INDEX `idx_al_request`  (`request_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Log de auditoría completo de acciones del sistema SIA';
        ");

        // Agregar columnas que pueden faltar si la tabla ya existía
        $cols = [
            'actor_name'       => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `actor_name` VARCHAR(255) DEFAULT NULL AFTER `actor_user_id`",
            'endpoint'         => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `endpoint` VARCHAR(160) DEFAULT NULL AFTER `message`",
            'method'           => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `method` VARCHAR(10) DEFAULT NULL AFTER `endpoint`",
            'status_code'      => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `status_code` SMALLINT UNSIGNED DEFAULT 200 AFTER `method`",
            'response_message' => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `response_message` VARCHAR(255) DEFAULT NULL AFTER `status_code`",
            'request_id'       => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `request_id` CHAR(36) DEFAULT NULL AFTER `response_message`",
            'lat'              => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `lat` DECIMAL(10,7) DEFAULT NULL",
            'lon'              => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `lon` DECIMAL(10,7) DEFAULT NULL",
            'accuracy_m'       => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `accuracy_m` INT DEFAULT NULL",
            'changes_json'     => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `changes_json` JSON DEFAULT NULL",
            'before_json'      => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `before_json` JSON DEFAULT NULL",
            'after_json'       => "ALTER TABLE `audit_log` ADD COLUMN IF NOT EXISTS `after_json` JSON DEFAULT NULL",
        ];

        foreach ($cols as $sql) {
            try { $this->db->query($sql); } catch (\Exception $e) { /* columna ya existe */ }
        }
    }

    public function down(): void
    {
        // No eliminamos — el audit_log es datos históricos valiosos
    }
}
