<?php
// Pendaftaran santri baru (publik). Masuk sebagai "calon" menunggu verifikasi petugas.
$buka = Settings::get('pendaftaran_buka') === '1';
$cfgS = Settings::all();
$errors = [];
$v = [];

if (in_str('selesai') === '1' && !empty($_SESSION['daftar_ok'])) {
    $ok = $_SESSION['daftar_ok'];
    ob_start(); ?>
<div class="card" style="max-width:560px;margin:0 auto;text-align:center">
  <div class="kpi" style="border:0;box-shadow:none;justify-content:center"><div class="chip c-ok"><?= icon('check', 26) ?></div></div>
  <h1 style="margin:8px 0">Pendaftaran terkirim</h1>
  <p>Jazakumullahu khairan. Pendaftaran <b><?= e($ok['nama']) ?></b> telah kami terima dan akan diverifikasi petugas.</p>
  <div class="amt" style="font-size:1.3rem;background:var(--surface2);padding:14px;border-radius:10px;margin:14px 0"><small style="display:block;font-weight:400">Nomor pendaftaran</small><b><?= e($ok['nis']) ?></b></div>
  <p class="mut" style="font-size:.9rem">Simpan nomor ini. Petugas akan menghubungi wali melalui nomor HP yang didaftarkan.</p>
  <a class="btn ghost" href="?p=cek">Cek tagihan</a>
</div>
<?php
    render('Pendaftaran Terkirim', ob_get_clean(), true);
    exit;
}

if (!$buka) {
    render('Pendaftaran', '<div class="card" style="max-width:560px;margin:0 auto"><div class="empty">' . icon('alert') . '<div><b>Pendaftaran online sedang ditutup.</b><br>Silakan hubungi admin' . ($cfgS['telepon'] ? ' di ' . e($cfgS['telepon']) : '') . '.</div></div></div>', true);
    exit;
}

if (is_post()) {
    csrf_check();
    $ts = (int) ($_SESSION['daftar_ts'] ?? 0);
    if (in_str('website') !== '') {
        // jebakan bot: berpura-pura berhasil
        redirect('?p=daftar');
    }
    if ($ts === 0 || time() - $ts < 4) {
        $errors[] = 'Formulir terkirim terlalu cepat atau sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif (!RateLimit::hit('daftar', RateLimit::ip(), 5, 3600)) {
        $errors[] = 'Terlalu banyak pendaftaran dari jaringan ini. Silakan coba lagi nanti atau hubungi admin.';
    } else {
        $v = $_POST;
        try {
            $s = Santri::daftarOnline($_POST);
            $_SESSION['daftar_ok'] = ['nis' => $s['nis'], 'nama' => $s['nama']];
            unset($_SESSION['daftar_ts']);
            redirect('?p=daftar&selesai=1');
        } catch (InvalidArgumentException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}
$_SESSION['daftar_ts'] = $_SESSION['daftar_ts'] ?? time();
if (!is_post()) { $_SESSION['daftar_ts'] = time(); }
$g = static fn(string $k) => e($v[$k] ?? '');
ob_start(); ?>
<div class="page-h"><div><h1>Pendaftaran Santri Baru</h1><p>Isi data calon santri dan wali. Petugas kami akan menghubungi Anda setelah verifikasi.</p></div></div>
<?php if ($errors): ?><div class="note err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card form" autocomplete="off">
  <?= csrf_field() ?>
  <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
  <fieldset><legend>Calon santri</legend>
    <label>Nama lengkap <input name="nama" value="<?= $g('nama') ?>" required></label>
    <label>Jenis kelamin <select name="jenis_kelamin" required><option value="">Pilih...</option><?= options(['L' => 'Laki-laki', 'P' => 'Perempuan'], (string) ($v['jenis_kelamin'] ?? '')) ?></select></label>
    <label>Jenjang yang dituju <select name="jenjang" required><option value="">Pilih...</option><?= options(Domain::JENJANG, (string) ($v['jenjang'] ?? ''), false) ?></select></label>
    <label>Tempat lahir <input name="tempat_lahir" value="<?= $g('tempat_lahir') ?>"></label>
    <label>Tanggal lahir <input type="date" name="tanggal_lahir" value="<?= $g('tanggal_lahir') ?>"></label>
    <label>Asal sekolah <input name="asal_sekolah" value="<?= $g('asal_sekolah') ?>"></label>
    <label class="full">Alamat <textarea name="alamat" rows="2"><?= $g('alamat') ?></textarea></label>
  </fieldset>
  <fieldset><legend>Wali</legend>
    <label>Nama wali <input name="nama_wali" value="<?= $g('nama_wali') ?>" required></label>
    <label>Hubungan <input name="hubungan_wali" value="<?= $g('hubungan_wali') ?>" placeholder="Ayah / Ibu / Wali"></label>
    <label>No. HP / WhatsApp <input name="hp_wali" value="<?= $g('hp_wali') ?>" inputmode="tel" required></label>
  </fieldset>
  <button class="btn"><?= icon('check') ?> Kirim pendaftaran</button>
</form>
<?php render('Pendaftaran Santri Baru', ob_get_clean(), true);
