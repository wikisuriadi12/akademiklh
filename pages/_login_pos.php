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
    $robot = LoginGuard::cek($_POST);
    if ($robot !== null) {
        $err = $robot;
    } elseif (!LoginGuard::inputWajar(in_str('username'), (string) ($_POST['password'] ?? ''))) {
        $err = 'Username atau password salah.';
    } elseif (Auth::lockedSeconds() > 0) {
        $err = 'Terlalu banyak percobaan. Coba lagi ' . Auth::lockedSeconds() . ' detik lagi.';
    } elseif (Auth::attempt(in_str('username'), (string) ($_POST['password'] ?? ''))) {
        if (Domain::sistemBoleh('pos', Auth::user()['role'])) {
            $_SESSION['sistem'] = 'pos';
            redirect('?p=' . Domain::home('pos', Auth::user()['role']));
        }
        Auth::logout();
        session_start();
        $err = 'Akun ini tidak punya akses ke LH Mart & Gudang.';
    } elseif (Auth::$userBlocked) {
        $err = 'Terlalu banyak percobaan untuk akun ini. Coba lagi 15 menit lagi atau hubungi admin.';
    } elseif (Auth::$ipBlocked) {
        $err = 'Terlalu banyak percobaan dari jaringan ini. Coba lagi beberapa menit lagi.';
    } else {
        $err = Auth::lockedSeconds() > 0 ? 'Terlalu banyak percobaan. Tunggu 1 menit.' : 'Username atau password salah.';
    }
}
$lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
LoginGuard::header();
$soal = LoginGuard::siapkan();
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
      <form method="post" id="f-login">
        <?= csrf_field() ?>
        <label>Username <input name="username" required autofocus autocapitalize="none" maxlength="64" autocomplete="username"></label>
        <label>Password <input type="password" name="password" required maxlength="200" autocomplete="current-password"></label>
        <?= LoginGuard::bidang() ?>
        <div class="captcha-box">
          <?php if ($soal === null): ?><img src="?p=captcha&amp;v=<?= time() ?>" alt="Kode verifikasi" width="230" height="76" id="cap-img"><button type="button" class="btn ghost sm" id="cap-ulang" title="Kode baru">&#8635; Kode baru</button>
          <?php else: ?><p style="margin:0"><b><?= e($soal) ?></b></p><?php endif; ?>
          <label>Ketik kode di atas <input name="captcha" required autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="12" inputmode="<?= $soal === null ? 'text' : 'numeric' ?>"></label>
        </div>
        <button class="btn" style="width:100%">Masuk <?= icon('arrow-right') ?></button>
      </form>
      <script>(function(){var b=document.getElementById('cap-ulang'),i=document.getElementById('cap-img');if(b&&i){b.addEventListener('click',function(){i.src='?p=captcha&baru=1&v='+Date.now();var c=document.querySelector('input[name=captcha]');if(c){c.value='';c.focus();}});}})();</script>
    </div>
  </section>
</div>
<?php render('Masuk Kasir - LH Mart & Gudang', ob_get_clean(), 'bare');
