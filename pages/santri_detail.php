<?php
Auth::require('santri.view');
require_once APP_ROOT . '/src/GoogleDriveLH.php';
$adminDrive = (Auth::user()['role'] ?? '') === 'admin';
if (in_str('tab') === 'google_callback') {
    if (!$adminDrive) { http_response_code(403); exit('Hanya admin yang dapat menghubungkan Google Drive.'); }
    try {
        $sidDrive = GoogleDriveLH::callback(in_str('code'), in_str('state'), (int) Auth::id(), in_str('error'));
        redirect('?p=santri_detail&id=' . $sidDrive . '&tab=berkas');
    } catch (InvalidArgumentException $ex) {
        render('Koneksi Google Drive', '<div class="card"><h1>Koneksi belum berhasil</h1><p>' . e($ex->getMessage()) . '</p><a href="?p=santri">Kembali ke Data Santri</a></div>');
    }
    exit;
}

$id = (int) in_str('id', '0');
$s = $id ? Santri::find($id) : null;
if (!$s) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Santri tidak ditemukan.</div></div>'); exit; }
Auth::assertUnit($s['unit']);
// Tampilan berkas memakai route detail yang sudah tersedia, tanpa route baru.
if (in_str('tab') === 'berkas') {
    Auth::require('santri.profil');
    $berkasError = '';
    $bolehUnggah = Auth::can('santri.edit');
    if (is_post() && str_starts_with(in_str('aksi_berkas'), 'drive_')) {
        csrf_check();
        if (!$adminDrive) { http_response_code(403); exit('Hanya admin yang dapat menghubungkan Google Drive.'); }
        try {
            if (in_str('aksi_berkas') === 'drive_connect') {
                redirect(GoogleDriveLH::mulai($id, (int) Auth::id())); exit;
            }
            if (in_str('aksi_berkas') === 'drive_confirm') {
                GoogleDriveLH::konfirmasi((int) Auth::id()); flash('Akun Google Drive yayasan berhasil dihubungkan.');
            } elseif (in_str('aksi_berkas') === 'drive_cancel') {
                unset($_SESSION['lh_drive_pending']);
            }
            redirect('?p=santri_detail&id=' . $id . '&tab=berkas'); exit;
        } catch (InvalidArgumentException $ex) { $berkasError = $ex->getMessage(); }
    }
    if (is_post() && !str_starts_with(in_str('aksi_berkas'), 'drive_')) {
        Auth::require('santri.edit');
        csrf_check();
        try {
            if (in_str('aksi_berkas') === 'hapus') {
                if (in_str('konfirmasi_hapus') !== 'ya') {
                    throw new InvalidArgumentException('Konfirmasi penghapusan terlebih dahulu.');
                }
                GoogleDriveLH::locked(static fn() => Berkas::hapusSantri($id, in_str('hapus_file')));
                flash('Berkas lokal berhasil dihapus. Salinan yang pernah terkirim ke Drive tetap disimpan.');
                redirect('?p=santri_detail&id=' . $id . '&tab=berkas');
                exit;
            }
            if (in_str('aksi_berkas') === 'kirim_drive') {
                Berkas::kirimDrive($id, in_str('kirim_file'), $s);
                flash('Berkas berhasil terkirim ke Google Drive yayasan.');
                redirect('?p=santri_detail&id=' . $id . '&tab=berkas'); exit;
            }
            $unggahan = $_FILES['berkas'] ?? null;
            if (!is_array($unggahan)) {
                throw new InvalidArgumentException('Berkas belum diterima. Pilih berkas maksimal 5 MB.');
            }
            $namaBaru = Berkas::unggahSantri($id, in_str('jenis_berkas'), $unggahan, in_str('nama_berkas'));
            $pesanUpload = 'Berkas tersimpan di sistem. Google Drive belum terhubung.';
            try {
                if (GoogleDriveLH::info()['aktif']) {
                    Berkas::kirimDrive($id, $namaBaru, $s);
                    $pesanUpload = 'Berkas tersimpan di sistem dan terkirim ke Google Drive yayasan.';
                }
            } catch (InvalidArgumentException $ex) {
                $pesanUpload = 'Berkas tersimpan di sistem, tetapi belum terkirim ke Drive. ' . $ex->getMessage() . ' Gunakan tombol Kirim ke Drive untuk mencoba lagi.';
            }
            flash($pesanUpload);
            redirect('?p=santri_detail&id=' . $id . '&tab=berkas');
            exit;
        } catch (InvalidArgumentException $ex) {
            $berkasError = $ex->getMessage();
        }
    }
    $daftarBerkas = Berkas::daftarSantri($id);
    $konfirmasiHapus = null;
    if ($bolehUnggah && in_str('hapus_file') !== '') {
        foreach ($daftarBerkas as $b) {
            if ($b['file'] === in_str('hapus_file')) { $konfirmasiHapus = $b; break; }
        }
        if (!$konfirmasiHapus && !$berkasError) {
            $berkasError = 'Berkas yang akan dihapus tidak ditemukan.';
        }
    }
    $fileDiminta = in_str('berkas_file');
    if ($fileDiminta !== '') {
        $ditemukan = false;
        foreach ($daftarBerkas as $b) {
            if ($b['file'] === $fileDiminta) { $ditemukan = true; break; }
        }
        $jalur = $ditemukan ? Berkas::jalur($fileDiminta) : null;
        if (!$jalur) {
            http_response_code(404);
            render('Tidak ditemukan', '<div class="card">Berkas tidak ditemukan.</div>');
            exit;
        }
        header('Content-Type: ' . Berkas::mime($fileDiminta));
        header('Content-Disposition: inline; filename="' . $fileDiminta . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($jalur));
        readfile($jalur);
        exit;
    }
    $driveInfo = ['email' => '', 'aktif' => false, 'folder_id' => ''];
    try { $driveInfo = GoogleDriveLH::info(); } catch (InvalidArgumentException $ex) { $berkasError = $berkasError ?: $ex->getMessage(); }
    $pendingDrive = $adminDrive ? ($_SESSION['lh_drive_pending'] ?? null) : null;
    if ($pendingDrive && ($pendingDrive['uid'] !== (int) Auth::id() || $pendingDrive['expires'] < time())) $pendingDrive = null;
    ob_start(); ?>
    <div class="page-h">
      <div><h1>Berkas Santri</h1><p><?= e($s['nama']) ?> &middot; <?= e($s['nis']) ?></p></div>
      <a class="btn ghost" href="?p=santri_detail&amp;id=<?= $id ?>">Kembali ke detail</a>
    </div>
    <?php if ($berkasError): ?><div class="note err"><?= e($berkasError) ?></div><?php endif; ?>
    <div class="card">
      <h2>Google Drive Yayasan</h2>
      <?php if ($driveInfo['aktif']): ?>
        <p>Terhubung: <b><?= e($driveInfo['email']) ?></b>. Upload baru akan otomatis dikirim ke Drive.</p>
        <?php if ($adminDrive && $driveInfo['folder_id']): ?><p><a target="_blank" rel="noopener" href="https://drive.google.com/drive/folders/<?= e($driveInfo['folder_id']) ?>">Buka folder yayasan</a></p><?php endif; ?>
      <?php else: ?><p>Belum terhubung. Berkas tetap dapat disimpan di sistem.</p><?php endif; ?>
      <?php if ($adminDrive): ?>
        <?php if ($pendingDrive): ?>
          <p>Gunakan akun <b><?= e($pendingDrive['connection']['email']) ?></b> sebagai Drive yayasan? Berkas santri akan dikirim ke akun ini.</p>
          <form method="post" action="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas" class="acts">
            <?= csrf_field() ?>
            <button class="btn" name="aksi_berkas" value="drive_confirm">Ya, gunakan akun yayasan ini</button>
            <button class="btn ghost" name="aksi_berkas" value="drive_cancel">Batal</button>
          </form>
        <?php elseif (GoogleDriveLH::siap()): ?>
          <form method="post" action="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas">
            <?= csrf_field() ?><button class="btn ghost" name="aksi_berkas" value="drive_connect"><?= $driveInfo['aktif'] ? 'Hubungkan ulang Google Drive' : 'Hubungkan Google Drive' ?></button>
          </form>
        <?php else: ?>
          <p>Pengaturan koneksi Google belum lengkap. Ikuti PETUNJUK_GOOGLE_DRIVE.txt untuk mengaktifkan tombol login.</p>
          <button class="btn ghost" disabled>Hubungkan Google Drive</button>
        <?php endif; ?>
      <?php elseif (!$driveInfo['aktif']): ?><p>Hubungi admin yayasan untuk menghubungkan akun Google.</p><?php endif; ?>
    </div>
    <?php if ($konfirmasiHapus): ?>
    <form method="post" action="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas" class="card">
      <h2>Hapus berkas ini?</h2>
      <p><b><?= e(Berkas::labelSantri($konfirmasiHapus)) ?></b> — <?= e($konfirmasiHapus['tanggal']) ?></p>
      <p>Berkas akan dihapus permanen dari sistem ini. Salinan yang sudah terkirim ke Google Drive tetap ada sebagai cadangan; hapus terpisah di Drive bila diperlukan.</p>
      <?= csrf_field() ?>
      <input type="hidden" name="aksi_berkas" value="hapus">
      <input type="hidden" name="hapus_file" value="<?= e($konfirmasiHapus['file']) ?>">
      <input type="hidden" name="konfirmasi_hapus" value="ya">
      <div class="acts">
        <button type="submit" class="btn" style="background:var(--err)">Ya, hapus berkas</button>
        <a class="btn ghost" href="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas">Batal</a>
      </div>
    </form>
    <?php endif; ?>
    <?php if ($bolehUnggah): ?>
    <form method="post" action="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas" enctype="multipart/form-data" class="card">
      <h2>Upload berkas</h2>
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= Berkas::MAKS_BYTE ?>">
      <label>Jenis berkas <select name="jenis_berkas" id="jenisBerkas" required>
        <option value="">Pilih jenis berkas</option>
        <?php foreach (Berkas::JENIS_SANTRI as $k => $label): ?><option value="<?= e($k) ?>"<?= in_str('jenis_berkas') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label id="namaBerkasLabel">Nama berkas (wajib untuk Berkas lainnya)
        <input type="text" name="nama_berkas" id="namaBerkas" maxlength="100" value="<?= e(in_str('nama_berkas')) ?>" placeholder="Contoh: Surat keterangan sehat">
      </label>
      <label>Pilih file <input type="file" name="berkas" accept=".pdf,.jpg,.jpeg,.png" required></label>
      <p class="mut">PDF, JPG, atau PNG. Maksimal 5 MB per file. Unggah satu per satu.</p>
      <button class="btn" type="submit"><?= icon('upload') ?> Upload berkas</button>
    </form>
    <?php endif; ?>
    <div class="card"><h2>Berkas tersimpan</h2>
      <?php if (!$daftarBerkas && empty($s['surat_rekomendasi'])): ?>
        <p class="mut">Belum ada berkas tersimpan untuk santri ini.</p>
      <?php else: ?>
      <div class="scroll"><table class="rt">
        <thead><tr><th>Jenis berkas</th><th>Diunggah</th><th>Ukuran</th><th>Google Drive</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (!empty($s['surat_rekomendasi'])): ?>
          <tr><td data-label="Jenis berkas">Surat rekomendasi (data sebelumnya)</td><td data-label="Diunggah">—</td><td data-label="Ukuran">—</td><td data-label="Google Drive">Data lama; belum disalin</td><td data-label="Aksi"><a class="btn sm ghost" target="_blank" rel="noopener" href="?p=dokumen&amp;jenis=surat&amp;id=<?= $id ?>">Lihat</a></td></tr>
        <?php endif; ?>
        <?php foreach ($daftarBerkas as $b): ?>
          <tr><td data-label="Jenis berkas"><?= e(Berkas::labelSantri($b)) ?></td>
          <td data-label="Diunggah"><?= e($b['tanggal']) ?></td>
          <td data-label="Ukuran"><?= number_format((int) $b['ukuran'] / 1024, 1, ',', '.') ?> KB</td>
          <td data-label="Google Drive">
            <?php if (($b['drive_status'] ?? '') === 'terkirim'): ?>
              <span>Terkirim</span><br><small><?= e($b['drive_waktu'] ?? '') ?></small>
            <?php else: ?>
              <span>Belum terkirim</span>
              <?php if ($bolehUnggah && $driveInfo['aktif']): ?>
                <form method="post" action="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas" style="margin-top:6px">
                  <?= csrf_field() ?><input type="hidden" name="aksi_berkas" value="kirim_drive">
                  <input type="hidden" name="kirim_file" value="<?= e($b['file']) ?>">
                  <button class="btn sm ghost">Kirim ke Drive</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td data-label="Aksi"><a class="btn sm ghost" target="_blank" rel="noopener" href="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas&amp;berkas_file=<?= e($b['file']) ?>">Lihat</a>
            <?php if ($bolehUnggah): ?><a class="btn sm ghost" style="color:var(--err)" href="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas&amp;hapus_file=<?= e($b['file']) ?>">Hapus</a><?php endif; ?>
          </td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <script>
    (function () {
      var jenis = document.getElementById('jenisBerkas');
      var nama = document.getElementById('namaBerkas');
      var label = document.getElementById('namaBerkasLabel');
      if (!jenis || !nama || !label) return;
      function sesuaikanNama() {
        var lainnya = jenis.value === 'lainnya';
        label.hidden = !lainnya;
        label.style.display = lainnya ? '' : 'none';
        nama.required = lainnya;
        nama.disabled = !lainnya;
      }
      jenis.addEventListener('change', sesuaikanNama);
      sesuaikanNama();
    })();
    </script>
    <?php render('Berkas Santri', ob_get_clean());
    exit;
}

