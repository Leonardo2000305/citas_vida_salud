<?php
/**
 * ARCHIVO: borrar_cuenta_prueba.php
 * CAPA: BACKEND (limpieza de una sola vez)
 * SIRVE PARA: borrar la cuenta de prueba "Jose Tilin" creada con el carnet 11305021.
 * QUÉ HACE: al abrirlo con ?t=limpia-7741-vida borra SOLO esa cuenta (no la ficha de paciente)
 *           y se borra a sí mismo.
 * CONECTA CON: config/db.php
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['t'] ?? '') !== 'limpia-7741-vida') {
    http_response_code(404);
    exit('No encontrado.');
}

$st = db()->prepare("DELETE FROM usuario WHERE ci = '11305021' AND nombre_usuario = 'pac_11305021' AND nombres = 'Jose' AND apellidos = 'Tilin'");
$st->execute();
echo 'Cuentas borradas: ' . $st->rowCount() . "\n";
@unlink(__FILE__);
echo "Este archivo se borró del servidor.\n";
