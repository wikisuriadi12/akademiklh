<?php
// Akun petugas LH Mart & Gudang: admin, kasir, petugas gudang. Hanya admin.
Auth::require('users');
$peran = ['kasir' => 'Kasir', 'gudang' => 'Petugas gudang', 'admin' => 'Admin (semua sistem)'];
if (is_post()) {
    csrf_check();
    try {
        $aksi = in_str('aksi');
        $uid = (int) in_str('uid');
        if ($aksi === 'qris') {
            Settings::set('qris_nama', mb_substr(trim(in_str('qris_nama')), 0, 80) ?: Settings::DEFAULTS['qris_nama']);
            Settings::set('qris_nmid', mb_substr((string) preg_replace('/[^A-Za-z0-9]/', '', in_str('qris_nmid')), 0, 30));
            $f = $_FILES['qris_gambar'] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (!is_uploaded_file((string) $f['tmp_name'])) { throw new InvalidArgumentException('Unggahan tidak valid.'); }
                Qris::simpan($f);
            }
            $aktif = in_str('qris_aktif') === '1';
            if ($aktif && Qris::url() === null) { throw new InvalidArgumentException('Unggah gambar QR dulu sebelum mengaktifkan QRIS.'); }
            Settings::set('qris_aktif', $aktif ? '1' : '0');
            Db::audit(Auth::id(), 'ubah', 'pengaturan', null, 'qris ' . ($aktif ? 'aktif' : 'nonaktif'));
            flash('Pengaturan QRIS disimpan.');
            redirect('?p=akun_pos');
        }
        if ($aksi !== 'tambah') {
            $r = Db::val('SELECT role FROM users WHERE id = ?', [$uid]);
            if (!$r || !isset($peran[$r])) { throw new InvalidArgumentException('Akun ini tidak diatur dari halaman ini.'); }
        }
        if ($aksi === 'ubah') {
            if (!isset($peran[in_str('role')])) { throw new InvalidArgumentException('Peran tidak valid.'); }
            Auth::updateUser($uid, in_str('nama'), in_str('role'), 'semua', Auth::id());
            flash('Data akun diperbarui. Akun itu perlu login ulang.');
        } elseif ($aksi === 'reset') {
            Auth::resetPassword($uid, (string) ($_POST['password'] ?? ''), Auth::id());
            flash('Password direset. Sampaikan password baru secara langsung kepada petugas.', 'warn');
        } elseif ($aksi === 'status') {
            if ($uid === Auth::id()) { throw new InvalidArgumentException('Tidak dapat menonaktifkan akun sendiri.'); }
            if (in_str('aktif') !== '1' && Db::val('SELECT role FROM users WHERE id = ?', [$uid]) === 'admin' && Auth::adminAktifLain($uid) < 1) { throw new InvalidArgumentException('Harus tersisa minimal satu admin aktif.'); }
            Db::exec('UPDATE users SET aktif = ?, session_version = session_version + 1 WHERE id = ?', [in_str('aktif') === '1' ? 1 : 0, $uid]);
            Db::audit(Auth::id(), 'status', 'users', $uid, 'aktif=' . in_str('aktif'));
            flash('Status akun diperbarui.');
        } else {
            if (!isset($peran[in_str('role')])) { throw new InvalidArgumentException('Peran tidak valid.'); }
            Auth::createUser(in_str('username'), in_str('nama'), in_str('role'), 'semua', (string) ($_POST['password'] ?? ''), Auth::id());
            flash('Akun ditambahkan.');
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=akun_pos');
}
$ed = ($eid = (int) in_str('edit', '0')) ? Db::one('SELECT id, username, nama, role, unit_akses, aktif FROM users WHERE id = ? AND role IN (\'kasir\',\'gudang\',\'admin\')', [$eid]) : null;
$users = Db::all('SELECT id, username, nama, role, unit_akses, aktif FROM users WHERE role IN (\'kasir\',\'gudang\',\'admin\') ORDER BY role, username');
$unitOpt = ['semua' => 'Semua unit', 'ikhwan' => 'Ikhwan saja', 'akhwat' => 'Akhwat saja'];
ob_start(); ?>
<div class="page-h"><div><h1>Akun LH Mart &amp; Gudang</h1><p><?= count($users) ?> akun: admin, kasir, dan petugas gudang</p></div></div>
<div class="card">
  <div class="scroll"><table class="rt">
    <thead><tr><th>Petugas</th><th>Peran</th><th>Login terakhir</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $ll = Auth::lastLogin((int) $u['id']); ?>
      <tr><td class="who-c"><div class="who"><?= avatar($u['nama']) ?><span><b><?= e($u['nama']) ?></b><small><?= e($u['username']) ?></small></span></div></td>
        <td data-label="Peran"><?= e($peran[$u['role']]) ?></td>
        <td data-label="Login terakhir"><?= $ll ? e(substr($ll, 0, 16)) : '<span class="mut">Belum pernah</span>' ?></td>
        <td data-label="Status"><?= $u['aktif'] ? '<span class="badge b-aktif">Aktif</span>' : '<span class="badge">Nonaktif</span>' ?></td>
        <td class="r"><a class="btn sm ghost" href="?p=akun_pos&amp;edit=<?= (int) $u['id'] ?>#form-edit">Kelola</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if ($ed): ?>
<div class="grid g2" id="form-edit" style="margin:16px 0">
  <form method="post" class="card form" autocomplete="off"><h2>Ubah akun: <?= e($ed['username']) ?></h2><?= csrf_field() ?><input type="hidden" name="aksi" value="ubah"><input type="hidden" name="uid" value="<?= (int) $ed['id'] ?>">
    <label>Nama <input name="nama" value="<?= e($ed['nama']) ?>" required></label>
    <label>Peran <select name="role"><?= options($peran, $ed['role']) ?></select></label>
    <button class="btn"><?= icon('check') ?> Simpan</button></form>
  <div>
    <form method="post" class="card form" autocomplete="off"><h2>Reset password</h2><?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="uid" value="<?= (int) $ed['id'] ?>">
      <label>Password baru (min. 10 karakter) <input type="password" name="password" required minlength="10"></label>
      <button class="btn ghost" data-confirm="Reset password akun ini? Semua sesinya berakhir."><?= icon('key') ?> Reset password</button></form>
    <?php if ((int) $ed['id'] !== Auth::id()): ?>
    <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="aksi" value="status"><input type="hidden" name="uid" value="<?= (int) $ed['id'] ?>"><input type="hidden" name="aktif" value="<?= $ed['aktif'] ? 0 : 1 ?>">
      <h2><?= $ed['aktif'] ? 'Nonaktifkan akun' : 'Aktifkan akun' ?></h2><button class="btn <?= $ed['aktif'] ? 'danger' : '' ?>"><?= $ed['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<form method="post" class="card form" autocomplete="off" style="margin-top:16px">
  <h2>Tambah akun</h2>
  <p class="mut" style="margin:0 0 12px">Kasir untuk transaksi, petugas gudang untuk stok, admin untuk semua pengaturan. Akun admin juga bisa masuk ke sistem akademik.</p>
  <?= csrf_field() ?>
  <input type="hidden" name="aksi" value="tambah">
  <fieldset style="border:0;padding:0;margin:0">
    <label>Username <input name="username" required autocapitalize="none"></label>
    <label>Nama <input name="nama" required></label>
    <label>Peran <select name="role"><?= options($peran, 'kasir') ?></select></label>
    <label>Password awal (min. 10 karakter) <input type="password" name="password" required minlength="10"></label>
  </fieldset>
  <button class="btn"><?= icon('plus') ?> Tambah</button>
</form>
<form method="post" enctype="multipart/form-data" class="card form" autocomplete="off" style="margin-top:16px">
  <h2>Pembayaran QRIS kasir</h2>
  <?= csrf_field() ?><input type="hidden" name="aksi" value="qris">
  <label class="chk full"><input type="checkbox" name="qris_aktif" value="1" <?= Settings::get('qris_aktif') === '1' ? 'checked' : '' ?>> Tampilkan QRIS sebagai metode bayar di kasir</label>
  <label>Nama merchant <input name="qris_nama" value="<?= e(Settings::get('qris_nama')) ?>" maxlength="80"></label>
  <label>NMID <input name="qris_nmid" value="<?= e(Settings::get('qris_nmid')) ?>" maxlength="30" placeholder="ID..."></label>
  <label class="full">Gambar QR (JPG/PNG, maks. 3 MB) <input type="file" name="qris_gambar" accept="image/png,image/jpeg"></label>
  <?php if (Qris::url() !== null): ?><div class="full"><small>QR saat ini:</small><br><img src="<?= e(Qris::url()) ?>" alt="QRIS" style="max-width:220px;height:auto;border-radius:8px"></div><?php endif; ?>
  <small>QRIS statis tanpa nominal dan tanpa notifikasi otomatis. Kasir mengecek notifikasi di aplikasi merchant lalu mencentang konfirmasi sebelum menyimpan transaksi. Berlaku untuk pelanggan umum, pegawai, dan santri.</small>
  <button class="btn" style="margin-top:12px"><?= icon('check') ?> Simpan QRIS</button>
</form>
<?php render('Akun LH Mart & Gudang', ob_get_clean());
