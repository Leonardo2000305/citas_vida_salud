-- Derivación entre médicos: un médico puede programar una cita con otro médico
-- para un paciente que lo necesita. Se guarda quién derivó.
ALTER TABLE cita ADD COLUMN IF NOT EXISTS derivado_por INT REFERENCES medico(id_medico);
