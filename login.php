<?php
/** Inicio de sesión con nombre de usuario o correo y contraseña. */
require_once __DIR__ . '/includes/bootstrap.php';

if (usuario_actual() !== null) {
    redirigir('index.php');
}

$error = '';
$identificador = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $identificador = texto_param('usuario');
    $clave = (string) ($_POST['clave'] ?? '');

    if ($identificador === '' || $clave === '') {
        $error = 'Ingrese su usuario o correo y su contraseña.';
    } else {
        $st = db()->prepare(
            'SELECT u.id_usuario, u.nombres, u.apellidos, u.password_hash, u.puede_marcar_atendida,
                    u.id_medico, r.nombre AS rol
               FROM usuario u
               JOIN rol r ON r.id_rol = u.id_rol
              WHERE u.activo = TRUE
                AND (u.nombre_usuario = :x OR lower(u.correo) = lower(:x))'
        );
        $st->execute([':x' => $identificador]);
        $u = $st->fetch();

        if ($u !== false && password_verify($clave, $u['password_hash'])) {
            session_regenerate_id(true);   // evita la fijación de sesión
            $_SESSION['usuario'] = [
                'id'             => (int) $u['id_usuario'],
                'nombre'         => $u['nombres'] . ' ' . $u['apellidos'],
                'rol'            => $u['rol'],
                'id_medico'      => $u['id_medico'] !== null ? (int) $u['id_medico'] : null,
                'puede_atendida' => (bool) $u['puede_marcar_atendida'],
            ];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirigir('index.php');
        }
        $error = 'Usuario o contraseña incorrectos.';   // mensaje genérico a propósito
    }
}

echo cabecera_pagina('Iniciar sesión', null);
?>
<div class="acceso">
    <div class="tarjeta">
        <p class="subtitulo">Sistema de gestión de citas médicas</p>
        <?php if ($error !== ''): ?>
            <div class="aviso aviso-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="login.php" novalidate data-validar>
            <?= campo_csrf() ?>
            <label>Usuario o correo
                <input type="text" name="usuario" value="<?= e($identificador) ?>" required autocomplete="username">
            </label>
            <label>Contraseña
                <input type="password" name="clave" required autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-principal">Ingresar</button>
        </form>
    </div>
</div>
<?= pie_pagina() ?>
