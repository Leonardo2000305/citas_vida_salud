<?php
/** Permite a cualquier usuario cambiar su propia contraseña. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $actual = (string) ($_POST['actual'] ?? '');
    $nueva  = (string) ($_POST['nueva'] ?? '');
    $repite = (string) ($_POST['repite'] ?? '');

    $st = db()->prepare('SELECT password_hash FROM usuario WHERE id_usuario = :u');
    $st->execute([':u' => $usuario['id']]);
    $hash = $st->fetchColumn();

    if (!password_verify($actual, (string) $hash)) {
        $error = 'La contraseña actual no es correcta.';
    } elseif ($error = validar_clave($nueva)) {
        // error asignado en la condición
    } elseif ($nueva !== $repite) {
        $error = 'La nueva contraseña y su repetición no coinciden.';
    } else {
        db()->prepare('UPDATE usuario SET password_hash = :h WHERE id_usuario = :u')
            ->execute([':h' => password_hash($nueva, PASSWORD_DEFAULT), ':u' => $usuario['id']]);
        mensaje_flash('exito', 'Contraseña actualizada correctamente.');
        redirigir('index.php');
    }
}

echo cabecera_pagina('Cambiar contraseña', $usuario, '');
?>
<div class="tarjeta formulario-ancho">
    <?php if ($error): ?><div class="aviso aviso-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" novalidate data-validar>
        <?= campo_csrf() ?>
        <label>Contraseña actual <input type="password" name="actual" required></label>
        <label>Nueva contraseña <input type="password" name="nueva" required minlength="8" data-min="8">
            <small class="ayuda">Mínimo 8 caracteres, con al menos una letra y un número.</small></label>
        <label>Repetir nueva contraseña <input type="password" name="repite" required></label>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Guardar</button>
            <a class="btn" href="index.php">Cancelar</a>
        </div>
    </form>
</div>
<?= pie_pagina() ?>
