CREATE TABLE IF NOT EXISTS bloqueo_agenda (
    id_bloqueo  SERIAL     PRIMARY KEY,
    id_medico   INT        NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,
    inicio      TIMESTAMP  NOT NULL,
    fin         TIMESTAMP  NOT NULL,
    motivo      TEXT       NOT NULL,
    activo      BOOLEAN    NOT NULL DEFAULT TRUE,
    creado      TIMESTAMP  NOT NULL DEFAULT NOW(),
    CHECK (fin > inicio)
);
