<?php
/**
 * ARCHIVO: resumen.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: dar los números del panel de inicio (pacientes, citas de hoy, médicos activos).
 * QUÉ HACE: GET -> cuenta pacientes activos, citas de hoy por estado y médicos activos.
 * CONECTA CON: inicio.html.
 */
require_once __DIR__ . '/_json.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Método no permitido.');
}
$usuario = json_requerir();
$pdo = db();

// Cada quien ve solo sus citas: el médico las suyas, el paciente las suyas; el personal ve todas
$filtroCita = '';
$params = [];
if ($usuario['rol'] === ROL_MEDICO && $usuario['id_medico'] !== null) {
    $filtroCita = ' AND c.id_medico = :propio';
    $params[':propio'] = (int) $usuario['id_medico'];
} elseif ($usuario['rol'] === ROL_PACIENTE) {
    $filtroCita = ' AND c.id_paciente = :propio';
    $params[':propio'] = (int) ($usuario['id_paciente'] ?? 0);
}

$pacientes = (int) $pdo->query('SELECT COUNT(*) FROM paciente WHERE activo = TRUE')->fetchColumn();
$medicos = (int) $pdo->query('SELECT COUNT(*) FROM medico WHERE activo = TRUE')->fetchColumn();

$hoy = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM cita c WHERE c.inicio::date = CURRENT_DATE" . $filtroCita . " GROUP BY estado");
$hoy->execute($params);
$porEstado = [];
foreach ($hoy->fetchAll() as $fila) {
    $porEstado[$fila['estado']] = (int) $fila['total'];
}

$proximas = $pdo->prepare(
    "SELECT c.id_cita, c.codigo, c.inicio, c.estado, p.nombres || ' ' || p.apellidos AS paciente,
            m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN medico m ON m.id_medico = c.id_medico
       JOIN especialidad e ON e.id_especialidad = c.id_especialidad
      WHERE c.inicio >= now() AND c.estado IN ('Programada', 'Confirmada')" . $filtroCita . "
      ORDER BY c.inicio LIMIT 6"
);
$proximas->execute($params);

json_responder(200, [
    'pacientes_activos' => $pacientes,
    'medicos_activos'   => $medicos,
    'citas_hoy'         => array_sum($porEstado),
    'citas_hoy_estado'  => $porEstado,
    'proximas'          => $proximas->fetchAll(),
]);
