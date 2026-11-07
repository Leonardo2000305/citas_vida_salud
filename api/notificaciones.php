<?php
/**
 * ARCHIVO: notificaciones.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: mostrar los avisos del usuario que entró.
 * QUÉ HACE: GET -> avisos recientes y cuántos no ha leído. POST {accion:'leer'} -> los marca como leídos.
 * CONECTA CON: includes/notificaciones.php, js/app.js
 */
require_once __DIR__ . '/_json.php';

$usuario = json_requerir();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $pdo->prepare('SELECT id_notificacion, mensaje, leida, creada FROM notificacion WHERE id_usuario = :u ORDER BY creada DESC LIMIT 30');
    $st->execute([':u' => $usuario['id']]);
    $avisos = $st->fetchAll();
    foreach ($avisos as &$a) {
        $a['leida'] = $a['leida'] === 't' || $a['leida'] === true;   // PostgreSQL devuelve 't'/'f'
    }
    unset($a);
    $sinLeer = count(array_filter($avisos, static fn ($a) => !$a['leida']));
    json_responder(200, ['avisos' => $avisos, 'sin_leer' => $sinLeer]);
}

json_metodo('POST');
json_verificar_csrf();
$d = json_entrada();
if (($d['accion'] ?? '') === 'leer') {
    $pdo->prepare('UPDATE notificacion SET leida = TRUE WHERE id_usuario = :u AND NOT leida')->execute([':u' => $usuario['id']]);
    json_responder(200, ['mensaje' => 'Avisos marcados como leídos.']);
}
json_error(400, 'Acción no válida.');
