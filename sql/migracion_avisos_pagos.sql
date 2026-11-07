CREATE TABLE IF NOT EXISTS notificacion (
    id_notificacion SERIAL     PRIMARY KEY,
    id_usuario      INT        NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    mensaje         TEXT       NOT NULL,
    leida           BOOLEAN    NOT NULL DEFAULT FALSE,
    creada          TIMESTAMP  NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS lista_espera (
    id_espera      SERIAL     PRIMARY KEY,
    id_paciente    INT        NOT NULL REFERENCES paciente(id_paciente) ON DELETE CASCADE,
    id_medico      INT        NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,
    fecha_deseada  DATE       NOT NULL,
    activa         BOOLEAN    NOT NULL DEFAULT TRUE,
    creada         TIMESTAMP  NOT NULL DEFAULT NOW(),
    UNIQUE (id_paciente, id_medico, fecha_deseada)
);
ALTER TABLE cita ADD COLUMN IF NOT EXISTS monto NUMERIC(10,2);
ALTER TABLE cita ADD COLUMN IF NOT EXISTS pagado BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE cita ADD COLUMN IF NOT EXISTS metodo_pago VARCHAR(30);
ALTER TABLE cita ADD COLUMN IF NOT EXISTS recordatorio_enviado BOOLEAN NOT NULL DEFAULT FALSE;
