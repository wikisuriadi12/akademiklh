<?php
// Endpoint publik untuk notifikasi pembayaran dari BNI. Tanpa sesi/layout; selalu membalas JSON.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => '999', 'message' => 'metode tidak diizinkan']);
    exit;
}
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!Va::izinkan($ip, (string) ($_GET['k'] ?? ''))) {
    Va::catat('masuk', 'callback', null, 'ditolak-izin', $ip, null);
    http_response_code(403);
    echo json_encode(['status' => '999', 'message' => 'tidak diizinkan']);
    exit;
}
$raw = (string) file_get_contents('php://input', false, null, 0, 20000);
echo json_encode(Va::proses($raw, $ip));
