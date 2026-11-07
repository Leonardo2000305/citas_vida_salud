<?php
/**
 * ARCHIVO: recuperar.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: recuperar la contraseña con un código enviado al correo.
 * QUÉ HACE:
 *   POST {accion:'pedir', correo}                -> envía un código (si la cuenta existe, responde igual en todos los casos)
 *   POST {accion:'confirmar', correo, codigo, clave} -> cambia la contraseña
 * CONECTA CON: api/cuenta_lib.php, recuperar.html
 */
require_once __DIR__ . '/cuenta_lib.php';

json_metodo('POST');
$d = json_entrada();
$correo = mb_strtolower(trim((string) ($d['correo'] ?? '')));
$pdo = db();
$st = $pdo->prepare('SELECT id_usuario FROM usuario WHERE lower(correo) = :c AND activo = TRUE AND codigo_verificacion IS NULL');
$st->execute([':c' => $correo]);
$idUsuario = $st->fetchColumn();

if (($d['accion'] ?? '') === 'pedir') {
    // Respuesta igual exista o no el correo, para no revelar qué cuentas hay
    if ($idUsuario !== false) {
        $codigo = cuenta_codigo();
        $pdo->prepare('UPDATE usuario SET recuperacion_hash = :h, recuperacion_expira = :e WHERE id_usuario = :id')
            ->execute([':h' => password_hash($codigo, PASSWORD_DEFAULT), ':e' => date('Y-m-d H:i:s', time() + 900), ':id' => $idUsuario]);
        cuenta_enviar_codigo($correo, 'Recuperar contraseña - Clínica Vida Salud', $codigo);
    }
    json_responder(200, ['mensaje' => 'Si el correo tiene una cuenta, le enviamos un código. Revise también la carpeta de spam.']);
}

if (($d['accion'] ?? '') === 'confirmar') {
    $codigo = trim((string) ($d['codigo'] ?? ''));
    $clave = (string) ($d['clave'] ?? '');
    $error = validar_clave($clave);
    if ($error !== null) json_error(400, $error);
    if ($idUsuario === false) json_error(400, 'El código no es correcto.');

    $fila = $pdo->prepare('SELECT recuperacion_hash, recuperacion_expira FROM usuario WHERE id_usuario = :id');
    $fila->execute([':id' => $idUsuario]);
    $r = $fila->fetch();
    if ($r['recuperacion_hash'] === null || strtotime((string) $r['recuperacion_expira']) < time()) {
        json_error(400, 'El código venció. Pida uno nuevo.');
    }
    if (!password_verify($codigo, (string) $r['recuperacion_hash'])) {
        json_error(400, 'El código no es correcto.');
    }
    $pdo->prepare('UPDATE usuario SET password_hash = :p, recuperacion_hash = NULL, recuperacion_expira = NULL WHERE id_usuario = :id')
        ->execute([':p' => password_hash($clave, PASSWORD_DEFAULT), ':id' => $idUsuario]);
    json_responder(200, ['mensaje' => 'Contraseña cambiada. Ya puede iniciar sesión.']);
}

json_error(400, 'Acción no válida.');
