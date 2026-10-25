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
if (is_post() && in_str('aksi') === 'ambil' && $edit) {
    csrf_check();
    $ids = in_str('semua') === '1' ? array_column(Gudang::belumDiMarket(), 'id') : (array) ($_POST['ids'] ?? []);
    $n = Gudang::daftarkanBanyak($ids, Auth::id());
    flash($n > 0 ? $n . ' item diambil ke Stok Market. Isi stoknya lewat Barang masuk atau Transfer dari Gudang.' : 'Tidak ada item yang dipilih.', $n > 0 ? 'ok' : 'warn');
    redirect('?p=gudang&lok=ikhwan');
}
if (is_post() && in_str('aksi') === 'input_market' && $edit) {
    csrf_check();
    try {
        $bid = (int) in_str('id', '0'); $qty = (int) preg_replace('/\D/', '', in_str('qty', '0'));
        Gudang::masuk($bid, Pos::TOKO, $qty, 0, '', 'Input stok Market', Auth::id(), null);
        flash($qty . ' pcs stok Market ditambahkan.');
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=gudang&lok=ikhwan');
}
if (is_post() && in_str('aksi') === 'tarik' && $edit) {
    csrf_check();
    try {
        $bid = (int) in_str('id', '0'); $qty = (int) preg_replace('/\D/', '', in_str('qty', '0'));
        Gudang::transfer($bid, Pos::GUDANG, Pos::TOKO, $qty, 'Tarik dari Gudang', Auth::id());
        flash($qty . ' pcs ditarik dari Gudang ke Market. Barang siap dijual di kasir.');
    } catch (InvalidArgumentException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('?p=gudang&lok=ikhwan');
}
$belum = $edit && $unit === Pos::TOKO ? Gudang::belumDiMarket() : [];
$sisaAkhwat = (int) Db::val('SELECT COUNT(*) FROM stok WHERE unit = ? AND qty > 0', ['akhwat']);
$onlyMarket = $unit === Pos::TOKO;
$rows = Gudang::daftar($unit, $q, $rendah, false, $onlyMarket);
$nilai = Gudang::nilaiPersediaan($unit);
$nRendah = Gudang::jumlahRendah($unit);
$jenis = count(Gudang::daftar($unit, '', false, true, $onlyMarket));
ob_start(); ?>
<div class="page-h"><div><h1>Stok <?= e(Pos::LOKASI[$unit]) ?></h1><p><?= $onlyMarket ? 'Barang yang tampil di kasir. Stok berkurang otomatis saat kasir menjual.' : 'Persediaan di gudang. Pindahkan ke Market lewat Transfer agar barang muncul di kasir.' ?></p></div>
  <div style="display:flex;gap:8px"><a class="btn ghost" href="?p=label"><?= icon('tag') ?> Cetak label</a>
  <?php if ($edit): ?><a class="btn ghost" href="?p=impor_stok"><?= icon('upload') ?> Impor Excel</a><?php endif; ?>
  <?php if ($edit): ?><a class="btn" href="?p=barang"><?= icon('plus') ?> Barang baru</a><?php endif; ?></div></div>
<?php if ($edit && $sisaAkhwat > 0): ?>
<div class="card" style="margin-bottom:16px"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="gabung">
  <b>Ada stok lama di unit Akhwat (<?= $sisaAkhwat ?> barang).</b> Stok itu belum terhitung di Gudang maupun Market.
  <button class="btn sm" data-confirm="Pindahkan semua stok unit Akhwat ke Gudang?">Pindahkan ke Gudang</button></form></div>
<?php endif; ?>
<div class="tabs" style="margin-bottom:12px"><?php foreach (Pos::LOKASI as $k => $v): ?><a class="<?= $unit === $k ? 'on' : '' ?>" href="?p=gudang&amp;lok=<?= e($k) ?>">Stok <?= e($v) ?></a><?php endforeach; ?></div>
<?php if ($belum): ?>
<div class="card" style="margin-bottom:16px"><h2>Ambil item dari Master</h2>
  <p class="mut" style="margin:0 0 10px">Master barang hanya satu. Centang item yang mau dijual di Market, lalu klik Ambil. Stok awalnya 0.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="ambil">
    <div class="scroll" style="max-height:240px;overflow:auto;margin-bottom:10px">
    <?php foreach ($belum as $x): ?><label class="chk" style="display:block;padding:4px 0"><input type="checkbox" name="ids[]" value="<?= (int) $x['id'] ?>"> <b><?= e($x['nama']) ?></b> <small class="mut"><?= e($x['kode']) ?> &middot; <?= rp($x['harga_jual']) ?></small></label><?php endforeach; ?>
    </div>
    <button class="btn"><?= icon('plus') ?> Ambil yang dicentang</button>
    <button class="btn ghost" name="semua" value="1" data-confirm="Ambil semua <?= count($belum) ?> item ke Stok Market?">Ambil semua (<?= count($belum) ?>)</button>
  </form></div>
<?php endif; ?>
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
    <thead><tr><th>Barang</th><th>Kategori</th><th class="r">Harga jual</th><th class="r">Stok</th><?php if ($onlyMarket && $edit): ?><th>Input stok Market</th><th class="r">Stok Gudang</th><th>Tarik dari Gudang</th><?php endif; ?><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b):
        $habis = (int) $b['qty'] <= 0; $tipis = (int) $b['qty'] <= (int) $b['stok_min']; ?>
      <tr>
        <td><b><?= e($b['nama']) ?></b><br><small><?= e($b['kode']) ?></small></td>
        <td data-label="Kategori"><?= e($b['kategori'] ?: '-') ?></td>
        <td class="r" data-label="Harga jual"><?= rp($b['harga_jual']) ?></td>
        <td class="r" data-label="Stok"><b><?= (int) $b['qty'] ?></b> <?= e($b['satuan']) ?></td>
        <?php if ($onlyMarket && $edit): $sg = Gudang::stok((int) $b['id'], Pos::GUDANG); ?>
        <td data-label="Input stok"><form method="post" class="inline" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="aksi" value="input_market"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input name="qty" inputmode="numeric" placeholder="+ Jumlah" style="width:84px" required><button class="btn sm">Tambah</button></form></td>
        <td class="r" data-label="Stok Gudang"><b><?= $sg ?></b></td>
        <td data-label="Tarik"><?php if ($sg > 0): ?><form method="post" class="inline" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="aksi" value="tarik"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input name="qty" inputmode="numeric" placeholder="Jumlah" style="width:84px" required><button class="btn sm">Tarik</button></form><?php else: ?><small class="mut">Gudang kosong</small><?php endif; ?></td>
        <?php endif; ?>
        <td data-label="Status"><?php if (!(int) $b['aktif']): ?><span class="badge">Nonaktif</span><?php elseif ($habis): ?><span class="badge b-belum">Habis</span><?php elseif ($tipis): ?><span class="badge b-sebagian">Menipis</span><?php else: ?><span class="badge b-lunas">Aman</span><?php endif; ?></td>
        <td class="r"><a class="btn sm ghost" href="?p=gudang_item&amp;id=<?= (int) $b['id'] ?>&amp;lok=<?= e($unit) ?>"><?= $edit ? 'Kelola' : 'Detail' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Belum ada barang<?= $edit ? '. Klik "Barang baru" untuk menambah.' : '.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php render('Stok Gudang', ob_get_clean());
