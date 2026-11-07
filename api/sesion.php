<?php
/**
 * ARCHIVO: sesion.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: iniciar sesión, cerrar sesión y saber quién está conectado.
 * QUÉ HACE:
 *   GET    -> datos del usuario conectado y token CSRF (o 401 si no hay sesión)
 *   POST   -> inicia sesión con {usuario, clave}
 *   DELETE -> cierra la sesión
 * CONECTA CON: api/_json.php, tabla usuario y rol (sql/schema.sql), login.html del frontend.
 */
require_once __DIR__ . '/_json.php';

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $usuario = usuario_actual();
        if ($usuario === null) {
            json_error(401, 'Debe iniciar sesión.');
        }
        json_responder(200, ['usuario' => $usuario, 'csrf' => csrf_token()]);

    case 'POST':
        $entrada = json_entrada();
        $identificador = trim((string) ($entrada['usuario'] ?? ''));
        $clave = (string) ($entrada['clave'] ?? '');
        if ($identificador === '' || $clave === '') {
            json_error(400, 'Ingrese su usuario o correo y su contraseña.');
        }

        $st = db()->prepare(
            'SELECT u.id_usuario, u.nombres, u.apellidos, u.password_hash, u.puede_marcar_atendida,
                    u.id_medico, r.nombre AS rol
               FROM usuario u
               JOIN rol r ON r.id_rol = u.id_rol
              WHERE u.activo = TRUE
                AND (u.nombre_usuario = :x OR lower(u.correo) = lower(:x))'
        );
        $st->execute([':x' => $identificador]);
        $u = $st->fetch();

        if ($u === false || !password_verify($clave, $u['password_hash'])) {
            json_error(401, 'Usuario o contraseña incorrectos.');   // mensaje genérico a propósito
        }

        session_regenerate_id(true);   // evita la fijación de sesión
        $_SESSION['usuario'] = [
            'id'             => (int) $u['id_usuario'],
            'nombre'         => $u['nombres'] . ' ' . $u['apellidos'],
            'rol'            => $u['rol'],
            'id_medico'      => $u['id_medico'] !== null ? (int) $u['id_medico'] : null,
            'puede_atendida' => (bool) $u['puede_marcar_atendida'],
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_responder(200, ['usuario' => $_SESSION['usuario'], 'csrf' => $_SESSION['csrf']]);

    case 'DELETE':
        json_verificar_csrf();
        $_SESSION = [];
        session_destroy();
        json_responder(200, ['ok' => true]);

    default:
        json_error(405, 'Método no permitido.');
}
