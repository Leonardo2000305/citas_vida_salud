<?php
/**
 * ARCHIVO: especialidades_medicos.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: entregar los catálogos de especialidades y médicos que usa la interfaz.
 * QUÉ HACE:
 *   GET ?tipo=especialidades -> lista de especialidades activas
 *   GET ?tipo=medicos        -> médicos con sus especialidades y horarios de atención
 * CONECTA CON: tablas especialidad, medico, medico_especialidad, horario_medico; citas.html y medicos.html.
 */
require_once __DIR__ . '/_json.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Método no permitido.');
}
json_requerir();
$pdo = db();
$tipo = (string) ($_GET['tipo'] ?? '');

if ($tipo === 'especialidades') {
    $filas = $pdo->query(
        'SELECT id_especialidad, nombre, descripcion FROM especialidad WHERE activo = TRUE ORDER BY nombre'
    )->fetchAll();
    json_responder(200, ['especialidades' => $filas]);
}

if ($tipo === 'medicos') {
    $medicos = $pdo->query(
        'SELECT id_medico, codigo, registro_profesional, nombres, apellidos, telefono, correo, activo
           FROM medico ORDER BY apellidos, nombres'
    )->fetchAll();
    $esp = $pdo->query(
        'SELECT me.id_medico, e.id_especialidad, e.nombre
           FROM medico_especialidad me JOIN especialidad e ON e.id_especialidad = me.id_especialidad'
    )->fetchAll();
    $hor = $pdo->query(
        'SELECT id_medico, dia_semana, to_char(hora_inicio, \'HH24:MI\') AS hora_inicio,
                to_char(hora_fin, \'HH24:MI\') AS hora_fin, duracion_cita
           FROM horario_medico ORDER BY dia_semana, hora_inicio'
    )->fetchAll();

    foreach ($medicos as &$m) {
        $id = (int) $m['id_medico'];
        $m['especialidades'] = array_values(array_filter($esp, static fn ($e) => (int) $e['id_medico'] === $id));
        $m['horarios'] = array_values(array_filter($hor, static fn ($h) => (int) $h['id_medico'] === $id));
    }
    unset($m);
    json_responder(200, ['medicos' => $medicos]);
}

json_error(400, 'Indique tipo=especialidades o tipo=medicos.');
