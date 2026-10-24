<?php
Auth::require('bayar');
$scope = Auth::scope();
$sid = (int) in_str('santri', '0');

if (is_post()) {
    csrf_check();
    try {
        if (in_str('aksi') === 'batal') {
            Spp::batalkan((int) in_str('pembayaran'), in_str('alasan'), Auth::id(), $scope);
            flash('Pembayaran dibatalkan dan tercatat di jejak audit.', 'warn');
        } else {
            $jumlah = (int) preg_replace('/\D/', '', in_str('jumlah'));
            $pid = Spp::bayar((int) in_str('tagihan'), in_str('tanggal'), $jumlah, in_str('metode'), in_str('keterangan'), Auth::id(), $scope);
            flash('Pembayaran dicatat.');
            redirect('?p=kuitansi&id=' . $pid);
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=bayar&santri=' . $sid);
}

$q = in_str('q');
$hasil = [];
if ($q !== '') {
    [$hasil] = Santri::daftar(['q' => $q], $scope, 1);
}
$santri = $sid ? Santri::find($sid) : null;
if ($santri) { Auth::assertUnit($santri['unit']); }
$tagihan = $santri ? Spp::tagihanSantri($sid) : [];
$riwayat = $santri ? Spp::riwayatSantri($sid, 30) : [];
$sisaTotal = 0;
foreach ($tagihan as $t) { $sisaTotal += max(0, (int) $t['nominal'] - (int) $t['dibayar']); }
ob_start(); ?>
<div class="page-h"><div><h1>Pembayaran</h1><p>Cari santri, lalu catat pembayaran pada tagihan yang dipilih.</p></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="bayar">
    <input name="q" placeholder="Cari nama santri, NIS, atau wali..." value="<?= e($q) ?>" autofocus style="flex:1;min-width:240px">
    <button class="btn"><?= icon('search') ?> Cari</button>
  </form>
  <?php if ($q !== ''): ?>
    <ul class="list">
      <?php foreach ($hasil as $r): ?>
        <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?> &middot; wali: <?= e($r['nama_wali']) ?></small></div><a class="btn sm" href="?p=bayar&amp;santri=<?= (int) $r['id'] ?>">Pilih <?= icon('arrow-right', 15) ?></a></li>
      <?php endforeach; ?>
      <?php if (!$hasil): ?><li class="mut">Tidak ditemukan.</li><?php endif; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($santri): ?>
<div class="card">
  <div class="who" style="margin-bottom:12px"><?= avatar($santri['nama'], $santri['unit']) ?><div><b style="font-size:1.1rem"><?= e($santri['nama']) ?></b><br><small><?= e($santri['nis']) ?> &middot; <?= e($santri['jenjang']) ?> <?= e(Domain::UNIT[$santri['unit']]) ?> &middot; wali: <?= e($santri['nama_wali']) ?></small></div>
    <div style="margin-left:auto;text-align:right"><small>Total sisa tagihan</small><br><b class="big" style="color:<?= $sisaTotal > 0 ? 'var(--err)' : 'var(--ok)' ?>"><?= rp($sisaTotal) ?></b></div></div>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Tagihan</th><th class="r">Nominal</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th><th>Catat pembayaran</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($tagihan, 0, 24) as $t): $sisa = (int) $t['nominal'] - (int) $t['dibayar']; ?>
      <tr><td data-label="Tagihan"><b><?= e(Spp::label($t)) ?></b></td><td class="r" data-label="Nominal"><?= rp($t['nominal']) ?></td><td class="r" data-label="Dibayar"><?= rp($t['dibayar']) ?></td><td class="r" data-label="Sisa"><?= rp($sisa) ?></td>
        <td data-label="Status"><?= status_tagihan((int) $t['nominal'], (int) $t['dibayar']) ?></td>
        <td><?php if ($sisa > 0): ?>
          <form method="post" class="inline">
            <?= csrf_field() ?><input type="hidden" name="tagihan" value="<?= (int) $t['id'] ?>"><input type="hidden" name="santri" value="<?= $sid ?>">
            <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required aria-label="Tanggal">
            <input name="jumlah" inputmode="numeric" data-rupiah value="<?= $sisa ?>" size="9" required aria-label="Jumlah">
            <select name="metode" aria-label="Metode"><option value="tunai">Tunai</option><option value="transfer">Transfer</option></select>
            <input name="keterangan" placeholder="Keterangan" size="12">
            <button class="btn sm"><?= icon('check', 15) ?> Catat</button>
          </form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$tagihan): ?><tr><td colspan="6"><div class="empty"><?= icon('file-text') ?><div>Belum ada tagihan untuk santri ini.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="card">
  <h2>Riwayat pembayaran</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Kuitansi</th><th>Tanggal</th><th>Untuk</th><th class="r">Jumlah</th><th>Metode</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $r): ?>
      <tr class="<?= $r['dibatalkan'] ? 'void' : '' ?>"><td data-label="Kuitansi"><a href="?p=kuitansi&amp;id=<?= (int) $r['id'] ?>"><?= e($r['no_kuitansi']) ?></a></td>
        <td data-label="Tanggal"><?= e($r['tanggal']) ?></td><td data-label="Untuk"><?= e(Spp::label($r)) ?></td><td class="r" data-label="Jumlah"><?= rp($r['jumlah']) ?></td><td data-label="Metode"><?= e($r['metode']) ?></td>
        <td><?php if ($r['dibatalkan']): ?><small>Batal: <?= e($r['alasan_batal']) ?></small>
          <?php else: ?><form method="post" class="inline" data-confirm="Batalkan pembayaran ini?">
            <?= csrf_field() ?><input type="hidden" name="aksi" value="batal"><input type="hidden" name="pembayaran" value="<?= (int) $r['id'] ?>"><input type="hidden" name="santri" value="<?= $sid ?>">
            <input name="alasan" placeholder="Alasan batal" size="14" required aria-label="Alasan"><button class="btn sm danger">Batalkan</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$riwayat): ?><tr><td colspan="6"><div class="empty">Belum ada pembayaran.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif;
render('Pembayaran', ob_get_clean());
