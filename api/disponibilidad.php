<?php
/**
 * Devuelve en JSON los horarios libres de un médico en una fecha.
 * Lo usa el formulario de citas para actualizar la lista de horas sin recargar la página.
 * GET ?medico=ID&fecha=YYYY-MM-DD
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$usuario = usuario_actual();
if ($usuario === null || !es_rol($usuario, ROL_ADMIN, ROL_RECEPCION, ROL_PACIENTE, ROL_MEDICO)) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado.']);
    exit;
}

$idMedico = entero_param('medico');
$fecha = texto_param('fecha', $_GET);
if ($idMedico === null || fecha_valida($fecha) === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetros no válidos.']);
    exit;
}

try {
    $libres = array_map(static fn (array $s): array => [
        'valor' => $s['inicio']->format('H:i'),
        'texto' => $s['inicio']->format('H:i') . ' – ' . $s['fin']->format('H:i'),
    ], horarios_libres(db(), $idMedico, $fecha));
    echo json_encode(['horas' => array_values($libres)]);
} catch (ErrorCita $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
