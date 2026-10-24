<?php
declare(strict_types=1);

/** Dokumen santri (surat rekomendasi, sertifikat): PDF/JPG/PNG, disimpan di folder tertutup, dilayani lewat ?p=dokumen. */
final class Berkas
{
    public const MAKS_BYTE = 5 * 1024 * 1024;
    private const TIPE = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public static function folder(): string
    {
        if (defined('BERKAS_DIR_UJI')) {
            $dir = rtrim((string) BERKAS_DIR_UJI, '/\\');
        } else {
            $cfg = is_file(APP_ROOT . '/config.php') ? (require APP_ROOT . '/config.php') : [];
            $dir = rtrim((string) ($cfg['dokumen_dir'] ?? (APP_ROOT . '/storage/dokumen')), '/\\');
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
        return (bool) preg_match('/^[a-z]\d{1,9}-[a-f0-9]{12}\.(pdf|jpg|png)$/', $n);
    }

    public static function jalur(string $nama): ?string
    {
        if (!self::namaValid($nama)) {
            return null;
        }
        $p = self::folder() . '/' . $nama;
        return is_file($p) ? $p : null;
    }

    public static function mime(string $nama): string
    {
        return ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][pathinfo($nama, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    /** @param string $awalan satu huruf: 'r' surat rekomendasi, 'c' sertifikat. @return string nama berkas. @throws InvalidArgumentException */
    public static function simpan(string $awalan, int $id, array $f): string
    {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Ukuran berkas terlalu besar (maks. 5 MB).');
        }
        if ($err !== UPLOAD_ERR_OK || (!is_uploaded_file((string) $f['tmp_name']) && !defined('FOTO_UJI'))) {
            throw new InvalidArgumentException('Berkas gagal diunggah. Coba lagi.');
        }
        if ((int) $f['size'] > self::MAKS_BYTE) {
            throw new InvalidArgumentException('Ukuran berkas terlalu besar (maks. 5 MB).');
        }
        $data = (string) file_get_contents((string) $f['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (!isset(self::TIPE[$mime])) {
            throw new InvalidArgumentException('Format berkas harus PDF, JPG, atau PNG.');
        }
        if ($mime === 'application/pdf' && !str_starts_with($data, '%PDF-')) {
            throw new InvalidArgumentException('Berkas PDF tidak valid.');
        }
        if ($mime !== 'application/pdf' && !@getimagesizefromstring($data)) {
            throw new InvalidArgumentException('Berkas gambar tidak valid.');
        }
        $nama = $awalan . $id . '-' . bin2hex(random_bytes(6)) . '.' . self::TIPE[$mime];
        if (@file_put_contents(self::folder() . '/' . $nama, $data) === false) {
            throw new InvalidArgumentException('Berkas tidak dapat disimpan di server. Periksa izin folder.');
        }
        return $nama;
    }

    public static function hapus(?string $nama): void
    {
        if ($nama && ($p = self::jalur($nama))) {
            @unlink($p);
        }
    }
}
