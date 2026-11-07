<?php
/**
 * ARCHIVO: migrar_derivacion.php
 * CAPA: BACKEND (migración de una sola vez)
 * SIRVE PARA: agregar la columna de derivación entre médicos a la tabla cita.
 * QUÉ HACE: al abrirlo con ?t=migra-deriva-7741 agrega la columna, muestra el resultado y se borra sola.
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['t'] ?? '') !== 'migra-deriva-7741') {
    http_response_code(404);
    exit('No encontrado.');
}

$instrucciones = [
    'ALTER TABLE cita ADD COLUMN IF NOT EXISTS derivado_por INT REFERENCES medico(id_medico)',
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
    @unlink(__FILE__);
    echo "Este archivo se borró del servidor.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ERROR, no se aplicó nada: " . $e->getMessage() . "\n";
}
