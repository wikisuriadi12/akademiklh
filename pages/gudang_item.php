<?php
Auth::require('gudang.view');
$scope = null;
$edit = Auth::can('gudang.edit');
$id = (int) in_str('id', '0');
$b = Gudang::find($id);
if (!$b) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Barang tidak ditemukan.</div></div>'); exit; }
$unit = isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : Pos::GUDANG;
$num = static fn(string $k): int => (int) preg_replace('/\D/', '', in_str($k, '0'));
if (is_post() && $edit) {
    csrf_check();
    try {
        switch (in_str('aksi')) {
            case 'masuk':
                Gudang::masuk($id, $unit, $num('qty'), $num('harga_beli'), in_str('pemasok'), in_str('catatan'), Auth::id(), $scope);
                flash('Barang masuk dicatat.'); break;
            case 'keluar':
                Gudang::keluar($id, $unit, $num('qty'), in_str('catatan'), Auth::id(), $scope);
                flash('Pengurangan stok dicatat.', 'warn'); break;
            case 'opname':
                Gudang::opname($id, $unit, $num('fisik'), in_str('catatan'), Auth::id(), $scope);
                flash('Stok disesuaikan dengan hasil hitung fisik.'); break;
            case 'transfer':
                Gudang::transfer($id, in_str('dari'), in_str('ke'), $num('qty'), in_str('catatan'), Auth::id());
                flash('Stok dipindahkan.'); break;
            case 'aktif':
                Gudang::setAktif($id, in_str('aktif') === '1', Auth::id());
                flash('Status barang diperbarui.'); break;
        }
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=gudang_item&id=' . $id . '&lok=' . $unit);
}
$stok = Gudang::stok($id, $unit);
$riwayat = Gudang::riwayat($id, $scope, 30);
$margin = (int) $b['harga_jual'] - (int) $b['harga_beli'];
ob_start(); ?>
<div class="page-h"><div><h1><?= e($b['nama']) ?></h1><p><?= e($b['kode']) ?> &middot; <?= e($b['kategori'] ?: 'Tanpa kategori') ?> &middot; <?= (int) $b['aktif'] ? 'Aktif' : 'Nonaktif' ?></p></div>
  <div class="acts"><?php if ($edit): ?><a class="btn ghost" href="?p=barang&amp;id=<?= $id ?>"><?= icon('edit') ?> Ubah data</a><?php endif; ?><a class="btn ghost" href="?p=gudang&amp;lok=<?= e($unit) ?>">Kembali</a></div></div>
<div class="tabs"><?php foreach (Pos::LOKASI as $k => $v): ?><a class="<?= $unit === $k ? 'on' : '' ?>" href="?p=gudang_item&amp;id=<?= $id ?>&amp;lok=<?= e($k) ?>">Stok <?= e($v) ?></a><?php endforeach; ?></div>
<div class="kpis">
  <div class="kpi"><div class="chip"><?= icon('box', 22) ?></div><div><span>Stok <?= e(Pos::LOKASI[$unit]) ?></span><b><?= $stok ?> <?= e($b['satuan']) ?></b></div></div>
  <div class="kpi"><div class="chip c-info"><?= icon('tag', 22) ?></div><div><span>Harga jual / beli</span><b><?= rp($b['harga_jual']) ?></b><small class="mut">modal <?= rp($b['harga_beli']) ?></small></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('trending-up', 22) ?></div><div><span>Laba per satuan</span><b><?= rp($margin) ?></b></div></div>
</div>
<?php if ($edit): ?>
<form method="post" class="card form" style="margin-bottom:16px"><h2>Transfer stok</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="transfer"><input type="hidden" name="lok" value="<?= e($unit) ?>">
  <p class="mut" style="margin:0 0 10px">Stok Gudang <b><?= Gudang::stok($id, Pos::GUDANG) ?></b> &middot; Stok Market <b><?= Gudang::stok($id, Pos::TOKO) ?></b>. Barang baru tampil di kasir setelah dipindahkan ke Market.</p>
  <label>Dari <select name="dari"><?= options(Pos::LOKASI, Pos::GUDANG) ?></select></label>
  <label>Ke <select name="ke"><?= options(Pos::LOKASI, Pos::TOKO) ?></select></label>
  <label>Jumlah <input name="qty" inputmode="numeric" required></label>
  <label>Catatan <input name="catatan" placeholder="Opsional"></label>
  <button class="btn"><?= icon('arrow-right') ?> Pindahkan</button></form>
<div class="grid g3">
  <form method="post" class="card form"><h2>Barang masuk</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="masuk"><input type="hidden" name="lok" value="<?= e($unit) ?>">
    <label>Jumlah <input name="qty" inputmode="numeric" required></label>
    <label>Harga beli satuan <input name="harga_beli" data-rupiah inputmode="numeric" value="<?= (int) $b['harga_beli'] ?>"></label>
    <label>Pemasok <input name="pemasok" placeholder="Toko / agen"></label>
    <label>Catatan <input name="catatan"></label>
    <button class="btn"><?= icon('plus') ?> Catat masuk</button></form>
  <form method="post" class="card form"><h2>Opname (hitung fisik)</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="opname"><input type="hidden" name="lok" value="<?= e($unit) ?>">
    <label>Stok fisik sebenarnya <input name="fisik" inputmode="numeric" required value="<?= $stok ?>"></label>
    <label>Catatan <input name="catatan" placeholder="Opname akhir bulan"></label>
    <button class="btn ghost" data-confirm="Samakan stok sistem dengan hitungan fisik?"><?= icon('check') ?> Sesuaikan stok</button></form>
  <form method="post" class="card form"><h2>Keluar (rusak/hilang)</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="keluar"><input type="hidden" name="lok" value="<?= e($unit) ?>">
    <label>Jumlah <input name="qty" inputmode="numeric" required></label>
    <label>Alasan <input name="catatan" required placeholder="Rusak, kadaluarsa, hilang"></label>
    <button class="btn danger" data-confirm="Kurangi stok?"><?= icon('x') ?> Kurangi stok</button></form>
</div>
<form method="post" class="inline" style="margin:0 0 16px"><?= csrf_field() ?><input type="hidden" name="aksi" value="aktif"><input type="hidden" name="lok" value="<?= e($unit) ?>"><input type="hidden" name="aktif" value="<?= (int) $b['aktif'] ? 0 : 1 ?>">
  <button class="btn sm ghost"><?= (int) $b['aktif'] ? 'Nonaktifkan barang (tidak muncul di kasir)' : 'Aktifkan kembali' ?></button></form>
<?php endif; ?>
<div class="card"><h2>Riwayat stok</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Waktu</th><th>Jenis</th><th>Unit</th><th class="r">Jumlah</th><th class="r">Stok akhir</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $m): ?>
      <tr><td><?= e(substr($m['created_at'], 0, 16)) ?></td><td data-label="Jenis"><?= e(Gudang::TIPE[$m['tipe']] ?? $m['tipe']) ?></td><td data-label="Unit"><?= e(Domain::UNIT[$m['unit']]) ?></td>
        <td class="r" data-label="Jumlah"><b><?= ((int) $m['qty'] > 0 ? '+' : '') . (int) $m['qty'] ?></b></td><td class="r" data-label="Stok akhir"><?= (int) $m['stok_setelah'] ?></td>
        <td data-label="Catatan"><?= e(trim($m['pemasok'] . ' ' . $m['catatan']) ?: '-') ?><?= $m['petugas'] ? ' <small class="mut">&middot; ' . e($m['petugas']) . '</small>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$riwayat): ?><tr><td colspan="6" class="empty">Belum ada pergerakan stok.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render($b['nama'], ob_get_clean());
