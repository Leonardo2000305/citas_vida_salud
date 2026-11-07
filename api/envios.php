<?php
/**
 * ARCHIVO: envios.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: que recepción y administración vean los avisos enviados a los pacientes y usuarios.
 * QUÉ HACE: GET -> últimos 200 avisos, con a quién fue y si esa cuenta tiene correo registrado.
 * CONECTA CON: envios.html, includes/notificaciones.php
 */
require_once __DIR__ . '/_json.php';

json_metodo('GET');
json_requerir([ROL_ADMIN, ROL_RECEPCION]);
$pdo = db();

$st = $pdo->query(
    "SELECT n.id_notificacion, n.mensaje, n.creada, n.leida,
            u.nombres || ' ' || u.apellidos AS destinatario,
            CASE WHEN u.correo IS NOT NULL AND u.correo <> '' THEN 'Sí' ELSE 'No' END AS tiene_correo,
            COALESCE(u.correo, u.telefono, '') AS contacto
       FROM notificacion n JOIN usuario u ON u.id_usuario = n.id_usuario
      ORDER BY n.creada DESC LIMIT 200"
);
$avisos = $st->fetchAll();
foreach ($avisos as &$a) {
    $a['leida'] = $a['leida'] === 't' || $a['leida'] === true;
}
unset($a);

json_responder(200, ['avisos' => $avisos]);
