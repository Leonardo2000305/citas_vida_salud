<?php
/**
 * Lógica de negocio de citas médicas.
 * Todas las reglas se verifican aquí, en el servidor, y no dependen de lo que
 * el formulario o el navegador envíen (RN13). La base de datos añade una última
 * barrera con la restricción EXCLUDE de la tabla cita.
 */
declare(strict_types=1);

const ESTADO_PROGRAMADA   = 'Programada';
const ESTADO_CONFIRMADA   = 'Confirmada';
const ESTADO_ATENDIDA     = 'Atendida';
const ESTADO_CANCELADA    = 'Cancelada';
const ESTADO_INASISTENCIA = 'Inasistencia';

const ESTADOS_CITA = [
    ESTADO_PROGRAMADA, ESTADO_CONFIRMADA, ESTADO_ATENDIDA, ESTADO_CANCELADA, ESTADO_INASISTENCIA,
];

/** Transiciones válidas: estado actual => estados a los que puede pasar. */
const TRANSICIONES = [
    ESTADO_PROGRAMADA   => [ESTADO_CONFIRMADA, ESTADO_ATENDIDA, ESTADO_CANCELADA, ESTADO_INASISTENCIA],
    ESTADO_CONFIRMADA   => [ESTADO_ATENDIDA, ESTADO_CANCELADA, ESTADO_INASISTENCIA],
    ESTADO_ATENDIDA     => [],   // una cita atendida no se cancela ni se reprograma
    ESTADO_CANCELADA    => [],
    ESTADO_INASISTENCIA => [],
];

/** Solo se puede marcar como atendida o inasistencia cuando la cita ya empezó. */
const ESTADOS_SOLO_TRAS_INICIO = [ESTADO_ATENDIDA, ESTADO_INASISTENCIA];

/** Error de regla de negocio: su mensaje sí puede mostrarse al usuario. */
final class ErrorCita extends RuntimeException
{
}

/**
 * Devuelve los horarios libres de un médico en una fecha, en intervalos de la
 * duración configurada. Ignora citas canceladas (RN09) y horas ya pasadas.
 *
 * @return list<array{inicio: DateTimeImmutable, fin: DateTimeImmutable}>
 */
function horarios_libres(PDO $pdo, int $idMedico, string $fecha, ?int $excluirCita = null): array
{
    $dia = fecha_valida($fecha);
    if ($dia === null) {
        throw new ErrorCita('La fecha indicada no es válida.');
    }

    $ahora = new DateTimeImmutable('now');
    if ($dia->format('Y-m-d') < $ahora->format('Y-m-d')) {
        return [];   // RN06: no se programan citas en fechas pasadas
    }

    $st = $pdo->prepare(
        'SELECT hora_inicio, hora_fin, duracion_cita
           FROM horario_medico
          WHERE id_medico = :m AND dia_semana = :d
          ORDER BY hora_inicio'
    );
    $st->execute([':m' => $idMedico, ':d' => (int) $dia->format('N')]);
    $bloques = $st->fetchAll();
    if ($bloques === []) {
        return [];   // el médico no atiende ese día (RN05)
    }

    $sqlOcupadas = 'SELECT inicio, fin FROM cita
                     WHERE id_medico = :m AND estado <> :cancelada AND inicio::date = :f';
    $params = [':m' => $idMedico, ':cancelada' => ESTADO_CANCELADA, ':f' => $dia->format('Y-m-d')];
    if ($excluirCita !== null) {
        $sqlOcupadas .= ' AND id_cita <> :excluir';
        $params[':excluir'] = $excluirCita;
    }
    $oc = $pdo->prepare($sqlOcupadas);
    $oc->execute($params);
    $ocupadas = [];
    foreach ($oc->fetchAll() as $c) {
        $ocupadas[] = [new DateTimeImmutable($c['inicio']), new DateTimeImmutable($c['fin'])];
    }

    $libres = [];
    foreach ($bloques as $b) {
        $duracion = (int) $b['duracion_cita'];
        $inicioBloque = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $b['hora_inicio']);
        $finBloque    = new DateTimeImmutable($dia->format('Y-m-d') . ' ' . $b['hora_fin']);

        $t = $inicioBloque;
        while (true) {
            $te = $t->modify("+{$duracion} minutes");
            if ($te > $finBloque) {
                break;                       // RN08 y RN05: la cita no sale del horario
            }
            $hora_pasada = $te <= $ahora;
            if (!$hora_pasada && !hay_cruce($t, $te, $ocupadas)) {
                $libres[] = ['inicio' => $t, 'fin' => $te];
            }
            $t = $te;
        }
    }
    return $libres;
}

/** true si el intervalo [a, b) se cruza con alguno de la lista. */
function hay_cruce(DateTimeImmutable $a, DateTimeImmutable $b, array $lista): bool
{
    foreach ($lista as [$x, $y]) {
        if ($a < $y && $x < $b) {
            return true;
        }
    }
    return false;
}

