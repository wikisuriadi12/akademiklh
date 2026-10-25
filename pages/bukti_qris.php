<?php
if (!Auth::can('pos') && !Auth::can('penjualan')) { Auth::require('penjualan'); }
$id = (int) in_str('id', '0');
$nama = (string) Db::val('SELECT bukti_qris FROM penjualan WHERE id = ?', [$id]);
$p = $nama !== '' ? BuktiQris::jalur($nama) : null;
if (!$p) { http_response_code(404); echo 'Bukti tidak ada.'; exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($p));
readfile($p);
exit;
