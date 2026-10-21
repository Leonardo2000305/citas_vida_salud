<?php
/**
 * Agenda diaria de un médico (RF11). El médico solo ve su propia agenda.
 * Recepción y administración pueden elegir cualquier médico.
 */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION, ROL_MEDICO]);
$pdo = db();

$fecha = texto_param('fecha', $_GET) ?: date('Y-m-d');
if (fecha_valida($fecha) === null) {
    $fecha = date('Y-m-d');
}

if ($usuario['rol'] === ROL_MEDICO) {
    $idMedico = $usuario['id_medico'];
} else {
    $idMedico = entero_param('medico') ?? ($pdo->query('SELECT MIN(id_medico) FROM medico')->fetchColumn() ?: null);
}
if ($idMedico === null) {
    echo cabecera_pagina('Agenda', $usuario, 'agenda.php');
    echo '<p>No hay médicos registrados.</p>' . pie_pagina();
    exit;
}

$medicos = $pdo->query("SELECT id_medico, nombres, apellidos FROM medico ORDER BY apellidos, nombres")->fetchAll();
$st = $pdo->prepare('SELECT nombres, apellidos, especialidades FROM (
        SELECT m.nombres, m.apellidos, string_agg(e.nombre, \', \') AS especialidades
          FROM medico m
          LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
          LEFT JOIN especialidad e ON e.id_especialidad = me.id_especialidad
         WHERE m.id_medico = :m GROUP BY m.id_medico) t');
$st->execute([':m' => $idMedico]);
$med = $st->fetch();

$citas = $pdo->prepare(
    'SELECT c.id_cita, c.codigo, c.inicio, c.fin, c.estado, c.motivo,
            p.nombres || \' \' || p.apellidos AS paciente, p.ci, esp.nombre AS especialidad
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN especialidad esp ON esp.id_especialidad = c.id_especialidad
      WHERE c.id_medico = :m AND c.inicio::date = :f
      ORDER BY c.inicio'
);
$citas->execute([':m' => $idMedico, ':f' => $fecha]);
$filas = $citas->fetchAll();

$libres = count(horarios_libres($pdo, (int) $idMedico, $fecha));
$volver = 'agenda.php?' . http_build_query(['fecha' => $fecha, 'medico' => $idMedico]);
$puedeAtender = $usuario['rol'] === ROL_MEDICO && $usuario['puede_atendida'];

echo cabecera_pagina('Agenda', $usuario, 'agenda.php');
?>
<form method="get" class="filtros tarjeta">
    <?php if ($usuario['rol'] !== ROL_MEDICO): ?>
        <label>Médico
            <select name="medico">
                <?php foreach ($medicos as $m): ?>
                    <option value="<?= (int) $m['id_medico'] ?>" <?= (int) $idMedico === (int) $m['id_medico'] ? 'selected' : '' ?>>
                        <?= e($m['apellidos'] . ', ' . $m['nombres']) ?></option>
                <?php endforeach; ?>
            </select></label>
    <?php endif; ?>
    <label>Fecha <input type="date" name="fecha" value="<?= e($fecha) ?>"></label>
    <div class="acciones"><button class="btn" type="submit">Ver agenda</button></div>
</form>

<?php if ($med): ?>
<p class="subtitulo">Dr(a). <?= e($med['nombres'] . ' ' . $med['apellidos']) ?>
   · <?= e($med['especialidades'] ?? 'Sin especialidad') ?>
   · <?= e((new DateTimeImmutable($fecha))->format('d/m/Y')) ?>
   · <?= count($filas) ?> cita(s), <?= $libres ?> horario(s) libre(s)</p>
<?php endif; ?>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Hora</th><th>Código</th><th>Paciente</th><th>Especialidad</th><th>Motivo</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($filas as $c):
        $ini = new DateTimeImmutable($c['inicio']);
        $abierta = in_array($c['estado'], [ESTADO_PROGRAMADA, ESTADO_CONFIRMADA], true);
    ?>
        <tr>
            <td><?= e($ini->format('H:i')) ?>–<?= e((new DateTimeImmutable($c['fin']))->format('H:i')) ?></td>
            <td><?= e($c['codigo']) ?></td>
            <td><?= e($c['paciente']) ?><br><small class="ayuda">CI <?= e($c['ci']) ?></small></td>
            <td><?= e($c['especialidad']) ?></td>
            <td><?= $usuario['rol'] === ROL_MEDICO && !$puedeAtender ? '—' : e($c['motivo'] ?? '') ?></td>
            <td><span class="etiqueta estado-<?= e(strtolower($c['estado'])) ?>"><?= e($c['estado']) ?></span></td>
            <td class="acciones-fila">
                <?php if ($abierta && $puedeAtender && $ini <= new DateTimeImmutable('now')): ?>
                    <form method="post" action="cita_accion.php" class="en-linea" data-confirmar="¿Marcar la cita como atendida?">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int) $c['id_cita'] ?>">
                        <input type="hidden" name="nuevo_estado" value="<?= e(ESTADO_ATENDIDA) ?>">
                        <input type="hidden" name="volver" value="<?= e($volver) ?>">
                        <button class="enlace" type="submit">Marcar atendida</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($filas === []): ?><tr><td colspan="7">No hay citas programadas para este día.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pie_pagina() ?>
