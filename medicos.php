<?php
/** Listado de médicos (RF03). Administrador gestiona; Recepcionista solo consulta. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();
$esAdmin = es_rol($usuario, ROL_ADMIN);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdmin) {
    verificar_csrf();
    $id = entero_param('id', $_POST);
    if ($id !== null) {
        $pdo->prepare('UPDATE medico SET activo = NOT activo WHERE id_medico = :i')->execute([':i' => $id]);
        mensaje_flash('exito', 'Estado del médico actualizado. Sus citas históricas se conservan.');
    }
    redirigir('medicos.php');
}

$q = texto_param('q', $_GET);
$sql = "SELECT m.id_medico, m.codigo, m.registro_profesional, m.nombres, m.apellidos, m.telefono, m.activo,
               string_agg(e.nombre, ', ' ORDER BY e.nombre) AS especialidades
          FROM medico m
          LEFT JOIN medico_especialidad me ON me.id_medico = m.id_medico
          LEFT JOIN especialidad e ON e.id_especialidad = me.id_especialidad";
$params = [];
if ($q !== '') {
    $sql .= " WHERE m.nombres ILIKE :q OR m.apellidos ILIKE :q OR m.registro_profesional ILIKE :q
              OR e.nombre ILIKE :q";
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' GROUP BY m.id_medico ORDER BY m.apellidos, m.nombres';
$st = $pdo->prepare($sql);
$st->execute($params);
$medicos = $st->fetchAll();

echo cabecera_pagina('Médicos', $usuario, 'medicos.php');
?>
<div class="barra-acciones">
    <form method="get" class="buscador">
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, registro o especialidad">
        <button class="btn" type="submit">Buscar</button>
    </form>
    <?php if ($esAdmin): ?><a class="btn btn-principal" href="medico_form.php">Nuevo médico</a><?php endif; ?>
</div>

<div class="tabla-responsive">
<table class="tabla">
    <thead><tr><th>Código</th><th>Registro</th><th>Médico</th><th>Especialidades</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($medicos as $m): ?>
        <tr>
            <td><?= e($m['codigo']) ?></td>
            <td><?= e($m['registro_profesional']) ?></td>
            <td><?= e($m['apellidos'] . ', ' . $m['nombres']) ?></td>
            <td><?= e($m['especialidades'] ?? '—') ?></td>
            <td><span class="etiqueta <?= $m['activo'] ? 'verde' : 'gris' ?>"><?= $m['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td class="acciones-fila">
                <a href="agenda.php?medico=<?= (int) $m['id_medico'] ?>&fecha=<?= e(date('Y-m-d')) ?>">Agenda</a>
                <?php if ($esAdmin): ?>
                    <a href="medico_form.php?id=<?= (int) $m['id_medico'] ?>">Editar</a>
                    <a href="medico_horarios.php?id=<?= (int) $m['id_medico'] ?>">Horarios</a>
                    <form method="post" class="en-linea" data-confirmar="<?= e($m['activo'] ? '¿Desactivar al médico? No recibirá citas nuevas.' : '¿Activar al médico?') ?>">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="id" value="<?= (int) $m['id_medico'] ?>">
                        <button class="enlace" type="submit"><?= $m['activo'] ? 'Desactivar' : 'Activar' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($medicos === []): ?><tr><td colspan="6">No se encontraron médicos.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?= pie_pagina() ?>
