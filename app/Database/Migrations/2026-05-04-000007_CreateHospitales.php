<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migración 007 — Módulo Hospitales / Inventario
 * Crea las tablas: hospitales, producto, recepcion, salida, inventario (vista o tabla)
 * Todas con IF NOT EXISTS — no rompe si ya existen.
 */
class CreateHospitales extends Migration
{
    public function up(): void
    {
        // ── Hospitales ────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `hospitales` (
                `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `unidad_medica` VARCHAR(255)    NOT NULL,
                `clave`         VARCHAR(50)     DEFAULT NULL,
                `municipio`     VARCHAR(120)    DEFAULT NULL,
                `estado`        VARCHAR(80)     DEFAULT NULL,
                `activo`        TINYINT(1)      NOT NULL DEFAULT 1,
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_hosp_activo` (`activo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Catálogo de hospitales / unidades médicas';
        ");

        // ── Productos ─────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `producto` (
                `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `nombre_base`  VARCHAR(255)    NOT NULL,
                `codigo`       VARCHAR(80)     DEFAULT NULL,
                `descripcion`  TEXT            DEFAULT NULL,
                `unidad`       VARCHAR(40)     DEFAULT NULL COMMENT 'piezas, cajas, litros...',
                `activo`       TINYINT(1)      NOT NULL DEFAULT 1,
                `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_prod_activo` (`activo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Catálogo de productos del módulo hospitales';
        ");

        // ── Recepciones (entradas) ────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `recepcion` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_empleado`   INT(10)         NOT NULL,
                `id_producto`   INT UNSIGNED    NOT NULL,
                `cantidad`      DECIMAL(10,2)   NOT NULL,
                `id_hospital`   INT UNSIGNED    NOT NULL,
                `observaciones` TEXT            DEFAULT NULL,
                `fecha_ingreso` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_rec_hospital`  (`id_hospital`),
                INDEX `idx_rec_producto`  (`id_producto`),
                INDEX `idx_rec_empleado`  (`id_empleado`),
                INDEX `idx_rec_fecha`     (`fecha_ingreso`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Entradas de producto a hospital';
        ");

        // ── Salidas ───────────────────────────────────────────────────────
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `salida` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_empleado`  INT(10)         NOT NULL,
                `id_producto`  INT UNSIGNED    NOT NULL,
                `cantidad`     DECIMAL(10,2)   NOT NULL,
                `id_hospital`  INT UNSIGNED    NOT NULL,
                `motivo`       TEXT            DEFAULT NULL,
                `fecha_salida` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_sal_hospital`  (`id_hospital`),
                INDEX `idx_sal_producto`  (`id_producto`),
                INDEX `idx_sal_empleado`  (`id_empleado`),
                INDEX `idx_sal_fecha`     (`fecha_salida`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Salidas de producto de hospital';
        ");

        // ── Inventario (vista materializada o tabla de stock) ─────────────
        // Si en tu BD inventario ya es una VIEW, este CREATE se saltará.
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `inventario` (
                `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `id_producto`  INT UNSIGNED    NOT NULL,
                `id_hospital`  INT UNSIGNED    NOT NULL,
                `nombre_base`  VARCHAR(255)    NOT NULL,
                `stock_actual` DECIMAL(10,2)   NOT NULL DEFAULT 0,
                `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_inv_prod_hosp` (`id_producto`, `id_hospital`),
                INDEX `idx_inv_hospital` (`id_hospital`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='Stock actual por producto y hospital. Actualizar con triggers o desde la app.';
        ");

        // Columna id_hospital en empleados (si no existe)
        try {
            $this->db->query("
                ALTER TABLE `empleados`
                    ADD COLUMN IF NOT EXISTS `id_hospital` INT UNSIGNED DEFAULT NULL
                    COMMENT 'Hospital asignado al empleado' AFTER `estatus`;
            ");
        } catch (\Exception $e) { /* ya existe */ }
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `inventario`");
        $this->db->query("DROP TABLE IF EXISTS `salida`");
        $this->db->query("DROP TABLE IF EXISTS `recepcion`");
        $this->db->query("DROP TABLE IF EXISTS `producto`");
        $this->db->query("DROP TABLE IF EXISTS `hospitales`");
    }
}
