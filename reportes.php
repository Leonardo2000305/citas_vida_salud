<?php
/**
 * Reportes administrativos (RF13): citas por fecha, médico, especialidad y estado,
 * pacientes registrados y resumen de atendidas, canceladas e inasistencias.
 * Filtros por rango de fechas y exportación opcional a CSV. Solo Administrador.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN]);
$pdo = db();

$desde = texto_param('desde', $_GET) ?: date('Y-m-d');
$hasta = texto_param('hasta', $_GET) ?: $desde;
if (fecha_valida($desde) === null || fecha_valida($hasta) === null || $hasta < $desde) {
    $desde = $hasta = date('Y-m-d');
}
$tipo = texto_param('tipo', $_GET) ?: 'resumen';

$rango = ['desde' => $desde, 'hasta' => $hasta];
$consultas = [
    'resumen' => [
        'titulo' => 'Resumen de atenciones',
        'sql' => "SELECT
                    COUNT(*) FILTER (WHERE estado = 'Atendida')   AS atendidas,
                    COUNT(*) FILTER (WHERE estado = 'Cancelada')  AS canceladas,
                    COUNT(*) FILTER (WHERE estado = 'Inasistencia') AS inasistencias,
                    COUNT(*) FILTER (WHERE estado IN ('Programada','Confirmada')) AS pendientes,
                    COUNT(*) AS total
                  FROM cita WHERE inicio::date BETWEEN :desde AND :hasta",
        'columnas' => ['atendidas' => 'Atendidas', 'canceladas' => 'Canceladas', 'inasistencias' => 'Inasistencias',
                       'pendientes' => 'Pendientes', 'total' => 'Total'],
    ],
    'fecha' => [
        'titulo' => 'Citas por fecha',
        'sql' => "SELECT to_char(c.inicio, 'DD/MM/YYYY HH24:MI') AS fecha_hora, c.codigo,
                         p.nombres || ' ' || p.apellidos AS paciente,
                         m.nombres || ' ' || m.apellidos AS medico, esp.nombre AS especialidad, c.estado
                    FROM cita c
                    JOIN paciente p ON p.id_paciente = c.id_paciente
                    JOIN medico m ON m.id_medico = c.id_medico
                    JOIN especialidad esp ON esp.id_especialidad = c.id_especialidad
                   WHERE c.inicio::date BETWEEN :desde AND :hasta
                   ORDER BY c.inicio",
        'columnas' => ['fecha_hora' => 'Fecha y hora', 'codigo' => 'Código', 'paciente' => 'Paciente',
                       'medico' => 'Médico', 'especialidad' => 'Especialidad', 'estado' => 'Estado'],
    ],
    'medico' => [
        'titulo' => 'Citas por médico',
        'sql' => "SELECT m.nombres || ' ' || m.apellidos AS medico, COUNT(c.id_cita) AS total,
                         COUNT(c.id_cita) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                         COUNT(c.id_cita) FILTER (WHERE c.estado = 'Cancelada') AS canceladas,
                         COUNT(c.id_cita) FILTER (WHERE c.estado = 'Inasistencia') AS inasistencias
                    FROM medico m
                    LEFT JOIN cita c ON c.id_medico = m.id_medico
                         AND c.inicio::date BETWEEN :desde AND :hasta
                   GROUP BY m.id_medico, m.apellidos, m.nombres
                   ORDER BY m.apellidos, m.nombres",
        'columnas' => ['medico' => 'Médico', 'total' => 'Total', 'atendidas' => 'Atendidas',
                       'canceladas' => 'Canceladas', 'inasistencias' => 'Inasistencias'],
    ],
    'especialidad' => [
        'titulo' => 'Citas por especialidad',
        'sql' => "SELECT esp.nombre AS especialidad, COUNT(c.id_cita) AS total,
                         COUNT(c.id_cita) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                         COUNT(c.id_cita) FILTER (WHERE c.estado = 'Cancelada') AS canceladas
                    FROM especialidad esp
                    LEFT JOIN cita c ON c.id_especialidad = esp.id_especialidad
                         AND c.inicio::date BETWEEN :desde AND :hasta
                   GROUP BY esp.id_especialidad, esp.nombre
                   ORDER BY esp.nombre",
        'columnas' => ['especialidad' => 'Especialidad', 'total' => 'Total', 'atendidas' => 'Atendidas', 'canceladas' => 'Canceladas'],
    ],
    'estado' => [
        'titulo' => 'Citas por estado',
        'sql' => "SELECT c.estado, COUNT(*) AS total
                    FROM cita c
                   WHERE c.inicio::date BETWEEN :desde AND :hasta
                   GROUP BY c.estado
                   ORDER BY c.estado",
        'columnas' => ['estado' => 'Estado', 'total' => 'Total'],
    ],
    'pacientes' => [
        'titulo' => 'Pacientes registrados',
        'sql' => "SELECT p.codigo, p.ci, p.apellidos || ', ' || p.nombres AS paciente,
                         to_char(p.fecha_registro, 'DD/MM/YYYY') AS registrado, p.telefono,
                         CASE WHEN p.activo THEN 'Activo' ELSE 'Inactivo' END AS estado
                    FROM paciente p
                   WHERE p.fecha_registro::date BETWEEN :desde AND :hasta
                   ORDER BY p.fecha_registro",
        'columnas' => ['codigo' => 'Código', 'ci' => 'CI', 'paciente' => 'Paciente',
                       'registrado' => 'Registrado', 'telefono' => 'Teléfono', 'estado' => 'Estado'],
    ],
];
if (!isset($consultas[$tipo])) {
    $tipo = 'resumen';
}

$config = $consultas[$tipo];
$st = $pdo->prepare($config['sql']);
$st->execute([':desde' => $desde, ':hasta' => $hasta]);
$filas = $st->fetchAll();

// Exportación a CSV (mejora opcional)
if (texto_param('csv', $_GET) === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . $desde . '_' . $hasta . '.csv"');
    echo "\xEF\xBB\xBF";   // BOM para que Excel reconozca los acentos
    $salida = fopen('php://output', 'w');
    fputcsv($salida, array_values($config['columnas']), ';');
    foreach ($filas as $f) {
        fputcsv($salida, array_map(static fn ($k) => $f[$k] ?? '', array_keys($config['columnas'])), ';');
    }
    fclose($salida);
    exit;
}

echo cabecera_pagina('Reportes', $usuario, 'reportes.php');
?>
<form method="get" class="filtros tarjeta">
    <label>Tipo de reporte
        <select name="tipo">
            <?php foreach ($consultas as $clave => $c): ?>
                <option value="<?= e($clave) ?>" <?= $clave === $tipo ? 'selected' : '' ?>><?= e($c['titulo']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>" required></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>" required></label>
    <div class="acciones">
        <button class="btn btn-principal" type="submit">Generar</button>
        <a class="btn" href="reportes.php?<?= e(http_build_query(['tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'csv' => 1])) ?>">Exportar CSV</a>
    </div>
</form>

<h2><?= e($config['titulo']) ?> <small class="ayuda">del <?= e((new DateTimeImmutable($desde))->format('d/m/Y')) ?> al <?= e((new DateTimeImmutable($hasta))->format('d/m/Y')) ?></small></h2>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><?php foreach ($config['columnas'] as $titulo): ?><th><?= e($titulo) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr><?php foreach (array_keys($config['columnas']) as $k): ?><td><?= e($f[$k] ?? '') ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if ($filas === []): ?><tr><td colspan="<?= count($config['columnas']) ?>">Sin datos para el rango seleccionado.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<p class="ayuda">La agenda diaria de cada médico está en <a href="agenda.php?fecha=<?= e($desde) ?>">Agenda</a>.</p>
<?= pie_pagina() ?>
