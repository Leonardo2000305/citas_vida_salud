<?php
/** Gestión de especialidades médicas (RF04). Solo Administrador. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN]);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $accion = texto_param('accion');

    if ($accion === 'alternar') {
        $pdo->prepare('UPDATE especialidad SET activo = NOT activo WHERE id_especialidad = :i')
            ->execute([':i' => entero_param('id', $_POST)]);
        mensaje_flash('exito', 'Estado de la especialidad actualizado.');
    } else {
        $id = entero_param('id', $_POST);
        try {
            guardar_especialidad($pdo, texto_param('nombre'), texto_param('descripcion'), $id);
            mensaje_flash('exito', $id === null ? 'Especialidad registrada.' : 'Especialidad actualizada.');
        } catch (ErrorCita $e) {
            mensaje_flash('error', $e->getMessage());
        }
    }
    redirigir('especialidades.php');
}

$filas = $pdo->query('SELECT * FROM especialidad ORDER BY nombre')->fetchAll();
$editar = null;
if (($idEditar = entero_param('editar')) !== null) {
    foreach ($filas as $f) {
        if ((int) $f['id_especialidad'] === $idEditar) {
            $editar = $f;
        }
    }
}

echo cabecera_pagina('Especialidades', $usuario, 'especialidades.php');
?>
<div class="tarjeta formulario-ancho">
    <h2><?= $editar ? 'Editar especialidad' : 'Nueva especialidad' ?></h2>
    <form method="post" novalidate data-validar>
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="guardar">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id_especialidad'] ?>"><?php endif; ?>
        <label>Nombre *
            <input name="nombre" required maxlength="100" value="<?= e($editar['nombre'] ?? '') ?>"></label>
        <label>Descripción (opcional)
            <input name="descripcion" maxlength="200" value="<?= e($editar['descripcion'] ?? '') ?>"></label>
        <div class="acciones">
            <button class="btn btn-principal" type="submit">Guardar</button>
            <?php if ($editar): ?><a class="btn" href="especialidades.php">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Nombre</th><th>Descripción</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td><?= e($f['nombre']) ?></td>
            <td><?= e($f['descripcion'] ?? '') ?></td>
            <td><span class="etiqueta <?= $f['activo'] ? 'verde' : 'gris' ?>"><?= $f['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
            <td class="acciones-fila">
                <a href="especialidades.php?editar=<?= (int) $f['id_especialidad'] ?>">Editar</a>
                <form method="post" class="en-linea">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="accion" value="alternar">
                    <input type="hidden" name="id" value="<?= (int) $f['id_especialidad'] ?>">
                    <button class="enlace" type="submit"><?= $f['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?= pie_pagina() ?>
