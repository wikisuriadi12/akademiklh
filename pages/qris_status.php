<?php
// JSON: status QRIS dinamis (polling kasir). Pada mode simulasi, admin boleh menandai lunas.
Auth::require('pos');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$ref = in_str('ref');
if (is_post() && in_str('simulasi') === '1') { csrf_check(); if (Auth::can('users')) { QrisApi::simulasiLunas($ref); } }
echo json_encode(['status' => QrisApi::status($ref)]);
exit;
