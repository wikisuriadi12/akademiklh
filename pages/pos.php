<?php
if (Auth::user() && !Auth::can('pos')) { redirect(Auth::can('gudang.view') ? '?p=gudang' : '?p=penjualan'); }
Auth::require('pos');
$scope = Auth::scope();
$unit = $scope ?? (in_array(in_str('unit'), ['ikhwan', 'akhwat'], true) ? in_str('unit') : 'ikhwan');
$sid = (int) in_str('santri', '0');
$santri = $sid ? Santri::find($sid) : null;
if ($santri && $santri['unit'] !== $unit) { $santri = null; $sid = 0; }

if (is_post()) {
    csrf_check();
    $unit = in_array(in_str('unit'), ['ikhwan', 'akhwat'], true) ? in_str('unit') : $unit;
    $sidP = (int) in_str('santri_id', '0');
    try {
        $items = [];
        foreach ((array) ($_POST['qty'] ?? []) as $bid => $q) { $items[(int) $bid] = (int) $q; }
        $dibayar = (int) preg_replace('/\D/', '', in_str('dibayar', '0'));
        $id = Pos::jual($unit, $sidP ?: null, $items, in_str('metode'), $dibayar, Auth::id(), $scope);
        flash('Penjualan tersimpan.');
        redirect('?p=struk&id=' . $id);
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('?p=pos&unit=' . $unit . ($sidP ? '&santri=' . $sidP : ''));
    }
}
$q = in_str('q');
$hasil = [];
if ($q !== '') { [$hasil] = Santri::daftar(['q' => $q, 'unit' => $unit], $scope, 1); }
$barang = Gudang::daftar($unit, in_str('cari'), false, true);
$saldo = $santri ? Pos::saldo((int) $santri['id']) : 0;
$kat = [];
foreach ($barang as $b) { $kat[$b['kategori'] ?: 'Lainnya'][] = $b; }
ob_start(); ?>
<div class="page-h"><div><h1>Kasir (POS)</h1><p>Koperasi pondok <?= e(Domain::UNIT[$unit]) ?>. Stok gudang berkurang otomatis.</p></div>
  <?php if ($scope === null): ?><div class="tabs" style="margin:0"><?php foreach (Domain::UNIT as $k => $v): ?><a class="<?= $unit === $k ? 'on' : '' ?>" href="?p=pos&amp;unit=<?= $k ?>"><?= e($v) ?></a><?php endforeach; ?></div><?php endif; ?></div>

<div class="card">
  <h2>Pembeli</h2>
  <?php if ($santri): ?>
    <div class="who"><?= avatar($santri['nama'], $santri['unit']) ?><span><b><?= e($santri['nama']) ?></b><small><?= e($santri['nis']) ?> &middot; <?= e($santri['jenjang']) ?> <?= e($santri['kelas'] ?: '') ?></small></span>
      <span style="margin-left:auto;text-align:right"><small>Saldo dompet</small><br><b style="font-size:1.2rem"><?= rp($saldo) ?></b></span>
      <a class="btn sm ghost" href="?p=pos&amp;unit=<?= e($unit) ?>">Ganti</a></div>
  <?php else: ?>
    <form method="get" class="filters"><input type="hidden" name="p" value="pos"><input type="hidden" name="unit" value="<?= e($unit) ?>">
      <input name="q" placeholder="Cari santri untuk bayar dengan dompet (opsional)..." value="<?= e($q) ?>" style="flex:1;min-width:220px"><button class="btn ghost"><?= icon('search') ?> Cari</button></form>
    <?php if ($q !== ''): ?><ul class="list"><?php foreach ($hasil as $r): ?>
      <li><?= avatar($r['nama'], $r['unit']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; saldo <?= rp(Pos::saldo((int) $r['id'])) ?></small></div><a class="btn sm" href="?p=pos&amp;unit=<?= e($unit) ?>&amp;santri=<?= (int) $r['id'] ?>">Pilih</a></li>
    <?php endforeach; ?><?php if (!$hasil): ?><li class="mut">Tidak ditemukan.</li><?php endif; ?></ul><?php endif; ?>
    <small class="mut">Tanpa memilih santri, pembayaran hanya tunai.</small>
  <?php endif; ?>
</div>

<form method="post" data-pos autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="unit" value="<?= e($unit) ?>"><input type="hidden" name="santri_id" value="<?= (int) $sid ?>">
  <?php foreach ($kat as $nama => $list): ?>
  <div class="card"><h2><?= e($nama) ?></h2>
    <div class="pos-grid">
    <?php foreach ($list as $b): $habis = (int) $b['qty'] <= 0; ?>
      <div class="pos-item <?= $habis ? 'off' : '' ?>">
        <div><b><?= e($b['nama']) ?></b><br><small><?= rp($b['harga_jual']) ?> &middot; stok <?= (int) $b['qty'] ?></small></div>
        <div class="step"><button type="button" data-step="-1" <?= $habis ? 'disabled' : '' ?> aria-label="Kurangi">&minus;</button>
          <input type="number" min="0" max="<?= (int) $b['qty'] ?>" value="0" name="qty[<?= (int) $b['id'] ?>]" data-harga="<?= (int) $b['harga_jual'] ?>" <?= $habis ? 'disabled' : '' ?>>
          <button type="button" data-step="1" <?= $habis ? 'disabled' : '' ?> aria-label="Tambah">+</button></div>
      </div>
    <?php endforeach; ?>
    </div></div>
  <?php endforeach; ?>
  <?php if (!$barang): ?><div class="card"><div class="empty">Belum ada barang aktif di unit ini. Tambahkan lewat menu Stok Gudang.</div></div><?php endif; ?>

  <div class="card pos-bar">
    <div><small>Total belanja</small><br><b class="pos-total" data-total>Rp 0</b></div>
    <label>Metode <select name="metode" data-metode>
      <option value="tunai">Tunai</option>
      <?php if ($santri): ?><option value="dompet">Dompet santri (saldo <?= rp($saldo) ?>)</option><?php endif; ?>
    </select></label>
    <label data-tunai>Uang diterima <input name="dibayar" data-rupiah inputmode="numeric" placeholder="0"></label>
    <div data-tunai><small>Kembalian</small><br><b data-kembali>Rp 0</b></div>
    <button class="btn" <?= $barang ? '' : 'disabled' ?>><?= icon('check') ?> Bayar &amp; cetak struk</button>
  </div>
</form>
<?php render('Kasir (POS)', ob_get_clean());
