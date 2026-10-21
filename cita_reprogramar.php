<?php
/** Reprogramar una cita a una nueva fecha y hora libres (RF08). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$id = entero_param('id') ?? entero_param('id', $_POST);
$st = $pdo->prepare(
    'SELECT c.*, p.nombres || \' \' || p.apellidos AS paciente, m.nombres || \' \' || m.apellidos AS medico
       FROM cita c
       JOIN paciente p ON p.id_paciente = c.id_paciente
       JOIN medico m ON m.id_medico = c.id_medico
      WHERE c.id_cita = :i'
);
$st->execute([':i' => $id]);
$cita = $st->fetch();
if ($cita === false) {
    http_response_code(404);
    exit('Cita no encontrada.');
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    try {
        reprogramar_cita($pdo, $id, texto_param('fecha'), texto_param('hora'));
        mensaje_flash('exito', 'La cita fue reprogramada. Vuelva a confirmarla con el paciente.');
        redirigir('citas.php?desde=' . urlencode(texto_param('fecha')) . '&hasta=' . urlencode(texto_param('fecha')));
    } catch (ErrorCita $e) {
        $errores[] = $e->getMessage();
    }
}

$fecha = texto_param('fecha', $_GET) ?: substr($cita['inicio'], 0, 10);
$horas = [];
if (fecha_valida($fecha) !== null) {
    foreach (horarios_libres($pdo, (int) $cita['id_medico'], $fecha, $id) as $s) {
        $horas[] = $s['inicio']->format('H:i');
    }
}

echo cabecera_pagina('Reprogramar cita ' . $cita['codigo'], $usuario, 'citas.php');
?>
<div class="tarjeta formulario-ancho">
    <p><strong>Paciente:</strong> <?= e($cita['paciente']) ?><br>
       <strong>Médico:</strong> <?= e($cita['medico']) ?><br>
       <strong>Horario actual:</strong> <?= e((new DateTimeImmutable($cita['inicio']))->format('d/m/Y H:i')) ?></p>

    <?php if ($errores): ?><div class="aviso aviso-error" role="alert"><?= e($errores[0]) ?></div><?php endif; ?>

    <form method="get" class="rejilla">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label>Nueva fecha
            <input type="date" name="fecha" required min="<?= e(date('Y-m-d')) ?>" value="<?= e($fecha) ?>"></label>
        <div class="acciones"><button class="btn" type="submit">Ver horarios libres</button></div>
    </form>

    <form method="post" class="rejilla" novalidate data-validar>
        <?= campo_csrf() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
        <label>Hora disponible *
            <select name="hora" required>
                <option value="">Seleccione una hora</option>
                <?php foreach ($horas as $h): ?><option value="<?= e($h) ?>"><?= e($h) ?></option><?php endforeach; ?>
            </select></label>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Reprogramar</button>
            <a class="btn" href="citas.php">Volver</a>
        </div>
    </form>
</div>
<?= pie_pagina() ?>
