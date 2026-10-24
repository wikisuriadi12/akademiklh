<?php
Auth::require('va.edit');
$scope = Auth::scope();
$sid = (int) in_str('santri', '0');
if (is_post()) {
    csrf_check();
    $sid = (int) in_str('santri_id', '0');
    try {
        $ids = array_map('intval', (array) ($_POST['tagihan'] ?? []));
        if (!$ids) { throw new InvalidArgumentException('Pilih minimal satu tagihan.'); }
        $id = Va::buat($sid, $ids, Auth::id(), $scope);
        flash('VA dibuat. Kirim nomor VA ke wali.');
        redirect('?p=va_detail&id=' . $id);
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('?p=va_baru&santri=' . $sid);
    }
}
$q = in_str('q');
$hasil = [];
if ($q !== '') { [$hasil] = Santri::daftar(['q' => $q], $scope, 1); }
$santri = $sid ? Santri::find($sid) : null;
if ($santri) { Auth::assertUnit($santri['unit']); }
$tagihan = $santri ? array_values(array_filter(Spp::tagihanSantri($sid), static fn($t) => (int) $t['nominal'] > (int) $t['dibayar'])) : [];
$aktif = $santri ? Va::aktifUntuk($sid) : null;
ob_start(); ?>
<div class="page-h"><div><h1>Buat Virtual Account</h1><p>Pilih santri dan tagihan yang akan dibayar lewat BNI. Nomor VA berisi awalan BNI + NIS santri.</p></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="va_baru">
    <input name="q" placeholder="Cari nama santri, NIS, atau wali..." value="<?= e($q) ?>" autofocus style="flex:1;min-width:240px"><button class="btn"><?= icon('search') ?> Cari</button></form>
  <?php if ($q !== ''): ?><ul class="list"><?php foreach ($hasil as $r): ?>
    <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?> <?= e(Domain::UNIT[$r['unit']]) ?></small></div><a class="btn sm" href="?p=va_baru&amp;santri=<?= (int) $r['id'] ?>">Pilih <?= icon('arrow-right', 15) ?></a></li>
  <?php endforeach; ?><?php if (!$hasil): ?><li class="mut">Tidak ditemukan.</li><?php endif; ?></ul><?php endif; ?>
</div>
<?php if ($santri): ?>
<div class="card">
  <div class="who"><?= avatar($santri['nama'], $santri['unit']) ?><span><b><?= e($santri['nama']) ?></b><small><?= e($santri['nis']) ?> &middot; VA: <code><?= e(Bni::nomorVa($santri['nis'])) ?></code></small></span></div>
  <?php if ($aktif): ?>
    <div class="note warn" style="margin-top:12px">Masih ada VA aktif (<?= rp($aktif['jumlah']) ?>, sampai <?= e(substr($aktif['expired_at'], 0, 16)) ?>). <a href="?p=va_detail&amp;id=<?= (int) $aktif['id'] ?>">Buka VA tersebut</a>.</div>
  <?php elseif (!$tagihan): ?>
    <div class="empty">Tidak ada tagihan yang belum lunas.</div>
  <?php else: ?>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="santri_id" value="<?= $sid ?>">
    <div class="scroll"><table class="rt"><thead><tr><th style="width:36px"><input type="checkbox" data-check-all=".pilih" checked aria-label="Pilih semua"></th><th>Tagihan</th><th class="r">Sisa</th></tr></thead><tbody>
    <?php foreach ($tagihan as $t): ?>
      <tr><td><input type="checkbox" class="pilih" name="tagihan[]" value="<?= (int) $t['id'] ?>" checked></td><td data-label="Tagihan"><b><?= e(Spp::label($t)) ?></b></td><td class="r" data-label="Sisa"><?= rp($t['nominal'] - $t['dibayar']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="mut" style="font-size:.85rem">VA berlaku <?= (int) Settings::get('va_jam') ?> jam dan hanya bisa dibayar sesuai total yang dipilih (nominal pas).</p>
    <button class="btn"><?= icon('check') ?> Buat VA BNI</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php render('Buat VA', ob_get_clean());
