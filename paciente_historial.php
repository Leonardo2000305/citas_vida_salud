<?php
/** Historial de citas de un paciente, incluidas las canceladas y pasadas (RF12). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$id = entero_param('id');
$st = $pdo->prepare('SELECT * FROM paciente WHERE id_paciente = :i');
$st->execute([':i' => $id]);
$paciente = $st->fetch();
if ($paciente === false) {
    http_response_code(404);
    exit('Paciente no encontrado.');
}

$citas = $pdo->prepare(
    'SELECT c.codigo, c.inicio, c.estado, c.motivo, c.motivo_cancelacion, c.fecha_creacion,
            m.nombres || \' \' || m.apellidos AS medico, esp.nombre AS especialidad
       FROM cita c
       JOIN medico m ON m.id_medico = c.id_medico
       JOIN especialidad esp ON esp.id_especialidad = c.id_especialidad
      WHERE c.id_paciente = :p
      ORDER BY c.inicio DESC'
);
$citas->execute([':p' => $id]);
$filas = $citas->fetchAll();

echo cabecera_pagina('Historial de ' . $paciente['nombres'] . ' ' . $paciente['apellidos'], $usuario, 'pacientes.php');
?>
<p class="subtitulo">CI <?= e($paciente['ci']) ?> · Código <?= e($paciente['codigo']) ?> · Tel. <?= e($paciente['telefono']) ?></p>
<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Código</th><th>Fecha y hora</th><th>Médico</th><th>Especialidad</th><th>Estado</th><th>Motivo</th><th>Registrada</th></tr></thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td><?= e($f['codigo']) ?></td>
            <td><?= e((new DateTimeImmutable($f['inicio']))->format('d/m/Y H:i')) ?></td>
            <td><?= e($f['medico']) ?></td>
            <td><?= e($f['especialidad']) ?></td>
            <td><?= e($f['estado']) ?><?= $f['motivo_cancelacion'] ? '<br><small class="ayuda">' . e($f['motivo_cancelacion']) . '</small>' : '' ?></td>
            <td><?= e($f['motivo'] ?? '') ?></td>
            <td><?= e((new DateTimeImmutable($f['fecha_creacion']))->format('d/m/Y H:i')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if ($filas === []): ?><tr><td colspan="7">Este paciente aún no tiene citas.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<p><a href="pacientes.php">← Volver a pacientes</a></p>
<?= pie_pagina() ?>
