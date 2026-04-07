<?php
// auth.php — Enhanced: session persistence (8 jam) + security
if (session_status() === PHP_SESSION_NONE) {
    // Set session lifetime 8 jam (28800 detik)
    $sessionLifetime = 28800;
    ini_set('session.gc_maxlifetime', $sessionLifetime);
    ini_set('session.cookie_lifetime', $sessionLifetime);

    // Konfigurasi cookie session yang aman
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'secure'   => false,      // Ganti true jika pakai HTTPS
        'httponly' => true,        // Cegah akses JavaScript ke cookie session
        'samesite' => 'Lax'       // Proteksi CSRF dasar
    ]);

    session_start();

    // Perpanjang masa session setiap request (sliding expiration)
    if (isset($_SESSION['user'])) {
        $_SESSION['_last_activity'] = time();
    }
}

function checkRole($allowedRoles) {
    if (!isset($_SESSION['user'])) {
        header("Location: index.php");
        exit();
    }

    // Guard: jika user wajib ganti password, paksa redirect ke halaman ganti password
    // Kecuali halaman itu sendiri (change_password.php) atau logout
    $currentPage = basename($_SERVER['PHP_SELF']);
    if (!empty($_SESSION['user']['must_change_password'])
        && !in_array($currentPage, ['change_password.php', 'logout.php'])
    ) {
        header("Location: change_password.php");
        exit();
    }

    if (!in_array($_SESSION['user']['role'], $allowedRoles)) {
        $role = $_SESSION['user']['role'];
        if ($role === 'admin') {
            header("Location: admin.php");
        } else {
            header("Location: asesor.php");
        }
        exit();
    }
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}
?>
