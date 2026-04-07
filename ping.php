<?php
// ping.php — Session heartbeat: menjaga session tetap aktif
// Dipanggil dari JavaScript setiap ~5 menit via fetch()
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

require 'auth.php'; // Session sudah distart + lifetime sudah diset

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['alive' => false, 'msg' => 'Session habis']);
    exit();
}

// Refresh timestamp aktifitas
$_SESSION['_last_activity'] = time();

echo json_encode([
    'alive' => true,
    'user'  => $_SESSION['user']['name'] ?? 'Asesor',
    'time'  => date('H:i:s'),
]);
?>
