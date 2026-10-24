<?php
// Halaman login khusus POS "LH Mart & Gudang" (poslh.*). Terpisah dari login akademik.
$sistem = 'pos';
$S = Domain::SISTEM['pos'];
if (Auth::user() && Domain::sistemBoleh('pos', Auth::user()['role'])) {
    $_SESSION['sistem'] = 'pos';
    redirect('?p=' . Domain::home('pos', Auth::user()['role']));
}
$err = '';
if (is_post()) {
    csrf_check();
    if (Auth::lockedSeconds() > 0) {
        $err = 'Terlalu banyak percobaan. Coba lagi ' . Auth::lockedSeconds() . ' detik lagi.';
    } elseif (Auth::attempt(in_str('username'), (string) ($_POST['password'] ?? ''))) {
        if (Domain::sistemBoleh('pos', Auth::user()['role'])) {
            $_SESSION['sistem'] = 'pos';
            redirect('?p=' . Domain::home('pos', Auth::user()['role']));
        }
        Auth::logout();
        session_start();
        $err = 'Akun ini tidak punya akses ke LH Mart & Gudang.';
    } elseif (Auth::$ipBlocked) {
        $err = 'Terlalu banyak percobaan dari jaringan ini. Coba lagi beberapa menit lagi.';
    } else {
        $err = Auth::lockedSeconds() > 0 ? 'Terlalu banyak percobaan. Tunggu 1 menit.' : 'Username atau password salah.';
    }
}
$lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
ob_start(); ?>
<div class="auth sys-pos auth-foto auth-foto-pos">
  <section class="auth-hero">
    <div class="brand"><span class="logo" style="background:rgba(255,255,255,.18)"><?= icon('cart', 20) ?></span><div><b>LH Mart &amp; Gudang</b><small style="color:rgba(255,255,255,.8)"><?= e($lembaga) ?></small></div></div>
    <div>
      <h1>LH Mart &amp; Gudang</h1>
      <p><?= e($S['desc']) ?></p>
    </div>
    <small style="opacity:.8">Akses khusus petugas kasir dan gudang.</small>
  </section>
  <section class="auth-box">
    <div class="card">
      <div class="auth-mobile-brand"><span class="logo"><?= icon('cart', 20) ?></span><div><b>LH Mart &amp; Gudang</b><small class="mut"><?= e($lembaga) ?></small></div></div>
      <h2 class="big" style="margin-bottom:4px">Masuk Kasir</h2>
      <p class="mut" style="margin:0 0 16px">Gunakan akun kasir atau petugas gudang.</p>
      <?php if ($err): ?><div class="note err"><?= e($err) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Username <input name="username" required autofocus autocapitalize="none"></label>
        <label>Password <input type="password" name="password" required></label>
        <button class="btn" style="width:100%">Masuk <?= icon('arrow-right') ?></button>
      </form>
    </div>
  </section>
</div>
<?php render('Masuk Kasir - LH Mart & Gudang', ob_get_clean(), 'bare');