/** Busca el horario libre que coincide exactamente con fecha y hora. */
function buscar_slot(array $libres, string $fechaHora): ?array
{
    foreach ($libres as $s) {
        if ($s['inicio']->format('Y-m-d H:i') === $fechaHora) {
            return $s;
        }
    }
    return null;
}

/**
 * Crea una cita. Bloquea al médico (FOR UPDATE) para que dos recepcionistas
 * no puedan reservar el mismo horario al mismo tiempo.
 *
 * @param array{paciente:int, medico:int, especialidad:int, fecha:string, hora:string, motivo:?string} $d
 */
function crear_cita(PDO $pdo, array $d, int $idUsuario): int
{
    $fecha = (string) ($d['fecha'] ?? '');
    $hora  = (string) ($d['hora'] ?? '');
    if (!is_string(hora_valida($hora))) {
        throw new ErrorCita('La hora indicada no es válida.');
    }

    $pdo->beginTransaction();
    try {
        // Bloqueo del médico: serializa las reservas de ese médico
        $med = $pdo->prepare('SELECT activo FROM medico WHERE id_medico = :m FOR UPDATE');
        $med->execute([':m' => $d['medico']]);
        $medico = $med->fetch();
        if ($medico === false) {
            throw new ErrorCita('El médico seleccionado no existe.');
        }
        if (!$medico['activo']) {
            throw new ErrorCita('El médico no está activo y no puede recibir citas nuevas.');   // RN04
        }

        $pac = $pdo->prepare('SELECT activo FROM paciente WHERE id_paciente = :p');
        $pac->execute([':p' => $d['paciente']]);
        $paciente = $pac->fetch();
        if ($paciente === false) {
            throw new ErrorCita('El paciente seleccionado no existe.');
        }
        if (!$paciente['activo']) {
            throw new ErrorCita('El paciente está inactivo. Actívelo antes de programar una cita.');
        }

        $esp = $pdo->prepare(
            'SELECT 1 FROM medico_especialidad WHERE id_medico = :m AND id_especialidad = :e'
        );
        $esp->execute([':m' => $d['medico'], ':e' => $d['especialidad']]);
        if ($esp->fetch() === false) {
            throw new ErrorCita('El médico no atiende la especialidad seleccionada.');
        }

        $slot = buscar_slot(horarios_libres($pdo, (int) $d['medico'], $fecha), "{$fecha} {$hora}");
        if ($slot === null) {
            throw new ErrorCita('El horario no está disponible para ese médico. Elija otro.');
        }

        $ins = $pdo->prepare(
            'INSERT INTO cita (id_paciente, id_medico, id_especialidad, inicio, fin, motivo, id_usuario_registro)
             VALUES (:p, :m, :e, :ini, :fin, :motivo, :u)
             RETURNING id_cita'
        );
        $ins->execute([
            ':p'      => $d['paciente'],
            ':m'      => $d['medico'],
            ':e'      => $d['especialidad'],
            ':ini'    => $slot['inicio']->format('Y-m-d H:i:s'),
            ':fin'    => $slot['fin']->format('Y-m-d H:i:s'),
            ':motivo' => $d['motivo'] !== '' ? $d['motivo'] : null,
            ':u'      => $idUsuario,
        ]);
        $id = (int) $ins->fetchColumn();
        $pdo->commit();
        return $id;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 23P01 = violación de restricción EXCLUDE: el horario ya fue ocupado (RF07)
        if ($e->getCode() === '23P01') {
            throw new ErrorCita('Ese horario acaba de ser ocupado por otra cita. Seleccione otro.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Reprograma una cita programada o confirmada a otra fecha y hora libres (RF08).
 * La cita queda como "Programada" porque el nuevo horario debe volver a confirmarse.
 */
function reprogramar_cita(PDO $pdo, int $idCita, string $fecha, string $hora): void
{
    if (!is_string(hora_valida($hora))) {
        throw new ErrorCita('La hora indicada no es válida.');
    }

    $pdo->beginTransaction();
    try {
        $c = $pdo->prepare('SELECT id_medico, estado FROM cita WHERE id_cita = :c FOR UPDATE');
        $c->execute([':c' => $idCita]);
        $cita = $c->fetch();
        if ($cita === false) {
            throw new ErrorCita('La cita no existe.');
        }
        if (!in_array($cita['estado'], [ESTADO_PROGRAMADA, ESTADO_CONFIRMADA], true)) {
            throw new ErrorCita('Solo se pueden reprogramar citas programadas o confirmadas.');
        }

        $pdo->prepare('SELECT 1 FROM medico WHERE id_medico = :m FOR UPDATE')
            ->execute([':m' => $cita['id_medico']]);

        $slot = buscar_slot(
            horarios_libres($pdo, (int) $cita['id_medico'], $fecha, $idCita),
            "{$fecha} {$hora}"
        );
        if ($slot === null) {
            throw new ErrorCita('El nuevo horario no está disponible. Elija otro.');
        }

        $upd = $pdo->prepare(
            'UPDATE cita SET inicio = :ini, fin = :fin, estado = :estado WHERE id_cita = :c'
        );
        $upd->execute([
            ':ini'    => $slot['inicio']->format('Y-m-d H:i:s'),
            ':fin'    => $slot['fin']->format('Y-m-d H:i:s'),
            ':estado' => ESTADO_PROGRAMADA,
            ':c'      => $idCita,
        ]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23P01') {
            throw new ErrorCita('Ese horario acaba de ser ocupado por otra cita. Seleccione otro.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Cambia el estado de una cita aplicando permisos por rol y transiciones válidas (RF10).
 * - Administrador: cualquier transición válida.
 * - Recepcionista: confirmar, cancelar, registrar inasistencia.
 * - Médico: marcar como atendida solo sus propias citas y si tiene el permiso asignado.
 */
function cambiar_estado_cita(PDO $pdo, int $idCita, string $nuevo, array $usuario, ?string $motivo = null): void
{
    if (!in_array($nuevo, ESTADOS_CITA, true)) {
        throw new ErrorCita('El estado indicado no existe.');
    }

    $c = $pdo->prepare('SELECT id_medico, estado, inicio FROM cita WHERE id_cita = :c FOR UPDATE');
    $c->execute([':c' => $idCita]);
    $cita = $c->fetch();
    if ($cita === false) {
        throw new ErrorCita('La cita no existe.');
    }

    if (!puede_cambiar_estado($usuario, $cita['id_medico'], $nuevo)) {
        throw new ErrorCita('Su rol no tiene permiso para realizar ese cambio.');
    }

    if (!in_array($nuevo, TRANSICIONES[$cita['estado']], true)) {
        throw new ErrorCita("No se puede cambiar una cita de «{$cita['estado']}» a «{$nuevo}».");
    }

    if (in_array($nuevo, ESTADOS_SOLO_TRAS_INICIO, true)
        && new DateTimeImmutable($cita['inicio']) > new DateTimeImmutable('now')) {
        throw new ErrorCita('La cita todavía no ha comenzado.');
    }

    $motivoCancelacion = $nuevo === ESTADO_CANCELADA ? ($motivo ?: null) : null;
    $upd = $pdo->prepare(
        'UPDATE cita
            SET estado = :estado,
                motivo_cancelacion = COALESCE(:mot, motivo_cancelacion)
          WHERE id_cita = :c'
    );
    $upd->execute([':estado' => $nuevo, ':mot' => $motivoCancelacion, ':c' => $idCita]);
}

function puede_cambiar_estado(array $usuario, int $idMedicoCita, string $nuevo): bool
{
    return match ($usuario['rol']) {
        ROL_ADMIN     => true,
        ROL_RECEPCION => in_array($nuevo, [ESTADO_CONFIRMADA, ESTADO_CANCELADA, ESTADO_INASISTENCIA], true),
        ROL_MEDICO    => $nuevo === ESTADO_ATENDIDA
                         && $usuario['puede_atendida']
                         && $usuario['id_medico'] === (int) $idMedicoCita,
        default       => false,
    };
}

/**
 * Crea o actualiza una especialidad. El nombre se compara normalizado
 * (sin espacios extra y en minúsculas, también con tildes) para impedir duplicados (RF04).
 * La comparación se hace en PHP para no depender de la configuración regional del servidor.
 */
function guardar_especialidad(PDO $pdo, string $nombre, ?string $descripcion, ?int $id): void
{
    $nombre = trim((string) preg_replace('/\s+/u', ' ', trim($nombre)));
    if ($nombre === '') {
        throw new ErrorCita('El nombre de la especialidad es obligatorio.');
    }
    $clave = clave_normalizada($nombre);

    foreach ($pdo->query('SELECT id_especialidad, nombre FROM especialidad')->fetchAll() as $fila) {
        $existente = clave_normalizada((string) $fila['nombre']);
        if ((int) $fila['id_especialidad'] !== (int) $id && $existente === $clave) {
            throw new ErrorCita('Ya existe una especialidad con ese nombre.');
        }
    }

    $descripcion = $descripcion !== null && $descripcion !== '' ? $descripcion : null;
    try {
        if ($id === null) {
            $pdo->prepare('INSERT INTO especialidad (nombre, descripcion) VALUES (:n, :d)')
                ->execute([':n' => $nombre, ':d' => $descripcion]);
        } else {
            $pdo->prepare('UPDATE especialidad SET nombre = :n, descripcion = :d WHERE id_especialidad = :i')
                ->execute([':n' => $nombre, ':d' => $descripcion, ':i' => $id]);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            throw new ErrorCita('Ya existe una especialidad con ese nombre.');
        }
        throw $e;
    }
}

/** Minúsculas, sin tildes y con espacios simples: para comparar nombres sin confundirse por la forma. */
function clave_normalizada(string $texto): string
{
    $limpio = trim((string) preg_replace('/\s+/u', ' ', $texto));
    $minusculas = mb_strtolower($limpio, 'UTF-8');
    return strtr($minusculas, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}
