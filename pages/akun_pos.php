<?php
// Akun petugas LH Mart & Gudang: admin, kasir, petugas gudang. Hanya admin.
Auth::require('users');
$peran = ['kasir' => 'Kasir', 'gudang' => 'Petugas gudang', 'admin' => 'Admin (semua sistem)'];
if (is_post()) {
    csrf_check();
    try {
        $aksi = in_str('aksi');
        $uid = (int) in_str('uid');
        if ($aksi !== 'tambah') {
            $r = Db::val('SELECT role FROM users WHERE id = ?', [$uid]);
            if (!$r || !isset($peran[$r])) { throw new InvalidArgumentException('Akun ini tidak diatur dari halaman ini.'); }
        }
        if ($aksi === 'ubah') {
            if (!isset($peran[in_str('role')])) { throw new InvalidArgumentException('Peran tidak valid.'); }
            Auth::updateUser($uid, in_str('nama'), in_str('role'), in_str('unit'), Auth::id());
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
            Auth::createUser(in_str('username'), in_str('nama'), in_str('role'), in_str('unit'), (string) ($_POST['password'] ?? ''), Auth::id());
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
    <thead><tr><th>Petugas</th><th>Peran</th><th>Unit</th><th>Login terakhir</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $ll = Auth::lastLogin((int) $u['id']); ?>
      <tr><td class="who-c"><div class="who"><?= avatar($u['nama']) ?><span><b><?= e($u['nama']) ?></b><small><?= e($u['username']) ?></small></span></div></td>
        <td data-label="Peran"><?= e($peran[$u['role']]) ?></td>
        <td data-label="Unit"><?= e($u['unit_akses'] === 'semua' ? 'Semua' : Domain::UNIT[$u['unit_akses']]) ?></td>
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
    <label>Cakupan unit <select name="unit"><?= options($unitOpt, $ed['unit_akses']) ?></select></label>
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
    <label>Cakupan unit <select name="unit"><?= options($unitOpt) ?></select></label>
    <label>Password awal (min. 10 karakter) <input type="password" name="password" required minlength="10"></label>
  </fieldset>
  <button class="btn"><?= icon('plus') ?> Tambah</button>
</form>
<?php render('Akun LH Mart & Gudang', ob_get_clean());
