<?php
/** Horarios de atención de un médico (RF05). Solo Administrador. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN]);
$pdo = db();

$id = entero_param('id');
$st = $pdo->prepare('SELECT * FROM medico WHERE id_medico = :i');
$st->execute([':i' => $id]);
$medico = $st->fetch();
if ($medico === false) {
    http_response_code(404);
    exit('Médico no encontrado.');
}

$nombres_dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = texto_param('accion');

    if ($accion === 'eliminar') {
        $pdo->prepare('DELETE FROM horario_medico WHERE id_horario = :h AND id_medico = :m')
            ->execute([':h' => entero_param('id_horario', $_POST), ':m' => $id]);
        mensaje_flash('exito', 'Horario eliminado. Las citas ya registradas no se modifican.');
        redirigir("medico_horarios.php?id={$id}");
    }

    $dia = (int) texto_param('dia_semana');
    $inicio = hora_valida(texto_param('hora_inicio'));
    $fin = hora_valida(texto_param('hora_fin'));
    $duracion = (int) texto_param('duracion_cita');

    if ($dia < 1 || $dia > 7) {
        mensaje_flash('error', 'Seleccione un día válido.');
    } elseif ($inicio === null || $fin === null) {
        mensaje_flash('error', 'Las horas deben tener formato HH:MM.');
    } elseif ($fin <= $inicio) {
        mensaje_flash('error', 'La hora final debe ser posterior a la hora inicial.');   // RF05
    } elseif (!in_array($duracion, [15, 20, 30, 45, 60], true)) {
        mensaje_flash('error', 'La duración de la cita no es válida.');
    } else {
        $solape = $pdo->prepare(
            'SELECT 1 FROM horario_medico
              WHERE id_medico = :m AND dia_semana = :d
                AND hora_inicio < :fin AND :ini < hora_fin'
        );
        $solape->execute([':m' => $id, ':d' => $dia, ':ini' => $inicio, ':fin' => $fin]);
        if ($solape->fetch()) {
            mensaje_flash('error', 'Este horario se cruza con otro horario ya registrado para ese día.');
        } else {
            $pdo->prepare(
                'INSERT INTO horario_medico (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita)
                 VALUES (:m, :d, :ini, :fin, :dur)'
            )->execute([':m' => $id, ':d' => $dia, ':ini' => $inicio, ':fin' => $fin, ':dur' => $duracion]);
            mensaje_flash('exito', 'Horario agregado.');
        }
    }
    redirigir("medico_horarios.php?id={$id}");
}

$lista = $pdo->prepare('SELECT * FROM horario_medico WHERE id_medico = :m ORDER BY dia_semana, hora_inicio');
$lista->execute([':m' => $id]);
$horarios = $lista->fetchAll();

echo cabecera_pagina('Horarios de ' . $medico['nombres'] . ' ' . $medico['apellidos'], $usuario, 'medicos.php');
?>
<div class="tarjeta">
    <h2>Agregar horario</h2>
    <form method="post" novalidate data-validar class="rejilla">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="agregar">
        <label>Día
            <select name="dia_semana" required>
                <?php foreach ($nombres_dias as $n => $nombre): ?><option value="<?= $n ?>"><?= e($nombre) ?></option><?php endforeach; ?>
            </select></label>
        <label>Hora inicial <input type="time" name="hora_inicio" required value="08:00"></label>
        <label>Hora final <input type="time" name="hora_fin" required value="12:00"></label>
        <label>Duración de cada cita
            <select name="duracion_cita">
                <?php foreach ([15, 20, 30, 45, 60] as $min): ?><option value="<?= $min ?>" <?= $min === 30 ? 'selected' : '' ?>><?= $min ?> min</option><?php endforeach; ?>
            </select></label>
        <div class="acciones"><button class="btn btn-principal" type="submit">Agregar horario</button></div>
    </form>
</div>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Día</th><th>Desde</th><th>Hasta</th><th>Duración</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($horarios as $h): ?>
        <tr>
            <td><?= e($nombres_dias[(int) $h['dia_semana']]) ?></td>
            <td><?= e(substr($h['hora_inicio'], 0, 5)) ?></td>
            <td><?= e(substr($h['hora_fin'], 0, 5)) ?></td>
            <td><?= (int) $h['duracion_cita'] ?> min</td>
            <td>
                <form method="post" class="en-linea" data-confirmar="¿Eliminar este horario?">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="eliminar">
                    <input type="hidden" name="id_horario" value="<?= (int) $h['id_horario'] ?>">
                    <button class="enlace" type="submit">Eliminar</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($horarios === []): ?><tr><td colspan="5">Este médico aún no tiene horarios de atención.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<p><a href="medicos.php">← Volver a médicos</a></p>
<?= pie_pagina() ?>
