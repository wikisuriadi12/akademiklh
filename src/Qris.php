<?php
declare(strict_types=1);

/** QRIS statis koperasi: gambar QR dan identitas merchant yang ditampilkan di layar kasir. */
final class Qris
{
    public const DIR = '/assets/qris';
    public const MAX_BYTES = 3145728;

    public static function aktif(): bool
    {
        return Settings::get('qris_aktif') === '1' && self::url() !== null;
    }

    /** Alamat relatif gambar QR, atau null bila belum diunggah. */
    public static function url(): ?string
    {
        $f = Settings::get('qris_gambar');
        if (!preg_match('/^qris-[a-f0-9]{12}\.png$/', $f) || !is_file(APP_ROOT . self::DIR . '/' . $f)) {
            return null;
        }
        return 'assets/qris/' . $f;
    }

    /**
     * Simpan gambar QR hasil unggahan. Gambar dibaca ulang dan ditulis sebagai PNG baru
     * (maks. 1000 px) sehingga hanya data gambar yang tersimpan.
     * @param array{tmp_name:string,size:int,error:int} $f entri dari $_FILES
     */
    public static function simpan(array $f): string
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('Pilih file gambar QR terlebih dahulu.'); }
        if ((int) $f['size'] > self::MAX_BYTES) { throw new InvalidArgumentException('Ukuran gambar maksimal 3 MB.'); }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) { throw new InvalidArgumentException('Gambar harus berformat JPG atau PNG.'); }
        if (!function_exists('imagecreatefromstring')) { throw new InvalidArgumentException('Ekstensi GD PHP belum aktif di server.'); }
        $im = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
        if (!$im) { throw new InvalidArgumentException('Gambar tidak dapat dibaca.'); }
        $w = imagesx($im); $h = imagesy($im); $max = 1000;
        if (max($w, $h) > $max) {
            $r = $max / max($w, $h);
            $kecil = imagescale($im, max(1, (int) round($w * $r)), max(1, (int) round($h * $r)));
            if ($kecil) { $im = $kecil; }
        }
        $dir = APP_ROOT . self::DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new InvalidArgumentException('Folder assets/qris tidak dapat dibuat. Periksa izin folder.'); }
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php8)$\">\n  Require all denied\n</FilesMatch>\n");
        $nama = 'qris-' . bin2hex(random_bytes(6)) . '.png';
        if (!@imagepng($im, $dir . '/' . $nama)) { throw new InvalidArgumentException('Gagal menyimpan gambar QR.'); }
        $lama = Settings::get('qris_gambar');
        if (preg_match('/^qris-[a-f0-9]{12}\.png$/', $lama)) { @unlink($dir . '/' . $lama); }
        Settings::set('qris_gambar', $nama);
        return $nama;
    }
}
