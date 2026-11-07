-- ARCHIVO: migracion_cuentas_pacientes.sql
-- CAPA: BASE DE DATOS (migración)
-- SIRVE PARA: agregar cuentas de pacientes: registro con correo o teléfono, verificación y recuperación de contraseña.
-- QUÉ HACE: ejecutar UNA sola vez sobre una base que ya tiene schema.sql. No borra datos.
-- CONECTA CON: api/registro.php, api/verificar.php, api/recuperar.php, api/sesion.php y api/citas.php

INSERT INTO rol (id_rol, nombre) VALUES (4, 'Paciente') ON CONFLICT (id_rol) DO NOTHING;

ALTER TABLE usuario ALTER COLUMN correo DROP NOT NULL;                       -- la cuenta puede ser solo por teléfono
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS ci VARCHAR(20) UNIQUE;          -- un solo carnet por cuenta
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) UNIQUE;    -- un solo teléfono por cuenta
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS id_paciente INT UNIQUE REFERENCES paciente(id_paciente);
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS codigo_verificacion VARCHAR(255);  -- hash del código del correo; NULL = verificado
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS codigo_expira TIMESTAMP;
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS recuperacion_hash VARCHAR(255);    -- hash del código de recuperación
ALTER TABLE usuario ADD COLUMN IF NOT EXISTS recuperacion_expira TIMESTAMP;
