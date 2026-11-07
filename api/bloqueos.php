<?php
/**
 * ARCHIVO: bloqueos.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: que el médico (o recepción/administración por él) bloquee días u horas en que no atiende.
 * QUÉ HACE:
 *   GET  -> bloqueos vigentes del médico.
 *   POST accion 'crear'   -> bloquea un rango. Avisa a los pacientes con cita en ese rango y a recepción.
 *                            Si cancelar_citas = true (y escribió CONFIRMAR), también cancela esas citas.
 *   POST accion 'levantar' -> quita el bloqueo (no borra nada).
 *   El médico solo puede tocar su propia agenda.
 * CONECTA CON: includes/citas_servicio.php (horarios libres), includes/notificaciones.php, citas.html
 */
require_once __DIR__ . '/_json.php';
require_once __DIR__ . '/../includes/citas_servicio.php';
require_once __DIR__ . '/../includes/notificaciones.php';

$usuario = json_requerir([ROL_ADMIN, ROL_RECEPCION, ROL_MEDICO]);
$pdo = db();

/** Médico de la agenda: el propio si es médico; si es personal, el que se indique. */
function agenda_medico(array $usuario, $indicado): int
{
    if ($usuario['rol'] === ROL_MEDICO) {
        if (empty($usuario['id_medico'])) {
            json_error(403, 'Su usuario no está ligado a un médico.');
        }
        return (int) $usuario['id_medico'];
    }
    $id = (int) $indicado;
    if ($id <= 0) {
        json_error(400, 'Elija el médico.');
    }
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $medico = agenda_medico($usuario, $_GET['medico'] ?? null);
    $st = $pdo->prepare(
        "SELECT id_bloqueo, to_char(inicio, 'YYYY-MM-DD\"T\"HH24:MI') AS inicio,
                to_char(fin, 'DD/MM/YYYY HH24:MI') AS fin_texto,
                to_char(inicio, 'DD/MM/YYYY HH24:MI') AS inicio_texto, motivo
           FROM bloqueo_agenda WHERE id_medico = :m AND activo AND fin > now() ORDER BY inicio"
    );
    $st->execute([':m' => $medico]);
    json_responder(200, ['bloqueos' => $st->fetchAll()]);
}

json_metodo('POST');
json_verificar_csrf();
$d = json_entrada();
$accion = (string) ($d['accion'] ?? '');
$medico = agenda_medico($usuario, $d['medico'] ?? null);

if ($accion === 'levantar') {
    $pdo->prepare('UPDATE bloqueo_agenda SET activo = FALSE WHERE id_bloqueo = :b AND id_medico = :m')
        ->execute([':b' => (int) ($d['id'] ?? 0), ':m' => $medico]);
    json_responder(200, ['mensaje' => 'Bloqueo levantado. Las horas vuelven a estar disponibles.']);
}
if ($accion !== 'crear') {
    json_error(400, 'Acción no válida.');
}

$ini = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($d['inicio'] ?? ''));
$fin = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($d['fin'] ?? ''));
if (!$ini || !$fin) {
    json_error(400, 'Indique desde cuándo y hasta cuándo.');
}
if ($fin <= $ini) {
    json_error(400, 'La hora final debe ser posterior a la inicial.');
}
if ($ini < new DateTimeImmutable('now')) {
    json_error(400, 'No se puede bloquear una fecha que ya pasó.');
}
$motivo = trim((string) ($d['motivo'] ?? ''));
if ($motivo === '' || mb_strlen($motivo) > 200) {
    json_error(400, 'Escriba el motivo (máximo 200 letras).');
}
$cancelar = !empty($d['cancelar_citas']);
if ($cancelar && (string) ($d['confirmacion'] ?? '') !== 'CONFIRMAR') {
    json_error(400, 'Para cancelar las citas, escriba CONFIRMAR.');
}

$iniSql = $ini->format('Y-m-d H:i:s');
$finSql = $fin->format('Y-m-d H:i:s');
$sistema = ['rol' => ROL_ADMIN, 'id' => (int) $usuario['id'], 'id_medico' => null, 'puede_atendida' => false];

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO bloqueo_agenda (id_medico, inicio, fin, motivo) VALUES (:m, :i, :f, :mo)')
        ->execute([':m' => $medico, ':i' => $iniSql, ':f' => $finSql, ':mo' => $motivo]);

    $st = $pdo->prepare(
        "SELECT c.id_cita, c.id_paciente, c.inicio::date AS dia FROM cita c
          WHERE c.id_medico = :m AND c.estado IN ('Programada', 'Confirmada')
            AND c.inicio < :f AND c.fin > :i ORDER BY c.inicio"
    );
    $st->execute([':m' => $medico, ':i' => $iniSql, ':f' => $finSql]);
    $citas = $st->fetchAll();

    $canceladas = 0;
    foreach ($citas as $c) {
        $detalle = detalle_cita($pdo, (int) $c['id_cita']);
        if ($cancelar) {
            cambiar_estado_cita($pdo, (int) $c['id_cita'], ESTADO_CANCELADA, $sistema, $motivo);
            notificar_paciente($pdo, (int) $c['id_paciente'], 'Cita cancelada',
                "Su cita fue cancelada: {$detalle}. Motivo: {$motivo}. Puede reservar otra en la página.");
            avisar_lista_espera($pdo, $medico, (string) $c['dia'], $detalle);
            $canceladas++;
        } else {
            notificar_paciente($pdo, (int) $c['id_paciente'], 'Cita afectada',
                "Su cita {$detalle} quedó afectada: {$motivo}. Recepción se comunicará con usted.");
        }
    }

    // Resumen para recepción y administración
    $resumen = 'Bloqueo de agenda del ' . $ini->format('d/m/Y H:i') . ' al ' . $fin->format('d/m/Y H:i')
        . '. Citas afectadas: ' . count($citas) . ($cancelar ? " (canceladas: {$canceladas})" : ' (no se canceló ninguna)')
        . ". Motivo: {$motivo}.";
    $personal = $pdo->query(
        "SELECT u.id_usuario FROM usuario u JOIN rol r ON r.id_rol = u.id_rol
          WHERE r.nombre IN ('Administrador', 'Recepcionista') AND u.activo"
    )->fetchAll();
    foreach ($personal as $p) {
        notificar_usuario($pdo, (int) $p['id_usuario'], 'Bloqueo de agenda', $resumen);
    }
    $pdo->commit();
} catch (ErrorCita $e) {
    $pdo->rollBack();
    json_error(400, $e->getMessage());
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Error al bloquear agenda: ' . $e->getMessage());
    json_error(500, 'No se pudo guardar el bloqueo. Intente de nuevo.');
}

json_responder(201, [
    'mensaje' => $cancelar
        ? "Agenda bloqueada. Se cancelaron {$canceladas} cita(s) y se avisó a los pacientes."
        : 'Agenda bloqueada. Los pacientes con cita en ese rango fueron avisados; recepción decidirá cada caso.',
    'afectadas' => count($citas),
]);
