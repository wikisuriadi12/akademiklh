<?php
declare(strict_types=1);

/** Foto santri: divalidasi, diperkecil, disimpan sebagai JPEG di folder tertutup (hanya dilayani lewat ?p=foto). */
final class Foto
{
    public const MAKS_BYTE = 6 * 1024 * 1024;
    public const SISI = 480;

    public static function folder(): string
    {
        if (defined('FOTO_DIR_UJI')) {
            $dir = rtrim((string) FOTO_DIR_UJI, '/\\');
        } else {
            $cfg = is_file(APP_ROOT . '/config.php') ? (require APP_ROOT . '/config.php') : [];
            $dir = rtrim((string) ($cfg['foto_dir'] ?? (APP_ROOT . '/storage/foto')), '/\\');
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        return $dir;
    }

    public static function namaValid(string $n): bool
    {
        return (bool) preg_match('/^s\d{1,9}-[a-f0-9]{12}\.jpg$/', $n);
    }

    public static function jalur(string $nama): ?string
    {
        if (!self::namaValid($nama)) {
            return null;
        }
        $p = self::folder() . '/' . $nama;
        return is_file($p) ? $p : null;
    }

    /** Simpan foto baru dari $_FILES[...]; mengembalikan nama berkas. @throws InvalidArgumentException */
    public static function simpan(int $santriId, array $f): string
    {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Ukuran foto terlalu besar (maks. 6 MB).');
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) && !defined('FOTO_UJI')) {
            throw new InvalidArgumentException('Foto gagal diunggah. Coba lagi.');
        }
        if ((int) $f['size'] > self::MAKS_BYTE) {
            throw new InvalidArgumentException('Ukuran foto terlalu besar (maks. 6 MB).');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new InvalidArgumentException('Ekstensi GD di server belum aktif, foto tidak dapat diproses.');
        }
        $data = (string) file_get_contents((string) $f['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Format foto harus JPG, PNG, atau WebP.');
        }
        $info = @getimagesizefromstring($data);
        if (!$info || $info[0] < 20 || $info[1] < 20 || $info[0] * $info[1] > 40_000_000) {
            throw new InvalidArgumentException('Berkas bukan foto yang valid.');
        }
        $src = @imagecreatefromstring($data);
        if (!$src) {
            throw new InvalidArgumentException('Berkas bukan foto yang valid.');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
            $rot = [3 => 180, 6 => -90, 8 => 90][$ex['Orientation'] ?? 1] ?? 0;
            if ($rot) {
                $r = imagerotate($src, $rot, 0);
                if ($r) {
                    $src = $r;
                }
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min(1.0, self::SISI / max($w, $h));
        $nw = max(1, (int) round($w * $k));
        $nh = max(1, (int) round($h * $k));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // latar putih untuk PNG transparan
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $nama = 's' . $santriId . '-' . bin2hex(random_bytes(6)) . '.jpg';
        $tujuan = self::folder() . '/' . $nama;
        if (!@imagejpeg($dst, $tujuan, 85)) {
            throw new InvalidArgumentException('Foto tidak dapat disimpan di server. Periksa izin folder.');
        }
        $lama = (string) Db::val('SELECT foto FROM santri WHERE id = ?', [$santriId]);
        Db::exec('UPDATE santri SET foto = ? WHERE id = ?', [$nama, $santriId]);
        self::hapusBerkas($lama);
        return $nama;
    }

    public static function hapus(int $santriId): void
    {
        $lama = (string) Db::val('SELECT foto FROM santri WHERE id = ?', [$santriId]);
        Db::exec('UPDATE santri SET foto = NULL WHERE id = ?', [$santriId]);
        self::hapusBerkas($lama);
    }

    private static function hapusBerkas(string $nama): void
    {
        if ($nama !== '' && ($p = self::jalur($nama))) {
            @unlink($p);
        }
    }

    /** URL tampilan foto (butuh login & hak lihat santri). */
    public static function url(int $santriId, ?string $nama): ?string
    {
        return $nama && self::namaValid($nama) ? '?p=foto&id=' . $santriId . '&v=' . substr($nama, -10, 6) : null;
    }
}
