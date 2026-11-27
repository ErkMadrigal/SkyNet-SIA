-- Webhook de empleados, parte 2: marca de "ya lo tiene la otra plataforma".
-- Correr UNA vez (si dice "Duplicate column name 'migrado_at'" ya la tienes).
ALTER TABLE empleados ADD COLUMN migrado_at DATETIME NULL;
