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
json_requerir();
$pdo = db();

$pacientes = (int) $pdo->query('SELECT COUNT(*) FROM paciente WHERE activo = TRUE')->fetchColumn();
$medicos = (int) $pdo->query('SELECT COUNT(*) FROM medico WHERE activo = TRUE')->fetchColumn();
$hoy = $pdo->query(
    "SELECT estado, COUNT(*) AS total FROM cita WHERE inicio::date = CURRENT_DATE GROUP BY estado"
)->fetchAll();
$porEstado = [];
foreach ($hoy as $fila) {
    $porEstado[$fila['estado']] = (int) $fila['total'];
}
$proximas = $pdo->query(
    "SELECT c.id_cita, c.codigo, c.inicio, c.estado, p.nombres || ' ' || p.apellidos AS paciente,
            m.nombres || ' ' || m.apellidos AS medico, e.nombre AS especialidad
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN medico m ON m.id_medico = c.id_medico
       JOIN especialidad e ON e.id_especialidad = c.id_especialidad
      WHERE c.inicio >= now() AND c.estado IN ('Programada', 'Confirmada')
      ORDER BY c.inicio LIMIT 6"
)->fetchAll();

json_responder(200, [
    'pacientes_activos' => $pacientes,
    'medicos_activos'   => $medicos,
    'citas_hoy'         => array_sum($porEstado),
    'citas_hoy_estado'  => $porEstado,
    'proximas'          => $proximas,
]);
