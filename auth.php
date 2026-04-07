<?php
// auth.php — Enhanced: proper redirect instead of die()
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
