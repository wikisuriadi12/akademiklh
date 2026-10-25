<?php
$hs = Host::sistem();
$sistem = in_array($hs, ['pendidikan', 'pos'], true) ? $hs : in_str('sistem');
if (!isset(Domain::SISTEM[$sistem])) { redirect(Host::url('portal')); }
// POS LH Mart & Gudang punya halaman login sendiri, terpisah dari sistem akademik.
if ($sistem === 'pos') { require __DIR__ . '/_login_pos.php'; return; }
$S = Domain::SISTEM[$sistem];
if (Auth::user() && Domain::sistemBoleh($sistem, Auth::user()['role'])) {
    $_SESSION['sistem'] = $sistem;
    redirect('?p=' . Domain::home($sistem, Auth::user()['role']));
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
        if (Domain::sistemBoleh($sistem, Auth::user()['role'])) {
            $_SESSION['sistem'] = $sistem;
            redirect('?p=' . Domain::home($sistem, Auth::user()['role']));
        }
        Auth::logout();
        session_start();
        $err = 'Akun ini tidak punya akses ke ' . $S['nama'] . '. Gunakan pintu masuk sistem bagian Anda.';
    } elseif (Auth::$userBlocked) {
        $err = 'Terlalu banyak percobaan untuk akun ini. Coba lagi 15 menit lagi atau hubungi admin.';
    } elseif (Auth::$ipBlocked) {
        $err = 'Terlalu banyak percobaan dari jaringan ini. Coba lagi beberapa menit lagi.';
    } else {
        $err = Auth::lockedSeconds() > 0 ? 'Terlalu banyak percobaan. Tunggu 1 menit.' : 'Username atau password salah.';
    }
}
$lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
$foto = $sistem === 'pendidikan';
$kelas = ['pendidikan' => ' auth-foto'][$sistem] ?? '';
$portalAda = !Host::aktif() || !empty(Host::cfg()['portal_utama']);
LoginGuard::header();
$soal = LoginGuard::siapkan();
ob_start(); ?>
<div class="auth sys-<?= e($sistem) ?><?= $kelas ?>">
  <section class="auth-hero">
    <div class="brand"><span class="logo" style="background:rgba(255,255,255,.18)"><?= icon('book', 20) ?></span><div><b><?= e($lembaga) ?></b><small style="color:rgba(255,255,255,.8)"><?= e($S['nama']) ?></small></div></div>
    <div>
      <?php if ($foto): ?>
        <span class="auth-tag">TKQ · Ula · Wustha · Ulya</span>
        <h1>Sistem Akademik<br>Pondok Pesantren</h1>
        <p>Data santri, tahfidz, kehadiran, perizinan, dan keuangan SPP Pondok Ikhwan &amp; Akhwat dalam satu tempat.</p>
      <?php else: ?>
        <h1><?= e($S['nama']) ?></h1>
        <p><?= e($S['desc']) ?></p>
        <ul>
          <li><?= icon('check') ?> Akun dan menu khusus bagian ini</li>
          <li><?= icon('check') ?> Hanya untuk: <?= e($S['untuk']) ?></li>
        </ul>
      <?php endif; ?>
    </div>
    <small style="opacity:.8">Akses khusus petugas yayasan.</small>
  </section>
  <section class="auth-box">
    <div class="card">
      <?php if ($kelas !== ''): ?><div class="auth-mobile-brand"><span class="logo"><?= icon('book', 20) ?></span><div><b><?= e($lembaga) ?></b><small class="mut"><?= $foto ? 'Sistem Akademik' : e($S['nama']) ?></small></div></div><?php endif; ?>
      <h2 class="big" style="margin-bottom:4px"><?= $foto ? 'Masuk' : 'Masuk ke ' . e($S['nama']) ?></h2>
      <p class="mut" style="margin:0 0 16px"><?= $foto ? 'Untuk admin, TU, bendahara, pimpinan, ustadz, dan pembina.' : 'Gunakan akun petugas Anda.' ?></p>
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
      <p style="margin:18px 0 0;font-size:.88rem" class="mut">
        <?php if ($portalAda): ?><a href="<?= e(Host::url('portal')) ?>">&larr; Pilih sistem lain</a> &middot; <?php endif; ?>
        Wali santri? <a href="<?= e(Host::url('wali', 'p=cek')) ?>">Cek tagihan</a>
      </p>
    </div>
  </section>
</div>
<?php render('Masuk', ob_get_clean(), 'bare');
