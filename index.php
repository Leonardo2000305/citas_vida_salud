<?php
/** Panel de inicio con resumen del día, según el rol. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login();
$pdo = db();

$hoy = date('Y-m-d');
$resumen = $pdo->prepare(
    "SELECT
        COUNT(*) FILTER (WHERE estado <> 'Cancelada') AS activas,
        COUNT(*) FILTER (WHERE estado = 'Atendida')   AS atendidas,
        COUNT(*) FILTER (WHERE estado = 'Cancelada')  AS canceladas
       FROM cita WHERE inicio::date = :hoy"
);
$resumen->execute([':hoy' => $hoy]);
$r = $resumen->fetch();

echo cabecera_pagina('Inicio', $usuario, 'index.php');
?>
<p class="subtitulo">Bienvenido, <?= e($usuario['nombre']) ?>. Hoy es <?= e((new DateTimeImmutable('today'))->format('d/m/Y')) ?>.</p>

<div class="tarjetas-resumen">
    <div class="tarjeta"><span>Citas de hoy</span><strong><?= (int) $r['activas'] ?></strong></div>
    <div class="tarjeta"><span>Atendidas hoy</span><strong><?= (int) $r['atendidas'] ?></strong></div>
    <div class="tarjeta"><span>Canceladas hoy</span><strong><?= (int) $r['canceladas'] ?></strong></div>
</div>

<div class="tarjeta">
    <h2>Accesos rápidos</h2>
    <div class="acciones">
        <?php if (es_rol($usuario, ROL_ADMIN, ROL_RECEPCION)): ?>
            <a class="btn btn-principal" href="cita_nueva.php">Programar cita</a>
            <a class="btn" href="paciente_form.php">Registrar paciente</a>
            <a class="btn" href="citas.php?fecha=<?= e($hoy) ?>">Citas de hoy</a>
        <?php endif; ?>
        <?php if ($usuario['rol'] === ROL_MEDICO): ?>
            <a class="btn btn-principal" href="agenda.php?fecha=<?= e($hoy) ?>">Mi agenda de hoy</a>
        <?php endif; ?>
    </div>
</div>
<?= pie_pagina() ?>
