<?php
Auth::require('santri.view');
$scope = Auth::scope();
$id = (int) in_str('id', '0');
$boleh = Auth::can('santri.edit');

// ---- Pilih santri ----
if (!$id) {
    $f = ['q' => in_str('q'), 'unit' => '', 'jenjang' => in_str('jenjang'), 'status' => 'aktif'];
    [$rows, $total] = Santri::daftar($f, $scope, max(1, (int) in_str('hal', '1')));
    ob_start(); ?>
<div class="page-h"><div><h1>Riwayat Santri</h1><p>Cari santri untuk melihat riwayat masuk, akademik, dan prestasi.</p></div></div>
<div class="card">
  <form method="get" class="filters"><input type="hidden" name="p" value="riwayat">
    <input name="q" placeholder="Cari nama, NIS, atau wali..." value="<?= e($f['q']) ?>" autofocus>
    <select name="jenjang"><option value="">Semua jenjang</option><?= options(Domain::JENJANG, $f['jenjang'], false) ?></select>
    <button class="btn"><?= icon('search') ?> Cari</button>
  </form>
  <ul class="list">
  <?php foreach ($rows as $r): ?>
    <li><?= avatar($r['nama'], $r['unit'], $r['foto'] ?? null, (int) $r['id']) ?><div class="sp"><b><?= e($r['nama']) ?></b><br><small><?= e($r['nis']) ?> &middot; <?= e($r['jenjang']) ?><?= $r['kelas'] ? ' / ' . e($r['kelas']) : '' ?> &middot; <?= e(Domain::UNIT[$r['unit']]) ?></small></div><a class="btn sm" href="?p=riwayat&amp;id=<?= (int) $r['id'] ?>">Lihat riwayat <?= icon('arrow-right', 15) ?></a></li>
  <?php endforeach; if (!$rows): ?><li class="empty">Tidak ada santri ditemukan.</li><?php endif; ?>
  </ul>
  <?php if ($total > count($rows)): ?><p class="mut" style="font-size:.85rem;margin-bottom:0">Menampilkan <?= count($rows) ?> dari <?= $total ?> santri. Persempit pencarian untuk hasil lebih spesifik.</p><?php endif; ?>
</div>
<?php render('Riwayat Santri', ob_get_clean()); exit;
}

// ---- Riwayat satu santri ----
$s = Santri::find($id);
if (!$s) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Santri tidak ditemukan.</div></div>'); exit; }
Auth::assertUnit($s['unit']);
$kembali = '?p=riwayat&id=' . $id;

