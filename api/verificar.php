<?php
/**
 * ARCHIVO: verificar.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: confirmar el código enviado al correo al crear la cuenta.
 * QUÉ HACE: POST {correo, codigo} -> activa la cuenta. POST {correo, reenviar:true} -> envía un código nuevo.
 * CONECTA CON: api/cuenta_lib.php, registro.html
 */
require_once __DIR__ . '/cuenta_lib.php';

json_metodo('POST');
$d = json_entrada();
$correo = mb_strtolower(trim((string) ($d['correo'] ?? '')));
$pdo = db();
$st = $pdo->prepare('SELECT id_usuario, codigo_verificacion, codigo_expira FROM usuario WHERE lower(correo) = :c');
$st->execute([':c' => $correo]);
$u = $st->fetch();
if ($u === false || $u['codigo_verificacion'] === null) {
    json_error(400, 'Esa cuenta no está pendiente de verificación.');
}

if (!empty($d['reenviar'])) {
    $codigo = cuenta_codigo();
    $pdo->prepare('UPDATE usuario SET codigo_verificacion = :h, codigo_expira = :e WHERE id_usuario = :id')
        ->execute([':h' => password_hash($codigo, PASSWORD_DEFAULT), ':e' => date('Y-m-d H:i:s', time() + 1800), ':id' => $u['id_usuario']]);
    $ok = cuenta_enviar_codigo($correo, 'Código de verificación - Clínica Vida Salud', $codigo);
    json_responder(200, ['mensaje' => $ok ? 'Enviamos un código nuevo a su correo.' : 'No pudimos enviar el correo ahora.']);
}

$codigo = trim((string) ($d['codigo'] ?? ''));
if (strtotime((string) $u['codigo_expira']) < time()) {
    json_error(400, 'El código venció. Pida uno nuevo.');
}
if (!password_verify($codigo, (string) $u['codigo_verificacion'])) {
    json_error(400, 'El código no es correcto.');
}
$pdo->prepare('UPDATE usuario SET codigo_verificacion = NULL, codigo_expira = NULL WHERE id_usuario = :id')
    ->execute([':id' => $u['id_usuario']]);
json_responder(200, ['mensaje' => 'Cuenta verificada. Ya puede iniciar sesión.']);
