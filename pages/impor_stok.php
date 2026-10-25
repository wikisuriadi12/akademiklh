<?php
// Impor master barang dan stok (Gudang/Market) dari Excel (.xlsx) atau CSV.
Auth::require('gudang.edit');

function unduh_xlsx(string $nama, string $bin): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}
$petunjuk = [
    ['Kolom', 'Wajib?', 'Keterangan'],
    ['kode', 'Ya', 'Kode unik barang (huruf/angka/titik/strip, 2-30 karakter). Juga dipakai sebagai barcode label.'],
    ['nama', 'Ya', 'Nama barang, maks. 120 karakter.'],
    ['kategori', 'Tidak', 'Contoh: Kantin, Alat Tulis, Perlengkapan.'],
    ['satuan', 'Tidak', 'Contoh: pcs, pak, botol. Kosong = pcs.'],
    ['harga_beli', 'Tidak', 'Harga modal per satuan (angka, tanpa Rp).'],
    ['harga_jual', 'Ya', 'Harga jual per satuan, lebih dari 0.'],
    ['stok_min', 'Tidak', 'Batas stok menipis.'],
    ['stok_gudang', 'Tidak', 'Jumlah akhir stok di Gudang. Kosong = tidak diubah.'],
    ['stok_market', 'Tidak', 'Jumlah akhir stok di Market (barang tampil di kasir bila terisi). Kosong = tidak diubah.'],
    ['Catatan', '', 'Semua-atau-tidak-sama-sekali: bila ada satu baris bermasalah, tidak ada yang disimpan. Barang yang kodenya sudah ada hanya ditimpa bila kotak "Perbarui" dicentang.'],
];
$aksi = in_str('aksi');
if ($aksi === 'template') {
    unduh_xlsx('template-impor-barang.xlsx', Xlsx::tulis(
        ['Master Barang' => array_merge([Gudang::IMPOR_HEADER], Gudang::templateContoh()), 'Petunjuk' => $petunjuk],
        ['Master Barang' => [14, 28, 14, 9, 12, 12, 10, 13, 13], 'Petunjuk' => [14, 10, 90]]
    ));
}
if ($aksi === 'template_csv') { csv_out('template-impor-barang.csv', Gudang::IMPOR_HEADER, Gudang::templateContoh()); }
if ($aksi === 'ekspor') {
    unduh_xlsx('barang-' . date('Ymd') . '.xlsx', Xlsx::tulis(['Master Barang' => Gudang::eksporBaris(), 'Petunjuk' => $petunjuk], ['Master Barang' => [14, 28, 14, 9, 12, 12, 10, 13, 13], 'Petunjuk' => [14, 10, 90]]));
}
$hasil = null;
if (is_post()) {
    csrf_check();
    try {
        $f = $_FILES['berkas'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('Pilih berkas Excel (.xlsx) atau CSV terlebih dahulu.'); }
        if ($f['size'] > 3 * 1024 * 1024) { throw new InvalidArgumentException('Ukuran berkas maksimal 3 MB.'); }
        if (!is_uploaded_file((string) $f['tmp_name'])) { throw new InvalidArgumentException('Unggahan tidak valid.'); }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx') { $rows = Xlsx::baca($f['tmp_name']); }
        elseif ($ext === 'csv') { $rows = Xlsx::bacaCsv($f['tmp_name']); }
        else { throw new InvalidArgumentException('Berkas harus berekstensi .xlsx atau .csv.'); }
        $hasil = Gudang::imporBarang($rows, in_str('perbarui') === '1', Auth::id(), in_str('stok_ke') === 'ikhwan' ? 'ikhwan' : 'gudang');
        if ($hasil['ok']) {
            flash($hasil['baru'] . ' barang baru, ' . $hasil['ubah'] . ' barang diperbarui.');
            redirect('?p=gudang&lok=gudang');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
}
ob_start(); ?>
<div class="page-h"><div><h1>Impor Barang &amp; Stok</h1><p>Masukkan master barang dan stok awal Gudang/Market sekaligus dari Excel.</p></div>
  <div class="acts"><a class="btn ghost" href="?p=impor_stok&amp;aksi=template"><?= icon('download') ?> Template Excel</a>
    <a class="btn ghost" href="?p=impor_stok&amp;aksi=template_csv"><?= icon('download') ?> Template CSV</a>
    <a class="btn ghost" href="?p=impor_stok&amp;aksi=ekspor"><?= icon('download') ?> Ekspor data saat ini</a></div></div>
<?php if ($hasil && !$hasil['ok']): ?>
<div class="card">
  <div class="note err"><b>Tidak ada data yang disimpan.</b> Perbaiki <?= (int) $hasil['jumlah_galat'] ?> masalah di bawah, lalu unggah ulang.</div>
  <ul class="list"><?php foreach ($hasil['errors'] as $m): ?><li><?= icon('alert', 16) ?><span class="sp"><?= e($m) ?></span></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<div class="grid g-main">
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <h2>Unggah berkas</h2>
    <label>Berkas Excel .xlsx atau CSV (maks. 3 MB, 1000 baris) <input type="file" name="berkas" accept=".xlsx,.csv" required></label>
    <label>Kolom "Stok"/"Qty" dari sistem lama masuk ke <select name="stok_ke"><option value="gudang">Stok Gudang</option><option value="ikhwan">Stok Market</option></select></label>
    <label style="flex-direction:row;align-items:flex-start;gap:8px"><input type="checkbox" name="perbarui" value="1" style="width:auto;margin-top:4px"> <span><b>Perbarui barang yang kodenya sudah ada</b> (harga, kategori, dan stok yang diisi akan ditimpa).</span></label>
    <button class="btn"><?= icon('upload') ?> Impor sekarang</button>
    <p class="mut" style="font-size:.85rem;margin-bottom:0">Semua-atau-tidak-sama-sekali: bila ada satu baris bermasalah, tidak ada yang disimpan.</p>
  </form>
  <div class="card">
    <h2>Format kolom</h2>
    <ul class="list" style="font-size:.88rem">
      <li><span class="sp"><b>Wajib:</b> kode, nama, harga_jual</span></li>
      <li><span class="sp"><b>Opsional:</b> kategori, satuan, harga_beli, stok_min</span></li>
      <li><span class="sp"><b>Stok:</b> stok_gudang dan stok_market berisi jumlah akhir. Kosong berarti tidak diubah. Barang tampil di kasir hanya bila punya stok Market.</span></li>
      <li><span class="sp">Gunakan <b>Template Excel</b> (sudah ada contoh dan lembar Petunjuk), atau <b>Ekspor data saat ini</b> untuk menyunting barang yang sudah ada.</span></li>
    </ul>
  </div>
</div>
<?php render('Impor Barang & Stok', ob_get_clean());
