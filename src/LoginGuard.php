<?php
declare(strict_types=1);

/** Pengaman halaman login: CAPTCHA gambar buatan sendiri (tanpa layanan luar), honeypot, waktu isi minimum, dan batas laju. */
final class LoginGuard
{
    private const HURUF = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const MIN_DETIK = 2;
    public const KADALUARSA = 300;

    /** Header keamanan untuk halaman login. */
    public static function header(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cache-Control: no-store');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { header('Strict-Transport-Security: max-age=31536000'); }
    }

    public static function gdTersedia(): bool { return function_exists('imagecreatetruecolor') && function_exists('imagepng'); }

    /** Siapkan tantangan baru untuk formulir (dipanggil saat menampilkan form). Mengembalikan teks soal bila tanpa GD. */
    public static function siapkan(): ?string
    {
        $_SESSION['lg_t'] = time();
        $salt = bin2hex(random_bytes(8));
        if (self::gdTersedia()) {
            $kode = '';
            for ($i = 0; $i < 5; $i++) { $kode .= self::HURUF[random_int(0, strlen(self::HURUF) - 1)]; }
            $_SESSION['lg_c'] = ['h' => hash('sha256', $salt . $kode), 's' => $salt, 'exp' => time() + self::KADALUARSA];
            $_SESSION['lg_kode'] = $kode;
            return null;
        }
        $a = random_int(3, 19); $b = random_int(2, 9);
        $_SESSION['lg_c'] = ['h' => hash('sha256', $salt . ($a + $b)), 's' => $salt, 'exp' => time() + self::KADALUARSA];
        return "Berapa $a + $b ?";
    }

    /** Buat gambar PNG dari kode yang tersimpan di sesi dan langsung mengirimkannya. */
    public static function gambar(): void
    {
        $kode = (string) ($_SESSION['lg_kode'] ?? '');
        if ($kode === '' || !self::gdTersedia()) { http_response_code(404); exit; }
        $W = 230; $H = 76;
        $im = imagecreatetruecolor($W, $H);
        $bg = imagecolorallocate($im, random_int(232, 248), random_int(238, 250), random_int(232, 248));
        imagefilledrectangle($im, 0, 0, $W, $H, $bg);
        for ($i = 0; $i < 260; $i++) { imagesetpixel($im, random_int(0, $W - 1), random_int(0, $H - 1), imagecolorallocate($im, random_int(120, 200), random_int(120, 200), random_int(120, 200))); }
        $n = strlen($kode); $lebar = (int) (($W - 20) / $n);
        for ($i = 0; $i < $n; $i++) {
            $kecil = imagecreatetruecolor(20, 24);
            $tr = imagecolorallocate($kecil, 255, 0, 255); imagecolortransparent($kecil, $tr); imagefilledrectangle($kecil, 0, 0, 20, 24, $tr);
            imagestring($kecil, 5, 4, 4, $kode[$i], imagecolorallocate($kecil, random_int(0, 70), random_int(40, 110), random_int(0, 90)));
            $rot = @imagerotate($kecil, random_int(-28, 28), $tr);
            if ($rot) { imagecolortransparent($rot, $tr); $kecil = $rot; }
            $ukur = random_int(46, 58);
            imagecopyresampled($im, $kecil, 10 + $i * $lebar + random_int(-2, 4), random_int(4, $H - $ukur - 4 > 4 ? $H - $ukur - 4 : 5), 0, 0, $ukur - 8, $ukur, imagesx($kecil), imagesy($kecil));
        }
        for ($i = 0; $i < 6; $i++) { imageline($im, random_int(0, $W), random_int(0, $H), random_int(0, $W), random_int(0, $H), imagecolorallocate($im, random_int(60, 160), random_int(60, 160), random_int(60, 160))); }
        if (!headers_sent()) { header('Content-Type: image/png'); header('Cache-Control: no-store, max-age=0'); header('X-Content-Type-Options: nosniff'); }
        imagepng($im);
    }

    /** Bidang tersembunyi untuk formulir: honeypot. */
    public static function bidang(): string
    {
        return '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
    }

    /** Periksa kiriman. Mengembalikan pesan galat, atau null bila lolos. Tantangan selalu dihanguskan. */
    public static function cek(array $post): ?string
    {
        $c = $_SESSION['lg_c'] ?? null; $t = (int) ($_SESSION['lg_t'] ?? 0);
        unset($_SESSION['lg_c'], $_SESSION['lg_t'], $_SESSION['lg_kode']);
        if (!RateLimit::hit('login_robot', RateLimit::ip(), 40, 600)) { return 'Terlalu banyak percobaan dari jaringan ini. Coba lagi beberapa menit lagi.'; }
        if (trim((string) ($post['website'] ?? '')) !== '') { return 'Verifikasi gagal. Muat ulang halaman.'; }
        if ($t === 0 || time() - $t < self::MIN_DETIK) { return 'Formulir terkirim terlalu cepat. Coba lagi.'; }
        if (!is_array($c) || (int) $c['exp'] < time()) { return 'Kode verifikasi kedaluwarsa. Isi kode yang baru.'; }
        $jawab = strtoupper(preg_replace('/\s+/', '', (string) ($post['captcha'] ?? '')));
        if ($jawab === '' || !hash_equals((string) $c['h'], hash('sha256', $c['s'] . $jawab))) { return 'Kode verifikasi salah. Coba lagi dengan kode baru.'; }
        return null;
    }

    /** Alasan sah/ditolak untuk input kredensial yang tidak masuk akal (mencegah beban hash berlebihan). */
    public static function inputWajar(string $username, string $password): bool
    {
        return $username !== '' && mb_strlen($username) <= 64 && strlen($password) > 0 && strlen($password) <= 200 && !preg_match('/[\x00-\x1F]/', $username);
    }
}
