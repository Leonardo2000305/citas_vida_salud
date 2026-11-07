<?php
/**
 * ARCHIVO: notificaciones.php
 * CAPA: BACKEND (avisos)
 * SIRVE PARA: guardar avisos que se ven dentro de la página y enviarlos por correo con Brevo.
 * QUÉ HACE: notificar_usuario() guarda el aviso y, si la cuenta tiene correo, lo manda por Brevo.
 *           Si Brevo no está configurado, el aviso igual queda en la página.
 * CONFIGURACIÓN (en config/local.php del servidor):
 *           BREVO_API_KEY = la clave de la API de Brevo
 *           BREVO_REMITENTE = correo verificado en Brevo (el que aparece como remitente)
 * CONECTA CON: api/citas.php, api/recordatorios.php, api/notificaciones.php, api/cuenta_lib.php
 */
require_once __DIR__ . '/../config/db.php';

/** Envía un correo con la API de Brevo. Devuelve true si Brevo lo aceptó. */
function brevo_enviar(string $correo, string $asunto, string $texto): bool
{
    $clave = config_db('BREVO_API_KEY', '');
    if ($clave === '' || $correo === '' || !function_exists('curl_init')) {
        return false;
    }
    $cuerpo = json_encode([
        'sender'      => ['name' => 'Clínica Vida Salud', 'email' => config_db('BREVO_REMITENTE', 'no-responder@citasvidasalud.alwaysdata.net')],
        'to'          => [['email' => $correo]],
        'subject'     => $asunto,
        'textContent' => $texto,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['api-key: ' . $clave, 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    $respuesta = curl_exec($ch);
    $estado = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($respuesta === false || $estado < 200 || $estado >= 300) {
        error_log('Brevo no envió el correo a ' . $correo . ' (código ' . $estado . ')');
        return false;
    }
    return true;
}

/** Guarda un aviso para el usuario y, si tiene correo, se lo manda. */
function notificar_usuario(PDO $pdo, int $idUsuario, string $asunto, string $mensaje): void
{
    $st = $pdo->prepare('INSERT INTO notificacion (id_usuario, mensaje) VALUES (:u, :m)');
    $st->execute([':u' => $idUsuario, ':m' => $mensaje]);

    $c = $pdo->prepare('SELECT correo FROM usuario WHERE id_usuario = :u');
    $c->execute([':u' => $idUsuario]);
    $correo = (string) $c->fetchColumn();
    if ($correo !== '') {
        brevo_enviar($correo, $asunto, $mensaje . "\n\nClínica Vida Salud");
    }
}

/** Avisa a un paciente (si tiene cuenta). Los pacientes sin cuenta no pueden recibir avisos. */
function notificar_paciente(PDO $pdo, int $idPaciente, string $asunto, string $mensaje): void
{
    $u = $pdo->prepare('SELECT id_usuario FROM usuario WHERE id_paciente = :p');
    $u->execute([':p' => $idPaciente]);
    $id = $u->fetchColumn();
    if ($id !== false) {
        notificar_usuario($pdo, (int) $id, $asunto, $mensaje);
    }
}

/** Texto corto de una cita: "Dr. Pérez el 11/11 a las 14:00". */
function detalle_cita(PDO $pdo, int $idCita): string
{
    $st = $pdo->prepare(
        "SELECT m.apellidos, to_char(c.inicio, 'DD/MM/YYYY') AS dia, to_char(c.inicio, 'HH24:MI') AS hora,
                d.apellidos AS derivador
           FROM cita c
           JOIN medico m ON m.id_medico = c.id_medico
           LEFT JOIN medico d ON d.id_medico = c.derivado_por
          WHERE c.id_cita = :c"
    );
    $st->execute([':c' => $idCita]);
    $f = $st->fetch();
    if (!$f) {
        return '';
    }
    $texto = "Dr. {$f['apellidos']} el {$f['dia']} a las {$f['hora']}";
    return $f['derivador'] ? "{$texto} (derivada por el Dr. {$f['derivador']})" : $texto;
}

/** Cuando se libera una hora, avisa a quienes esperaban ese médico ese día. */
function avisar_lista_espera(PDO $pdo, int $idMedico, string $fecha, string $detalle): int
{
    $st = $pdo->prepare('SELECT id_espera, id_paciente FROM lista_espera WHERE id_medico = :m AND fecha_deseada = :f AND activa');
    $st->execute([':m' => $idMedico, ':f' => $fecha]);
    $n = 0;
    $marcar = $pdo->prepare('UPDATE lista_espera SET activa = FALSE WHERE id_espera = :e');
    foreach ($st->fetchAll() as $fila) {
        notificar_paciente($pdo, (int) $fila['id_paciente'], 'Se liberó una hora',
            "Se liberó una hora con {$detalle}. Ingrese a la página para reservarla.");
        // Ya fue avisado: no se le vuelve a avisar por la misma anotación
        $marcar->execute([':e' => $fila['id_espera']]);
        $n++;
    }
    return $n;
}
