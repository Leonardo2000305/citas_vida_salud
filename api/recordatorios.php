<?php
/**
 * ARCHIVO: recordatorios.php
 * CAPA: BACKEND (tarea programada)
 * SIRVE PARA: avisar a los pacientes que tienen cita mañana.
 * QUÉ HACE: con el token RECORDATORIO_TOKEN (config/local.php) avisa de las citas de mañana que aún no tienen recordatorio.
 *           Se programa una vez al día en AlwaysData (tareas programadas).
 * CONECTA CON: includes/notificaciones.php
 */
require_once __DIR__ . '/../includes/notificaciones.php';

header('Content-Type: text/plain; charset=utf-8');
$tokenReal = config_db('RECORDATORIO_TOKEN', '');
if ($tokenReal === '' || !hash_equals($tokenReal, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit('No encontrado.');
}

$pdo = db();
$st = $pdo->query(
    "SELECT c.id_cita, c.id_paciente FROM cita c
      WHERE c.inicio::date = (now() AT TIME ZONE 'America/La_Paz')::date + 1
        AND c.estado IN ('Programada', 'Confirmada') AND NOT c.recordatorio_enviado"
);
$enviados = 0;
foreach ($st->fetchAll() as $c) {
    notificar_paciente($pdo, (int) $c['id_paciente'], 'Recordatorio de cita',
        'Mañana tiene cita: ' . detalle_cita($pdo, (int) $c['id_cita']) . '. Llegue 10 minutos antes.');
    $pdo->prepare('UPDATE cita SET recordatorio_enviado = TRUE WHERE id_cita = :c')->execute([':c' => $c['id_cita']]);
    $enviados++;
}
echo "Recordatorios enviados: {$enviados}\n";
