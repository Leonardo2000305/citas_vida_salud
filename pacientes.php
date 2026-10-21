<?php
/** Listado y búsqueda de pacientes (RF02). Activar/desactivar, nunca eliminar (RN11). */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();
    $id = entero_param('id', $_POST);
    if ($id !== null) {
        $pdo->prepare('UPDATE paciente SET activo = NOT activo WHERE id_paciente = :i')->execute([':i' => $id]);
        mensaje_flash('exito', 'Estado del paciente actualizado. Su historial se conserva.');
    }
    redirigir('pacientes.php' . (isset($_GET['q']) ? '?q=' . urlencode((string) $_GET['q']) : ''));
}

$q = texto_param('q', $_GET);
$sql = 'SELECT id_paciente, codigo, ci, nombres, apellidos, telefono, activo FROM paciente';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE ci ILIKE :q OR nombres ILIKE :q OR apellidos ILIKE :q
              OR (nombres || \' \' || apellidos) ILIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY apellidos, nombres LIMIT 300';
$st = $pdo->prepare($sql);
$st->execute($params);
$pacientes = $st->fetchAll();

echo cabecera_pagina('Pacientes', $usuario, 'pacientes.php');
?>
<div class="barra-acciones">
    <form method="get" class="buscador">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por CI, nombre o apellido">
        <button class="btn" type="submit">Buscar</button>
    </form>
    <a class="btn btn-principal" href="paciente_form.php">Nuevo paciente</a>
</div>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Código</th><th>CI</th><th>Paciente</th><th>Teléfono</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pacientes as $p): ?>
        <tr>
            <td><?= e($p['codigo']) ?></td>
            <td><?= e($p['ci']) ?></td>
            <td><?= e($p['apellidos'] . ', ' . $p['nombres']) ?></td>
            <td><?= e($p['telefono']) ?></td>
            <td><span class="etiqueta <?= $p['activo'] ? 'verde' : 'gris' ?>"><?= $p['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td class="acciones-fila">
                <a href="paciente_form.php?id=<?= (int) $p['id_paciente'] ?>">Editar</a>
                <a href="paciente_historial.php?id=<?= (int) $p['id_paciente'] ?>">Historial</a>
                <form method="post" class="en-linea" data-confirmar="<?= e($p['activo'] ? '¿Desactivar este paciente? Su historial se conserva.' : '¿Activar este paciente?') ?>">
                    <?= campo_csrf() ?>
                    <input type="hidden" name="id" value="<?= (int) $p['id_paciente'] ?>">
                    <button class="enlace" type="submit"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($pacientes === []): ?>
        <tr><td colspan="6">No se encontraron pacientes.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?= pie_pagina() ?>
