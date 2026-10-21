<?php
/** Registro y modificación de médicos y sus especialidades (RF03). Solo Administrador. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN]);
$pdo = db();

$id = entero_param('id');
$medico = ['registro_profesional' => '', 'nombres' => '', 'apellidos' => '', 'telefono' => '', 'correo' => ''];
$seleccionadas = [];
if ($id !== null) {
    $st = $pdo->prepare('SELECT * FROM medico WHERE id_medico = :i');
    $st->execute([':i' => $id]);
    $encontrado = $st->fetch();
    if ($encontrado === false) {
        http_response_code(404);
        exit('Médico no encontrado.');
    }
    $medico = $encontrado;
    $sel = $pdo->prepare('SELECT id_especialidad FROM medico_especialidad WHERE id_medico = :i');
    $sel->execute([':i' => $id]);
    $seleccionadas = array_map('intval', array_column($sel->fetchAll(), 'id_especialidad'));
}

$especialidades = $pdo->query('SELECT id_especialidad, nombre FROM especialidad WHERE activo ORDER BY nombre')->fetchAll();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $medico = [
        'registro_profesional' => texto_param('registro_profesional'),
        'nombres'   => texto_param('nombres'),
        'apellidos' => texto_param('apellidos'),
        'telefono'  => texto_param('telefono'),
        'correo'    => texto_param('correo'),
    ];
    $seleccionadas = array_map('intval', (array) ($_POST['especialidades'] ?? []));

    $errores = array_filter([
        validar_requerido($medico['registro_profesional'], 'registro profesional'),
        validar_nombre($medico['nombres'], 'nombres'),
        validar_nombre($medico['apellidos'], 'apellidos'),
        validar_telefono($medico['telefono'], false),
        validar_correo($medico['correo']),
        $seleccionadas === [] ? 'Seleccione al menos una especialidad.' : null,
    ]);

    if ($errores === []) {
        $pdo->beginTransaction();
        try {
            $datos = [
                ':r' => $medico['registro_profesional'], ':n' => $medico['nombres'],
                ':a' => $medico['apellidos'], ':t' => $medico['telefono'] ?: null,
                ':c' => $medico['correo'] ?: null,
            ];
            if ($id === null) {
                $st = $pdo->prepare(
                    'INSERT INTO medico (registro_profesional, nombres, apellidos, telefono, correo)
                     VALUES (:r, :n, :a, :t, :c) RETURNING id_medico'
                );
                $st->execute($datos);
                $idMedico = (int) $st->fetchColumn();
            } else {
                $datos[':i'] = $id;
                $pdo->prepare(
                    'UPDATE medico SET registro_profesional = :r, nombres = :n, apellidos = :a,
                            telefono = :t, correo = :c WHERE id_medico = :i'
                )->execute($datos);
                $idMedico = $id;
            }

            $pdo->prepare('DELETE FROM medico_especialidad WHERE id_medico = :m')->execute([':m' => $idMedico]);
            $ins = $pdo->prepare('INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (:m, :e)');
            foreach (array_unique($seleccionadas) as $idEsp) {
                $ins->execute([':m' => $idMedico, ':e' => $idEsp]);
            }
            $pdo->commit();
            mensaje_flash('exito', $id === null ? 'Médico registrado correctamente.' : 'Datos del médico actualizados.');
            redirigir('medicos.php');
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23505') {
                $errores[] = 'Ya existe un médico con ese número de registro profesional.';
            } else {
                throw $e;
            }
        }
    }
}

echo cabecera_pagina($id === null ? 'Registrar médico' : 'Editar médico', $usuario, 'medicos.php');
?>
<div class="tarjeta formulario-ancho">
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert"><ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" novalidate data-validar>
        <?= campo_csrf() ?>
        <div class="rejilla">
            <label>Número de registro profesional *
                <input name="registro_profesional" required maxlength="30" value="<?= e($medico['registro_profesional']) ?>"></label>
            <span></span>
            <label>Nombres *
                <input name="nombres" required maxlength="80" value="<?= e($medico['nombres']) ?>"></label>
            <label>Apellidos *
                <input name="apellidos" required maxlength="80" value="<?= e($medico['apellidos']) ?>"></label>
            <label>Teléfono
                <input name="telefono" maxlength="20" value="<?= e($medico['telefono'] ?? '') ?>"></label>
            <label>Correo electrónico
                <input type="email" name="correo" maxlength="120" value="<?= e($medico['correo'] ?? '') ?>"></label>
        </div>
        <fieldset class="ancho-completo">
            <legend>Especialidades *</legend>
            <div class="casillas">
            <?php foreach ($especialidades as $esp): ?>
                <label class="casilla"><input type="checkbox" name="especialidades[]" value="<?= (int) $esp['id_especialidad'] ?>"
                    <?= in_array((int) $esp['id_especialidad'], $seleccionadas, true) ? 'checked' : '' ?>> <?= e($esp['nombre']) ?></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Guardar</button>
            <a class="btn" href="medicos.php">Cancelar</a>
        </div>
    </form>
</div>
<?= pie_pagina() ?>
