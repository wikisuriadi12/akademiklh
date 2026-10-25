<?php
// Cetak label barang (rak/etalase): ukuran, isi, dan tata letak diatur sendiri.
Auth::require('gudang.view');
$scope = null;
$unit = Pos::TOKO;
$bawaan = ['mode' => 'roll', 'w' => 40, 'h' => 30, 'kol' => 3, 'gap' => 2, 'toko' => 'LH Mart', 'tToko' => 1, 'tNama' => 1, 'tHarga' => 1, 'tKode' => 1, 'tBarcode' => 1, 'fNama' => 9, 'fHarga' => 12, 'rata' => 'center'];
$batas = ['w' => [15, 210], 'h' => [10, 297], 'kol' => [1, 8], 'gap' => [0, 20], 'fNama' => [5, 24], 'fHarga' => [6, 36]];

if (is_post() && in_str('aksi') === 'simpan_cfg') {
    csrf_check();
    if (!Auth::can('gudang.edit')) { http_response_code(403); exit('Akses ditolak'); }
    $in = json_decode(in_str('cfg'), true);
    $out = $bawaan;
    if (is_array($in)) {
        foreach ($batas as $k => [$lo, $hi]) { if (isset($in[$k])) { $out[$k] = max($lo, min($hi, (float) $in[$k])); } }
        foreach (['tToko', 'tNama', 'tHarga', 'tKode', 'tBarcode'] as $k) { if (isset($in[$k])) { $out[$k] = (int) (bool) $in[$k]; } }
        if (isset($in['mode'])) { $out['mode'] = $in['mode'] === 'a4' ? 'a4' : 'roll'; }
        if (isset($in['rata']) && in_array($in['rata'], ['left', 'center', 'right'], true)) { $out['rata'] = $in['rata']; }
        if (isset($in['toko'])) { $out['toko'] = mb_substr(trim((string) $in['toko']), 0, 40); }
    }
    Settings::set('label_cfg', json_encode($out, JSON_UNESCAPED_UNICODE));
    Db::audit(Auth::id(), 'ubah', 'pengaturan', null, 'label barang');
    flash('Pengaturan label disimpan sebagai bawaan.');
    redirect('?p=label&unit=' . $unit);
}

$tersimpan = json_decode(Settings::get('label_cfg', ''), true);
$cfg = is_array($tersimpan) ? array_merge($bawaan, array_intersect_key($tersimpan, $bawaan)) : $bawaan;
$barang = [];
foreach (Gudang::daftarMarket() as $b) {
    $barang[] = ['id' => (int) $b['id'], 'kode' => (string) $b['kode'], 'nama' => (string) $b['nama'], 'harga' => (int) $b['harga_jual'], 'qty' => (int) $b['qty']];
}
$json = json_encode(['cfg' => $cfg, 'items' => $barang], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
ob_start(); ?>
<link rel="stylesheet" href="assets/label.css?v=1">
<style id="lb-ukuran"></style>
<div class="page-h"><div><h1>Cetak Label</h1><p>Atur ukuran dan isi label, pilih barang dan jumlahnya, lalu cetak. Barcode diambil dari kode barang.</p></div></div>
<div id="lb" data-cfg="<?= e($json) ?>">
<div class="lb-wrap">
  <div class="card">
    <div class="filters" style="margin-bottom:10px">
      <input id="lb-cari" placeholder="Cari barang..." style="flex:1">
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
      <button type="button" class="btn sm ghost" id="lb-satu">1 label tiap barang</button>
      <button type="button" class="btn sm ghost" id="lb-stok">Sesuai jumlah stok</button>
      <button type="button" class="btn sm ghost" id="lb-nol">Kosongkan</button>
    </div>
    <div class="lb-tbl"><table><thead><tr><th>Barang</th><th>Harga</th><th>Stok</th><th>Jumlah label</th></tr></thead><tbody id="lb-daftar">
      <?php foreach ($barang as $b): ?>
      <tr data-s="<?= e(mb_strtolower($b['nama'] . ' ' . $b['kode'])) ?>"><td><b><?= e($b['nama']) ?></b><br><small class="mut"><?= e($b['kode']) ?></small></td><td><?= rp($b['harga']) ?></td><td><?= (int) $b['qty'] ?></td><td><input type="number" min="0" max="500" value="0" data-id="<?= (int) $b['id'] ?>"></td></tr>
      <?php endforeach; ?>
      <?php if (!$barang): ?><tr><td colspan="4" class="mut">Belum ada barang aktif.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="card" id="lb-set">
    <h2 style="margin-top:0">Pengaturan label</h2>
    <div class="lb-set">
      <label>Kertas <select id="c-mode"><option value="roll">Roll / stiker (satu label per halaman)</option><option value="a4">Lembar A4 (banyak label)</option></select></label>
      <label id="c-kolwrap" hidden>Kolom per baris <input type="number" id="c-kol" min="1" max="8"></label>
      <label>Lebar (mm) <input type="number" id="c-w" min="15" max="210" step="0.5"></label>
      <label>Tinggi (mm) <input type="number" id="c-h" min="10" max="297" step="0.5"></label>
      <label>Jarak antar label A4 (mm) <input type="number" id="c-gap" min="0" max="20" step="0.5"></label>
      <label>Rata teks <select id="c-rata"><option value="left">Kiri</option><option value="center">Tengah</option><option value="right">Kanan</option></select></label>
      <label>Ukuran huruf nama (pt) <input type="number" id="c-fNama" min="5" max="24" step="0.5"></label>
      <label>Ukuran huruf harga (pt) <input type="number" id="c-fHarga" min="6" max="36" step="0.5"></label>
      <label class="full">Teks toko di atas label <input id="c-toko" maxlength="40"></label>
      <label class="chk"><input type="checkbox" id="c-tToko"> Tampilkan teks toko</label>
      <label class="chk"><input type="checkbox" id="c-tNama"> Nama barang</label>
      <label class="chk"><input type="checkbox" id="c-tHarga"> Harga</label>
      <label class="chk"><input type="checkbox" id="c-tKode"> Kode</label>
      <label class="chk"><input type="checkbox" id="c-tBarcode"> Barcode</label>
    </div>
    <div class="lb-prevbox"><div id="lb-prev"></div></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button type="button" class="btn" id="lb-cetak-btn"><?= icon('receipt') ?> Cetak <span id="lb-jml">0 label</span></button>
      <?php if (Auth::can('gudang.edit')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="simpan_cfg"><input type="hidden" name="cfg" id="c-json"><button class="btn ghost">Simpan sebagai bawaan</button></form>
      <?php endif; ?>
    </div>
    <p class="mut" style="font-size:12.5px;margin:10px 0 0">Pengaturan juga diingat di browser ini. Pada jendela cetak pilih printer label Anda, skala 100%, dan matikan "Header dan footer".</p>
  </div>
</div>
<div class="lb-cetak roll" id="lb-cetak"></div>
</div>
<script src="assets/label.js?v=1" defer></script>
<?php render('Cetak Label', ob_get_clean());
