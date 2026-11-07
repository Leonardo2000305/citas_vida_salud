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

        $pdo = db();
        // Límite de intentos: 5 fallos por IP o por usuario en 15 minutos
        $pdo->exec('CREATE TABLE IF NOT EXISTS intentos_login (clave TEXT NOT NULL, creado TIMESTAMP NOT NULL DEFAULT NOW())');
        $claveIp = 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
        $claveUsu = 'usu:' . mb_strtolower($identificador);
        $fallos = $pdo->prepare("SELECT COUNT(*) FROM intentos_login WHERE clave IN (:a, :b) AND creado > NOW() - INTERVAL '15 minutes'");
        $fallos->execute([':a' => $claveIp, ':b' => $claveUsu]);
        if ((int) $fallos->fetchColumn() >= 5) {
            json_error(429, 'Demasiados intentos fallidos. Espere 15 minutos e intente de nuevo.');
        }

        $st = $pdo->prepare(
            'SELECT u.id_usuario, u.nombres, u.apellidos, u.password_hash, u.puede_marcar_atendida,
                    u.id_medico, u.id_paciente, u.codigo_verificacion, r.nombre AS rol
               FROM usuario u
               JOIN rol r ON r.id_rol = u.id_rol
              WHERE u.activo = TRUE
                AND (u.nombre_usuario = :x OR lower(u.correo) = lower(:x) OR u.telefono = :x OR u.ci = :x)'
        );
        $st->execute([':x' => $identificador]);
        $u = $st->fetch();

        if ($u === false || !password_verify($clave, $u['password_hash'])) {
            $pdo->prepare('INSERT INTO intentos_login (clave) VALUES (:a), (:b)')->execute([':a' => $claveIp, ':b' => $claveUsu]);
            json_error(401, 'Usuario o contraseña incorrectos.');   // mensaje genérico a propósito
        }
        // Entró bien: se borran los fallos de este usuario
        $pdo->prepare('DELETE FROM intentos_login WHERE clave = :b')->execute([':b' => $claveUsu]);
        if ($u['codigo_verificacion'] !== null) {
            json_error(403, 'Debe verificar su correo antes de entrar. Revise su bandeja de entrada.');
        }

        session_regenerate_id(true);   // evita la fijación de sesión
        $_SESSION['ultima_actividad'] = time();
        $_SESSION['usuario'] = [
            'id'             => (int) $u['id_usuario'],
            'nombre'         => $u['nombres'] . ' ' . $u['apellidos'],
            'rol'            => $u['rol'],
            'id_medico'      => $u['id_medico'] !== null ? (int) $u['id_medico'] : null,
            'puede_atendida' => (bool) $u['puede_marcar_atendida'],
            'id_paciente'    => $u['id_paciente'] !== null ? (int) $u['id_paciente'] : null,
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
