<?php
// Akun login SEMENTARA untuk uji poslh (kasir, gudang, bendahara, pimpinan).
// Letakkan di folder tools/ aplikasi, jalankan dari CLI (terminal cPanel / SSH):
//
//   php tools/buat_akun_sementara.php buat        -> buat akun, password acak ditampilkan SEKALI di layar
//   php tools/buat_akun_sementara.php reset       -> reset password semua akun tmp_* (password baru acak)
//   php tools/buat_akun_sementara.php nonaktifkan -> nonaktifkan semua akun tmp_* setelah uji selesai
//
// Password tidak disimpan di file mana pun dan tidak perlu dikirim ke chat.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Hanya dapat dijalankan dari CLI.\n"); }
require dirname(__DIR__) . '/src/bootstrap.php';
if (!is_file(APP_ROOT . '/config.php')) { exit("config.php belum ada.\n"); }

$aksi = $argv[1] ?? '';

// username => [nama, peran]. Semua cakupan unit "semua" supaya bisa menguji ikhwan & akhwat.
$akun = [
    'tmp_kasir'     => ['Kasir Sementara',     'kasir'],
    'tmp_gudang'    => ['Gudang Sementara',    'gudang'],
    'tmp_bendahara' => ['Bendahara Sementara', 'bendahara'],
    'tmp_pimpinan'  => ['Pimpinan Sementara',  'pimpinan'],
];

function sandiAcak(int $panjang = 14): string
{
    // Tanpa karakter yang mudah tertukar (0/O, 1/l/I).
    $set = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $panjang; $i++) { $s .= $set[random_int(0, strlen($set) - 1)]; }
    return $s;
}

switch ($aksi) {
    case 'buat':
        foreach ($akun as $username => [$nama, $peran]) {
            $pw = sandiAcak();
            try {
                Auth::createUser($username, $nama, $peran, 'semua', $pw, null);
                printf("%-14s %-10s password: %s\n", $username, $peran, $pw);
            } catch (InvalidArgumentException $e) {
                printf("%-14s dilewati: %s\n", $username, $e->getMessage());
            }
        }
        echo "\nCatat password di atas sekarang; tidak ditampilkan lagi. Jalankan 'nonaktifkan' setelah uji selesai.\n";
        break;

    case 'reset':
        foreach (array_keys($akun) as $username) {
            $id = Db::val('SELECT id FROM users WHERE username = ?', [$username]);
            if (!$id) { printf("%-14s tidak ada\n", $username); continue; }
            $pw = sandiAcak();
            Auth::resetPassword((int) $id, $pw, null);
            Db::exec('UPDATE users SET aktif = 1 WHERE id = ?', [$id]);
            printf("%-14s password baru: %s\n", $username, $pw);
        }
        break;

    case 'nonaktifkan':
        foreach (array_keys($akun) as $username) {
            $id = Db::val('SELECT id FROM users WHERE username = ?', [$username]);
            if (!$id) { continue; }
            Db::exec('UPDATE users SET aktif = 0, session_version = session_version + 1 WHERE id = ?', [$id]);
            Db::audit(null, 'nonaktif', 'users', (int) $id, $username);
            echo "$username dinonaktifkan.\n";
        }
        break;

    default:
        echo "Pemakaian: php tools/buat_akun_sementara.php [buat|reset|nonaktifkan]\n";
}
