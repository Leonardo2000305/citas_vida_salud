<?php
/**
 * ARCHIVO: espera.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: que el paciente se anote en la lista de espera de un médico para un día.
 * QUÉ HACE: GET -> sus anotaciones activas. POST {accion:'anotar', medico, fecha} -> se anota.
 *           POST {accion:'quitar', id} -> se quita de la lista.
 *           Cuando se libera una hora de ese médico ese día, se le avisa (includes/notificaciones.php).
 * CONECTA CON: includes/notificaciones.php, js/voz_ui.js, mis-citas.html
 */
require_once __DIR__ . '/_json.php';
require_once __DIR__ . '/../includes/notificaciones.php';

$usuario = json_requerir([ROL_PACIENTE]);
$pdo = db();
$idPaciente = (int) ($usuario['id_paciente'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare(
        "SELECT le.id_espera, le.fecha_deseada, m.apellidos AS medico
           FROM lista_espera le JOIN medico m ON m.id_medico = le.id_medico
          WHERE le.id_paciente = :p AND le.activa AND le.fecha_deseada >= CURRENT_DATE
          ORDER BY le.fecha_deseada"
    );
    $st->execute([':p' => $idPaciente]);
    json_responder(200, ['espera' => $st->fetchAll()]);
}

json_metodo('POST');
json_verificar_csrf();
$d = json_entrada();
$accion = (string) ($d['accion'] ?? '');

if ($accion === 'anotar') {
    $medico = (int) ($d['medico'] ?? 0);
    $fecha = (string) ($d['fecha'] ?? '');
    $valida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && $fecha >= date('Y-m-d');
    if ($medico <= 0 || !$valida) {
        json_error(400, 'Elija un médico y un día de hoy en adelante.');
    }
    $pdo->prepare(
        'INSERT INTO lista_espera (id_paciente, id_medico, fecha_deseada, activa) VALUES (:p, :m, :f, TRUE)
         ON CONFLICT (id_paciente, id_medico, fecha_deseada) DO UPDATE SET activa = TRUE'
    )->execute([':p' => $idPaciente, ':m' => $medico, ':f' => $fecha]);
    json_responder(201, ['mensaje' => 'Quedó anotado. Le avisaremos si se libera una hora ese día.']);
}

if ($accion === 'quitar') {
    $pdo->prepare('UPDATE lista_espera SET activa = FALSE WHERE id_espera = :e AND id_paciente = :p')
        ->execute([':e' => (int) ($d['id'] ?? 0), ':p' => $idPaciente]);
    json_responder(200, ['mensaje' => 'Lo quitamos de la lista de espera.']);
}
json_error(400, 'Acción no válida.');
