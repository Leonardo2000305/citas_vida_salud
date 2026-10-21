-- =====================================================================
-- Clínica Vida Salud · Sistema Web de Gestión de Citas Médicas
-- Base de datos PostgreSQL (normalizada, con claves y restricciones)
-- Ejecutar sobre una base vacía:  psql -U postgres -d citas_vida_salud -f sql/schema.sql
-- =====================================================================

-- Extensión que permite a PostgreSQL impedir citas superpuestas
-- (incluida en las instalaciones estándar de PostgreSQL / contrib).
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- ---------------------------------------------------------------------
-- Roles del sistema
-- ---------------------------------------------------------------------
CREATE TABLE rol (
    id_rol  SMALLINT    PRIMARY KEY,
    nombre  VARCHAR(40) NOT NULL UNIQUE
);

INSERT INTO rol (id_rol, nombre) VALUES
    (1, 'Administrador'),
    (2, 'Recepcionista'),
    (3, 'Médico');

-- ---------------------------------------------------------------------
-- Secuencias para los códigos legibles (PAC-0001, MED-0001, CM-0001)
-- ---------------------------------------------------------------------
CREATE SEQUENCE seq_codigo_paciente START 1;
CREATE SEQUENCE seq_codigo_medico   START 1;
CREATE SEQUENCE seq_codigo_cita     START 1;

-- ---------------------------------------------------------------------
-- Especialidades (RN/RF04): nombre normalizado único
-- ---------------------------------------------------------------------
CREATE TABLE especialidad (
    id_especialidad SERIAL       PRIMARY KEY,
    nombre          VARCHAR(100) NOT NULL,
    descripcion     TEXT,
    activo          BOOLEAN      NOT NULL DEFAULT TRUE
);

-- Evita "Pediatría" y "  pediatria " duplicadas comparando en minúsculas y sin espacios extra
CREATE UNIQUE INDEX uq_especialidad_nombre
    ON especialidad (lower(regexp_replace(trim(nombre), '\s+', ' ', 'g')));

-- ---------------------------------------------------------------------
-- Pacientes (RF02). Nunca se eliminan: se desactivan (RN11)
-- ---------------------------------------------------------------------
CREATE TABLE paciente (
    id_paciente      SERIAL       PRIMARY KEY,
    codigo           VARCHAR(20)  NOT NULL UNIQUE
                     DEFAULT ('PAC-' || lpad(nextval('seq_codigo_paciente')::text, 4, '0')),
    ci               VARCHAR(20)  NOT NULL UNIQUE,          -- RF02: CI único
    nombres          VARCHAR(80)  NOT NULL,
    apellidos        VARCHAR(80)  NOT NULL,
    fecha_nacimiento DATE         NOT NULL,
    sexo             VARCHAR(10)  CHECK (sexo IN ('Masculino', 'Femenino', 'Otro')),
    telefono         VARCHAR(20)  NOT NULL,
    correo           VARCHAR(120),
    direccion        VARCHAR(200),
    activo           BOOLEAN      NOT NULL DEFAULT TRUE,
    fecha_registro   TIMESTAMP    NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Médicos (RF03)
-- ---------------------------------------------------------------------
CREATE TABLE medico (
    id_medico            SERIAL       PRIMARY KEY,
    codigo               VARCHAR(20)  NOT NULL UNIQUE
                         DEFAULT ('MED-' || lpad(nextval('seq_codigo_medico')::text, 4, '0')),
    registro_profesional VARCHAR(30)  NOT NULL UNIQUE,
    nombres              VARCHAR(80)  NOT NULL,
    apellidos            VARCHAR(80)  NOT NULL,
    telefono             VARCHAR(20),
    correo               VARCHAR(120),
    activo               BOOLEAN      NOT NULL DEFAULT TRUE   -- RN04: inactivo no recibe citas nuevas
);

-- Un médico puede tener varias especialidades y una especialidad varios médicos
CREATE TABLE medico_especialidad (
    id_medico       INT NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,
    id_especialidad INT NOT NULL REFERENCES especialidad(id_especialidad) ON DELETE CASCADE,
    PRIMARY KEY (id_medico, id_especialidad)
);

-- Horarios de atención (RF05). dia_semana: 1 = lunes ... 7 = domingo (ISO)
CREATE TABLE horario_medico (
    id_horario    SERIAL   PRIMARY KEY,
    id_medico     INT      NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,
    dia_semana    SMALLINT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),
    hora_inicio   TIME     NOT NULL,
    hora_fin      TIME     NOT NULL,
    duracion_cita SMALLINT NOT NULL DEFAULT 30 CHECK (duracion_cita IN (15, 20, 30, 45, 60)),
    CHECK (hora_fin > hora_inicio)                          -- RF05: hora final posterior a la inicial
);

-- ---------------------------------------------------------------------
-- Usuarios del sistema y roles (RF01)
-- ---------------------------------------------------------------------
CREATE TABLE usuario (
    id_usuario             SERIAL       PRIMARY KEY,
    nombre_usuario         VARCHAR(40)  NOT NULL UNIQUE,
    correo                 VARCHAR(120) NOT NULL UNIQUE,
    nombres                VARCHAR(80)  NOT NULL,
    apellidos              VARCHAR(80)  NOT NULL,
    password_hash          VARCHAR(255) NOT NULL,            -- password_hash() / password_verify()
    id_rol                 SMALLINT     NOT NULL REFERENCES rol(id_rol),
    id_medico              INT UNIQUE REFERENCES medico(id_medico),
    puede_marcar_atendida  BOOLEAN      NOT NULL DEFAULT FALSE,  -- permiso del médico (RF10)
    activo                 BOOLEAN      NOT NULL DEFAULT TRUE,
    CHECK (id_rol <> 3 OR id_medico IS NOT NULL)             -- un usuario Médico debe estar ligado a un médico
);

