<?php
/** Cierra la sesión y elimina la cookie. */
require_once __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
redirigir('login.php');
