<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 008 — Ajustes finales del sistema SIA
 *
 * 1. Tabla `tabulador_salarios_detalle` — si no existe la crea
 * 2. Tabla `importe_adicional`          — si no existe la crea
 * 3. Event de limpieza de jwt_tokens expirados (cada día)
 */
class AjustesFinalesSIA extends Migration
{
    public function up(): void
    {
        // ── Tabulador salarios detalle ────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `tabulador_salarios` (
                `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `id_zona`         INT             NOT NULL,
                `nombre`          VARCHAR(120)    NOT NULL,
                `vigencia_inicio` DATE            NOT NULL,
                `vigencia_fin`    DATE            DEFAULT NULL,
                `estatus`         TINYINT(1)      NOT NULL DEFAULT 1,
                `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ts_zona`    (`id_zona`),
                INDEX `idx_ts_estatus` (`estatus`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `tabulador_salarios_detalle` (
                `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `id_tabulador` INT UNSIGNED    NOT NULL,
                `id_puesto`    INT             NOT NULL,
                `sueldo`       DECIMAL(10,2)   NOT NULL DEFAULT 0,
                `bono`         DECIMAL(10,2)   NOT NULL DEFAULT 0,
                `descuento`    DECIMAL(10,2)   NOT NULL DEFAULT 0,
                `estatus`      TINYINT(1)      NOT NULL DEFAULT 1,
                `updated_at`   DATETIME        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tab_puesto` (`id_tabulador`, `id_puesto`),
                INDEX `idx_tsd_tabulador` (`id_tabulador`),
                INDEX `idx_tsd_puesto`    (`id_puesto`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Detalle de sueldos, bonos y descuentos por puesto y tabulador';
        ");

        // ── Importe adicional ─────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `importe_adicional` (
                `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `no_empleado`    INT(10)         NOT NULL,
                `concepto`       VARCHAR(120)    NOT NULL,
                `tipo`           ENUM('INGRESO','DESCUENTO') NOT NULL,
                `importe`        DECIMAL(10,2)   NOT NULL,
                `fecha_aplicada` DATE            NOT NULL,
                `descripcion`    TEXT            DEFAULT NULL,
                `status`         TINYINT(1)      NOT NULL DEFAULT 1,
                `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ia_empleado` (`no_empleado`),
                INDEX `idx_ia_fecha`    (`fecha_aplicada`),
                INDEX `idx_ia_status`   (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Importes adicionales (ingresos y descuentos) para cálculo de nómina';
        ");

        // ── Evento MySQL: limpiar jwt_tokens expirados cada día ───────────
        // Solo se crea si el servidor tiene el event scheduler habilitado.
        try {
            $this->db->query("
                CREATE EVENT IF NOT EXISTS `evt_limpiar_jwt_tokens`
                ON SCHEDULE EVERY 1 DAY
                STARTS CURRENT_TIMESTAMP
                DO
                    DELETE FROM `jwt_tokens`
                    WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)
                    OR (`revocado` = 1 AND `revocado_en` < DATE_SUB(NOW(), INTERVAL 7 DAY));
            ");
        } catch (\Exception $e) {
            // El event scheduler puede estar deshabilitado en hosting compartido
            log_message('info', '[Migración 008] Event scheduler no disponible: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        $this->db->query("DROP EVENT IF EXISTS `evt_limpiar_jwt_tokens`");
        $this->db->query("DROP TABLE IF EXISTS `importe_adicional`");
        $this->db->query("DROP TABLE IF EXISTS `tabulador_salarios_detalle`");
        $this->db->query("DROP TABLE IF EXISTS `tabulador_salarios`");
    }
}
