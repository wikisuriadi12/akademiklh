<?php
Auth::require('va');
$scope = Auth::scope();
Va::kedaluwarsa();
$v = Va::find((int) in_str('id', '0'));
if (!$v) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">VA tidak ditemukan.</div></div>'); exit; }
Auth::assertUnit($v['unit']);
if (is_post() && Auth::can('va.edit')) {
    csrf_check();
    try {
        switch (in_str('aksi')) {
            case 'batal': Va::batalkan((int) $v['id'], Auth::id(), $scope); flash('VA dibatalkan.', 'warn'); break;
            case 'sim':
                $r = Va::simulasiBayar((int) $v['id'], in_str('kurang') === '1' ? (int) $v['jumlah'] - 1000 : null);
                flash($r['status'] === '000' ? 'Simulasi: pembayaran diterima dan tagihan dilunasi otomatis.' : 'Simulasi: notifikasi ditolak (' . ($r['pesan'] ?? $r['status']) . ').', $r['status'] === '000' ? 'ok' : 'warn');
                break;
            case 'cek':
                $r = Bni::inquiry($v['trx_id']);
                flash('Status dari BNI diterima dan dicatat di log.');
                break;
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=va_detail&id=' . (int) $v['id']);
}
$items = Va::items((int) $v['id']);
$wa = wa_link($v['hp_wali'], Va::pesanWa($v));
$mode = Bni::mode();
ob_start(); ?>
<div class="page-h"><div><h1>Detail Virtual Account</h1><p><?= e($v['nama']) ?> &middot; <?= e($v['nis']) ?> &middot; <?= e($v['trx_id']) ?></p></div>
  <div class="acts"><?php if ($wa && $v['status'] === 'menunggu'): ?><a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?> Kirim ke wali</a><?php endif; ?><a class="btn ghost" href="?p=va">Kembali</a></div></div>
<div class="card va-box">
  <small>Nomor Virtual Account BNI</small>
  <div class="va-no"><?= e($v['virtual_account']) ?></div>
  <div class="va-meta"><div><small>Jumlah (pas)</small><br><b><?= rp($v['jumlah']) ?></b></div><div><small>Berlaku sampai</small><br><b><?= e(substr($v['expired_at'], 0, 16)) ?></b></div>
    <div><small>Status</small><br><span class="badge vs-<?= e($v['status']) ?>"><?= e(Va::STATUS[$v['status']]) ?></span></div>
    <?php if ($v['ntb']): ?><div><small>NTB bank</small><br><b><?= e($v['ntb']) ?></b></div><?php endif; ?></div>
  <?php if ((int) $v['kelebihan'] > 0): ?><div class="note err" style="margin-top:12px">Perhatian: <?= rp($v['kelebihan']) ?> dari pembayaran ini tidak bisa dialokasikan (tagihan sudah lunas lewat jalur lain). Periksa dan kembalikan/arahkan secara manual.</div><?php endif; ?>
</div>
<div class="card"><h2>Tagihan yang dibayar</h2>
  <div class="scroll"><table class="rt"><thead><tr><th>Tagihan</th><th class="r">Nominal VA</th></tr></thead><tbody>
  <?php foreach ($items as $i): ?><tr><td data-label="Tagihan"><b><?= e(Spp::label($i)) ?></b></td><td class="r" data-label="Nominal VA"><?= rp($i['jumlah']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if (Auth::can('va.edit') && $v['status'] === 'menunggu'): ?>
<div class="card"><h2>Tindakan</h2><div class="acts">
  <?php if ($mode !== 'simulasi'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="cek"><button class="btn ghost">Cek status ke BNI</button></form><?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><button class="btn danger" data-confirm="Batalkan VA ini?">Batalkan VA</button></form>
  <?php if ($mode === 'simulasi'): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="sim"><button class="btn">Simulasikan: wali membayar penuh</button></form>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="sim"><input type="hidden" name="kurang" value="1"><button class="btn ghost">Simulasikan: nominal kurang (ditolak)</button></form>
  <?php endif; ?></div>
  <?php if ($mode === 'simulasi'): ?><p class="mut" style="font-size:.85rem;margin-bottom:0">Tombol simulasi membuat notifikasi terenkripsi persis seperti dari BNI dan memprosesnya lewat jalur yang sama dengan callback asli.</p><?php endif; ?>
</div>
<?php endif; ?>
<div class="card"><h2>Cara bayar (untuk wali)</h2><ol style="margin:0;padding-left:20px;line-height:1.8">
  <li>ATM BNI / BNI Mobile Banking / transfer dari bank lain, pilih <b>Virtual Account</b>.</li><li>Masukkan nomor VA <b><?= e($v['virtual_account']) ?></b>.</li>
  <li>Pastikan nama <b><?= e($v['nama']) ?></b> dan jumlah <b><?= rp($v['jumlah']) ?></b> sesuai, lalu konfirmasi.</li><li>Tagihan otomatis lunas dan kuitansi tersedia di sistem.</li></ol></div>
<?php render('Detail VA', ob_get_clean());
