<?php
declare(strict_types=1);

/** Foto bukti notifikasi pembayaran QRIS: divalidasi, diperkecil, disimpan di folder tertutup (dilayani lewat ?p=bukti_qris). */
final class BuktiQris
{
    public const MAKS_BYTE = 8 * 1024 * 1024;
    public const SISI = 1280;

    public static function folder(): string
    {
        $dir = defined('BUKTI_DIR_UJI') ? rtrim((string) BUKTI_DIR_UJI, '/\\') : APP_ROOT . '/storage/bukti_qris';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($ht)) { @file_put_contents($ht, "Require all denied\n"); }
        return $dir;
    }

    public static function namaValid(string $n): bool { return (bool) preg_match('/^b\d{1,9}-[a-f0-9]{12}\.jpg$/', $n); }

    public static function jalur(string $nama): ?string
    {
        if (!self::namaValid($nama)) { return null; }
        $p = self::folder() . '/' . $nama;
        return is_file($p) ? $p : null;
    }

    /** Validasi unggahan dan kembalikan data JPEG yang sudah diperkecil. @throws InvalidArgumentException */
    public static function siapkan(?array $f): string
    {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) { throw new InvalidArgumentException('Foto bukti notifikasi QRIS wajib dilampirkan.'); }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { throw new InvalidArgumentException('Foto bukti terlalu besar (maks. 8 MB).'); }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || (!is_uploaded_file($tmp) && !defined('BUKTI_UJI'))) { throw new InvalidArgumentException('Foto bukti gagal diunggah. Coba lagi.'); }
        if ((int) filesize($tmp) > self::MAKS_BYTE) { throw new InvalidArgumentException('Foto bukti terlalu besar (maks. 8 MB).'); }
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) { throw new InvalidArgumentException('Foto bukti harus berformat JPG atau PNG.'); }
        if (!function_exists('imagecreatefromstring')) { throw new InvalidArgumentException('Ekstensi GD PHP belum aktif di server.'); }
        $im = @imagecreatefromstring((string) file_get_contents($tmp));
        if (!$im) { throw new InvalidArgumentException('Foto bukti tidak dapat dibaca.'); }
        $w = imagesx($im); $h = imagesy($im);
        if (max($w, $h) > self::SISI) {
            $r = self::SISI / max($w, $h);
            $kecil = imagescale($im, max(1, (int) round($w * $r)), max(1, (int) round($h * $r)));
            if ($kecil) { $im = $kecil; }
        }
        ob_start(); imagejpeg($im, null, 82); $bin = (string) ob_get_clean();
        if ($bin === '') { throw new InvalidArgumentException('Foto bukti gagal diproses.'); }
        return $bin;
    }

    /** Simpan data JPEG untuk penjualan; mengembalikan nama berkas. */
    public static function simpan(int $penjualanId, string $bin): string
    {
        $nama = 'b' . $penjualanId . '-' . bin2hex(random_bytes(6)) . '.jpg';
        if (@file_put_contents(self::folder() . '/' . $nama, $bin) === false) { throw new RuntimeException('Folder storage/bukti_qris tidak dapat ditulis.'); }
        try { Db::exec('UPDATE penjualan SET bukti_qris = ? WHERE id = ?', [$nama, $penjualanId]); } catch (PDOException $e) { /* kolom belum dimigrasi */ }
        return $nama;
    }
}
