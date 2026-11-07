<?php
/**
 * ARCHIVO: pagos.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: el reporte de cobros de la clínica en un rango de fechas.
 * QUÉ HACE: GET ?desde=AAAA-MM-DD&hasta=AAAA-MM-DD -> citas pagadas, total por método y total general.
 *           El día que cuenta es el día de la cita.
 * CONECTA CON: pagos.html, api/citas.php (acción cobrar)
 */
require_once __DIR__ . '/_json.php';

json_metodo('GET');
json_requerir([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$desde = (string) ($_GET['desde'] ?? date('Y-m-d'));
$hasta = (string) ($_GET['hasta'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) || $hasta < $desde) {
    json_error(400, 'Indique un rango de fechas válido.');
}

$st = $pdo->prepare(
    "SELECT c.id_cita, c.codigo, to_char(c.inicio, 'DD/MM/YYYY') AS dia, to_char(c.inicio, 'HH24:MI') AS hora,
            p.nombres || ' ' || p.apellidos AS paciente,
            m.apellidos AS medico, c.monto, c.metodo_pago
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN medico m ON m.id_medico = c.id_medico
      WHERE c.pagado AND c.inicio::date BETWEEN :d AND :h
      ORDER BY c.inicio"
);
$st->execute([':d' => $desde, ':h' => $hasta]);
$cobros = $st->fetchAll();

$porMetodo = [];
$total = 0.0;
foreach ($cobros as $c) {
    $porMetodo[$c['metodo_pago']] = ($porMetodo[$c['metodo_pago']] ?? 0) + (float) $c['monto'];
    $total += (float) $c['monto'];
}

json_responder(200, [
    'cobros'      => $cobros,
    'por_metodo'  => $porMetodo,
    'total'       => round($total, 2),
    'cantidad'    => count($cobros),
]);
