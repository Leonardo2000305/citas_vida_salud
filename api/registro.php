<?php
/**
 * ARCHIVO: registro.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: crear la cuenta de un paciente.
 * QUÉ HACE: POST {metodo:'correo'|'telefono', ci, nombres, apellidos, fecha_nacimiento, telefono, correo, clave}
 *   - Un carnet, un teléfono y un correo por cuenta (si ya existen, responde 409).
 *   - metodo 'correo': la cuenta queda pendiente hasta confirmar el código del correo.
 *   - metodo 'telefono': la cuenta queda lista al instante (no hay SMS disponible).
 * CONECTA CON: api/cuenta_lib.php, api/verificar.php, entrar.html, registro.html
 */
require_once __DIR__ . '/cuenta_lib.php';

json_metodo('POST');
$d = json_entrada();
$metodo = (string) ($d['metodo'] ?? 'telefono');
$ci = trim((string) ($d['ci'] ?? ''));
$nombres = trim((string) ($d['nombres'] ?? ''));
$apellidos = trim((string) ($d['apellidos'] ?? ''));
$fechaNac = trim((string) ($d['fecha_nacimiento'] ?? ''));
$telefono = trim((string) ($d['telefono'] ?? ''));
$correo = mb_strtolower(trim((string) ($d['correo'] ?? '')));
$clave = (string) ($d['clave'] ?? '');

$errores = array_values(array_filter([
    validar_ci($ci),
    validar_nombre($nombres, 'nombre'),
    validar_nombre($apellidos, 'apellido'),
    validar_fecha_nacimiento($fechaNac),
    validar_telefono($telefono, true),
    validar_clave($clave),
    $metodo === 'correo' ? validar_correo($correo) : null,
    in_array($metodo, ['correo', 'telefono'], true) ? null : 'Elija registrarse con correo o con teléfono.',
], static fn ($e) => is_string($e)));
if ($errores !== []) {
    json_responder(400, ['error' => implode(' ', $errores)]);
}

$pdo = db();

// Un carnet, un teléfono y un correo por cuenta
$chequeo = $pdo->prepare('SELECT ci, telefono, correo FROM usuario WHERE ci = :ci OR telefono = :tel OR (:correo <> \'\' AND lower(correo) = :correo)');
$chequeo->execute([':ci' => $ci, ':tel' => $telefono, ':correo' => $correo]);
foreach ($chequeo->fetchAll() as $fila) {
    if ($fila['ci'] === $ci) json_error(409, 'Ya existe una cuenta con ese carnet.');
    if ($fila['telefono'] === $telefono) json_error(409, 'Ese número de teléfono ya tiene una cuenta.');
    if ($correo !== '' && mb_strtolower((string) $fila['correo']) === $correo) json_error(409, 'Ese correo ya tiene una cuenta.');
}

// Las fichas creadas por recepción también cuentan: no se puede duplicar carnet ni teléfono
$ficha = $pdo->prepare('SELECT id_paciente, nombres, apellidos FROM paciente WHERE ci = :ci');
$ficha->execute([':ci' => $ci]);
$fichaExistente = $ficha->fetch();
if ($fichaExistente) {
    if (cuenta_normalizar($fichaExistente['nombres'] . ' ' . $fichaExistente['apellidos']) !== cuenta_normalizar($nombres . ' ' . $apellidos)) {
        json_error(409, 'Ese carnet ya está registrado en la clínica con otro nombre. Acuda a recepción para corregirlo.');
    }
}
$telefonoOtro = $pdo->prepare('SELECT 1 FROM paciente WHERE telefono = :tel AND ci <> :ci LIMIT 1');
$telefonoOtro->execute([':tel' => $telefono, ':ci' => $ci]);
if ($telefonoOtro->fetchColumn()) {
    json_error(409, 'Ese número de teléfono ya está registrado en otra ficha de la clínica.');
}

$pdo->beginTransaction();
try {
    // Ficha de paciente: si ya existe con ese carnet y el mismo nombre, se usa esa misma ficha
    $idPaciente = $fichaExistente ? $fichaExistente['id_paciente'] : false;
    if ($idPaciente === false) {
        $ins = $pdo->prepare(
            'INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, telefono, correo)
             VALUES (:ci, :n, :a, :f, :t, :c) RETURNING id_paciente'
        );
        $ins->execute([':ci' => $ci, ':n' => $nombres, ':a' => $apellidos, ':f' => $fechaNac,
                       ':t' => $telefono, ':c' => $correo !== '' ? $correo : null]);
        $idPaciente = $ins->fetchColumn();
    }

    $codigo = $metodo === 'correo' ? cuenta_codigo() : null;
    $usu = $pdo->prepare(
        'INSERT INTO usuario (nombre_usuario, correo, nombres, apellidos, password_hash, id_rol, ci, telefono,
                              id_paciente, codigo_verificacion, codigo_expira)
         VALUES (:nu, :correo, :n, :a, :ph, 4, :ci, :tel, :ip, :cv, :ce)'
    );
    $usu->execute([
        ':nu' => 'pac_' . $ci,
        ':correo' => $metodo === 'correo' ? $correo : null,
        ':n' => $nombres, ':a' => $apellidos,
        ':ph' => password_hash($clave, PASSWORD_DEFAULT),
        ':ci' => $ci, ':tel' => $telefono, ':ip' => $idPaciente,
        ':cv' => $codigo !== null ? password_hash($codigo, PASSWORD_DEFAULT) : null,
        ':ce' => $codigo !== null ? date('Y-m-d H:i:s', time() + 1800) : null,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error(500, 'No pudimos crear la cuenta. Intente de nuevo.');
}

if ($metodo === 'correo') {
    $enviado = cuenta_enviar_codigo($correo, 'Código de verificación - Clínica Vida Salud', $codigo);
    json_responder(201, [
        'mensaje' => $enviado
            ? 'Le enviamos un código a su correo. Escríbalo para activar la cuenta.'
            : 'No pudimos enviar el correo ahora. Escriba su correo otra vez más tarde o pida ayuda en recepción.',
        'paso' => 'verificar',
        'correo' => $correo,
    ]);
}
json_responder(201, ['mensaje' => 'Cuenta creada. Ya puede iniciar sesión con su número de teléfono.', 'paso' => 'listo']);
