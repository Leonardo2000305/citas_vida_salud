<?php
/**
 * ARCHIVO: pacientes.php
 * CAPA: BACKEND (API)
 * SIRVE PARA: listar, registrar y editar pacientes (RF02). Nunca se borran: se desactivan (RN11).
 * QUÉ HACE:
 *   GET  ?q=texto            -> lista de pacientes (busca por CI, nombre o código)
 *   GET  ?id=N               -> un paciente
 *   POST {ci, nombres, ...}  -> registra un paciente nuevo (CI único)
 *   PUT  ?id=N {...}         -> actualiza datos o activa/desactiva (campo activo)
 * CONECTA CON: api/_json.php, includes/validaciones.php, tabla paciente (sql/schema.sql), pacientes.html.
 */
require_once __DIR__ . '/_json.php';

const SEXOS_PACIENTE = ['Masculino', 'Femenino', 'Otro'];

/** Valida y limpia los datos de un paciente. Devuelve el arreglo limpio o lanza error 400. */
function paciente_validar(array $d): array
{
    $errores = [];
    $limpio = [
        'ci'               => trim((string) ($d['ci'] ?? '')),
        'nombres'          => trim((string) ($d['nombres'] ?? '')),
        'apellidos'        => trim((string) ($d['apellidos'] ?? '')),
        'fecha_nacimiento' => trim((string) ($d['fecha_nacimiento'] ?? '')),
        'sexo'             => trim((string) ($d['sexo'] ?? '')),
        'telefono'         => trim((string) ($d['telefono'] ?? '')),
        'correo'           => trim((string) ($d['correo'] ?? '')),
        'direccion'        => trim((string) ($d['direccion'] ?? '')),
    ];

    $errores[] = validar_ci($limpio['ci']);
    $errores[] = validar_nombre($limpio['nombres'], 'nombre');
    $errores[] = validar_nombre($limpio['apellidos'], 'apellido');
    $errores[] = validar_fecha_nacimiento($limpio['fecha_nacimiento']);
    $errores[] = validar_telefono($limpio['telefono'], true);
    if ($limpio['correo'] !== '') {
        $errores[] = validar_correo($limpio['correo']);
    }
    if ($limpio['sexo'] !== '' && !in_array($limpio['sexo'], SEXOS_PACIENTE, true)) {
        $errores[] = 'El sexo indicado no es válido.';
    }

    $errores = array_values(array_filter($errores, static fn ($e) => is_string($e)));
    if ($errores !== []) {
        json_responder(400, ['error' => implode(' ', $errores), 'errores' => $errores]);
    }

    $limpio['sexo'] = $limpio['sexo'] !== '' ? $limpio['sexo'] : null;
    $limpio['correo'] = $limpio['correo'] !== '' ? $limpio['correo'] : null;
    $limpio['direccion'] = $limpio['direccion'] !== '' ? $limpio['direccion'] : null;
    return $limpio;
}

$usuario = json_requerir();
$pdo = db();
$metodo = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : null;

if ($metodo === 'GET') {
    $columnas = 'id_paciente, codigo, ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion, activo, fecha_registro';
    // Un paciente solo puede ver su propia ficha
    if ($usuario['rol'] === ROL_PACIENTE) {
        $propia = (int) ($usuario['id_paciente'] ?? 0);
        $st = $pdo->prepare("SELECT {$columnas} FROM paciente WHERE id_paciente = :id");
        $st->execute([':id' => $propia]);
        json_responder(200, ['pacientes' => $st->fetchAll()]);
    }
    if ($id !== null) {
        $st = $pdo->prepare("SELECT {$columnas} FROM paciente WHERE id_paciente = :id");
        $st->execute([':id' => $id]);
        $fila = $st->fetch();
        $fila === false ? json_error(404, 'El paciente no existe.') : json_responder(200, ['paciente' => $fila]);
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    $st = $pdo->prepare(
        "SELECT {$columnas},
                (SELECT COUNT(*) FROM cita c WHERE c.id_paciente = p.id_paciente) AS total_citas
           FROM paciente p
          WHERE :q = '' OR p.ci ILIKE :like OR p.nombres ILIKE :like OR p.apellidos ILIKE :like OR p.codigo ILIKE :like
          ORDER BY p.apellidos, p.nombres"
    );
    $st->execute([':q' => $q, ':like' => "%{$q}%"]);
    json_responder(200, ['pacientes' => $st->fetchAll()]);
}

json_verificar_csrf();
json_requerir([ROL_ADMIN, ROL_RECEPCION]);
$datos = json_entrada();

if ($metodo === 'POST') {
    $limpio = paciente_validar($datos);
    $existe = $pdo->prepare('SELECT 1 FROM paciente WHERE ci = :ci');
    $existe->execute([':ci' => $limpio['ci']]);
    if ($existe->fetch() !== false) {
        json_error(409, 'Ya existe un paciente con ese CI.');   // RF02
    }
    $ins = $pdo->prepare(
        'INSERT INTO paciente (ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, correo, direccion)
         VALUES (:ci, :nombres, :apellidos, :fecha_nacimiento, :sexo, :telefono, :correo, :direccion)
         RETURNING id_paciente'
    );
    $ins->execute($limpio);
    json_responder(201, ['id' => (int) $ins->fetchColumn(), 'mensaje' => 'Paciente registrado correctamente.']);
}

if ($metodo === 'PUT') {
    if ($id === null) {
        json_error(400, 'Falta el identificador del paciente.');
    }
    if (array_key_exists('activo', $datos) && count($datos) === 1) {
        $st = $pdo->prepare('UPDATE paciente SET activo = :a WHERE id_paciente = :id');
        $st->execute([':a' => (bool) $datos['activo'] ? 'true' : 'false', ':id' => $id]);
        json_responder(200, ['mensaje' => 'Estado del paciente actualizado.']);
    }
    $limpio = paciente_validar($datos);
    $otro = $pdo->prepare('SELECT 1 FROM paciente WHERE ci = :ci AND id_paciente <> :id');
    $otro->execute([':ci' => $limpio['ci'], ':id' => $id]);
    if ($otro->fetch() !== false) {
        json_error(409, 'Ya existe otro paciente con ese CI.');
    }
    $limpio['id'] = $id;
    $up = $pdo->prepare(
        'UPDATE paciente SET ci = :ci, nombres = :nombres, apellidos = :apellidos,
                fecha_nacimiento = :fecha_nacimiento, sexo = :sexo, telefono = :telefono,
                correo = :correo, direccion = :direccion
          WHERE id_paciente = :id'
    );
    $up->execute($limpio);
    json_responder(200, ['mensaje' => 'Datos del paciente actualizados.']);
}

json_error(405, 'Método no permitido.');
