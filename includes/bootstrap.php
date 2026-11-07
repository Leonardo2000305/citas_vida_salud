<?php
/**
 * Arranque común de todas las páginas: sesión, seguridad, roles y utilidades.
 */
declare(strict_types=1);

date_default_timezone_set('America/La_Paz');

// Los errores técnicos se registran en el log del servidor, nunca se muestran al usuario.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/validaciones.php';
require_once __DIR__ . '/citas_servicio.php';
require_once __DIR__ . '/layout.php';

const ROL_ADMIN        = 'Administrador';
const ROL_RECEPCION    = 'Recepcionista';
const ROL_MEDICO       = 'Médico';
const ROL_PACIENTE     = 'Paciente';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                    // la cookie no es accesible desde JavaScript
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[citas-vida-salud] ' . $e->getMessage());
    http_response_code(500);
    echo 'Ocurrió un error interno. Intente nuevamente o contacte al administrador.';
});

/** Escapa texto para mostrarlo en HTML. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirigir(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Mensajes que sobreviven a una redirección (éxito o error). */
function mensaje_flash(string $tipo, string $texto): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'texto' => $texto];
}

function leer_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------
// Protección CSRF: cada formulario POST lleva un token que debe coincidir
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verificar_csrf(): void
{
    $enviado = $_POST['csrf'] ?? '';
    if (!is_string($enviado) || !hash_equals(csrf_token(), $enviado)) {
        http_response_code(403);
        exit('Solicitud no válida. Recargue la página e intente nuevamente.');
    }
}

// ---------------------------------------------------------------------
// Sesión y roles
// ---------------------------------------------------------------------
/** @return array{id:int,nombre:string,rol:string,id_medico:?int,puede_atendida:bool}|null */
function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/**
 * Exige sesión válida. Si se pasan roles, también exige uno de ellos.
 * Sin sesión redirige al login; con rol incorrecto muestra 403.
 */
function requerir_login(array $roles = []): array
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        redirigir('login.php');
    }
    if ($roles !== [] && !in_array($usuario['rol'], $roles, true)) {
        http_response_code(403);
        echo cabecera_pagina('Acceso denegado', $usuario);
        echo '<div class="tarjeta"><h2>Acceso denegado</h2>'
           . '<p>Su rol no tiene permiso para ver esta página.</p>'
           . '<a class="btn" href="index.php">Volver al inicio</a></div>';
        echo pie_pagina();
        exit;
    }
    return $usuario;
}

function es_rol(array $usuario, string ...$roles): bool
{
    return in_array($usuario['rol'], $roles, true);
}

/** Lee un entero de la URL o del formulario, o devuelve null. */
function entero_param(string $clave, ?array $origen = null): ?int
{
    $origen ??= $_GET;
    $valor = $origen[$clave] ?? null;
    return (is_string($valor) || is_int($valor)) && ctype_digit((string) $valor) ? (int) $valor : null;
}

/** Texto recortado de un formulario o URL. */
function texto_param(string $clave, ?array $origen = null): string
{
    $origen ??= $_POST;
    return trim((string) ($origen[$clave] ?? ''));
}
