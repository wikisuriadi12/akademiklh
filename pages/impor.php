<?php
Auth::require('impor');
$scope = Auth::scope();

if (in_str('aksi') === 'template') {
    csv_out('template-impor-santri.csv', Santri::templateCsv()[0], array_slice(Santri::templateCsv(), 1));
}
$hasil = null;
if (is_post()) {
    csrf_check();
    try {
        $f = $_FILES['berkas'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Pilih berkas CSV terlebih dahulu.');
        }
        if ($f['size'] > 2 * 1024 * 1024) { throw new InvalidArgumentException('Ukuran berkas maksimal 2 MB.'); }
        if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'csv') { throw new InvalidArgumentException('Berkas harus berekstensi .csv'); }
        $hasil = Santri::importCsv($f['tmp_name'], $scope, Auth::id());
        if ($hasil['ok']) {
            flash($hasil['count'] . ' santri berhasil diimpor.');
            redirect('?p=santri');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
}
ob_start(); ?>
<div class="page-h"><div><h1>Impor Data Santri</h1><p>Masukkan banyak santri sekaligus dari berkas CSV (bisa dibuat dari Excel).</p></div>
  <div class="acts"><a class="btn ghost" href="?p=impor&amp;aksi=template"><?= icon('download') ?> Unduh template</a></div></div>
<?php if ($hasil && !$hasil['ok']): ?>
<div class="card">
  <div class="note err"><b>Tidak ada data yang disimpan.</b> Perbaiki <?= (int) $hasil['jumlah_galat'] ?> baris di bawah, lalu unggah ulang.</div>
  <ul class="list"><?php foreach ($hasil['errors'] as $m): ?><li><?= icon('alert', 16) ?><span class="sp"><?= e($m) ?></span></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<div class="grid g-main">
  <form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <h2>Unggah berkas</h2>
    <label>Berkas CSV (maks. 2 MB, 1000 baris) <input type="file" name="berkas" accept=".csv,text/csv" required></label>
    <button class="btn"><?= icon('upload') ?> Impor sekarang</button>
    <p class="mut" style="font-size:.85rem;margin-bottom:0">Semua-atau-tidak-sama-sekali: bila ada satu baris bermasalah, tidak ada santri yang disimpan.</p>
  </form>
  <div class="card">
    <h2>Format kolom</h2>
    <ul class="list" style="font-size:.88rem">
      <li><span class="sp"><b>Wajib:</b> nama, jenis_kelamin (L/P), jenjang (TKQ/Ula/Wustha/Ulya), nama_wali</span></li>
      <li><span class="sp"><b>Opsional:</b> kelas, hp_wali, tahun_masuk, tanggal_lahir (YYYY-MM-DD), tempat_lahir, alamat, potongan_spp, nis, status</span></li>
      <li><span class="sp">NIS dibuat otomatis bila kosong. Pemisah koma atau titik koma sama-sama diterima.</span></li>
    </ul>
  </div>
</div>
<?php render('Impor Data', ob_get_clean());
