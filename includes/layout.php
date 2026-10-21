<?php
/**
 * Diseño común: encabezado, menú lateral según el rol y pie de página.
 */
declare(strict_types=1);

/** Opciones del menú: [texto, archivo, roles que la ven (vacío = todos)]. */
function opciones_menu(): array
{
    return [
        ['Inicio',         'index.php',       []],
        ['Pacientes',      'pacientes.php',   [ROL_ADMIN, ROL_RECEPCION]],
        ['Citas',          'citas.php',       [ROL_ADMIN, ROL_RECEPCION]],
        ['Médicos',        'medicos.php',     [ROL_ADMIN, ROL_RECEPCION]],
        ['Especialidades', 'especialidades.php', [ROL_ADMIN]],
        ['Agenda',         'agenda.php',      [ROL_ADMIN, ROL_RECEPCION, ROL_MEDICO]],
        ['Reportes',       'reportes.php',    [ROL_ADMIN]],
        ['Usuarios',       'usuarios.php',    [ROL_ADMIN]],
    ];
}

function cabecera_pagina(string $titulo, ?array $usuario, string $seccion = ''): string
{
    $menu = '';
    if ($usuario !== null) {
        foreach (opciones_menu() as [$texto, $archivo, $roles]) {
            if ($roles !== [] && !in_array($usuario['rol'], $roles, true)) {
                continue;
            }
            $activo = $seccion === $archivo ? ' class="activo"' : '';
            $menu .= '<a href="' . e($archivo) . '"' . $activo . '>' . e($texto) . '</a>';
        }
    }

    $flash = leer_flash();
    $aviso = '';
    if ($flash !== null) {
        $aviso = '<div class="aviso aviso-' . e($flash['tipo']) . '" role="alert">' . e($flash['texto']) . '</div>';
    }

    $barra = '';
    $cuerpoClase = 'publico';
    if ($usuario !== null) {
        $cuerpoClase = 'interno';
        $barra = '<aside class="menu" id="menu">
                    <div class="marca">Clínica Vida Salud<small>Gestión de citas</small></div>
                    <nav>' . $menu . '</nav>
                    <div class="sesion">
                        <div>' . e($usuario['nombre']) . '<small>' . e($usuario['rol']) . '</small></div>
                        <a href="cambiar_clave.php">Cambiar contraseña</a>
                        <a href="logout.php">Cerrar sesión</a>
                    </div>
                  </aside>
                  <button type="button" class="menu-toggle" aria-controls="menu" aria-expanded="false">Menú</button>';
    }

    return '<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . e($titulo) . ' · Clínica Vida Salud</title>
    <link rel="stylesheet" href="assets/css/estilos.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body class="' . $cuerpoClase . '">
' . $barra . '
<main class="contenido">
    <h1>' . e($titulo) . '</h1>
    ' . $aviso;
}

function pie_pagina(): string
{
    return '
</main>
</body>
</html>';
}
