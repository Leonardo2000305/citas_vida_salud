<?php
/** Registro y modificación de pacientes (RF02). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$id = entero_param('id');
$paciente = [
    'ci' => '', 'nombres' => '', 'apellidos' => '', 'fecha_nacimiento' => '', 'sexo' => '',
    'telefono' => '', 'correo' => '', 'direccion' => '',
];
if ($id !== null) {
    $st = $pdo->prepare('SELECT * FROM paciente WHERE id_paciente = :i');
    $st->execute([':i' => $id]);
    $encontrado = $st->fetch();
    if ($encontrado === false) {
        http_response_code(404);
        exit('Paciente no encontrado.');
    }
    $paciente = $encontrado;
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $paciente = [
        'ci'               => strtoupper(texto_param('ci')),
        'nombres'          => texto_param('nombres'),
        'apellidos'        => texto_param('apellidos'),
        'fecha_nacimiento' => texto_param('fecha_nacimiento'),
        'sexo'             => texto_param('sexo'),
        'telefono'         => texto_param('telefono'),
        'correo'           => texto_param('correo'),
        'direccion'        => texto_param('direccion'),
    ];

    $errores = array_filter([
        validar_ci($paciente['ci']),
        validar_nombre($paciente['nombres'], 'nombres'),
        validar_nombre($paciente['apellidos'], 'apellidos'),
        validar_fecha_nacimiento($paciente['fecha_nacimiento']),
        validar_telefono($paciente['telefono']),
        validar_correo($paciente['correo']),
        $paciente['sexo'] !== '' && !in_array($paciente['sexo'], ['Masculino', 'Femenino', 'Otro'], true)
            ? 'El sexo seleccionado no es válido.' : null,
    ]);

    if ($errores === []) {
        try {
            $datos = [
                ':ci' => $paciente['ci'], ':n' => $paciente['nombres'], ':a' => $paciente['apellidos'],
                ':f'  => $paciente['fecha_nacimiento'], ':s' => $paciente['sexo'] !== '' ? $paciente['sexo'] : null,
                ':t'  => $paciente['telefono'], ':c' => $paciente['correo'] !== '' ? $paciente['correo'] : null,
                ':d'  => $paciente['direccion'] !== '' ? $paciente['direccion'] : null,
            ];
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion)
                     VALUES (:ci, :n, :a, :f, :s, :t, :c, :d)'
                )->execute($datos);
                mensaje_flash('exito', 'Paciente registrado correctamente.');
            } else {
                $datos[':id'] = $id;
                $pdo->prepare(
                    'UPDATE paciente SET ci = :ci, nombres = :n, apellidos = :a, fecha_nacimiento = :f,
                            sexo = :s, telefono = :t, correo = :c, direccion = :d
                      WHERE id_paciente = :id'
                )->execute($datos);
                mensaje_flash('exito', 'Datos del paciente actualizados.');
            }
            redirigir('pacientes.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {   // violación de unicidad
                $errores[] = 'Ya existe un paciente registrado con ese CI.';
            } else {
                throw $e;
            }
        }
    }
}

echo cabecera_pagina($id === null ? 'Registrar paciente' : 'Editar paciente', $usuario, 'pacientes.php');
?>
<div class="tarjeta formulario-ancho">
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert"><ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" novalidate data-validar>
        <?= campo_csrf() ?>
        <div class="rejilla">
            <label>CI (documento de identidad) *
                <input name="ci" required maxlength="15" value="<?= e($paciente['ci']) ?>"></label>
            <label>Sexo
                <select name="sexo">
                    <option value="">Seleccione</option>
                    <?php foreach (['Masculino', 'Femenino', 'Otro'] as $op): ?>
                        <option <?= $paciente['sexo'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label>Nombres *
                <input name="nombres" required maxlength="80" value="<?= e($paciente['nombres']) ?>"></label>
            <label>Apellidos *
                <input name="apellidos" required maxlength="80" value="<?= e($paciente['apellidos']) ?>"></label>
            <label>Fecha de nacimiento *
                <input type="date" name="fecha_nacimiento" required value="<?= e($paciente['fecha_nacimiento']) ?>"></label>
            <label>Teléfono *
                <input name="telefono" required maxlength="15" value="<?= e($paciente['telefono']) ?>"></label>
            <label>Correo electrónico (opcional)
                <input type="email" name="correo" maxlength="120" value="<?= e($paciente['correo']) ?>"></label>
            <label class="ancho-completo">Dirección (opcional)
                <input name="direccion" maxlength="200" value="<?= e($paciente['direccion']) ?>"></label>
        </div>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Guardar</button>
            <a class="btn" href="pacientes.php">Cancelar</a>
        </div>
    </form>
</div>
<?= pie_pagina() ?>
