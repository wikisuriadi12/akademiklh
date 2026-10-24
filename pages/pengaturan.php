<?php
Auth::require('pengaturan');
if (is_post()) {
    csrf_check();
    try {
        $aksi = in_str('aksi');
        if ($aksi === 'jenis_tambah') {
            Spp::tambahJenis(in_str('kode'), in_str('nama'), Auth::id());
            flash('Jenis biaya ditambahkan.');
        } elseif ($aksi === 'jenis_status') {
            Spp::setJenisAktif((int) in_str('id'), in_str('aktif') === '1', Auth::id());
            flash('Status jenis biaya diperbarui.');
        } else {
            foreach (['nama_lembaga', 'alamat', 'telepon', 'wa_admin', 'rekening'] as $k) {
                Settings::set($k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $k === 'rekening' ? 400 : 200));
            }
            if (trim(Settings::get('nama_lembaga')) === '') { Settings::set('nama_lembaga', Settings::DEFAULTS['nama_lembaga']); }
            Settings::set('pendaftaran_buka', in_str('pendaftaran_buka') === '1' ? '1' : '0');
            Settings::set('izin_wali', in_str('izin_wali') === '1' ? '1' : '0');
            Settings::set('sesi_hadir', mb_substr(trim(in_str('sesi_hadir')), 0, 300) ?: Settings::DEFAULTS['sesi_hadir']);
            Settings::set('va_aktif', in_str('va_aktif') === '1' ? '1' : '0');
            Settings::set('va_jam', (string) max(1, min(720, (int) in_str('va_jam', '72'))));
            Settings::set('biaya_pendaftaran', (string) (int) preg_replace('/\D/', '', in_str('biaya_pendaftaran')));
            Db::audit(Auth::id(), 'ubah', 'pengaturan', null, 'pengaturan lembaga');
            flash('Pengaturan disimpan.');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=pengaturan');
}
$s = Settings::all();
$jenis = Spp::jenisList(false);
ob_start(); ?>
<div class="page-h"><div><h1>Pengaturan</h1><p>Identitas lembaga untuk kuitansi, pendaftaran, dan portal wali.</p></div></div>
<form method="post" class="card form">
  <?= csrf_field() ?>
  <fieldset><legend>Identitas lembaga</legend>
    <label>Nama lembaga <input name="nama_lembaga" value="<?= e($s['nama_lembaga']) ?>" required></label>
    <label>Telepon <input name="telepon" value="<?= e($s['telepon']) ?>"></label>
    <label>WhatsApp admin (untuk portal wali) <input name="wa_admin" value="<?= e($s['wa_admin']) ?>" inputmode="tel" placeholder="0812..."></label>
    <label class="full">Alamat <input name="alamat" value="<?= e($s['alamat']) ?>"></label>
    <label class="full">Rekening pembayaran (tampil di portal wali &amp; pesan pengingat) <textarea name="rekening" rows="2" placeholder="BSI 1234567890 a.n. Yayasan Luqmanul Hakim"><?= e($s['rekening']) ?></textarea></label>
  </fieldset>
  <fieldset><legend>Pendaftaran santri baru</legend>
    <label class="chk full"><input type="checkbox" name="pendaftaran_buka" value="1" <?= $s['pendaftaran_buka'] === '1' ? 'checked' : '' ?>> Buka formulir pendaftaran online</label>
    <label>Biaya pendaftaran (Rp) <input name="biaya_pendaftaran" data-rupiah inputmode="numeric" value="<?= (int) $s['biaya_pendaftaran'] ?>"></label>
    <small>Bila diisi, saat calon santri diterima akan muncul pilihan membuat tagihan uang pendaftaran dengan nominal ini.</small>
  </fieldset>
  <fieldset><legend>Akademik &amp; kepengasuhan</legend>
    <label class="full">Sesi kehadiran (pisahkan dengan koma) <input name="sesi_hadir" value="<?= e($s['sesi_hadir']) ?>"></label>
    <label class="chk full"><input type="checkbox" name="izin_wali" value="1" <?= $s['izin_wali'] === '1' ? 'checked' : '' ?>> Wali boleh mengajukan izin dari portal wali</label>
  </fieldset>
  <fieldset><legend>Pembayaran online (BNI Virtual Account)</legend>
    <label class="chk full"><input type="checkbox" name="va_aktif" value="1" <?= $s['va_aktif'] === '1' ? 'checked' : '' ?>> Izinkan wali membuat VA BNI sendiri dari portal wali</label>
    <label>Masa berlaku VA (jam) <input name="va_jam" inputmode="numeric" value="<?= (int) $s['va_jam'] ?>"></label>
    <small>Mode saat ini: <b><?= e(Bni::mode()) ?></b><?= Bni::siap() ? '' : ' (kredensial BNI belum lengkap di config.php)' ?>. Alamat callback untuk didaftarkan ke BNI: <code>https://DOMAIN-ANDA/?p=bni_callback<?= Bni::cfg()['secret_cb'] !== '' ? '&amp;k=TOKEN' : '' ?></code>. Kunci rahasia BNI hanya di <code>config.php</code>.</small>
  </fieldset>
  <button class="btn"><?= icon('check') ?> Simpan pengaturan</button>
</form>

<div class="card">
  <h2>Jenis biaya</h2>
  <div class="scroll"><table>
    <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($jenis as $j): ?>
      <tr><td><b><?= e($j['kode']) ?></b></td><td><?= e($j['nama']) ?></td><td><?= $j['tipe'] === 'bulanan' ? 'Bulanan (tarif)' : 'Sekali / per kegiatan' ?></td>
        <td><?= $j['aktif'] ? '<span class="badge b-aktif">Aktif</span>' : '<span class="badge">Nonaktif</span>' ?></td>
        <td class="r"><?php if ($j['kode'] !== 'SPP'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="aksi" value="jenis_status"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>"><input type="hidden" name="aktif" value="<?= $j['aktif'] ? 0 : 1 ?>"><button class="btn sm ghost"><?= $j['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <form method="post" class="filters" style="margin:14px 0 0">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="jenis_tambah">
    <input name="kode" placeholder="KODE (mis. QURBAN)" maxlength="20" required style="text-transform:uppercase">
    <input name="nama" placeholder="Nama biaya" maxlength="60" required>
    <button class="btn ghost"><?= icon('plus') ?> Tambah jenis</button>
  </form>
</div>
<?php render('Pengaturan', ob_get_clean());
