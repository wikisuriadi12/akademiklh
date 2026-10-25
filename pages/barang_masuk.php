<?php
Auth::require('gudang.edit');
$unit = isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : Pos::GUDANG;
$barang = Db::all('SELECT id, kode, nama, satuan, harga_beli FROM barang WHERE aktif = 1 ORDER BY nama');
$pemasokList = Gudang::daftarPemasok();
if (is_post()) {
    csrf_check();
    $lok = isset(Pos::LOKASI[in_str('lok')]) ? in_str('lok') : Pos::GUDANG;
    $ids = (array) ($_POST['barang_id'] ?? []); $qs = (array) ($_POST['qty'] ?? []); $hs = (array) ($_POST['harga_beli'] ?? []);
    $baris = [];
    foreach ($ids as $i => $bid) {
        $baris[] = [(int) $bid, (int) preg_replace('/\D/', '', (string) ($qs[$i] ?? '0')), (int) preg_replace('/\D/', '', (string) ($hs[$i] ?? '0'))];
    }
    try {
        $faktur = in_str('faktur');
        $n = Gudang::masukBanyak($lok, in_str('pemasok'), trim(($faktur !== '' ? 'Faktur ' . $faktur . '. ' : '') . in_str('catatan')), $baris, Auth::id());
        flash($n . ' item masuk ke stok ' . Pos::LOKASI[$lok] . ' dari ' . trim(in_str('pemasok')) . '.');
        redirect('?p=barang_masuk&lok=' . $lok);
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); $unit = $lok; }
}
$opsi = '<option value="">- pilih barang -</option>';
foreach ($barang as $b) { $opsi .= '<option value="' . (int) $b['id'] . '" data-hb="' . (int) $b['harga_beli'] . '">' . e($b['nama'] . ' (' . $b['kode'] . ')') . '</option>'; }
$terakhir = Gudang::laporanMasuk(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), '', '');
ob_start(); ?>
<div class="page-h"><div><h1>Barang Masuk</h1><p>Catat barang datang dari supplier. Stok langsung bertambah di lokasi yang dipilih.</p></div>
  <div class="acts"><a class="btn ghost" href="?p=laporan_pos&amp;tab=masuk">Laporan barang masuk</a><a class="btn ghost" href="?p=barang">Barang baru</a></div></div>
<form method="post" class="card form" id="bm"><?= csrf_field() ?>
  <div class="grid g3">
    <label>Masuk ke <select name="lok"><?= options(Pos::LOKASI, $unit) ?></select></label>
    <label>Nama supplier <input name="pemasok" list="dl-pemasok" required maxlength="100" value="<?= e(in_str('pemasok')) ?>" placeholder="Ketik atau pilih supplier"></label>
    <label>No. faktur / surat jalan <input name="faktur" maxlength="60" placeholder="Opsional" value="<?= e(in_str('faktur')) ?>"></label>
  </div>
  <datalist id="dl-pemasok"><?php foreach ($pemasokList as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
  <div class="scroll"><table class="rt" id="bm-t">
    <thead><tr><th>Barang</th><th class="r">Jumlah</th><th class="r">Harga beli satuan</th></tr></thead>
    <tbody></tbody></table></div>
  <div class="acts" style="margin:8px 0"><button type="button" class="btn ghost sm" id="bm-tambah"><?= icon('plus') ?> Tambah baris</button></div>
  <label>Catatan <input name="catatan" maxlength="200" placeholder="Opsional"></label>
  <div class="acts"><button class="btn"><?= icon('check') ?> Simpan barang masuk</button></div>
</form>
<div class="card" style="margin-top:16px"><h2>Barang masuk 30 hari terakhir</h2>
  <div class="scroll"><table class="rt"><thead><tr><th>Waktu</th><th>Supplier</th><th>Barang</th><th>Lokasi</th><th class="r">Jumlah</th><th class="r">Harga beli</th></tr></thead><tbody>
  <?php foreach (array_slice($terakhir, 0, 30) as $r): ?>
    <tr><td><?= e(substr($r['created_at'], 0, 16)) ?></td><td data-label="Supplier"><?= e($r['pemasok'] ?: '-') ?></td><td data-label="Barang"><b><?= e($r['nama']) ?></b></td><td data-label="Lokasi"><?= e(Pos::LOKASI[$r['unit']] ?? $r['unit']) ?></td><td class="r" data-label="Jumlah"><?= (int) $r['qty'] ?> <?= e($r['satuan']) ?></td><td class="r" data-label="Harga"><?= rp($r['harga_beli']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$terakhir): ?><tr><td colspan="6" class="empty">Belum ada barang masuk.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<script>
(function () {
  var opsi = <?= json_encode($opsi) ?>, tb = document.querySelector('#bm-t tbody');
  function baris() {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><select name="barang_id[]">' + opsi + '</select></td><td class="r"><input name="qty[]" inputmode="numeric" style="width:90px;text-align:right"></td><td class="r"><input name="harga_beli[]" inputmode="numeric" style="width:120px;text-align:right" placeholder="otomatis"></td>';
    tr.querySelector('select').addEventListener('change', function () {
      var o = this.options[this.selectedIndex], h = tr.querySelector('[name="harga_beli[]"]');
      if (o && o.dataset.hb && !h.value) { h.value = o.dataset.hb; }
    });
    tb.appendChild(tr);
  }
  for (var i = 0; i < 5; i++) { baris(); }
  document.getElementById('bm-tambah').addEventListener('click', baris);
})();
</script>
<?php render('Barang Masuk', ob_get_clean());