$keu = Auth::can('tagihan.view');
$tagihan = $keu ? Spp::tagihanSantri($id) : [];
$riwayat = $keu ? Spp::riwayatSantri($id, 15) : [];
$totNom = 0; $totBayar = 0;
foreach ($tagihan as $t) { $totNom += (int) $t['nominal']; $totBayar += (int) $t['dibayar']; }
$sisa = $totNom - $totBayar;
$pf = Auth::can('santri.profil') ? Santri::profil($id) : null;
$lembaga = Settings::get('nama_lembaga');
$wa = wa_link($s['hp_wali'], "Assalamu'alaikum {$s['nama_wali']}, kami dari $lembaga" . ($sisa > 0 ? '. Kami mengingatkan sisa pembayaran ananda ' . $s['nama'] . ' sebesar ' . rp($sisa) . '. Mohon konfirmasinya. Jazakumullahu khairan.' : ' terkait ananda ' . $s['nama'] . '.'));
ob_start(); ?>
<div class="page-h">
  <div class="who"><?= avatar($s['nama'], $s['unit'], $s['foto'] ?? null, $id) ?><div><h1><?= e($s['nama']) ?></h1><p><?= e($s['nis']) ?> &middot; <?= e($s['jenjang']) ?><?= $s['kelas'] ? ' / ' . e($s['kelas']) : '' ?> &middot; <?= e(Domain::UNIT[$s['unit']]) ?> &middot; <?= badge($s['status']) ?></p></div></div>
  <div class="acts">
    <?php if (Auth::can('bayar')): ?><a class="btn" href="?p=bayar&amp;santri=<?= $id ?>"><?= icon('credit-card') ?> Bayar</a><?php endif; ?>
    <a class="btn ghost" href="?p=riwayat&amp;id=<?= $id ?>"><?= icon('clipboard') ?> Riwayat</a>
    <?php if (Auth::can('santri.profil')): ?><a class="btn ghost" href="?p=santri_detail&amp;id=<?= $id ?>&amp;tab=berkas"><?= icon('file-text') ?> Berkas Santri</a><?php endif; ?>
    <?php if (Auth::can('santri.edit')): ?><a class="btn ghost" href="?p=santri_form&amp;id=<?= $id ?>"><?= icon('edit') ?> Ubah</a><?php endif; ?>
    <?php if ($wa): ?><a class="btn wa" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?> WhatsApp wali</a><?php endif; ?>
  </div>
