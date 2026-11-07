<?php
/**
 * ARCHIVO: migrar_avisos_pagos.php
 * CAPA: BACKEND (migración de una sola vez)
 * SIRVE PARA: crear tablas de avisos y lista de espera, y columnas de pago y recordatorio.
 * QUÉ HACE: al abrirlo con ?t=migra-avisos-7741 ejecuta las instrucciones (seguras de repetir),
 *           muestra el resultado y se borra sola.
 * CONECTA CON: config/db.php, sql/migracion_avisos_pagos.sql
 */
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/plain; charset=utf-8');
if (($_GET['t'] ?? '') !== 'migra-avisos-7741') {
    http_response_code(404);
    exit('No encontrado.');
}

$instrucciones = [
    'CREATE TABLE IF NOT EXISTS notificacion (     id_notificacion SERIAL     PRIMARY KEY,     id_usuario      INT        NOT NULL REFERENCES usuario(id_usuario) ON DELETE CASCADE,     mensaje         TEXT       NOT NULL,     leida           BOOLEAN    NOT NULL DEFAULT FALSE,     creada          TIMESTAMP  NOT NULL DEFAULT NOW() )',
    'CREATE TABLE IF NOT EXISTS lista_espera (     id_espera      SERIAL     PRIMARY KEY,     id_paciente    INT        NOT NULL REFERENCES paciente(id_paciente) ON DELETE CASCADE,     id_medico      INT        NOT NULL REFERENCES medico(id_medico) ON DELETE CASCADE,     fecha_deseada  DATE       NOT NULL,     activa         BOOLEAN    NOT NULL DEFAULT TRUE,     creada         TIMESTAMP  NOT NULL DEFAULT NOW(),     UNIQUE (id_paciente, id_medico, fecha_deseada) )',
    'ALTER TABLE cita ADD COLUMN IF NOT EXISTS monto NUMERIC(10,2)',
    'ALTER TABLE cita ADD COLUMN IF NOT EXISTS pagado BOOLEAN NOT NULL DEFAULT FALSE',
    'ALTER TABLE cita ADD COLUMN IF NOT EXISTS metodo_pago VARCHAR(30)',
    'ALTER TABLE cita ADD COLUMN IF NOT EXISTS recordatorio_enviado BOOLEAN NOT NULL DEFAULT FALSE',
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
