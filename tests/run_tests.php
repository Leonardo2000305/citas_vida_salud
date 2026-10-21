<?php
/**
 * Pruebas automáticas del sistema (pruebas mínimas del documento + reglas de negocio).
 *
 * Uso (línea de comandos, sobre una base de PRUEBAS, nunca sobre la real):
 *   DB_NAME=citas_vida_salud_test DB_USER=postgres DB_PASS=... php tests/run_tests.php
 *
 * El script reinstala el esquema en la base indicada y luego ejecuta cada prueba.
 * Las pruebas de interfaz (botones, redirecciones) se describen en el README.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Ejecute este archivo desde la línea de comandos.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();
echo "Reinstalando esquema en la base '" . (getenv('DB_NAME') ?: 'citas_vida_salud') . "'...\n";
$raiz = __DIR__ . '/..';
$pdo->exec((string) file_get_contents($raiz . '/sql/drop.sql'));
$pdo->exec((string) file_get_contents($raiz . '/sql/schema.sql'));

// Fecha de prueba: el próximo lunes con al menos 7 días de anticipación (médico 1 atiende lunes 08:00-12:00)
$lunes = new DateTimeImmutable('today +7 days');
while ((int) $lunes->format('N') !== 1) {
    $lunes = $lunes->modify('+1 day');
}
$fecha = $lunes->format('Y-m-d');
$martes = $lunes->modify('+1 day')->format('Y-m-d');   // el médico 1 no atiende martes

$recepcion = ['id' => 2, 'nombre' => 'Recepción', 'rol' => ROL_RECEPCION, 'id_medico' => null, 'puede_atendida' => false];
$admin     = ['id' => 1, 'nombre' => 'Admin', 'rol' => ROL_ADMIN, 'id_medico' => null, 'puede_atendida' => false];
$medicoJP  = ['id' => 3, 'nombre' => 'Dr. Pérez', 'rol' => ROL_MEDICO, 'id_medico' => 1, 'puede_atendida' => true];
$medicoSinPermiso = ['id' => 3, 'nombre' => 'Dr. Pérez', 'rol' => ROL_MEDICO, 'id_medico' => 1, 'puede_atendida' => false];

$resultados = [];
function prueba(string $nombre, callable $fn): void
{
    global $resultados;
    try {
        $detalle = $fn();
        $resultados[] = [true, $nombre, is_string($detalle) ? $detalle : 'OK'];
    } catch (Throwable $e) {
        $resultados[] = [false, $nombre, $e->getMessage()];
    }
}

function afirmar(bool $cond, string $mensaje): void
{
    if (!$cond) {
        throw new RuntimeException($mensaje);
    }
}

function espera_error_cita(callable $fn, string $fragmento): void
{
    try {
        $fn();
    } catch (ErrorCita $e) {
        afirmar(str_contains($e->getMessage(), $fragmento), "Mensaje inesperado: " . $e->getMessage());
        return;
    }
    throw new RuntimeException('Se esperaba un rechazo y la operación fue aceptada.');
}

function nuevo_paciente(PDO $pdo, string $ci): int
{
    $st = $pdo->prepare(
        "INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, telefono)
         VALUES (:ci, 'Prueba', 'Paciente', '1990-01-01', '71111111') RETURNING id_paciente"
    );
    $st->execute([':ci' => $ci]);
    return (int) $st->fetchColumn();
}

// ---------------------------------------------------------------------
// Pruebas mínimas del documento (sección 8)
// ---------------------------------------------------------------------
prueba('P1 Registrar un paciente nuevo', function () use ($pdo): string {
    $id = nuevo_paciente($pdo, '1000001');
    afirmar($id > 0, 'El paciente no quedó guardado.');
    return "Paciente registrado con id {$id}.";
});

prueba('P2 Registrar un paciente con CI duplicado', function () use ($pdo): string {
    try {
        nuevo_paciente($pdo, '1000001');
    } catch (PDOException $e) {
        afirmar($e->getCode() === '23505', 'Error distinto al de duplicado: ' . $e->getCode());
        return 'La base de datos rechazó el CI duplicado (restricción UNIQUE).';
    }
    throw new RuntimeException('Se aceptó un CI duplicado.');
});

$idCitaPrincipal = 0;
prueba('P3 Programar una cita en horario disponible', function () use ($pdo, $fecha, &$idCitaPrincipal): string {
    $idCitaPrincipal = crear_cita($pdo, [
        'paciente' => 1, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '08:00', 'motivo' => 'Control',
    ], 2);
    $c = $pdo->query("SELECT estado, codigo FROM cita WHERE id_cita = {$idCitaPrincipal}")->fetch();
    afirmar($c['estado'] === ESTADO_PROGRAMADA, 'Estado inicial incorrecto.');
    return "Cita {$c['codigo']} registrada como Programada.";
});

prueba('P4 Programar otra cita superpuesta para el mismo médico', function () use ($pdo, $fecha): string {
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '08:00', 'motivo' => '',
    ], 2), 'no está disponible');
    return 'La aplicación rechazó el horario ya ocupado.';
});

prueba('P4b La base de datos impide el cruce aunque se salte la aplicación', function () use ($pdo, $fecha): string {
    try {
        $pdo->prepare(
            "INSERT INTO cita (id_paciente, id_medico, id_especialidad, inicio, fin)
             VALUES (2, 1, 1, :ini, :fin)"
        )->execute([':ini' => "{$fecha} 08:15:00", ':fin' => "{$fecha} 08:45:00"]);
    } catch (PDOException $e) {
        afirmar($e->getCode() === '23P01', 'Error distinto al de solapamiento: ' . $e->getCode());
        return 'Restricción EXCLUDE activa: rechazó el cruce 08:15-08:45.';
    }
    throw new RuntimeException('La base aceptó dos citas cruzadas.');
});

prueba('P5 Reservar fuera del horario del médico', function () use ($pdo, $fecha, $martes): string {
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '20:00', 'motivo' => '',
    ], 2), 'no está disponible');
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $martes, 'hora' => '08:00', 'motivo' => '',
    ], 2), 'no está disponible');
    return 'Rechazó una hora fuera de horario y un día sin atención.';
});

prueba('P6 Cancelar una cita: estado Cancelada y se conserva el historial', function () use ($pdo, $idCitaPrincipal, $fecha, $recepcion): string {
    cambiar_estado_cita($pdo, $idCitaPrincipal, ESTADO_CANCELADA, $recepcion, 'Paciente avisó');
    $c = $pdo->query("SELECT estado, motivo_cancelacion FROM cita WHERE id_cita = {$idCitaPrincipal}")->fetch();
    afirmar($c !== false, 'La cita fue eliminada físicamente.');
    afirmar($c['estado'] === ESTADO_CANCELADA, 'El estado no cambió a Cancelada.');
    afirmar($c['motivo_cancelacion'] === 'Paciente avisó', 'No se guardó el motivo.');
    $libres = horarios_libres($pdo, 1, $fecha);
    afirmar(buscar_slot($libres, "{$fecha} 08:00") !== null, 'El horario cancelado no quedó libre (RN09).');
    return 'La cita quedó Cancelada, con motivo, y el horario volvió a estar libre.';
});

prueba('P7 Consultar el historial de un paciente', function () use ($pdo, $fecha): string {
    $id = crear_cita($pdo, [
        'paciente' => 1, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '08:30', 'motivo' => '',
    ], 2);
    $st = $pdo->prepare('SELECT COUNT(*) FROM cita WHERE id_paciente = 1');
    $st->execute();
    afirmar((int) $st->fetchColumn() >= 2, 'El historial no muestra todas las citas (incluida la cancelada).');
    return "Historial del paciente 1 con citas activas y canceladas (cita nueva id {$id}).";
});

prueba('P8 Buscar las citas de un médico por fecha', function () use ($pdo, $fecha): string {
    $st = $pdo->prepare("SELECT COUNT(*) FROM cita WHERE id_medico = 1 AND inicio::date = :f AND estado <> 'Cancelada'");
    $st->execute([':f' => $fecha]);
    $n = (int) $st->fetchColumn();
    afirmar($n === 1, "Se esperaba 1 cita activa ese día y hubo {$n}.");
    return "1 cita activa para el médico en {$fecha}.";
});

prueba('P9 Ingresar a una página interna sin iniciar sesión', function (): string {
    $_SESSION = [];
    afirmar(usuario_actual() === null, 'Hay un usuario sin sesión.');
    $sinLogin = [];
    foreach (glob(__DIR__ . '/../*.php') as $archivo) {
        $nombre = basename($archivo);
        if (in_array($nombre, ['login.php', 'logout.php'], true)) {
            continue;
        }
        if (!str_contains((string) file_get_contents($archivo), 'requerir_login(')) {
            $sinLogin[] = $nombre;
        }
    }
    afirmar($sinLogin === [], 'Páginas sin control de acceso: ' . implode(', ', $sinLogin));
    return 'Sin sesión no hay usuario; todas las páginas internas llaman a requerir_login().';
});

prueba('P10 Operación con datos inválidos', function () use ($pdo, $fecha): string {
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 999999, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '09:00', 'motivo' => '',
    ], 2), 'no existe');
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => '2026-02-30', 'hora' => '09:00', 'motivo' => '',
    ], 2), 'no es válida');
    espera_error_cita(fn () => crear_cita($pdo, [
        'paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '25:99', 'motivo' => '',
    ], 2), 'no es válida');
    afirmar(validar_ci('ab') !== null && validar_correo('no-es-correo') !== null, 'Validadores no detectaron datos inválidos.');
    return 'Rechazó paciente inexistente, fecha imposible, hora inválida y validaciones de formato.';
});

// ---------------------------------------------------------------------
// Reglas de negocio adicionales
// ---------------------------------------------------------------------
prueba('R1 Una cita atendida no puede cancelarse ni reprogramarse', function () use ($pdo, $recepcion, $admin, $fecha): string {
    $id = crear_cita($pdo, ['paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '10:00', 'motivo' => ''], 2);
    $pdo->exec("UPDATE cita SET inicio = inicio - interval '30 days', fin = fin - interval '30 days' WHERE id_cita = {$id}");
    // Simula una cita pasada para poder marcarla como atendida
    cambiar_estado_cita($pdo, $id, ESTADO_CONFIRMADA, $recepcion);
    cambiar_estado_cita($pdo, $id, ESTADO_ATENDIDA, $admin);
    espera_error_cita(fn () => cambiar_estado_cita($pdo, $id, ESTADO_CANCELADA, $admin), 'No se puede cambiar');
    espera_error_cita(fn () => reprogramar_cita($pdo, $id, $fecha, '11:00'), 'Solo se pueden reprogramar');
    return 'Atendida es un estado final.';
});

prueba('R2 La recepcionista no puede marcar una cita como atendida', function () use ($pdo, $recepcion, $fecha): string {
    $id = crear_cita($pdo, ['paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '11:00', 'motivo' => ''], 2);
    $pdo->exec("UPDATE cita SET inicio = inicio - interval '30 days', fin = fin - interval '30 days' WHERE id_cita = {$id}");
    espera_error_cita(fn () => cambiar_estado_cita($pdo, $id, ESTADO_ATENDIDA, $recepcion), 'permiso');
    return 'Recepción no puede atender citas (solo el médico con permiso o el administrador).';
});

prueba('R3 El médico solo marca como atendidas sus citas y con permiso', function () use ($pdo, $medicoSinPermiso, $medicoJP, $fecha): string {
    $id = crear_cita($pdo, ['paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '11:30', 'motivo' => ''], 2);
    $pdo->exec("UPDATE cita SET inicio = inicio - interval '30 days', fin = fin - interval '30 days' WHERE id_cita = {$id}");
    espera_error_cita(fn () => cambiar_estado_cita($pdo, $id, ESTADO_ATENDIDA, $medicoSinPermiso), 'permiso');
    cambiar_estado_cita($pdo, $id, ESTADO_ATENDIDA, $medicoJP);
    return 'Sin permiso no puede; con permiso y cita propia sí.';
});

prueba('R4 Un médico inactivo no recibe citas nuevas', function () use ($pdo, $fecha): string {
    $pdo->exec('UPDATE medico SET activo = FALSE WHERE id_medico = 1');
    try {
        espera_error_cita(fn () => crear_cita($pdo, ['paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '08:45', 'motivo' => ''], 2), 'no está activo');
    } finally {
        $pdo->exec('UPDATE medico SET activo = TRUE WHERE id_medico = 1');
    }
    return 'Médico inactivo rechazado (RN04).';
});

prueba('R5 Reprogramar mueve la cita a un horario libre', function () use ($pdo, $fecha, $recepcion): string {
    $id = crear_cita($pdo, ['paciente' => 2, 'medico' => 1, 'especialidad' => 1, 'fecha' => $fecha, 'hora' => '09:30', 'motivo' => ''], 2);
    reprogramar_cita($pdo, $id, $fecha, '09:00');
    $inicio = $pdo->query("SELECT to_char(inicio, 'HH24:MI') FROM cita WHERE id_cita = {$id}")->fetchColumn();
    afirmar($inicio === '09:00', "La cita quedó a las {$inicio}.");
    return 'Cita reprogramada a 09:00 sin duplicar horario.';
});

prueba('R6 Especialidad con nombre duplicado (normalizado) se rechaza', function () use ($pdo): string {
    espera_error_cita(fn () => guardar_especialidad($pdo, '  PEDIATRÍA ', null, null), 'Ya existe');
    guardar_especialidad($pdo, 'Psicología', null, null);
    espera_error_cita(fn () => guardar_especialidad($pdo, 'psicologia  ', null, null), 'Ya existe');
    return 'Sin distinguir mayúsculas, espacios ni tildes en el nombre.';
});

prueba('R7 Validaciones de formato (CI, teléfono, contraseña, nombres)', function (): string {
    afirmar(validar_ci('7654321') === null, 'CI válido rechazado.');
    afirmar(validar_telefono('71234567') === null, 'Teléfono válido rechazado.');
    afirmar(validar_clave('Abcdef12') === null, 'Contraseña válida rechazada.');
    afirmar(validar_clave('sinnumero') !== null, 'Contraseña sin número aceptada.');
    afirmar(validar_nombre('María José', 'nombres') === null, 'Nombre válido rechazado.');
    afirmar(validar_nombre('Mar1a <script>', 'nombres') !== null, 'Nombre con caracteres peligrosos aceptado.');
    return 'Validadores de formato correctos.';
});

// ---------------------------------------------------------------------
// Resultado
// ---------------------------------------------------------------------
$ok = 0;
foreach ($resultados as [$pasa, $nombre, $detalle]) {
    $pasa ? $ok++ : null;
    printf("[%s] %s\n       %s\n", $pasa ? ' OK ' : 'FALLA', $nombre, $detalle);
}
printf("\n%d de %d pruebas pasaron.\n", $ok, count($resultados));
exit($ok === count($resultados) ? 0 : 1);
