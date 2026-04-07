<?php
// logout.php
require 'auth.php'; // Untuk session_start() dengan config yang konsisten

// Hapus semua data session
session_unset();
session_destroy();

// Hapus cookie session dari browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header("Location: index.php");
exit();
?>
