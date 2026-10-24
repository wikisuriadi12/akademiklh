<?php
// Pemasangan awal lewat web (sekali pakai). Aktif hanya bila config.php berisi 'setup_token'
// dan belum ada pengguna. Setelah selesai, token dihapus dari config.php secara otomatis.
$cfgPath = APP_ROOT . '/config.php';
$cfg = require $cfgPath;
$token = (string) ($cfg['setup_token'] ?? '');
$selesai = false;
$err = '';

$sudahAda = static function () use ($cfg): bool {
    try {
        $pdo = Db::pdo();
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
};

if ($token === '' || $sudahAda()) {
    http_response_code(404);
    render('Tidak tersedia', '<div class="card" style="max-width:520px;margin:40px auto"><h2>Pemasangan tidak tersedia</h2><p class="mut">Sistem sudah terpasang atau mode pemasangan tidak aktif.</p><a class="btn" href="?p=portal">Ke portal</a></div>', 'bare');
    exit;
}

if (is_post()) {
    csrf_check();
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        $err = 'Kode pemasangan salah.';
    } else {
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        try {
            $pdo = new PDO("mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4", $cfg['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        } catch (PDOException $e) {
            $pdo = null;
            $err = 'Tidak bisa masuk ke database dengan password itu. Periksa password user database ' . $cfg['db_user'] . '.';
        }
        if ($pdo) {
            try {
                Db::set($pdo);
                foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents(APP_ROOT . '/database/schema.sql')))) as $sql) {
                    $pdo->exec($sql);
                }
                if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Sudah ada pengguna di database.');
                }
                Auth::createUser(trim((string) ($_POST['username'] ?? '')), trim((string) ($_POST['nama'] ?? '')), 'admin', 'semua', (string) ($_POST['password'] ?? ''), null);
                // Simpan password database ke config.php dan matikan mode pemasangan.
                $isi = (string) file_get_contents($cfgPath);
                $esc = addcslashes($dbPass, "\\'");
                $isi = preg_replace("/'db_pass'\\s*=>\\s*'[^']*'/", "'db_pass'  => '" . str_replace(['\\', '$'], ['\\\\', '\\$'], $esc) . "'", $isi, 1);
                $isi = preg_replace("/\\n\\s*'setup_token'\\s*=>\\s*'[^']*',?/", '', $isi, 1);
                if (file_put_contents($cfgPath, $isi) === false) { throw new RuntimeException('config.php tidak dapat ditulis.'); }
                @chmod($cfgPath, 0600);
                $selesai = true;
            } catch (InvalidArgumentException $e) {
                $err = $e->getMessage();
            } catch (Throwable $e) {
                error_log('setup: ' . $e->getMessage());
                $err = 'Pemasangan gagal: ' . $e->getMessage();
            }
        }
    }
}
ob_start(); ?>
<div class="auth-box" style="min-height:100vh">
  <div class="card" style="max-width:460px;width:100%">
  <?php if ($selesai): ?>
    <h2 class="big">Pemasangan selesai</h2>
    <p>Tabel database dibuat dan akun admin siap. Mode pemasangan sudah dimatikan.</p>
    <a class="btn" href="?p=login&amp;sistem=pendidikan" style="width:100%">Masuk ke Sistem Pendidikan</a>
  <?php else: ?>
    <h2 class="big" style="margin-bottom:4px">Pemasangan awal</h2>
    <p class="mut" style="margin:0 0 16px">Sekali pakai. Halaman ini tertutup otomatis setelah berhasil.</p>
    <?php if ($err): ?><div class="note err"><?= e($err) ?></div><?php endif; ?>
    <form method="post" autocomplete="off"><?= csrf_field() ?>
      <label>Kode pemasangan <input name="token" required autocapitalize="none"></label>
      <label>Password user database <small class="mut">(<?= e($cfg['db_user']) ?>)</small> <input type="password" name="db_pass" required></label>
      <hr style="border:0;border-top:1px solid var(--line);margin:14px 0">
      <label>Username admin <input name="username" required autocapitalize="none" pattern="[a-z0-9._-]{3,50}" placeholder="mis. wiki"></label>
      <label>Nama lengkap admin <input name="nama" required></label>
      <label>Password admin (min. 10 karakter) <input type="password" name="password" required minlength="10"></label>
      <button class="btn" style="width:100%">Pasang sekarang</button>
    </form>
  <?php endif; ?>
  </div>
</div>
<?php render('Pemasangan', ob_get_clean(), 'bare');
