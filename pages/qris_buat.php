<?php
// JSON: buat QRIS dinamis untuk keranjang kasir (nominal dihitung ulang di server).
Auth::require('pos');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!is_post()) { http_response_code(405); echo '{"error":"metode"}'; exit; }
csrf_check();
try {
    $items = [];
    foreach ((array) ($_POST['qty'] ?? []) as $bid => $q) { $items[(int) $bid] = (int) $q; }
    $total = Pos::hitung($items, in_str('pelanggan'), (int) in_str('santri_id', '0') > 0, (int) preg_replace('/\D/', '', in_str('diskon', '0')));
    $t = QrisApi::mulai($total, Auth::id());
    echo json_encode(['ok' => true, 'ref' => $t['ref'], 'total' => $total, 'qr_url' => $t['qr_url'], 'provider' => $t['provider']]);
} catch (InvalidArgumentException $ex) {
    http_response_code(422); echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
}
exit;
