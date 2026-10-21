-- Borra todo el esquema (solo para reinstalar en una base de pruebas o desde cero)
DROP TABLE IF EXISTS cita, horario_medico, medico_especialidad, usuario, medico, paciente, especialidad, rol CASCADE;
DROP SEQUENCE IF EXISTS seq_codigo_paciente, seq_codigo_medico, seq_codigo_cita;