if (is_post()) {
    csrf_check();
    if (!$boleh) { http_response_code(403); render('Dilarang', '<div class="card"><div class="empty">Anda tidak berhak mengubah riwayat.</div></div>'); exit; }
    $aksi = in_str('aksi');
    try {
        if ($aksi === 'masuk') {
            Riwayat::simpanMasuk($id, in_str('tgl_masuk'), in_str('status_masuk'), in_str('asal_sekolah'), $_FILES['surat'] ?? null, in_str('hapus_surat') === '1', Auth::id());
            flash('Riwayat masuk disimpan.');
        } elseif ($aksi === 'prestasi_tambah') {
            Riwayat::tambahPrestasi($id, in_str('jenis'), in_str('nama'), in_str('tahun'), $_FILES['sertifikat'] ?? null, Auth::id());
            flash('Prestasi ditambahkan.');
        } elseif ($aksi === 'prestasi_hapus') {
            Riwayat::hapusPrestasi((int) in_str('pid'), $id, Auth::id());
            flash('Prestasi dihapus.');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect($kembali);
}

$prestasi = Riwayat::prestasi($id);
$kelasPilih = in_array(in_str('kelas'), Riwayat::KELAS, true) ? in_str('kelas') : Riwayat::kelasSaatIni($s);
$semPilih = in_array(in_str('semester'), Riwayat::SEMESTER, true) ? in_str('semester') : '1';
$raportUrl = Riwayat::urlRaport((string) $s['nis'], $kelasPilih ?: 'TKQ', $semPilih);
$raportTpl = trim(Settings::get('raport_url'));
$tglMasuk = (string) ($s['tgl_masuk'] ?? '');
ob_start(); ?>
<div class="page-h">
  <div class="who"><?= avatar($s['nama'], $s['unit'], $s['foto'] ?? null, $id) ?><div><h1>Riwayat Santri</h1><p><a href="?p=santri_detail&amp;id=<?= $id ?>"><?= e($s['nama']) ?></a></p></div></div>
  <div class="acts"><a class="btn ghost" href="?p=riwayat"><?= icon('search') ?> Cari santri lain</a></div>
</div>

<div class="card">
  <dl class="kv">
    <dt>Nama Siswa</dt><dd><b><?= e($s['nama']) ?></b></dd>
    <dt>NIS</dt><dd><?= e($s['nis']) ?></dd>
    <dt>Kelas</dt><dd><?= e($s['jenjang']) ?><?= $s['kelas'] ? ' / ' . e($s['kelas']) : '' ?></dd>
  </dl>
</div>

<div class="card" id="masuk" style="margin-top:16px">
  <h2>Riwayat Masuk</h2>
  <dl class="kv">
    <dt>Tanggal Masuk</dt><dd><?= $tglMasuk !== '' ? e($tglMasuk) : '-' ?></dd>
    <dt>Status Masuk</dt><dd><?= !empty($s['status_masuk']) ? e($s['status_masuk']) : '-' ?>
      <?php if (!empty($s['surat_rekomendasi'])): ?> <a class="btn sm ghost" href="?p=dokumen&amp;jenis=surat&amp;id=<?= $id ?>" target="_blank" rel="noopener"><?= icon('file-text', 15) ?> Lihat Surat Rekomendasi</a><?php endif; ?></dd>
    <dt>Asal Sekolah</dt><dd><?= !empty($s['asal_sekolah']) ? e($s['asal_sekolah']) : '-' ?></dd>
  </dl>
  <?php if ($boleh): ?>
  <details style="margin-top:10px"><summary class="btn sm ghost" style="display:inline-flex"><?= icon('edit', 15) ?> Ubah riwayat masuk</summary>
    <form method="post" enctype="multipart/form-data" class="form" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="masuk">
      <label>Tanggal masuk <input type="date" name="tgl_masuk" value="<?= e($tglMasuk) ?>"></label>
      <label>Status masuk <select name="status_masuk"><option value="">Pilih...</option><?= options(Riwayat::STATUS_MASUK, (string) ($s['status_masuk'] ?? '')) ?></select></label>
      <label>Asal sekolah <input name="asal_sekolah" value="<?= e((string) ($s['asal_sekolah'] ?? '')) ?>"></label>
      <label>Surat rekomendasi (PDF/JPG/PNG, maks. 5 MB) <input type="file" name="surat" accept="application/pdf,image/jpeg,image/png"></label>
      <?php if (!empty($s['surat_rekomendasi'])): ?><label style="flex-direction:row;gap:8px;align-items:center"><input type="checkbox" name="hapus_surat" value="1" style="width:auto"> Hapus surat rekomendasi saat ini</label><?php endif; ?>
      <div class="acts"><button class="btn"><?= icon('check') ?> Simpan</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>

<div class="card" id="akademik" style="margin-top:16px">
  <h2>Riwayat Akademik</h2>
  <form method="get" class="filters" id="fAkademik" data-tpl="<?= e($raportTpl) ?>" data-nis="<?= e((string) $s['nis']) ?>">
    <input type="hidden" name="p" value="riwayat"><input type="hidden" name="id" value="<?= $id ?>">
    <label>Pilih Kelas <select name="kelas" id="rKelas"><?= options(array_combine(Riwayat::KELAS, Riwayat::KELAS), $kelasPilih) ?></select></label>
    <label>Pilih Semester <select name="semester" id="rSem"><?= options(array_combine(Riwayat::SEMESTER, Riwayat::SEMESTER), $semPilih) ?></select></label>
    <?php if ($raportUrl): ?>
      <a class="btn" id="rLihat" href="<?= e($raportUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('eye') ?> Lihat Detail Nilai</a>
    <?php else: ?>
      <span class="btn ghost" aria-disabled="true" style="opacity:.6;cursor:not-allowed"><?= icon('eye') ?> Lihat Detail Nilai</span>
    <?php endif; ?>
  </form>
  <p class="mut" style="font-size:.85rem;margin:8px 0 0">Nilai raport diambil dari aplikasi raport.<?= $raportUrl ? '' : ' <b>Aplikasi raport belum dihubungkan</b>' . (Auth::can('pengaturan') ? ': isi "Alamat aplikasi raport" di <a href="?p=pengaturan">Pengaturan</a>.' : ': hubungi admin.') ?></p>
</div>

<div class="card" id="prestasi" style="margin-top:16px">
  <h2>Riwayat Prestasi</h2>
  <div class="tbl-wrap"><table>
    <thead><tr><th>Jenis Prestasi</th><th>Nama Prestasi</th><th>Tahun</th><th>Sertifikat</th><?php if ($boleh): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($prestasi as $p): ?>
      <tr><td><?= e($p['jenis']) ?></td><td><?= e($p['nama']) ?></td><td><?= $p['tahun'] ? e($p['tahun']) : '-' ?></td>
        <td><?php if (!empty($p['sertifikat'])): ?><a class="btn sm ghost" href="?p=dokumen&amp;jenis=sertifikat&amp;id=<?= (int) $p['id'] ?>" target="_blank" rel="noopener"><?= icon('file-text', 15) ?> Lihat Sertifikat</a><?php else: ?><span class="mut">-</span><?php endif; ?></td>
        <?php if ($boleh): ?><td><form method="post" onsubmit="return confirm('Hapus prestasi ini?')"><?= csrf_field() ?><input type="hidden" name="aksi" value="prestasi_hapus"><input type="hidden" name="pid" value="<?= (int) $p['id'] ?>"><button class="btn sm ghost">Hapus</button></form></td><?php endif; ?></tr>
    <?php endforeach; if (!$prestasi): ?><tr><td colspan="<?= $boleh ? 5 : 4 ?>" class="empty">Belum ada prestasi tercatat.</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php if ($boleh): ?>
  <details style="margin-top:12px"><summary class="btn sm" style="display:inline-flex"><?= icon('plus', 15) ?> Tambah prestasi</summary>
    <form method="post" enctype="multipart/form-data" class="form" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="prestasi_tambah">
      <label>Jenis prestasi <select name="jenis" required><option value="">Pilih...</option><?= options(array_combine(Riwayat::JENIS_PRESTASI, Riwayat::JENIS_PRESTASI), '') ?></select></label>
      <label>Nama prestasi <input name="nama" required maxlength="150" placeholder="mis. Juara 1 MTQ Tingkat Kota"></label>
      <label>Tahun <input name="tahun" inputmode="numeric" maxlength="4" placeholder="<?= date('Y') ?>"></label>
      <label>Sertifikat (PDF/JPG/PNG, maks. 5 MB) <input type="file" name="sertifikat" accept="application/pdf,image/jpeg,image/png"></label>
      <div class="acts"><button class="btn"><?= icon('check') ?> Simpan prestasi</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>
<script>
(function () {
  var f = document.getElementById('fAkademik'); if (!f) return;
  var tpl = f.dataset.tpl, a = document.getElementById('rLihat');
  function rakit() {
    if (!a || !tpl) return;
    a.href = tpl.replace('{nis}', encodeURIComponent(f.dataset.nis)).replace('{kelas}', encodeURIComponent(document.getElementById('rKelas').value)).replace('{semester}', encodeURIComponent(document.getElementById('rSem').value));
  }
  document.getElementById('rKelas').addEventListener('change', rakit);
  document.getElementById('rSem').addEventListener('change', rakit);
  f.addEventListener('submit', function (e) { e.preventDefault(); });
})();
</script>
<?php render('Riwayat Santri', ob_get_clean());
