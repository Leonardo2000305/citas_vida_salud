<?php
/**
 * Validaciones de datos del lado del servidor (la interfaz también valida con JavaScript).
 * Cada función devuelve null si el valor es válido o un mensaje de error.
 */
declare(strict_types=1);

function validar_requerido(string $valor, string $campo): ?string
{
    return $valor === '' ? "El campo {$campo} es obligatorio." : null;
}

function validar_nombre(string $valor, string $campo): ?string
{
    if ($valor === '') {
        return "El campo {$campo} es obligatorio.";
    }
    if (!preg_match('/^[\p{L}][\p{L} .\'-]{1,79}$/u', $valor)) {
        return "El campo {$campo} solo puede contener letras, espacios, puntos, apóstrofes y guiones.";
    }
    return null;
}

/** CI: entre 5 y 15 caracteres alfanuméricos (con guion permitido). */
function validar_ci(string $valor): ?string
{
    if ($valor === '') {
        return 'El CI es obligatorio.';
    }
    if (!preg_match('/^[A-Za-z0-9-]{5,15}$/', $valor)) {
        return 'El CI debe tener entre 5 y 15 caracteres (letras, números o guion).';
    }
    return null;
}

function validar_telefono(string $valor, bool $obligatorio = true): ?string
{
    if ($valor === '') {
        return $obligatorio ? 'El teléfono es obligatorio.' : null;
    }
    if (!preg_match('/^\+?[0-9 ]{7,15}$/', $valor)) {
        return 'El teléfono debe tener entre 7 y 15 dígitos.';
    }
    return null;
}

function validar_correo(string $valor): ?string
{
    if ($valor === '') {
        return null;   // el correo es opcional en pacientes
    }
    if (filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
        return 'El correo electrónico no es válido.';
    }
    return null;
}

/** Devuelve la fecha como DateTimeImmutable o null si no es una fecha real con formato Y-m-d. */
function fecha_valida(string $valor): ?DateTimeImmutable
{
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return ($f !== false && $f->format('Y-m-d') === $valor) ? $f : null;
}

function validar_fecha_nacimiento(string $valor): ?string
{
    $f = fecha_valida($valor);
    if ($f === null) {
        return 'La fecha de nacimiento no es válida.';
    }
    if ($f > new DateTimeImmutable('today')) {
        return 'La fecha de nacimiento no puede ser futura.';
    }
    return null;
}

/** Hora en formato HH:MM. */
function hora_valida(string $valor): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor)) {
        return null;
    }
    return $valor;
}

/** Contraseña: mínimo 8 caracteres, con al menos una letra y un número. */
function validar_clave(string $valor): ?string
{
    if (strlen($valor) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $valor) || !preg_match('/\d/', $valor)) {
        return 'La contraseña debe incluir al menos una letra y un número.';
    }
    return null;
}
