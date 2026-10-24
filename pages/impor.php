<?php
Auth::require('impor');
$scope = Auth::scope();

if (in_str('aksi') === 'template_excel') {
    csv_out('template-impor-santri-excel.csv', Santri::EXCEL_HEADER, [['3A', '', '3112345678', '0001', '510312710001260001', '1271120101150001', 'CONTOH NAMA SISWA', 'MEDAN', '2015-01-01', '11 Tahun', 'LAKI-LAKI', '2026-07-20', '2026-2027', 'JL. CONTOH NO. 1', '', 'SD CONTOH', 'JL. SEKOLAH NO. 2', '1271120101100001', 'NAMA AYAH', '1271120101800001', 'SLTA', 'WIRASWASTA', 'RP.1.000.000 - RP.2.000.000', 'NAMA IBU', '1271120101820001', 'SLTA', 'IBU RUMAH TANGGA', '-', '0812 3456 7890', '0812 3456 7891', '2', '1', 'MONDOK']]);
}
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
        $perbarui = in_str('perbarui') === '1';
        $hasil = Santri::importCsv($f['tmp_name'], $scope, Auth::id(), $perbarui);
        if ($hasil['ok']) {
            flash($hasil['count'] . (!empty($hasil['perbarui']) ? ' santri diperbarui (data identitas & orang tua).' : ' santri berhasil diimpor.') . (!empty($hasil['nis_dobel']) ? ' ' . $hasil['nis_dobel'] . ' NIS dobel dalam berkas dibuat otomatis oleh sistem.' : ''));
            redirect('?p=santri');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
}
ob_start(); ?>
<div class="page-h"><div><h1>Impor Data Santri</h1><p>Masukkan banyak santri sekaligus dari berkas CSV (bisa dibuat dari Excel).</p></div>
  <div class="acts"><a class="btn ghost" href="?p=impor&amp;aksi=template"><?= icon('download') ?> Template sederhana</a>
    <a class="btn ghost" href="?p=impor&amp;aksi=template_excel"><?= icon('download') ?> Template header Excel</a></div></div>
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
    <label style="flex-direction:row;align-items:flex-start;gap:8px"><input type="checkbox" name="perbarui" value="1" style="width:auto;margin-top:4px"> <span><b>Perbarui santri yang sudah ada</b> (tidak menambah santri baru). Dicocokkan lewat NIS; bila NIS kosong, lewat nama + tanggal lahir. Hanya data identitas &amp; orang tua yang diisi; data lain tidak diubah.</span></label>
    <button class="btn"><?= icon('upload') ?> Impor sekarang</button>
    <p class="mut" style="font-size:.85rem;margin-bottom:0">Semua-atau-tidak-sama-sekali: bila ada satu baris bermasalah, tidak ada santri yang disimpan.</p>
  </form>
  <div class="card">
    <h2>Format kolom</h2>
    <ul class="list" style="font-size:.88rem">
      <li><span class="sp"><b>Format Excel DATA SISWA diterima langsung</b> (simpan sebagai CSV; header KLS, NIS LOKAL, NAMA SISWA, L/P, ... dikenali otomatis). Jenjang diambil dari KLS: TKQ; 1A-6B = Ula; 7-9 = Wustha; 10-12 = Ulya. NIS sistem = NIS LOKAL. Tanggal: yyyy-mm-dd atau dd-mm-yyyy.</span></li>
      <li><span class="sp"><b>Wajib:</b> nama, jenis_kelamin (L/P), jenjang (TKQ/Ula/Wustha/Ulya), nama_wali</span></li>
      <li><span class="sp"><b>Opsional:</b> kelas, hp_wali, tahun_masuk, tanggal_lahir (YYYY-MM-DD), tempat_lahir, alamat, potongan_spp, nis, status</span></li>
      <li><span class="sp"><b>Data identitas &amp; orang tua (opsional):</b> nisn, nis_internal, nik, no_kk, va_lama, tgl_masuk, npsn_asal, alamat_asal, ayah_nama, ayah_nik, ayah_pendidikan, ayah_pekerjaan, ayah_penghasilan, hp_ayah, ibu_nama, ibu_nik, ibu_pendidikan, ibu_pekerjaan, ibu_penghasilan, hp_ibu, jumlah_saudara, anak_ke, tahun_ajaran, usia_masuk, keterangan, asal_sekolah, hubungan_wali, catatan</span></li>
      <li><span class="sp">NIS dibuat otomatis bila kosong. Pemisah koma atau titik koma sama-sama diterima.</span></li>
    </ul>
  </div>
</div>
<?php render('Impor Data', ob_get_clean());
