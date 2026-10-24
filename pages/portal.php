<?php
$lembaga = Settings::get('nama_lembaga', 'Yayasan Luqmanul Hakim');
$u = Auth::user();
ob_start(); ?>
<div class="portal">
  <header class="portal-h">
    <div class="brand"><span class="logo"><?= icon('book', 20) ?></span><div><b><?= e($lembaga) ?></b><small>Sistem Terpadu Pesantren</small></div></div>
    <button class="theme" data-theme-toggle aria-label="Ganti tema"><?= icon('moon', 18) ?></button>
  </header>
  <main class="portal-m">
    <h1>Pilih sistem</h1>
    <p class="mut">Setiap sistem memiliki pintu masuk sendiri. Masuk dengan akun petugas bagian Anda.</p>
    <div class="sys-grid">
      <?php foreach (Domain::SISTEM as $k => $s): ?>
      <a class="sys sys-<?= e($k) ?>" href="<?= e(Host::url($k)) ?>">
        <span class="sys-ic"><?= icon($s['icon'], 28) ?></span>
        <b><?= e($s['nama']) ?></b>
        <span class="sys-d"><?= e($s['desc']) ?></span>
        <small>Untuk: <?= e($s['untuk']) ?></small>
        <span class="sys-go">Masuk <?= icon('arrow-right', 16) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="portal-wali card">
      <div><b>Wali santri?</b><br><small class="mut">Tidak perlu akun petugas.</small></div>
      <div class="acts"><a class="btn ghost" href="<?= e(Host::url('wali', 'p=cek')) ?>"><?= icon('eye') ?> Cek tagihan</a><?php if (Settings::get('pendaftaran_buka') === '1'): ?><a class="btn ghost" href="<?= e(Host::url('wali', 'p=daftar')) ?>"><?= icon('user-plus') ?> Daftar santri baru</a><?php endif; ?></div>
    </div>
    <?php if ($u): ?><p class="mut" style="text-align:center">Masuk sebagai <b><?= e($u['nama']) ?></b> &middot; <a href="?p=logout">Keluar</a></p><?php endif; ?>
  </main>
</div>
<?php render('Pilih Sistem', ob_get_clean(), 'bare');
