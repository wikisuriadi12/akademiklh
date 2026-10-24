<?php
Auth::require('santri.edit');
$id = (int) in_str('id', '0');
$row = $id ? Santri::find($id) : null;
if ($id && !$row) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Santri tidak ditemukan.</div></div>'); exit; }
if ($row) { Auth::assertUnit($row['unit']); }
$errors = [];
$v = $row ?: ['status' => 'aktif', 'tahun_masuk' => date('Y'), 'potongan_spp' => 0, 'jenis_kelamin' => '', 'jenjang' => ''];
$pv = ($row ? Santri::profil($id) : null) ?: [];

/** Simpan/hapus foto sesuai isian formulir; mengembalikan catatan bila foto gagal (data lain tetap tersimpan). */
function urus_foto(int $sid): string
{
    try {
        if (!empty($_POST['hapus_foto'])) { Foto::hapus($sid); Db::audit(Auth::id(), 'foto', 'santri', $sid, 'hapus'); }
        $f = $_FILES['foto'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { Foto::simpan($sid, $f); Db::audit(Auth::id(), 'foto', 'santri', $sid, 'unggah'); }
    } catch (InvalidArgumentException $ex) {
        return ' Foto tidak disimpan: ' . $ex->getMessage();
    }
    return '';
}

if (is_post()) {
    csrf_check();
    [$c, $errors] = Santri::validate($_POST);
    [$pc, $pe] = Santri::validateProfil($_POST);
    $errors = array_merge($errors, $pe);
    $v = array_merge($v, $c);
    $pv = array_merge($pv, array_filter($pc, static fn($x) => $x !== null));
    if (!$errors) {
        try {
            Auth::assertUnit($c['unit']);
            if ($row) {
                Santri::update($id, $c, Auth::id()); Santri::simpanProfil($id, $pc);
                $fotoMsg = urus_foto($id);
                flash('Data santri diperbarui.' . $fotoMsg);
                redirect('?p=santri_detail&id=' . $id);
            }
            $newId = Santri::create($c, Auth::id());
            Santri::simpanProfil($newId, $pc);
            $fotoMsg = urus_foto($newId);
            flash('Santri ditambahkan.' . $fotoMsg);
            redirect('?p=santri_detail&id=' . $newId);
        } catch (InvalidArgumentException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}
$g = static fn(string $k) => e($v[$k] ?? '');
$pg = static fn(string $k) => e((string) ($pv[$k] ?? ''));
$pf = static fn(string $k, string $extra = '') => '<label>' . e(Santri::PROFIL[$k][0]) . ' <input name="' . $k . '" value="' . e((string) ($pv[$k] ?? '')) . '"' . $extra . '></label>';
ob_start(); ?>
<div class="page-h"><div><h1><?= $row ? 'Ubah Santri' : 'Santri Baru' ?></h1><p><?= $row ? e($row['nama']) . ' &middot; ' . e($row['nis']) : 'Isi data lengkap santri dan walinya.' ?></p></div></div>
<?php if ($errors): ?><div class="note err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card form">
  <?= csrf_field() ?>
  <fieldset><legend>Data santri</legend>
    <div class="foto-form">
      <div class="foto-prev" id="fotoPrev"><?php if ($row && ($u = Foto::url($id, $row['foto'] ?? null))): ?><img src="<?= e($u) ?>" alt="Foto <?= e($row['nama']) ?>"><?php else: ?>Belum ada foto<?php endif; ?></div>
      <div>
        <label>Foto santri (JPG, PNG, atau WebP, maks. 6 MB) <input type="file" name="foto" id="fotoIn" accept="image/jpeg,image/png,image/webp"></label>
        <?php if ($row && !empty($row['foto'])): ?><label style="flex-direction:row;gap:8px;align-items:center"><input type="checkbox" name="hapus_foto" value="1" style="width:auto"> Hapus foto saat ini</label><?php endif; ?>
        <small>Foto otomatis diperkecil dan hanya dapat dilihat petugas yang login.</small>
      </div>
    </div>
    <label>Nama lengkap <input name="nama" value="<?= $g('nama') ?>" required></label>
    <label>NIS <input name="nis" value="<?= $g('nis') ?>" placeholder="<?= $row ? '' : 'Kosongkan = otomatis' ?>"></label>
    <label>Jenis kelamin
      <select name="jenis_kelamin" required><option value="">Pilih...</option><?= options(['L' => 'Laki-laki (Ikhwan)', 'P' => 'Perempuan (Akhwat)'], (string) ($v['jenis_kelamin'] ?? '')) ?></select></label>
    <label>Jenjang <select name="jenjang" required><option value="">Pilih...</option><?= options(Domain::JENJANG, (string) ($v['jenjang'] ?? ''), false) ?></select></label>
    <label>Kelas <input name="kelas" value="<?= $g('kelas') ?>" placeholder="mis. 3A"></label>
    <label>Tahun masuk <input type="number" name="tahun_masuk" value="<?= $g('tahun_masuk') ?>" required></label>
    <label>Tempat lahir <input name="tempat_lahir" value="<?= $g('tempat_lahir') ?>"></label>
    <label>Tanggal lahir <input type="date" name="tanggal_lahir" value="<?= $g('tanggal_lahir') ?>"></label>
    <label>Asal sekolah <input name="asal_sekolah" value="<?= $g('asal_sekolah') ?>"></label>
    <label>Status <select name="status"><?= options(Domain::STATUS, (string) ($v['status'] ?? 'aktif')) ?></select></label>
    <label class="full">Alamat <textarea name="alamat" rows="2"><?= $g('alamat') ?></textarea></label>
  </fieldset>
  <fieldset><legend>Wali santri</legend>
    <label>Nama wali <input name="nama_wali" value="<?= $g('nama_wali') ?>" required></label>
    <label>Hubungan <input name="hubungan_wali" value="<?= $g('hubungan_wali') ?>" placeholder="Ayah / Ibu / Wali"></label>
    <label>No. HP / WhatsApp wali <input name="hp_wali" value="<?= $g('hp_wali') ?>" inputmode="tel"></label>
  </fieldset>
  <fieldset><legend>Identitas (NISN, NIK, KK)</legend>
    <?= $pf('nisn', ' inputmode="numeric"') ?><?= $pf('nis_internal') ?><?= $pf('nik', ' maxlength="30"') ?><?= $pf('no_kk', ' inputmode="numeric" maxlength="20"') ?>
    <label>Tanggal masuk <input type="date" name="tgl_masuk" value="<?= $pg('tgl_masuk') ?>"></label><?= $pf('va_lama', ' inputmode="numeric"') ?>
    <?= $pf('npsn_asal') ?><?= $pf('alamat_asal') ?>
    <?= $pf('jumlah_saudara', ' inputmode="numeric"') ?><?= $pf('anak_ke', ' inputmode="numeric"') ?>
    <?= $pf('tahun_ajaran', ' placeholder="2026-2027"') ?><?= $pf('usia_masuk', ' placeholder="mis. 15 Tahun"') ?><?= $pf('keterangan') ?>
    <small>Data pribadi: hanya Admin dan TU yang dapat melihat dan mengubahnya.</small>
  </fieldset>
  <fieldset><legend>Ayah</legend>
    <?= $pf('ayah_nama') ?><?= $pf('ayah_nik', ' maxlength="30"') ?><?= $pf('ayah_pendidikan') ?><?= $pf('ayah_pekerjaan') ?><?= $pf('ayah_penghasilan') ?><?= $pf('hp_ayah', ' inputmode="tel"') ?>
  </fieldset>
  <fieldset><legend>Ibu</legend>
    <?= $pf('ibu_nama') ?><?= $pf('ibu_nik', ' maxlength="30"') ?><?= $pf('ibu_pendidikan') ?><?= $pf('ibu_pekerjaan') ?><?= $pf('ibu_penghasilan') ?><?= $pf('hp_ibu', ' inputmode="tel"') ?>
  </fieldset>
  <fieldset><legend>Keringanan SPP</legend>
    <label>Potongan SPP per bulan (Rp) <input name="potongan_spp" inputmode="numeric" data-rupiah value="<?= $g('potongan_spp') ?>"></label>
    <label>Catatan <input name="catatan" value="<?= $g('catatan') ?>" placeholder="mis. beasiswa yatim"></label>
    <small>Potongan berlaku untuk tagihan yang dibuat setelah ini; tagihan lama tidak berubah.</small>
  </fieldset>
  <div class="acts"><button class="btn"><?= icon('check') ?> Simpan</button> <a class="btn ghost" href="?p=<?= $row ? 'santri_detail&amp;id=' . $id : 'santri' ?>">Batal</a></div>
</form>
<script>
document.getElementById('fotoIn')?.addEventListener('change', function () {
  var f = this.files && this.files[0], p = document.getElementById('fotoPrev');
  if (f && /^image\//.test(f.type)) { var i = document.createElement('img'); i.src = URL.createObjectURL(f); p.textContent = ''; p.appendChild(i); }
});
</script>
<?php render($row ? 'Ubah Santri' : 'Santri Baru', ob_get_clean());
