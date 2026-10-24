<?php
Auth::require('santri.edit');
$id = (int) in_str('id', '0');
$row = $id ? Santri::find($id) : null;
if ($id && !$row) { http_response_code(404); render('Tidak ditemukan', '<div class="card"><div class="empty">Santri tidak ditemukan.</div></div>'); exit; }
if ($row) { Auth::assertUnit($row['unit']); }
$errors = [];
$v = $row ?: ['status' => 'aktif', 'tahun_masuk' => date('Y'), 'potongan_spp' => 0, 'jenis_kelamin' => '', 'jenjang' => ''];

if (is_post()) {
    csrf_check();
    [$c, $errors] = Santri::validate($_POST);
    $v = array_merge($v, $c);
    if (!$errors) {
        try {
            Auth::assertUnit($c['unit']);
            if ($row) { Santri::update($id, $c, Auth::id()); flash('Data santri diperbarui.'); redirect('?p=santri_detail&id=' . $id); }
            $newId = Santri::create($c, Auth::id());
            flash('Santri ditambahkan.');
            redirect('?p=santri_detail&id=' . $newId);
        } catch (InvalidArgumentException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}
$g = static fn(string $k) => e($v[$k] ?? '');
ob_start(); ?>
<div class="page-h"><div><h1><?= $row ? 'Ubah Santri' : 'Santri Baru' ?></h1><p><?= $row ? e($row['nama']) . ' &middot; ' . e($row['nis']) : 'Isi data lengkap santri dan walinya.' ?></p></div></div>
<?php if ($errors): ?><div class="note err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card form">
  <?= csrf_field() ?>
  <fieldset><legend>Data santri</legend>
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
  <fieldset><legend>Keringanan SPP</legend>
    <label>Potongan SPP per bulan (Rp) <input name="potongan_spp" inputmode="numeric" data-rupiah value="<?= $g('potongan_spp') ?>"></label>
    <label>Catatan <input name="catatan" value="<?= $g('catatan') ?>" placeholder="mis. beasiswa yatim"></label>
    <small>Potongan berlaku untuk tagihan yang dibuat setelah ini; tagihan lama tidak berubah.</small>
  </fieldset>
  <div class="acts"><button class="btn"><?= icon('check') ?> Simpan</button> <a class="btn ghost" href="?p=<?= $row ? 'santri_detail&amp;id=' . $id : 'santri' ?>">Batal</a></div>
</form>
<?php render($row ? 'Ubah Santri' : 'Santri Baru', ob_get_clean());
