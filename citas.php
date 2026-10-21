<?php
/** Listado de citas con filtros por fecha, médico, especialidad y estado (RF11, RF13). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$desde = texto_param('desde', $_GET) ?: date('Y-m-d');
$hasta = texto_param('hasta', $_GET) ?: $desde;
$medico = entero_param('medico');
$especialidad = entero_param('especialidad');
$estado = texto_param('estado', $_GET);

$where = ['c.inicio::date BETWEEN :desde AND :hasta'];
$params = [':desde' => $desde, ':hasta' => $hasta];
if ($medico !== null) {
    $where[] = 'c.id_medico = :medico';
    $params[':medico'] = $medico;
}
if ($especialidad !== null) {
    $where[] = 'c.id_especialidad = :esp';
    $params[':esp'] = $especialidad;
}
if (in_array($estado, ESTADOS_CITA, true)) {
    $where[] = 'c.estado = :estado';
    $params[':estado'] = $estado;
}

$st = $pdo->prepare(
    'SELECT c.id_cita, c.codigo, c.inicio, c.fin, c.estado, c.motivo, c.motivo_cancelacion,
            p.nombres || \' \' || p.apellidos AS paciente, p.telefono,
            m.nombres || \' \' || m.apellidos AS medico, esp.nombre AS especialidad
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN medico m ON m.id_medico = c.id_medico
       JOIN especialidad esp ON esp.id_especialidad = c.id_especialidad
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY c.inicio
      LIMIT 500'
);
$st->execute($params);
$citas = $st->fetchAll();

$medicos = $pdo->query('SELECT id_medico, nombres, apellidos FROM medico ORDER BY apellidos')->fetchAll();
$especialidades = $pdo->query('SELECT id_especialidad, nombre FROM especialidad ORDER BY nombre')->fetchAll();

$volver = 'citas.php?' . http_build_query(array_filter([
    'desde' => $desde, 'hasta' => $hasta, 'medico' => $medico, 'especialidad' => $especialidad, 'estado' => $estado,
], static fn ($v) => $v !== null && $v !== ''));

echo cabecera_pagina('Citas médicas', $usuario, 'citas.php');
?>
<form method="get" class="filtros tarjeta">
    <label>Desde <input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <label>Médico
        <select name="medico"><option value="">Todos</option>
            <?php foreach ($medicos as $m): ?>
                <option value="<?= (int) $m['id_medico'] ?>" <?= $medico === (int) $m['id_medico'] ? 'selected' : '' ?>><?= e($m['apellidos'] . ', ' . $m['nombres']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Especialidad
        <select name="especialidad"><option value="">Todas</option>
            <?php foreach ($especialidades as $esp): ?>
                <option value="<?= (int) $esp['id_especialidad'] ?>" <?= $especialidad === (int) $esp['id_especialidad'] ? 'selected' : '' ?>><?= e($esp['nombre']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>Estado
        <select name="estado"><option value="">Todos</option>
            <?php foreach (ESTADOS_CITA as $op): ?>
                <option <?= $estado === $op ? 'selected' : '' ?>><?= e($op) ?></option>
            <?php endforeach; ?>
        </select></label>
    <div class="acciones">
        <button class="btn" type="submit">Filtrar</button>
        <a class="btn btn-principal" href="cita_nueva.php">Programar cita</a>
    </div>
</form>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Código</th><th>Fecha y hora</th><th>Paciente</th><th>Médico</th><th>Especialidad</th><th>Estado</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($citas as $c):
        $ini = new DateTimeImmutable($c['inicio']);
        $yaEmpezo = $ini <= new DateTimeImmutable('now');
        $abiertaCita = in_array($c['estado'], [ESTADO_PROGRAMADA, ESTADO_CONFIRMADA], true);
    ?>
        <tr>
            <td><?= e($c['codigo']) ?></td>
            <td><?= e($ini->format('d/m/Y H:i')) ?> – <?= e((new DateTimeImmutable($c['fin']))->format('H:i')) ?></td>
            <td><?= e($c['paciente']) ?><br><small class="ayuda"><?= e($c['telefono']) ?></small></td>
            <td><?= e($c['medico']) ?></td>
            <td><?= e($c['especialidad']) ?></td>
            <td>
                <span class="etiqueta estado-<?= e(strtolower($c['estado'])) ?>"><?= e($c['estado']) ?></span>
                <?php if ($c['motivo_cancelacion']): ?><br><small class="ayuda"><?= e($c['motivo_cancelacion']) ?></small><?php endif; ?>
            </td>
            <td class="acciones-fila">
                <?php if ($abiertaCita): ?>
                    <?php if ($c['estado'] === ESTADO_PROGRAMADA): ?>
                        <?= formulario_estado($c['id_cita'], ESTADO_CONFIRMADA, 'Confirmar', $volver) ?>
                    <?php endif; ?>
                    <?php if ($yaEmpezo): ?>
                        <?= formulario_estado($c['id_cita'], ESTADO_INASISTENCIA, 'Inasistencia', $volver, '¿Registrar inasistencia?') ?>
                    <?php endif; ?>
                    <a href="cita_reprogramar.php?id=<?= (int) $c['id_cita'] ?>">Reprogramar</a>
                    <form method="post" action="cita_accion.php" class="en-linea" data-confirmar="¿Cancelar esta cita? El horario quedará libre.">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int) $c['id_cita'] ?>">
                        <input type="hidden" name="nuevo_estado" value="<?= e(ESTADO_CANCELADA) ?>">
                        <input type="hidden" name="volver" value="<?= e($volver) ?>">
                        <input type="text" name="motivo" placeholder="Motivo" maxlength="200" class="entrada-corta">
                        <button class="enlace peligro" type="submit">Cancelar</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($citas === []): ?><tr><td colspan="7">No hay citas para los filtros seleccionados.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pie_pagina() ?>
<?php
/** Botón que envía un cambio de estado a cita_accion.php. */
function formulario_estado(int $id, string $nuevo, string $texto, string $volver, ?string $confirmacion = null): string
{
    $dataConfirmar = $confirmacion ? ' data-confirmar="' . e($confirmacion) . '"' : '';
    return '<form method="post" action="cita_accion.php" class="en-linea"' . $dataConfirmar . '>'
        . campo_csrf()
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="nuevo_estado" value="' . e($nuevo) . '">'
        . '<input type="hidden" name="volver" value="' . e($volver) . '">'
        . '<button class="enlace" type="submit">' . e($texto) . '</button></form>';
}
