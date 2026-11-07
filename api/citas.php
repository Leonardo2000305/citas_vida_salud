<?php
/**
 * ARCHIVO: citas.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: listar, programar, reprogramar y cambiar el estado de las citas (RF06 a RF10).
 * QUÉ HACE:
 *   GET ?fecha=YYYY-MM-DD&medico=N&estado=X  -> lista de citas filtrada
 *   GET ?paciente=N                          -> historial de citas de un paciente
 *   POST {accion:'crear', paciente, medico, especialidad, fecha, hora, motivo}
 *   POST {accion:'reprogramar', id, fecha, hora}
 *   POST {accion:'estado', id, estado, motivo}   (Confirmada, Atendida, Cancelada, Inasistencia)
 * CONECTA CON: includes/citas_servicio.php (reglas de negocio), citas.html y paciente.html.
 */
require_once __DIR__ . '/_json.php';

$usuario = json_requerir();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT c.id_cita, c.codigo, c.inicio, c.fin, c.estado, c.motivo, c.motivo_cancelacion,
                   c.id_paciente, p.codigo AS codigo_paciente, p.ci, p.nombres AS nombres_paciente,
                   p.apellidos AS apellidos_paciente, p.telefono AS telefono_paciente,
                   c.id_medico, m.nombres AS nombres_medico, m.apellidos AS apellidos_medico,
                   c.id_especialidad, e.nombre AS especialidad
              FROM cita c
              JOIN paciente p ON p.id_paciente = c.id_paciente
              JOIN medico m ON m.id_medico = c.id_medico
              JOIN especialidad e ON e.id_especialidad = c.id_especialidad
             WHERE 1 = 1";
    $params = [];
    if (!empty($_GET['fecha']) && fecha_valida((string) $_GET['fecha'])) {
        $sql .= ' AND c.inicio::date = :fecha';
        $params[':fecha'] = (string) $_GET['fecha'];
    }
    if (!empty($_GET['medico']) && ctype_digit((string) $_GET['medico'])) {
        $sql .= ' AND c.id_medico = :medico';
        $params[':medico'] = (int) $_GET['medico'];
    }
    if (!empty($_GET['paciente']) && ctype_digit((string) $_GET['paciente'])) {
        $sql .= ' AND c.id_paciente = :paciente';
        $params[':paciente'] = (int) $_GET['paciente'];
    }
    if (!empty($_GET['estado']) && in_array($_GET['estado'], ESTADOS_CITA, true)) {
        $sql .= ' AND c.estado = :estado';
        $params[':estado'] = (string) $_GET['estado'];
    }
    // Un paciente solo ve sus propias citas
    if ($usuario['rol'] === ROL_PACIENTE) {
        $sql .= ' AND c.id_paciente = :yo';
        $params[':yo'] = (int) ($usuario['id_paciente'] ?? 0);
    }
    // Un médico solo ve su propia agenda
    if ($usuario['rol'] === ROL_MEDICO && $usuario['id_medico'] !== null) {
        $sql .= ' AND c.id_medico = :propio';
        $params[':propio'] = $usuario['id_medico'];
    }
    $sql .= ' ORDER BY c.inicio DESC LIMIT 300';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    json_responder(200, ['citas' => $st->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'Método no permitido.');
}

json_verificar_csrf();
$datos = json_entrada();
$accion = (string) ($datos['accion'] ?? '');

try {
    if ($accion === 'crear') {
        $quien = json_requerir([ROL_ADMIN, ROL_RECEPCION, ROL_PACIENTE]);
        $d = [
            // El paciente siempre reserva para sí mismo; el personal elige el paciente
            'paciente'      => $quien['rol'] === ROL_PACIENTE ? (int) ($quien['id_paciente'] ?? 0) : (int) ($datos['paciente'] ?? 0),
            'medico'        => (int) ($datos['medico'] ?? 0),
            'especialidad'  => (int) ($datos['especialidad'] ?? 0),
            'fecha'         => (string) ($datos['fecha'] ?? ''),
            'hora'          => (string) ($datos['hora'] ?? ''),
            'motivo'        => trim((string) ($datos['motivo'] ?? '')),
        ];
        $id = crear_cita($pdo, $d, $quien['id']);
        json_responder(201, ['id' => $id, 'mensaje' => 'Cita programada correctamente.']);
    }

    if ($accion === 'reprogramar') {
        json_requerir([ROL_ADMIN, ROL_RECEPCION]);
        reprogramar_cita(
            $pdo,
            (int) ($datos['id'] ?? 0),
            (string) ($datos['fecha'] ?? ''),
            (string) ($datos['hora'] ?? '')
        );
        json_responder(200, ['mensaje' => 'Cita reprogramada correctamente.']);
    }

    if ($accion === 'estado') {
        // El paciente solo puede cancelar sus propias citas, con al menos 2 horas de anticipación
        if ($usuario['rol'] === ROL_PACIENTE) {
            $idCita = (int) ($datos['id'] ?? 0);
            $propia = $pdo->prepare('SELECT inicio FROM cita WHERE id_cita = :c AND id_paciente = :p');
            $propia->execute([':c' => $idCita, ':p' => (int) ($usuario['id_paciente'] ?? 0)]);
            $fila = $propia->fetch();
            if ($fila === false) {
                json_error(403, 'Esa cita no es suya.');
            }
            if (($datos['estado'] ?? '') !== ESTADO_CANCELADA) {
                json_error(403, 'Su rol no tiene permiso para realizar ese cambio.');
            }
            if (new DateTimeImmutable($fila['inicio']) < new DateTimeImmutable('+2 hours')) {
                json_error(400, 'Solo puede cancelar con al menos 2 horas de anticipación. Llame a recepción.');
            }
        }
        cambiar_estado_cita(
            $pdo,
            (int) ($datos['id'] ?? 0),
            (string) ($datos['estado'] ?? ''),
            $usuario,
            isset($datos['motivo']) ? trim((string) $datos['motivo']) : null
        );
        json_responder(200, ['mensaje' => 'Estado de la cita actualizado.']);
    }
} catch (ErrorCita $e) {
    json_error(400, $e->getMessage());
}

json_error(400, 'Acción no válida.');
