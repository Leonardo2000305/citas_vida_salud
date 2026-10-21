<?php
/** Cambia el estado de una cita (confirmar, atender, inasistencia, cancelar). Solo POST. */
require_once __DIR__ . '/includes/bootstrap.php';
$usuario = requerir_login([ROL_ADMIN, ROL_RECEPCION, ROL_MEDICO]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
verificar_csrf();

// Solo se vuelve a páginas internas conocidas (evita redirecciones a sitios externos)
$volver = texto_param('volver');
$destino = preg_match('/^(citas|agenda)\.php(\?[A-Za-z0-9=&_\-]*)?$/', $volver) ? $volver : 'index.php';

try {
    cambiar_estado_cita(
        db(),
        (int) entero_param('id', $_POST),
        texto_param('nuevo_estado'),
        $usuario,
        texto_param('motivo') ?: null
    );
    mensaje_flash('exito', 'La cita fue actualizada.');
} catch (ErrorCita $e) {
    mensaje_flash('error', $e->getMessage());
}
redirigir($destino);
