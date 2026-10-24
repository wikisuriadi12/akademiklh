<?php
Auth::require('gudang.view');
$scope = Auth::scope();
$unit = $scope ?? (in_array(in_str('unit'), ['ikhwan', 'akhwat'], true) ? in_str('unit') : 'ikhwan');
$q = in_str('q');
$rendah = in_str('rendah') === '1';
$edit = Auth::can('gudang.edit');
$rows = Gudang::daftar($unit, $q, $rendah);
$nilai = Gudang::nilaiPersediaan($unit);
$nRendah = Gudang::jumlahRendah($unit);
$jenis = count(Gudang::daftar($unit, '', false, true));
ob_start(); ?>
<div class="page-h"><div><h1>Stok Gudang</h1><p>Persediaan koperasi pondok <?= e(Domain::UNIT[$unit]) ?>. Stok berkurang otomatis saat kasir menjual.</p></div>
  <?php if ($edit): ?><a class="btn" href="?p=barang"><?= icon('plus') ?> Barang baru</a><?php endif; ?></div>
<div class="kpis">
  <div class="kpi"><div class="chip"><?= icon('box', 22) ?></div><div><span>Jenis barang aktif</span><b><?= (int) $jenis ?></b></div></div>
  <div class="kpi"><div class="chip c-acc"><?= icon('alert', 22) ?></div><div><span>Stok menipis / habis</span><b><?= (int) $nRendah ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Nilai persediaan (modal)</span><b><?= rp($nilai) ?></b></div></div>
</div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="gudang">
    <?php if ($scope === null): ?><select name="unit"><?= options(Domain::UNIT, $unit) ?></select><?php endif; ?>
    <input name="q" placeholder="Cari nama, kode, kategori..." value="<?= e($q) ?>">
    <label class="chk"><input type="checkbox" name="rendah" value="1" <?= $rendah ? 'checked' : '' ?>> Hanya stok menipis</label>
    <button class="btn"><?= icon('search') ?> Tampilkan</button>
  </form>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Barang</th><th>Kategori</th><th class="r">Harga jual</th><th class="r">Stok</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b):
        $habis = (int) $b['qty'] <= 0; $tipis = (int) $b['qty'] <= (int) $b['stok_min']; ?>
      <tr>
        <td><b><?= e($b['nama']) ?></b><br><small><?= e($b['kode']) ?></small></td>
        <td data-label="Kategori"><?= e($b['kategori'] ?: '-') ?></td>
        <td class="r" data-label="Harga jual"><?= rp($b['harga_jual']) ?></td>
        <td class="r" data-label="Stok"><b><?= (int) $b['qty'] ?></b> <?= e($b['satuan']) ?></td>
        <td data-label="Status"><?php if (!(int) $b['aktif']): ?><span class="badge">Nonaktif</span><?php elseif ($habis): ?><span class="badge b-belum">Habis</span><?php elseif ($tipis): ?><span class="badge b-sebagian">Menipis</span><?php else: ?><span class="badge b-lunas">Aman</span><?php endif; ?></td>
        <td class="r"><a class="btn sm ghost" href="?p=gudang_item&amp;id=<?= (int) $b['id'] ?>&amp;unit=<?= e($unit) ?>"><?= $edit ? 'Kelola' : 'Detail' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada barang<?= $edit ? '. Klik "Barang baru" untuk menambah.' : '.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render('Stok Gudang', ob_get_clean());
