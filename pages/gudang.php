<?php
Auth::require('gudang.view');
$scope = null;
$unit = isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : Pos::GUDANG;
$q = in_str('q');
$rendah = in_str('rendah') === '1';
$edit = Auth::can('gudang.edit');
if (is_post() && in_str('aksi') === 'gabung' && $edit) {
    csrf_check();
    $n = Gudang::gabungAkhwat(Auth::id());
    flash($n . ' barang dipindah dari stok lama (Akhwat) ke Gudang.');
    redirect('?p=gudang&lok=gudang');
}
$sisaAkhwat = (int) Db::val('SELECT COUNT(*) FROM stok WHERE unit = ? AND qty > 0', ['akhwat']);
$onlyMarket = $unit === Pos::TOKO;
$rows = Gudang::daftar($unit, $q, $rendah, false, $onlyMarket);
$nilai = Gudang::nilaiPersediaan($unit);
$nRendah = Gudang::jumlahRendah($unit);
$jenis = count(Gudang::daftar($unit, '', false, true, $onlyMarket));
ob_start(); ?>
<div class="page-h"><div><h1>Stok <?= e(Pos::LOKASI[$unit]) ?></h1><p><?= $onlyMarket ? 'Barang yang tampil di kasir. Stok berkurang otomatis saat kasir menjual.' : 'Persediaan di gudang. Pindahkan ke Market lewat Transfer agar barang muncul di kasir.' ?></p></div>
  <div style="display:flex;gap:8px"><a class="btn ghost" href="?p=label"><?= icon('tag') ?> Cetak label</a>
  <?php if ($edit): ?><a class="btn" href="?p=barang"><?= icon('plus') ?> Barang baru</a><?php endif; ?></div></div>
<?php if ($edit && $sisaAkhwat > 0): ?>
<div class="card" style="margin-bottom:16px"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="gabung">
  <b>Ada stok lama di unit Akhwat (<?= $sisaAkhwat ?> barang).</b> Stok itu belum terhitung di Gudang maupun Market.
  <button class="btn sm" data-confirm="Pindahkan semua stok unit Akhwat ke Gudang?">Pindahkan ke Gudang</button></form></div>
<?php endif; ?>
<div class="tabs" style="margin-bottom:12px"><?php foreach (Pos::LOKASI as $k => $v): ?><a class="<?= $unit === $k ? 'on' : '' ?>" href="?p=gudang&amp;lok=<?= e($k) ?>">Stok <?= e($v) ?></a><?php endforeach; ?></div>
<div class="kpis">
  <div class="kpi"><div class="chip"><?= icon('box', 22) ?></div><div><span>Jenis barang aktif</span><b><?= (int) $jenis ?></b></div></div>
  <div class="kpi"><div class="chip c-acc"><?= icon('alert', 22) ?></div><div><span>Stok menipis / habis</span><b><?= (int) $nRendah ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Nilai persediaan (modal)</span><b><?= rp($nilai) ?></b></div></div>
</div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="gudang"><input type="hidden" name="lok" value="<?= e($unit) ?>">
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
        <td class="r"><a class="btn sm ghost" href="?p=gudang_item&amp;id=<?= (int) $b['id'] ?>&amp;lok=<?= e($unit) ?>"><?= $edit ? 'Kelola' : 'Detail' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada barang<?= $edit ? '. Klik "Barang baru" untuk menambah.' : '.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render('Stok Gudang', ob_get_clean());
