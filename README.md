# Clínica Vida Salud · Sistema Web de Gestión de Citas Médicas

Proyecto de examen parcial II (Ingeniería de Requisitos). Aplicación web para el personal
administrativo de la clínica: pacientes, médicos, especialidades, horarios, citas, reportes y usuarios.

- **Backend:** PHP 8.1 o superior, PDO con `pdo_pgsql`
- **Base de datos:** PostgreSQL 13 o superior (con la extensión `btree_gist`, incluida en la instalación estándar)
- **Frontend:** HTML5, CSS3 y JavaScript (validación en el navegador; la validación real está en el servidor)

## Roles

| Rol | Puede |
|---|---|
| Administrador | Todo: usuarios, médicos, horarios, especialidades, reportes |
| Recepcionista | Pacientes, programar / reprogramar / cancelar citas, confirmar, registrar inasistencias, consultar agenda |
| Médico | Ver su propia agenda y marcar como atendidas sus citas, si el administrador le dio el permiso |

## Instalación

### 1. Crear la base de datos

```bash
createdb -U postgres citas_vida_salud
psql -U postgres -d citas_vida_salud -f sql/schema.sql
```

En Windows (XAMPP o PostgreSQL instalado) puede usar pgAdmin: cree la base `citas_vida_salud` y
ejecute el contenido de `sql/schema.sql` en la pestaña de consultas.

### 2. Configurar la conexión

`config/db.php` lee estas variables de entorno (valores por defecto entre paréntesis):

| Variable | Descripción |
|---|---|
| `DB_HOST` (`localhost`) | Servidor de PostgreSQL |
| `DB_PORT` (`5432`) | Puerto |
| `DB_NAME` (`citas_vida_salud`) | Nombre de la base |
| `DB_USER` (`postgres`) | Usuario |
| `DB_PASS` (vacío) | Contraseña |

Ejemplo en Linux o macOS:

```bash
export DB_PASS='su_clave'
php -S localhost:8000
```

En XAMPP se puede copiar la carpeta a `htdocs` y abrir `http://localhost/citas_vida_salud/`.

### 3. Ingresar

Usuarios de demostración (contraseña **Vida2026\***, cámbiela antes de usar datos reales):

| Usuario | Rol |
|---|---|
| `admin` | Administrador |
| `recepcion` | Recepcionista |
| `dr.perez` | Médico (con permiso para marcar citas como atendidas) |

## Pruebas automáticas

Las pruebas reinstalan el esquema en una base **de pruebas** (no use la base real):

```bash
createdb -U postgres citas_vida_salud_test
DB_NAME=citas_vida_salud_test php tests/run_tests.php
```

Resultado esperado: `18 de 18 pruebas pasaron`. Cubren las pruebas mínimas P1 a P10 del documento
y reglas adicionales (transiciones de estado, permisos por rol, médico inactivo, reprogramación).

## Lista de comprobación en el navegador (pruebas de interfaz)

| Prueba | Cómo verificarla | Resultado esperado |
|---|---|---|
| P1 | Pacientes → Nuevo paciente, llenar y guardar | Aparece en el listado |
| P2 | Repetir el mismo CI | Mensaje "Ya existe un paciente registrado con ese CI" |
| P3 | Citas → Programar cita, elegir médico, fecha y hora libre | Cita registrada como Programada |
| P4 | Programar otra cita con la misma hora y médico | "El horario no está disponible…" |
| P5 | Elegir una hora fuera del horario del médico | No aparece en la lista de horas |
| P6 | Citas → Cancelar, con motivo | Estado Cancelada; la cita sigue en el historial |
| P7 | Pacientes → Historial | Muestra todas las citas, incluidas las canceladas |
| P8 | Citas → filtrar por médico y fecha | Muestra solo esas citas |
| P9 | Abrir `citas.php` sin iniciar sesión | Redirige al inicio de sesión |
| P10 | Enviar un formulario con datos inválidos | Mensajes de error; el servidor también rechaza |

## Estructura

```
index.php, login.php, logout.php      Acceso y panel de inicio
pacientes.php, paciente_form.php,
paciente_historial.php                Pacientes (RF02, RF12)
medicos.php, medico_form.php,
medico_horarios.php, agenda.php       Médicos, horarios y agenda (RF03, RF05, RF11)
especialidades.php                    Especialidades (RF04)
citas.php, cita_nueva.php,
cita_reprogramar.php, cita_accion.php Citas (RF06 a RF10)
reportes.php                          Reportes y CSV (RF13)
usuarios.php, cambiar_clave.php       Usuarios y permisos (RF01)
api/disponibilidad.php                Horas libres en JSON para el formulario
includes/citas_servicio.php           Reglas de negocio de citas (núcleo del sistema)
includes/validaciones.php             Validación de datos del servidor
includes/bootstrap.php, layout.php    Sesión, CSRF, roles, diseño común
config/db.php                         Conexión PDO a PostgreSQL
sql/schema.sql                        Tablas, restricciones y datos de ejemplo
tests/run_tests.php                   Pruebas automáticas
```

## Decisiones de diseño

- **Sin citas superpuestas:** la base de datos tiene una restricción `EXCLUDE` sobre `(médico, intervalo)`
  que ignora las citas canceladas. Aunque dos recepcionistas guarden a la vez, PostgreSQL rechaza el cruce.
  Antes de guardar, la aplicación bloquea la fila del médico (`FOR UPDATE`) y vuelve a calcular los horarios libres.
- **Nada se elimina:** pacientes, médicos y especialidades se desactivan; las citas cambian de estado.
- **Reprogramar** una cita la deja en estado *Programada*, porque el nuevo horario debe confirmarse de nuevo.
- **Atendida e inasistencia** solo se registran cuando la cita ya empezó.
- **Seguridad:** contraseñas con `password_hash()` / `password_verify()`, consultas preparadas,
  protección CSRF en cada formulario, cookies `HttpOnly`, regeneración del identificador de sesión al ingresar,
  y errores técnicos solo en el log del servidor.

## Fuera del alcance de esta versión

- Feriados y bloqueos de agenda (opcional en el documento).
- Acceso de pacientes a la aplicación (el documento describe el sistema para personal administrativo).
- Recordatorios automáticos por correo o SMS.
