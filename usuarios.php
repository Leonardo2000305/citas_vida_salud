<?php
/** Administración de usuarios, roles y permisos básicos (RF01, 3.1). Solo Administrador. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN]);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = texto_param('accion');
    $id = entero_param('id', $_POST);

    if ($id === $usuario['id'] && in_array($accion, ['desactivar', 'quitar_admin'], true)) {
        mensaje_flash('error', 'No puede desactivarse ni quitarse el rol de administrador a sí mismo.');
    } elseif ($accion === 'alternar_activo') {
        $pdo->prepare('UPDATE usuario SET activo = NOT activo WHERE id_usuario = :i')->execute([':i' => $id]);
        mensaje_flash('exito', 'Estado del usuario actualizado.');
    } elseif ($accion === 'alternar_permiso') {
        $pdo->prepare('UPDATE usuario SET puede_marcar_atendida = NOT puede_marcar_atendida WHERE id_usuario = :i AND id_rol = 3')
            ->execute([':i' => $id]);
        mensaje_flash('exito', 'Permiso de atención actualizado.');
    } elseif ($accion === 'crear') {
        $nombreUsuario = texto_param('nombre_usuario');
        $correo = strtolower(texto_param('correo'));
        $nombres = texto_param('nombres');
        $apellidos = texto_param('apellidos');
        $rol = entero_param('rol', $_POST);
        $medico = entero_param('medico', $_POST);
        $clave = (string) ($_POST['clave'] ?? '');

        $errores = array_filter([
            preg_match('/^[a-z0-9._-]{3,40}$/i', $nombreUsuario) ? null : 'El nombre de usuario debe tener 3 a 40 caracteres (letras, números, punto, guion o guion bajo).',
            $correo === '' ? 'El correo es obligatorio.' : validar_correo($correo),
            validar_nombre($nombres, 'nombres'),
            validar_nombre($apellidos, 'apellidos'),
            validar_clave($clave),
            ($rol === 1 || $rol === 2 || $rol === 3) ? null : 'Seleccione un rol válido.',
            ($rol === 3 && $medico === null) ? 'Un usuario Médico debe estar vinculado a un médico.' : null,
        ]);

        if ($errores !== []) {
            mensaje_flash('error', implode(' ', $errores));
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO usuario (nombre_usuario, correo, nombres, apellidos, password_hash, id_rol, id_medico)
                     VALUES (:u, :c, :n, :a, :h, :r, :m)'
                )->execute([
                    ':u' => $nombreUsuario, ':c' => $correo, ':n' => $nombres, ':a' => $apellidos,
                    ':h' => password_hash($clave, PASSWORD_DEFAULT), ':r' => $rol,
                    ':m' => $rol === 3 ? $medico : null,
                ]);
                mensaje_flash('exito', 'Usuario creado correctamente.');
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                mensaje_flash('error', 'Ya existe un usuario con ese nombre de usuario, correo o médico asignado.');
            }
        }
    }
    redirigir('usuarios.php');
}

$usuarios = $pdo->query(
    'SELECT u.id_usuario, u.nombre_usuario, u.correo, u.nombres, u.apellidos, u.activo,
            u.puede_marcar_atendida, r.nombre AS rol
       FROM usuario u JOIN rol r ON r.id_rol = u.id_rol
      ORDER BY r.id_rol, u.apellidos, u.nombres'
)->fetchAll();
$medicos = $pdo->query(
    'SELECT m.id_medico, m.nombres, m.apellidos FROM medico m
      WHERE NOT EXISTS (SELECT 1 FROM usuario u WHERE u.id_medico = m.id_medico)
      ORDER BY m.apellidos, m.nombres'
)->fetchAll();

echo cabecera_pagina('Usuarios', $usuario, 'usuarios.php');
?>
<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Atender citas</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= e($u['nombre_usuario']) ?><br><small class="ayuda"><?= e($u['correo']) ?></small></td>
            <td><?= e($u['apellidos'] . ', ' . $u['nombres']) ?></td>
            <td><?= e($u['rol']) ?></td>
            <td>
                <?php if ($u['rol'] === ROL_MEDICO): ?>
                    <form method="post" class="en-linea">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="alternar_permiso">
                        <input type="hidden" name="id" value="<?= (int) $u['id_usuario'] ?>">
                        <button class="enlace" type="submit"><?= $u['puede_marcar_atendida'] ? 'Permiso: sí (quitar)' : 'Permiso: no (dar)' ?></button>
                    </form>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="etiqueta <?= $u['activo'] ? 'verde' : 'gris' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td>
                <?php if ((int) $u['id_usuario'] !== $usuario['id']): ?>
                <form method="post" class="en-linea" data-confirmar="¿Cambiar el estado de este usuario?">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="alternar_activo">
                    <input type="hidden" name="id" value="<?= (int) $u['id_usuario'] ?>">
                    <button class="enlace" type="submit"><?= $u['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div class="tarjeta formulario-ancho">
    <h2>Nuevo usuario</h2>
    <form method="post" novalidate data-validar>
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear">
        <div class="rejilla">
            <label>Nombre de usuario *<input name="nombre_usuario" required maxlength="40"></label>
            <label>Correo *<input type="email" name="correo" required maxlength="120"></label>
            <label>Nombres *<input name="nombres" required maxlength="80"></label>
            <label>Apellidos *<input name="apellidos" required maxlength="80"></label>
            <label>Rol *
                <select name="rol" required data-rol-usuario>
                    <option value="2">Recepcionista</option>
                    <option value="3">Médico</option>
                    <option value="1">Administrador</option>
                </select></label>
            <label>Médico vinculado (solo rol Médico)
                <select name="medico">
                    <option value="">—</option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id_medico'] ?>"><?= e($m['apellidos'] . ', ' . $m['nombres']) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label class="ancho-completo">Contraseña inicial *
                <input type="password" name="clave" required minlength="8">
                <small class="ayuda">Mínimo 8 caracteres, con al menos una letra y un número. Puede cambiarla después desde «Cambiar contraseña».</small></label>
        </div>
        <div class="acciones"><button class="btn btn-principal" type="submit">Crear usuario</button></div>
    </form>
</div>
<?= pie_pagina() ?>
