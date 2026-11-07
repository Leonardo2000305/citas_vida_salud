<?php
/**
 * ARCHIVO: migrar_agenda.php
 * CAPA: BACKEND (migración de una sola vez)
 * SIRVE PARA: crear la tabla de bloqueos de agenda del médico.
 * QUÉ HACE: al abrirlo con ?t=migra-agenda-7741 crea la tabla, muestra el resultado y se borra sola.
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['t'] ?? '') !== 'migra-agenda-7741') {
    http_response_code(404);
    exit('No encontrado.');
}

$instrucciones = [
    'CREATE TABLE IF NOT EXISTS bloqueo_agenda (     id_bloqueo  SERIAL     PRIMARY KEY,     id_medico   INT        NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,     inicio      TIMESTAMP  NOT NULL,     fin         TIMESTAMP  NOT NULL,     motivo      TEXT       NOT NULL,     activo      BOOLEAN    NOT NULL DEFAULT TRUE,     creado      TIMESTAMP  NOT NULL DEFAULT NOW(),     CHECK (fin > inicio) )',
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
