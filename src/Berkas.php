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

    public const JENIS_SANTRI = [
        'kk' => 'Kartu Keluarga', 'akta' => 'Akta kelahiran',
        'ijazah' => 'Ijazah', 'ktp' => 'KTP orang tua / wali',
        'surat' => 'Surat rekomendasi', 'lainnya' => 'Berkas lainnya',
    ];

    /** Metadata disimpan bersama dokumen, di folder tertutup yang sama. */
    public static function daftarSantri(int $id): array
    {
        if ($id < 1) { return []; }
        $hasil = [];
        foreach (glob(self::folder() . '/b' . $id . '-*.json') ?: [] as $p) {
            $m = json_decode((string) file_get_contents($p), true);
            if (!is_array($m) || (int) ($m['santri_id'] ?? 0) !== $id) { continue; }
            $nama = (string) ($m['file'] ?? '');
            if (!str_starts_with($nama, 'b' . $id . '-') || !self::jalur($nama)
                || pathinfo($p, PATHINFO_FILENAME) !== pathinfo($nama, PATHINFO_FILENAME)
                || !isset(self::JENIS_SANTRI[$m['jenis'] ?? ''])) { continue; }
            $hasil[] = $m;
        }
        usort($hasil, static fn($a, $b) => strcmp((string) $b['tanggal'], (string) $a['tanggal']));
        return $hasil;
    }

    public static function unggahSantri(int $id, string $jenis, array $f, string $judul = ''): string
    {
        if ($id < 1 || $id > 999999999 || !isset(self::JENIS_SANTRI[$jenis])) {
            throw new InvalidArgumentException('Santri atau jenis berkas tidak valid.');
        }
        $judul = trim($judul);
        if ($jenis === 'lainnya' && ($judul === '' || strlen($judul) > 200 || preg_match('/[\x00-\x1F\x7F]/', $judul))) {
            throw new InvalidArgumentException('Isi nama berkas lainnya (maksimal 200 byte, tanpa baris baru).');
        }
        if ($jenis !== 'lainnya') { $judul = ''; }
        if (!isset($f['tmp_name'], $f['size'], $f['error']) || is_array($f['tmp_name'])
            || is_array($f['size']) || is_array($f['error'])) {
            throw new InvalidArgumentException('Pilih satu berkas untuk diunggah.');
        }
        // Pastikan aturan penutupan folder tersedia sebelum menyimpan data pribadi.
        $folder = self::folder();
        if (!is_file($folder . '/.htaccess') || !is_writable($folder)) {
            throw new InvalidArgumentException('Folder dokumen belum siap. Hubungi administrator.');
        }
        $nama = self::simpan('b', $id, $f);
        $meta = ['santri_id' => $id, 'jenis' => $jenis, 'file' => $nama,
            'nama_berkas' => $judul, 'tanggal' => date('Y-m-d H:i:s'), 'ukuran' => (int) filesize(self::jalur($nama))];
        $tujuan = $folder . '/' . pathinfo($nama, PATHINFO_FILENAME) . '.json';
        $tmp = $tujuan . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $tujuan)) {
            @unlink($tmp);
            self::hapus($nama);
            throw new InvalidArgumentException('Data berkas gagal disimpan. Silakan ulangi.');
        }
        return $nama;
    }

    /** Hanya berkas unggahan milik santri ini yang boleh dihapus. */
    public static function hapusSantri(int $id, string $nama): void
    {
        $milikSantri = false;
        foreach (self::daftarSantri($id) as $b) {
            if ($b['file'] === $nama) { $milikSantri = true; break; }
        }
        if (!$milikSantri) {
            throw new InvalidArgumentException('Berkas tidak ditemukan atau bukan milik santri ini.');
        }
        $p = self::jalur($nama);
        if (!$p || !@unlink($p)) {
            throw new InvalidArgumentException('Berkas gagal dihapus. Periksa izin folder dokumen.');
        }
        $meta = self::folder() . '/' . pathinfo($nama, PATHINFO_FILENAME) . '.json';
        if (is_file($meta) && !@unlink($meta)) {
            throw new InvalidArgumentException('File sudah dihapus, tetapi metadata belum dapat dibersihkan. Hubungi administrator.');
        }
    }

    public static function labelSantri(array $b): string
    {
        $jenis = (string) ($b['jenis'] ?? '');
        $judul = trim((string) ($b['nama_berkas'] ?? ''));
        return $jenis === 'lainnya' && $judul !== '' ? $judul : (self::JENIS_SANTRI[$jenis] ?? 'Berkas');
    }

    public static function kirimDrive(int $id, string $nama, array $santri): void
    {
        GoogleDriveLH::locked(function () use ($id, $nama, $santri) {
            $m = null;
            foreach (self::daftarSantri($id) as $b) { if ($b['file'] === $nama) { $m = $b; break; } }
            if (!$m) throw new InvalidArgumentException('Berkas tidak ditemukan untuk santri ini.');
            $simpan = static function (array $data) use ($nama) {
                $p = self::folder() . '/' . pathinfo($nama, PATHINFO_FILENAME) . '.json';
                $tmp = $p . '.' . bin2hex(random_bytes(6)) . '.tmp';
                $raw = json_encode($data, JSON_THROW_ON_ERROR);
                if (@file_put_contents($tmp, $raw, LOCK_EX) !== strlen($raw) || !@rename($tmp, $p)) {
                    @unlink($tmp); throw new InvalidArgumentException('Status berkas belum dapat disimpan.');
                }
            };
            GoogleDriveLH::kirim($m, $santri, $simpan);
        });
    }
}
