<?php
/** Programar una cita: paciente, especialidad, médico, fecha y hora disponible (RF06). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$pacientes = $pdo->query(
    "SELECT id_paciente, codigo, ci, nombres, apellidos FROM paciente WHERE activo ORDER BY apellidos, nombres"
)->fetchAll();
$especialidades = $pdo->query('SELECT id_especialidad, nombre FROM especialidad WHERE activo ORDER BY nombre')->fetchAll();
$medicos = $pdo->query(
    "SELECT m.id_medico, m.nombres, m.apellidos,
            COALESCE(string_agg(me.id_especialidad::text, ','), '') AS esp_ids
       FROM medico m
       LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
      WHERE m.activo
      GROUP BY m.id_medico ORDER BY m.apellidos, m.nombres"
)->fetchAll();

$datos = [
    'paciente' => entero_param('paciente', $_GET), 'especialidad' => entero_param('especialidad', $_GET),
    'medico' => entero_param('medico', $_GET), 'fecha' => texto_param('fecha', $_GET), 'hora' => '', 'motivo' => '',
];
$errores = [];
$horas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $datos = [
        'paciente'     => entero_param('paciente', $_POST),
        'especialidad' => entero_param('especialidad', $_POST),
        'medico'       => entero_param('medico', $_POST),
        'fecha'        => texto_param('fecha'),
        'hora'         => texto_param('hora'),
        'motivo'       => texto_param('motivo'),
    ];

    if ($datos['paciente'] === null)     $errores[] = 'Seleccione un paciente.';
    if ($datos['especialidad'] === null) $errores[] = 'Seleccione una especialidad.';
    if ($datos['medico'] === null)       $errores[] = 'Seleccione un médico.';
    if (fecha_valida($datos['fecha']) === null) $errores[] = 'Indique una fecha válida.';
    if (hora_valida($datos['hora']) === null)   $errores[] = 'Seleccione una hora disponible.';
    if (mb_strlen($datos['motivo']) > 200)      $errores[] = 'El motivo no puede superar los 200 caracteres.';

    if ($errores === []) {
        try {
            $idCita = crear_cita($pdo, $datos, $usuario['id']);
            mensaje_flash('exito', 'Cita programada correctamente. Código asignado: ' . $idCita);
            redirigir('citas.php?desde=' . urlencode($datos['fecha']) . '&hasta=' . urlencode($datos['fecha']));
        } catch (ErrorCita $e) {
            $errores[] = $e->getMessage();
        }
    }
}

// Horarios libres del médico y la fecha elegidos (se calculan en el servidor)
if ($datos['medico'] !== null && fecha_valida($datos['fecha'] ?? '') !== null) {
    try {
        foreach (horarios_libres($pdo, $datos['medico'], $datos['fecha']) as $s) {
            $horas[] = $s['inicio']->format('H:i');
        }
    } catch (ErrorCita $e) {
        $errores[] = $e->getMessage();
    }
}

echo cabecera_pagina('Programar cita', $usuario, 'citas.php');
?>
<div class="tarjeta formulario-ancho">
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert"><ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="cita_nueva.php" novalidate data-validar data-horarios="api/disponibilidad.php">
        <?= campo_csrf() ?>
        <div class="rejilla">
            <label class="ancho-completo">Paciente *
                <select name="paciente" required>
                    <option value="">Seleccione un paciente</option>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= (int) $p['id_paciente'] ?>" <?= $datos['paciente'] === (int) $p['id_paciente'] ? 'selected' : '' ?>>
                            <?= e($p['apellidos'] . ', ' . $p['nombres'] . ' · CI ' . $p['ci']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Especialidad *
                <select name="especialidad" required data-filtra-medicos>
                    <option value="">Seleccione</option>
                    <?php foreach ($especialidades as $esp): ?>
                        <option value="<?= (int) $esp['id_especialidad'] ?>" <?= $datos['especialidad'] === (int) $esp['id_especialidad'] ? 'selected' : '' ?>><?= e($esp['nombre']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Médico *
                <select name="medico" required data-medicos>
                    <option value="">Seleccione</option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id_medico'] ?>" data-especialidades="<?= e($m['esp_ids']) ?>"
                            <?= $datos['medico'] === (int) $m['id_medico'] ? 'selected' : '' ?>>
                            Dr(a). <?= e($m['nombres'] . ' ' . $m['apellidos']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Fecha *
                <input type="date" name="fecha" required min="<?= e(date('Y-m-d')) ?>" value="<?= e((string) $datos['fecha']) ?>" data-fecha></label>
            <label>Hora disponible *
                <select name="hora" required data-hora>
                    <option value="">Seleccione médico y fecha</option>
                    <?php foreach ($horas as $h): ?>
                        <option value="<?= e($h) ?>" <?= $datos['hora'] === $h ? 'selected' : '' ?>><?= e($h) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($datos['medico'] !== null && $datos['fecha'] && $horas === []): ?>
                    <small class="ayuda">No hay horarios libres para ese médico y fecha.</small>
                <?php endif; ?>
            </label>
            <label class="ancho-completo">Motivo breve (opcional)
                <input name="motivo" maxlength="200" value="<?= e($datos['motivo']) ?>"></label>
        </div>
        <p class="ayuda">Solo aparecen horarios libres. El sistema vuelve a comprobar la disponibilidad al guardar.</p>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Programar cita</button>
            <button class="btn" type="submit" name="ver" value="1" formaction="cita_nueva.php" formmethod="get">Ver horarios</button>
            <a class="btn" href="citas.php">Cancelar</a>
        </div>
    </form>
</div>
<?= pie_pagina() ?>