-- ---------------------------------------------------------------------
-- Citas médicas (RF06 a RF10)
-- inicio / fin guardan fecha y hora exactas de la cita.
-- La restricción EXCLUDE impide que un mismo médico tenga dos citas
-- que se crucen (RF07, RN07). Las canceladas no ocupan horario (RN09).
-- ---------------------------------------------------------------------
CREATE TABLE cita (
    id_cita              SERIAL       PRIMARY KEY,
    codigo               VARCHAR(20)  NOT NULL UNIQUE
                         DEFAULT ('CM-' || lpad(nextval('seq_codigo_cita')::text, 4, '0')),
    id_paciente          INT          NOT NULL REFERENCES paciente(id_paciente),
    id_medico            INT          NOT NULL REFERENCES medico(id_medico),
    id_especialidad      INT          NOT NULL REFERENCES especialidad(id_especialidad),
    inicio               TIMESTAMP    NOT NULL,
    fin                  TIMESTAMP    NOT NULL,
    motivo               VARCHAR(200),
    estado               VARCHAR(20)  NOT NULL DEFAULT 'Programada'
                         CHECK (estado IN ('Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')),
    motivo_cancelacion   VARCHAR(200),
    fecha_creacion       TIMESTAMP    NOT NULL DEFAULT now(),
    id_usuario_registro  INT REFERENCES usuario(id_usuario),
    CHECK (fin > inicio),
    EXCLUDE USING gist (
        id_medico WITH =,
        tsrange(inicio, fin, '[)') WITH &&
    ) WHERE (estado <> 'Cancelada')
);

CREATE INDEX idx_cita_medico_inicio ON cita (id_medico, inicio);
CREATE INDEX idx_cita_paciente      ON cita (id_paciente);
CREATE INDEX idx_cita_estado        ON cita (estado);

-- =====================================================================
-- DATOS DE EJEMPLO (ficticios)
-- Contraseña de todos los usuarios de demostración: Vida2026*
-- Cambiarla antes de usar el sistema con datos reales.
-- =====================================================================

INSERT INTO especialidad (nombre, descripcion) VALUES
    ('Medicina general', 'Consulta general de adultos'),
    ('Pediatría',        'Atención de niños y adolescentes'),
    ('Ginecología',      'Salud de la mujer'),
    ('Odontología',      'Atención dental'),
    ('Cardiología',      'Enfermedades del corazón');

INSERT INTO medico (registro_profesional, nombres, apellidos, telefono, correo) VALUES
    ('REG-1001', 'Juan',  'Pérez',  '70000001', 'jperez@clinicavidasalud.bo'),
    ('REG-1002', 'Ana',   'Rojas',  '70000002', 'arojas@clinicavidasalud.bo'),
    ('REG-1003', 'Luis',  'Vargas', '70000003', 'lvargas@clinicavidasalud.bo');

INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES
    (1, 1), (2, 2), (3, 4);

-- Juan Pérez: lunes 08:00-12:00 y miércoles 14:00-18:00, citas de 30 min
-- Ana Rojas: martes 09:00-13:00, jueves 09:00-12:00, citas de 30 min
-- Luis Vargas: lunes a viernes 09:00-12:00, citas de 30 min
INSERT INTO horario_medico (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita) VALUES
    (1, 1, '08:00', '12:00', 30),
    (1, 3, '14:00', '18:00', 30),
    (2, 2, '09:00', '13:00', 30),
    (2, 4, '09:00', '12:00', 30),
    (3, 1, '09:00', '12:00', 30),
    (3, 2, '09:00', '12:00', 30),
    (3, 3, '09:00', '12:00', 30),
    (3, 4, '09:00', '12:00', 30),
    (3, 5, '09:00', '12:00', 30);

INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion) VALUES
    ('7654321', 'María',  'López',  '1990-04-12', 'Femenino',  '71234567', 'mlopez@correo.bo', 'Av. Ejemplo 123'),
    ('8123456', 'Carlos', 'Quispe', '1984-09-30', 'Masculino', '72345678', NULL,               'Calle Demo 45');

INSERT INTO usuario (nombre_usuario, correo, nombres, apellidos, password_hash, id_rol, id_medico, puede_marcar_atendida) VALUES
    ('admin',         'admin@clinicavidasalud.bo',  'Carlos',  'Mendoza',  '$2y$10$5jO4F8PVeUig3D5FtyIyfOn1/hmKkra8YhxYGLtELNAPCMJOShhti', 1, NULL, FALSE),
    ('recepcion',     'recepcion@clinicavidasalud.bo', 'Lucía', 'Fernández', '$2y$10$5jO4F8PVeUig3D5FtyIyfOn1/hmKkra8YhxYGLtELNAPCMJOShhti', 2, NULL, FALSE),
    ('dr.perez',      'jperez@clinicavidasalud.bo', 'Juan',    'Pérez',    '$2y$10$5jO4F8PVeUig3D5FtyIyfOn1/hmKkra8YhxYGLtELNAPCMJOShhti', 3, 1,    TRUE);
