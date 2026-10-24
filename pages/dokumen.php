<?php
Auth::require('santri.view');
$jenis = in_str('jenis');
$id = (int) in_str('id', '0');
$nama = null; $sid = 0;
if ($jenis === 'surat') {
    $s = Santri::find($id);
    if ($s) { $nama = (string) ($s['surat_rekomendasi'] ?? ''); $sid = (int) $s['id']; Auth::assertUnit($s['unit']); }
} elseif ($jenis === 'sertifikat') {
    $r = Db::one('SELECT * FROM prestasi WHERE id = ?', [$id]);
    $s = $r ? Santri::find((int) $r['santri_id']) : null;
    if ($r && $s) { $nama = (string) ($r['sertifikat'] ?? ''); $sid = (int) $s['id']; Auth::assertUnit($s['unit']); }
}
$p = $nama ? Berkas::jalur($nama) : null;
if (!$p) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Dokumen tidak ditemukan.</div></div>'); exit; }
header('Content-Type: ' . Berkas::mime($nama));
header('Content-Disposition: inline; filename="' . $nama . '"');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($p));
readfile($p);
exit;
