<?php
/**
 * ARCHIVO: _json.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: funciones comunes de todas las respuestas JSON de la API.
 * QUÉ HACE: pone las cabeceras JSON, valida el token CSRF de las peticiones que escriben datos
 *   y exige sesión y rol cuando hace falta.
 * CONECTA CON: includes/bootstrap.php y todos los archivos de api/.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

function json_responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(int $codigo, string $mensaje): never
{
    json_responder($codigo, ['error' => $mensaje]);
}

/** Lee el cuerpo JSON de la petición (o un arreglo vacío). */
function json_entrada(): array
{
    $crudo = file_get_contents('php://input') ?: '';
    $datos = json_decode($crudo, true);
    return is_array($datos) ? $datos : [];
}

/** Exige sesión; si se pasan roles, exige uno de ellos. Devuelve el usuario. */
function json_requerir(array $roles = []): array
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        json_error(401, 'Debe iniciar sesión.');
    }
    if ($roles !== [] && !in_array($usuario['rol'], $roles, true)) {
        json_error(403, 'Su rol no tiene permiso para esta acción.');
    }
    return $usuario;
}

/** Las peticiones que cambian datos deben enviar el token en la cabecera X-CSRF-Token. */
function json_verificar_csrf(): void
{
    $enviado = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($enviado) || !hash_equals(csrf_token(), $enviado)) {
        json_error(403, 'Solicitud no válida. Recargue la página e intente nuevamente.');
    }
}

/** Solo acepta el método HTTP indicado. */
function json_metodo(string $metodo): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) {
        json_error(405, 'Método no permitido.');
    }
}
