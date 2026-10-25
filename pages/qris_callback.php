<?php
// Endpoint publik notifikasi dari penyedia QRIS dinamis. Selalu JSON, tanpa sesi.
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"status":"metode tidak diizinkan"}'; exit; }
$tok = QrisApi::token();
if ($tok !== '' && !hash_equals($tok, (string) ($_GET['k'] ?? ''))) { http_response_code(403); echo '{"status":"tidak diizinkan"}'; exit; }
[$kode, $isi] = QrisApi::callback((string) file_get_contents('php://input', false, null, 0, 20000));
http_response_code($kode); echo json_encode($isi); exit;
