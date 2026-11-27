-- Webhook de empleados: id del empleado en la otra plataforma + bitácora de avisos recibidos.
ALTER TABLE empleados ADD COLUMN id_externo VARCHAR(60) NULL;
ALTER TABLE empleados ADD UNIQUE KEY uq_empleados_id_externo (id_externo);

CREATE TABLE IF NOT EXISTS webhook_empleados_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  created_at  DATETIME NOT NULL,
  ip          VARCHAR(45) NULL,
  evento      VARCHAR(40) NULL,
  id_externo  VARCHAR(60) NULL,
  curp        VARCHAR(20) NULL,
  resultado   VARCHAR(20) NOT NULL,        -- creado | actualizado | error
  id_empleado INT NULL,
  mensaje     VARCHAR(255) NULL,
  payload     TEXT NULL,
  KEY idx_wh_curp (curp),
  KEY idx_wh_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
