<?php
/** @var string $title @var string $content @var array $cfg @var ?array $user @var array $flash @var string $lembaga @var bool|string $public */
$cur = $_GET['p'] ?? 'dashboard';
$alias = ['santri_form' => 'santri', 'santri_detail' => 'santri', 'santri_lengkap' => 'santri', 'kuitansi' => 'bayar', 'va_baru' => 'va', 'va_detail' => 'va', 'struk' => 'penjualan', 'gudang_item' => 'gudang', 'barang' => 'gudang', 'label' => 'label', 'impor_stok' => 'impor_stok', 'barang_masuk' => 'barang_masuk', 'bukti_qris' => 'penjualan', 'laporan_pos' => 'laporan_pos'];
$cur = $alias[$cur] ?? $cur;
$asset = static fn(string $f): string => 'assets/' . $f . '?v=' . (@filemtime(__DIR__ . '/../assets/' . $f) ?: 8);
$appName = $cfg['app_name'] ?? 'SPP & Data Santri';
$showApp = $user && $public === false;
$pendaftarN = 0;
if ($showApp && Auth::can('pendaftar')) {
    try { $pendaftarN = Santri::hitungStatus('calon', Auth::scope()); } catch (Throwable $e) { $pendaftarN = 0; }
}
$rendahN = 0;
$izinN = 0;
if ($showApp && Auth::can('izin.view')) {
    try { $izinN = Izin::hitung('menunggu', Auth::scope()) + Izin::hitung('terlambat', Auth::scope()); } catch (Throwable $e) { $izinN = 0; }
}
if ($showApp && Auth::can('gudang.view')) {
    try { $rendahN = Gudang::jumlahRendah(Pos::TOKO); } catch (Throwable $e) { $rendahN = 0; }
}
$sis = $showApp ? Auth::sistem() : null;
$sisNama = $sis ? Domain::SISTEM[$sis]['nama'] : $appName;
$groups = [];
if ($showApp) {
    $groups = [
        'Utama' => [['dashboard', 'Dashboard', 'home', true, 0]],
        'Kesantrian' => [
            ['santri', 'Data Santri', 'users', Auth::can('santri.view'), 0],
            ['riwayat', 'Riwayat Santri', 'clipboard', Auth::can('santri.view'), 0],
            ['pendaftar', 'Pendaftar Baru', 'user-plus', Auth::can('pendaftar'), $pendaftarN],
            ['naik', 'Naik Kelas', 'trending-up', Auth::can('naik'), 0],
            ['impor', 'Impor Data', 'upload', Auth::can('impor'), 0],
        ],
        'Akademik' => [
            ['tahfidz', 'Tahfidz', 'book', Auth::can('tahfidz.view'), 0],
            ['kehadiran', 'Kehadiran', 'user-check', Auth::can('hadir.view'), 0],
        ],
        'Kepengasuhan' => [
            ['izin', 'Perizinan', 'clipboard', Auth::can('izin.view'), $izinN],
        ],
        'Keuangan' => [
            ['tagihan', 'Tagihan', 'file-text', Auth::can('tagihan.view'), 0],
            ['bayar', 'Pembayaran', 'credit-card', Auth::can('bayar'), 0],
            ['va', 'Bayar Online (BNI)', 'wallet', Auth::can('va'), 0],
            ['tarif', 'Tarif SPP', 'tag', Auth::can('tarif.view'), 0],
            ['laporan', 'Laporan', 'bar-chart', Auth::can('laporan'), 0],
        ],
        'Koperasi & Gudang' => [
            ['pos', 'Kasir (POS)', 'cart', Auth::can('pos'), 0],
            ['penjualan', 'Penjualan', 'receipt', Auth::can('penjualan'), 0],
            ['dompet', 'Dompet Santri', 'wallet', Auth::can('dompet'), 0],
            ['gudang', 'Stok Gudang & Market', 'box', Auth::can('gudang.view'), $rendahN],
            ['barang_masuk', 'Barang Masuk', 'plus', Auth::can('gudang.edit'), 0],
            ['laporan_pos', 'Laporan LH Mart', 'bar-chart', Auth::can('penjualan'), 0],
            ['impor_stok', 'Impor Barang & Stok', 'upload', Auth::can('gudang.edit'), 0],
            ['label', 'Cetak Label', 'tag', Auth::can('gudang.view'), 0],
            ['akun_pos', 'Akun LH Mart', 'shield', Auth::can('users'), 0],
        ],
        'Sistem' => [
            ['users', 'Pengguna', 'shield', Auth::can('users'), 0],
            ['pengaturan', 'Pengaturan', 'settings', Auth::can('pengaturan'), 0],
            ['audit', 'Log Audit', 'clipboard', Auth::can('audit'), 0],
            ['akun', 'Akun Saya', 'key', true, 0],
        ],
    ];
}
if ($sis) {
    $map = ['pendidikan' => ['Utama', 'Kesantrian', 'Akademik', 'Kepengasuhan', 'Keuangan', 'Sistem'], 'pos' => ['Koperasi & Gudang', 'Sistem']];
    $groups = array_intersect_key($groups, array_flip($map[$sis]));
    if ($sis === 'pos') {
        $groups['Sistem'] = array_values(array_filter($groups['Sistem'], static fn($i) => $i[0] === 'akun'));
    }
}
$logo = '<span class="logo">' . icon('book', 20) . '</span>';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f766e">
<title><?= e($title) ?> - <?= e($appName) ?></title>
<script>try{var t=localStorage.getItem('theme');if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link rel="stylesheet" href="<?= e($asset('app.css')) ?>">
</head>
<body class="<?= $showApp ? 'is-app' : 'is-pub' ?><?= $sis ? ' sys-' . e($sis) : '' ?>">
<?php if ($flash): ?>
<div id="toasts" role="status" aria-live="polite">
  <?php foreach ($flash as [$t, $m]): ?>
    <div class="toast <?= e($t) ?>"><?= icon($t === 'ok' ? 'check' : 'alert', 18) ?><div><?= e($m) ?></div><button class="x" data-close aria-label="Tutup"><?= icon('x', 16) ?></button></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($showApp): ?>
<div class="app">
  <aside class="sb" aria-label="Menu utama">
    <div class="brand"><?= $logo ?><div><b><?= e($lembaga) ?></b><small><?= e($sisNama) ?></small></div></div>
    <nav class="nav">
      <?php foreach ($groups as $gname => $items):
          $vis = array_filter($items, static fn($i) => $i[3]);
          if (!$vis) { continue; } ?>
        <div class="nav-h"><?= e($gname) ?></div>
        <?php foreach ($vis as [$k, $label, $ic, , $cnt]): ?>
          <a href="?p=<?= e($k) ?>" class="<?= $cur === $k ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span><?php if ($cnt > 0): ?><span class="cnt"><?= (int) $cnt ?></span><?php endif; ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sb-foot nav">
      <?php if (!Host::aktif() || !empty(Host::cfg()['portal_utama'])): ?><a href="<?= e(Host::url('portal')) ?>"><?= icon('home') ?><span>Pilih sistem lain</span></a><?php endif; ?>
      <a href="?p=logout"><?= icon('log-out') ?><span>Keluar</span></a>
    </div>
  </aside>
  <div id="scrim"></div>
  <div class="main">
    <header class="tb">
      <button class="ibtn burger" data-menu aria-label="Buka menu"><?= icon('menu', 20) ?></button>
      <span class="ttl"><?= e($title) ?></span>
      <span class="sp"></span>
      <button class="ibtn" data-theme-toggle aria-label="Ganti mode gelap/terang" title="Mode gelap/terang"><?= icon('moon', 18) ?></button>
      <div class="me"><?= avatar($user['nama']) ?><span><?= e($user['nama']) ?><small><?= e(Domain::ROLE[$user['role']] ?? $user['role']) ?><?= $user['unit_akses'] !== 'semua' ? ' &middot; ' . e(Domain::UNIT[$user['unit_akses']]) : '' ?></small></span></div>
    </header>
    <main class="content"><?= $content ?></main>
  </div>
</div>
<?php elseif ($public === 'bare'): ?>
<?= $content ?>
<?php else: ?>
<header class="pub-top">
  <a class="brand" href="?p=cek"><?= $logo ?><div><b><?= e($lembaga) ?></b><small>Layanan wali santri</small></div></a>
  <nav>
    <a href="?p=cek">Cek Tagihan</a>
    <?php if (Settings::get('pendaftaran_buka') === '1'): ?><a href="?p=daftar">Daftar Santri Baru</a><?php endif; ?>
    <a href="?p=<?= $user ? 'dashboard' : 'login' ?>"><?= $user ? 'Dashboard' : 'Masuk petugas' ?></a>
    <button class="ibtn" data-theme-toggle aria-label="Mode gelap/terang"><?= icon('moon', 18) ?></button>
  </nav>
</header>
<main class="pub"><?= $content ?></main>
<?php endif; ?>
<script src="<?= e($asset('app.js')) ?>" defer></script>
</body>
</html>
