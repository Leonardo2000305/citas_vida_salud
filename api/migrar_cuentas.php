<?php
/**
 * ARCHIVO: migrar_cuentas.php
 * CAPA: BACKEND (migración de una sola vez)
 * SIRVE PARA: agregar a la base de datos las columnas de cuentas de paciente.
 * QUÉ HACE: al abrirlo con ?t=migra-7741-vida ejecuta las instrucciones (son seguras de repetir),
 *           muestra el resultado y se borra solo.
 * CONECTA CON: config/db.php, sql/migracion_cuentas_pacientes.sql
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['t'] ?? '') !== 'migra-7741-vida') {
    http_response_code(404);
    exit('No encontrado.');
}

$instrucciones = [
    "INSERT INTO rol (id_rol, nombre) VALUES (4, 'Paciente') ON CONFLICT (id_rol) DO NOTHING",
    'ALTER TABLE usuario ALTER COLUMN correo DROP NOT NULL',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS ci VARCHAR(20) UNIQUE',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) UNIQUE',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS id_paciente INT UNIQUE REFERENCES paciente(id_paciente)',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS codigo_verificacion VARCHAR(255)',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS codigo_expira TIMESTAMP',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS recuperacion_hash VARCHAR(255)',
    'ALTER TABLE usuario ADD COLUMN IF NOT EXISTS recuperacion_expira TIMESTAMP',
];

$pdo = db();
$pdo->beginTransaction();
try {
    foreach ($instrucciones as $sql) {
        $pdo->exec($sql);
        echo "OK: " . substr($sql, 0, 70) . "\n";
    }
    $pdo->commit();
    echo "\nMigración aplicada correctamente.\n";
    @unlink(__FILE__);   // se borra sola para que nadie la vuelva a usar
    echo "Este archivo se borró del servidor.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR, no se aplicó nada: " . $e->getMessage() . "\n";
}
