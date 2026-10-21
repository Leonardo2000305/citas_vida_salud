<?php
/**
 * Conexión a PostgreSQL con PDO (pdo_pgsql).
 *
 * Orden de lectura de los datos:
 *   1. Variables de entorno (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS).
 *   2. Archivo config/local.php (copie config/local.ejemplo.php y llénelo). Este archivo
 *      NO se sube a GitHub porque está en .gitignore.
 *   3. Valores por defecto para un PostgreSQL local.
 */
declare(strict_types=1);

function config_local(): array
{
    $ruta = __DIR__ . '/local.php';
    return is_file($ruta) ? (array) require $ruta : [];
}

function config_db(string $clave, string $defecto): string
{
    static $local = null;
    $local ??= config_local();
    $env = getenv($clave);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return isset($local[$clave]) && $local[$clave] !== '' ? (string) $local[$clave] : $defecto;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = config_db('DB_HOST', 'localhost');
    $puerto  = config_db('DB_PORT', '5432');
    $nombre  = config_db('DB_NAME', 'citas_vida_salud');
    $usuario = config_db('DB_USER', 'postgres');
    $clave   = config_db('DB_PASS', '');

    $dsn = "pgsql:host={$host};port={$puerto};dbname={$nombre}";

    $pdo = new PDO($dsn, $usuario, $clave, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET TIME ZONE 'America/La_Paz'");

    return $pdo;
}
