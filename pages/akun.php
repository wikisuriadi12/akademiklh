<?php
if (!Auth::user()) { redirect(Host::masuk()); }
if (is_post()) {
    csrf_check();
    try {
        if (in_str('baru') !== (string) ($_POST['ulang'] ?? '')) { throw new InvalidArgumentException('Konfirmasi password tidak sama.'); }
        Auth::changePassword((int) Auth::id(), (string) ($_POST['lama'] ?? ''), (string) ($_POST['baru'] ?? ''));
        flash('Password diganti. Sesi di perangkat lain tidak berlaku lagi.');
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('?p=akun');
}
$u = Auth::user();
ob_start(); ?>
<div class="page-h"><div><h1>Akun Saya</h1><p><?= e($u['nama']) ?> &middot; <?= e($u['username']) ?> &middot; <?= e(Domain::ROLE[$u['role']] ?? $u['role']) ?></p></div></div>
<form method="post" class="card form" autocomplete="off" style="max-width:520px">
  <h2>Ganti password</h2>
  <?= csrf_field() ?>
  <label>Password lama <input type="password" name="lama" required></label>
  <label>Password baru (min. 10 karakter) <input type="password" name="baru" required minlength="10"></label>
  <label>Ulangi password baru <input type="password" name="ulang" required minlength="10"></label>
  <button class="btn"><?= icon('key') ?> Ganti password</button>
</form>
<?php render('Akun Saya', ob_get_clean());
