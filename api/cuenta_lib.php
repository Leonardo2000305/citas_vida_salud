<?php
/**
 * ARCHIVO: cuenta_lib.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: funciones comunes de las cuentas de pacientes (códigos y correo).
 * QUÉ HACE: genera códigos de 6 dígitos, los guarda como hash y envía el correo con mail() de PHP,
 *           igual que hace la biblioteca SEMSAD en AlwaysData.
 * CONECTA CON: api/registro.php, api/verificar.php, api/recuperar.php
 */
require_once __DIR__ . '/_json.php';

function cuenta_codigo(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/** Envía un código al correo. Devuelve true si el servidor aceptó el envío. */
function cuenta_enviar_codigo(string $correo, string $asunto, string $codigo): bool
{
    $cuerpo = "Clínica Vida Salud\n\nSu código es: {$codigo}\n\n"
            . "Si usted no lo pidió, ignore este mensaje.";
    $cabeceras = "Content-Type: text/plain; charset=UTF-8\r\n"
               . "From: Clínica Vida Salud <no-responder@citasvidasalud.alwaysdata.net>\r\n";
    return @mail($correo, $asunto, $cuerpo, $cabeceras);
}
