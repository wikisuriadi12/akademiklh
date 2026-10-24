<?php
$hs = Host::sistem();
$sistem = in_array($hs, ['pendidikan', 'pos'], true) ? $hs : in_str('sistem');
if (!isset(Domain::SISTEM[$sistem])) { redirect(Host::url('portal')); }
$S = Domain::SISTEM[$sistem];
if (Auth::user() && Domain::sistemBoleh($sistem, Auth::user()['role'])) {
    $_SESSION['sistem'] = $sistem;
    redirect('?p=' . Domain::home($sistem, Auth::user()['role']));
}
$err = '';
if (is_post()) {
    csrf_check();
    if (Auth::lockedSeconds() > 0) {
        $err = 'Terlalu banyak percobaan. Coba lagi ' . Auth::lockedSeconds() . ' detik lagi.';
    } elseif (Auth::attempt(in_str('username'), (string) ($_POST['password'] ?? ''))) {
        if (Domain::sistemBoleh($sistem, Auth::user()['role'])) {
            $_SESSION['sistem'] = $sistem;
            redirect('?p=' . Domain::home($sistem, Auth::user()['role']));
        }
        Auth::logout();
        session_start();
        $err = 'Akun ini tidak punya akses ke ' . $S['nama'] . '. Gunakan pintu masuk sistem bagian Anda.';
    } elseif (Auth::$ipBlocked) {
        $err = 'Terlalu banyak percobaan dari jaringan ini. Coba lagi beberapa menit lagi.';
    } else {
        $err = Auth::lockedSeconds() > 0 ? 'Terlalu banyak percobaan. Tunggu 1 menit.' : 'Username atau password salah.';
    }
}
$lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
ob_start(); ?>
<div class="auth sys-<?= e($sistem) ?>">
  <section class="auth-hero">
    <div class="brand"><span class="logo" style="background:rgba(255,255,255,.18)"><?= icon('book', 20) ?></span><div><b><?= e($lembaga) ?></b><small style="color:rgba(255,255,255,.75)"><?= e($S['nama']) ?></small></div></div>
    <div>
      <h1><?= e($S['nama']) ?></h1>
      <p><?= e($S['desc']) ?></p>
      <ul>
        <li><?= icon('check') ?> Akun dan menu khusus bagian ini</li>
        <li><?= icon('check') ?> Hanya untuk: <?= e($S['untuk']) ?></li>
      </ul>
    </div>
    <small style="opacity:.7">Akses khusus petugas yayasan.</small>
  </section>
  <section class="auth-box">
    <div class="card">
      <h2 class="big" style="margin-bottom:4px">Masuk ke <?= e($S['nama']) ?></h2>
      <p class="mut" style="margin:0 0 16px">Gunakan akun petugas Anda.</p>
      <?php if ($err): ?><div class="note err"><?= e($err) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Username <input name="username" required autofocus autocapitalize="none"></label>
        <label>Password <input type="password" name="password" required></label>
        <button class="btn" style="width:100%">Masuk <?= icon('arrow-right') ?></button>
      </form>
      <p style="margin:18px 0 0;font-size:.88rem" class="mut"><a href="<?= e(Host::url('portal')) ?>">&larr; Pilih sistem lain</a></p>
    </div>
  </section>
</div>
<?php render('Masuk', ob_get_clean(), 'bare');
