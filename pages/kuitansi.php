<?php
Auth::require('laporan');
$k = Spp::kuitansi((int) in_str('id', '0'));
if (!$k) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Kuitansi tidak ditemukan.</div></div>'); exit; }
Auth::assertUnit($k['unit']);
$sid = (int) Db::val('SELECT santri_id FROM tagihan WHERE id=?', [$k['tagihan_id']]);
$cfgS = Settings::all();
$wa = wa_link(Db::val('SELECT hp_wali FROM santri WHERE id=?', [$sid]), "Assalamu'alaikum {$k['nama_wali']}, berikut bukti pembayaran {$k['label']} ananda {$k['nama']}: " . rp($k['jumlah']) . ' (no. ' . $k['no_kuitansi'] . ', ' . $k['tanggal'] . ').' . ($k['sisa'] > 0 ? ' Sisa tagihan ' . rp($k['sisa']) . '.' : ' Tagihan ini sudah lunas.') . ' Jazakumullahu khairan. - ' . $cfgS['nama_lembaga']);
$stamp = $k['dibatalkan'] ? ['void', 'DIBATALKAN'] : ($k['sisa'] <= 0 ? ['', 'LUNAS'] : ['part', 'SEBAGIAN']);
ob_start(); ?>
<div class="page-h noprint"><div><h1>Kuitansi</h1></div>
  <div class="acts">
    <button class="btn" data-print><?= icon('printer') ?> Cetak</button>
    <?php if ($wa && !$k['dibatalkan']): ?><a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?> Kirim ke wali</a><?php endif; ?>
    <a class="btn ghost" href="?p=bayar&amp;santri=<?= $sid ?>">Kembali</a>
  </div></div>
<div class="card receipt">
  <div class="rh"><span class="logo"><?= icon('book', 20) ?></span><div><b style="font-size:1.1rem"><?= e($cfgS['nama_lembaga']) ?></b><br><small><?= e($cfgS['alamat']) ?><?= $cfgS['telepon'] ? ' &middot; ' . e($cfgS['telepon']) : '' ?></small></div>
    <span class="stamp <?= $stamp[0] ?>"><?= $stamp[1] ?></span></div>
  <dl class="kv">
    <dt>No. kuitansi</dt><dd><b><?= e($k['no_kuitansi']) ?></b></dd>
    <dt>Tanggal bayar</dt><dd><?= e($k['tanggal']) ?></dd>
    <dt>Telah terima dari</dt><dd><?= e($k['nama_wali']) ?> (wali)</dd>
    <dt>Santri</dt><dd><?= e($k['nama']) ?> &middot; <?= e($k['nis']) ?></dd>
    <dt>Jenjang</dt><dd><?= e($k['jenjang']) ?> <?= e(Domain::UNIT[$k['unit']]) ?><?= $k['kelas'] ? ' / ' . e($k['kelas']) : '' ?></dd>
    <dt>Untuk pembayaran</dt><dd><?= e($k['label']) ?></dd>
    <dt>Metode</dt><dd><?= e(ucfirst($k['metode'])) ?><?= $k['keterangan'] ? ' &middot; ' . e($k['keterangan']) : '' ?></dd>
  </dl>
  <div class="amt"><?= rp($k['jumlah']) ?></div>
  <dl class="kv">
    <dt>Tagihan</dt><dd><?= rp($k['tagihan_nominal']) ?></dd>
    <dt>Sisa tagihan</dt><dd><?= rp($k['sisa']) ?></dd>
    <dt>Petugas</dt><dd><?= e($k['petugas']) ?></dd>
    <?php if ($k['dibatalkan']): ?><dt>Pembatalan</dt><dd style="color:var(--err)"><?= e($k['alasan_batal']) ?></dd><?php endif; ?>
  </dl>
</div>
<?php render('Kuitansi', ob_get_clean());