</div>

<?php if ($keu): ?>
<div class="kpis">
  <div class="kpi"><div class="chip c-info"><?= icon('file-text', 22) ?></div><div><span>Total tagihan</span><b><?= rp($totNom) ?></b></div></div>
  <div class="kpi"><div class="chip c-ok"><?= icon('wallet', 22) ?></div><div><span>Sudah dibayar</span><b><?= rp($totBayar) ?></b></div></div>
  <div class="kpi"><div class="chip <?= $sisa > 0 ? 'c-err' : 'c-ok' ?>"><?= icon($sisa > 0 ? 'alert' : 'check', 22) ?></div><div><span>Sisa tagihan</span><b><?= rp($sisa) ?></b></div></div>
</div>
<?php endif; ?>

<div class="grid g2" style="margin-bottom:16px">
  <div class="card">
    <h2>Biodata</h2>
    <dl class="kv">
      <dt>Jenis kelamin</dt><dd><?= $s['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></dd>
      <dt>Tempat, tgl. lahir</dt><dd><?= e(trim($s['tempat_lahir'] . ', ' . $s['tanggal_lahir'], ', ')) ?: '-' ?></dd>
      <dt>Tahun masuk</dt><dd><?= e($s['tahun_masuk']) ?></dd>
      <dt>Asal sekolah</dt><dd><?= e($s['asal_sekolah'] ?: '-') ?></dd>
      <dt>Alamat</dt><dd><?= e($s['alamat'] ?: '-') ?></dd>
      <dt>Potongan SPP</dt><dd><?= (int) $s['potongan_spp'] > 0 ? rp($s['potongan_spp']) . ' / bulan' : '-' ?></dd>
      <?php if ($s['catatan']): ?><dt>Catatan</dt><dd><?= e($s['catatan']) ?></dd><?php endif; ?>
    </dl>
  </div>
  <div class="card">
    <h2>Wali santri</h2>
    <dl class="kv">
      <dt>Nama</dt><dd><?= e($s['nama_wali']) ?><?= $s['hubungan_wali'] ? ' (' . e($s['hubungan_wali']) . ')' : '' ?></dd>
      <dt>No. HP</dt><dd><?= e($s['hp_wali'] ?: '-') ?></dd>
      <dt>Pendaftaran</dt><dd><?= $s['sumber'] === 'online' ? 'Online' : ($s['sumber'] === 'impor' ? 'Impor data' : 'Input petugas') ?></dd>
    </dl>
  </div>
</div>



<?php if (Auth::can('santri.profil')): $pfv = static fn(string $k) => e((string) ($pf[$k] ?? '')) ?: '-'; ?>
<div class="grid g2" style="margin-bottom:16px">
  <div class="card">
    <h2>Identitas</h2>
    <dl class="kv">
      <dt>NISN</dt><dd><?= $pfv('nisn') ?></dd><dt>NIS (buku induk)</dt><dd><?= $pfv('nis_internal') ?></dd>
      <dt>NIK</dt><dd><?= $pfv('nik') ?></dd><dt>No. KK</dt><dd><?= $pfv('no_kk') ?></dd>
      <dt>Tanggal masuk</dt><dd><?= $pfv('tgl_masuk') ?></dd><dt>VA lama</dt><dd><?= $pfv('va_lama') ?></dd>
      <dt>NPSN asal sekolah</dt><dd><?= $pfv('npsn_asal') ?></dd><dt>Alamat asal sekolah</dt><dd><?= $pfv('alamat_asal') ?></dd>
      <dt>Saudara / anak ke</dt><dd><?= $pfv('jumlah_saudara') ?> / <?= $pfv('anak_ke') ?></dd>
      <dt>Tahun ajaran</dt><dd><?= $pfv('tahun_ajaran') ?></dd><dt>Usia masuk</dt><dd><?= $pfv('usia_masuk') ?></dd><dt>Keterangan</dt><dd><?= $pfv('keterangan') ?></dd>
    </dl>
  </div>
  <div class="card">
    <h2>Orang tua</h2>
    <dl class="kv">
      <dt>Ayah</dt><dd><?= $pfv('ayah_nama') ?></dd><dt>NIK ayah</dt><dd><?= $pfv('ayah_nik') ?></dd>
      <dt>Pendidikan / pekerjaan</dt><dd><?= $pfv('ayah_pendidikan') ?> / <?= $pfv('ayah_pekerjaan') ?></dd>
      <dt>Penghasilan</dt><dd><?= $pfv('ayah_penghasilan') ?></dd><dt>HP/WA ayah</dt><dd><?= $pfv('hp_ayah') ?></dd>
      <dt>Ibu</dt><dd><?= $pfv('ibu_nama') ?></dd><dt>NIK ibu</dt><dd><?= $pfv('ibu_nik') ?></dd>
      <dt>Pendidikan / pekerjaan</dt><dd><?= $pfv('ibu_pendidikan') ?> / <?= $pfv('ibu_pekerjaan') ?></dd>
      <dt>Penghasilan</dt><dd><?= $pfv('ibu_penghasilan') ?></dd><dt>HP/WA ibu</dt><dd><?= $pfv('hp_ibu') ?></dd>
    </dl>
  </div>
</div>
<?php endif; ?>

<?php if ($keu): ?>
<div class="card">
  <h2>Tagihan</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Tagihan</th><th class="r">Nominal</th><th class="r">Dibayar</th><th class="r">Sisa</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($tagihan, 0, 24) as $t): ?>
      <tr><td data-label="Tagihan"><b><?= e(Spp::label($t)) ?></b></td><td class="r" data-label="Nominal"><?= rp($t['nominal']) ?></td><td class="r" data-label="Dibayar"><?= rp($t['dibayar']) ?></td><td class="r" data-label="Sisa"><?= rp($t['nominal'] - $t['dibayar']) ?></td><td data-label="Status"><?= status_tagihan((int) $t['nominal'], (int) $t['dibayar']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$tagihan): ?><tr><td colspan="5"><div class="empty"><?= icon('file-text') ?><div>Belum ada tagihan.</div></div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<div class="card">
  <h2>Riwayat pembayaran</h2>
  <div class="scroll"><table class="rt">
    <thead><tr><th>Kuitansi</th><th>Tanggal</th><th>Untuk</th><th class="r">Jumlah</th><th>Metode</th></tr></thead>
    <tbody>
    <?php foreach ($riwayat as $r): ?>
      <tr class="<?= $r['dibatalkan'] ? 'void' : '' ?>"><td data-label="Kuitansi"><a href="?p=kuitansi&amp;id=<?= (int) $r['id'] ?>"><?= e($r['no_kuitansi']) ?></a></td><td data-label="Tanggal"><?= e($r['tanggal']) ?></td>
        <td data-label="Untuk"><?= e(Spp::label($r)) ?></td><td class="r" data-label="Jumlah"><?= rp($r['jumlah']) ?></td><td data-label="Metode"><?= e($r['metode']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$riwayat): ?><tr><td colspan="5"><div class="empty">Belum ada pembayaran.</div></td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif;
