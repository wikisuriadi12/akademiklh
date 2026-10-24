<?php
// Pemasangan awal (hanya CLI): php tools/install.php
// Membuat tabel yang belum ada dan akun admin pertama. Tidak membuat akun bawaan.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Hanya dapat dijalankan dari CLI.\n"); }
require dirname(__DIR__) . '/src/bootstrap.php';
if (!is_file(APP_ROOT . '/config.php')) { exit("Buat config.php dari config.example.php dulu.\n"); }

$cfg = require APP_ROOT . '/config.php';
echo "Database: {$cfg['db_name']} @ {$cfg['db_host']}\nLanjutkan? (ketik YA): ";
if (trim((string) fgets(STDIN)) !== 'YA') { exit("Dibatalkan.\n"); }

$pdo = Db::pdo();
foreach (array_filter(array_map('trim', explode(';', file_get_contents(APP_ROOT . '/database/schema.sql')))) as $sql) {
    $pdo->exec($sql);
}
foreach (glob(APP_ROOT . '/database/migrasi-*.sql') ?: [] as $mf) {
    foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents($mf)))) as $sql) {
        $pdo->exec($sql);
    }
}
echo "Tabel siap.\n";

if ((int) Db::val('SELECT COUNT(*) FROM users') > 0) { exit("Sudah ada pengguna; tidak membuat admin baru.\n"); }

echo 'Username admin: ';
$u = trim((string) fgets(STDIN));
echo 'Nama lengkap: ';
$n = trim((string) fgets(STDIN));
echo 'Password (min. 10 karakter, tidak disembunyikan di Windows): ';
if (stripos(PHP_OS, 'WIN') !== 0) { system('stty -echo'); }
$pw = trim((string) fgets(STDIN));
if (stripos(PHP_OS, 'WIN') !== 0) { system('stty echo'); echo "\n"; }

try {
    Auth::createUser($u, $n, 'admin', 'semua', $pw, null);
    echo "Admin dibuat. Silakan login.\n";
} catch (InvalidArgumentException $e) {
    exit('Gagal: ' . $e->getMessage() . "\n");
}
