<?php
Auth::require('santri.view');
$id = (int) in_str('id', '0');
$s = $id ? Santri::find($id) : null;
if (!$s || empty($s['foto'])) { http_response_code(404); exit; }
Auth::assertUnit($s['unit']);
$p = Foto::jalur((string) $s['foto']);
if (!$p) { http_response_code(404); exit; }
$etag = '"' . md5($s['foto']) . '"';
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($p));
readfile($p);
exit;