$__th = Tahfidz::rekapSantri($id); $__kh = Kehadiran::rekapSantri($id, date('Y-m')); $__iz = Izin::untukSantri($id, 3); ?>
<div class="grid g3" style="margin-top:16px">
  <div class="card"><h2>Tahfidz</h2><b style="font-size:1.4rem"><?= str_replace('.', ',', (string) $__th['juz']) ?> juz</b><br><small class="mut"><?= $__th['bulan_ini'] ?> setoran bulan ini<?= $__th['rata'] !== null ? ' · rata nilai ' . $__th['rata'] : '' ?></small><?php if (Auth::can('tahfidz.view')): ?><br><a href="?p=tahfidz&amp;santri=<?= $id ?>">Lihat rincian</a><?php endif; ?></div>
  <div class="card"><h2>Kehadiran bulan ini</h2><b style="font-size:1.4rem"><?= $__kh['persen'] === null ? '-' : $__kh['persen'] . '%' ?></b><br><small class="mut">hadir <?= $__kh['H'] ?> · izin <?= $__kh['I'] ?> · sakit <?= $__kh['S'] ?> · alpa <?= $__kh['A'] ?></small></div>
  <div class="card"><h2>Izin terakhir</h2><?php foreach ($__iz as $z): ?><div style="margin-bottom:6px"><span class="badge iz-<?= e($z['status']) ?>"><?= e(Izin::STATUS[$z['status']]) ?></span> <?= e(Izin::JENIS[$z['jenis']]) ?> · <?= e(substr($z['mulai'], 0, 10)) ?></div><?php endforeach; ?><?php if (!$__iz): ?><span class="mut">Belum ada izin.</span><?php endif; ?></div>
</div>
<?php render($s['nama'], ob_get_clean());
