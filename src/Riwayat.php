<?php
declare(strict_types=1);

/** Riwayat santri: masuk, akademik (tautan ke aplikasi raport), dan prestasi. */
final class Riwayat
{
    public const STATUS_MASUK = ['Baru' => 'Baru', 'Pindahan' => 'Pindahan'];
    public const KELAS = ['TKQ', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'];
    public const SEMESTER = ['1', '2'];
    public const JENIS_PRESTASI = ['Akademik', 'Tahfidz', 'Olahraga', 'Seni', 'Lomba / Kompetisi', 'Lainnya'];

    /** Kelas numerik/TKQ dari data santri (mis. "3A" => "3", jenjang TKQ => "TKQ"). */
    public static function kelasSaatIni(array $s): string
    {
        if (($s['jenjang'] ?? '') === 'TKQ') {
            return 'TKQ';
        }
        return preg_match('/^(\d{1,2})/', (string) ($s['kelas'] ?? ''), $m) && in_array($m[1], self::KELAS, true) ? $m[1] : '';
    }

    /** Alamat aplikasi raport (pengaturan) berisi {nis} {kelas} {semester}; null bila belum diatur atau tidak aman. */
    public static function urlRaport(string $nis, string $kelas, string $semester): ?string
    {
        $t = trim(Settings::get('raport_url'));
        if ($t === '' || !preg_match('#^https://[^\s]+$#i', $t)) {
            return null;
        }
        if (!in_array($kelas, self::KELAS, true) || !in_array($semester, self::SEMESTER, true)) {
            return null;
        }
        return strtr($t, ['{nis}' => rawurlencode($nis), '{kelas}' => rawurlencode($kelas), '{semester}' => rawurlencode($semester)]);
    }

    /** @throws InvalidArgumentException */
    public static function simpanMasuk(int $id, string $tgl, string $status, string $asal, ?array $surat, bool $hapusSurat, ?int $userId): string
    {
        $tgl = trim($tgl);
        if ($tgl !== '' && (!($d = DateTime::createFromFormat('Y-m-d', $tgl)) || $d->format('Y-m-d') !== $tgl)) {
            throw new InvalidArgumentException('Tanggal masuk tidak valid.');
        }
        if ($status !== '' && !isset(self::STATUS_MASUK[$status])) {
            throw new InvalidArgumentException('Status masuk harus Baru atau Pindahan.');
        }
        $asal = mb_substr(trim($asal), 0, 120);
        $lama = (string) Db::val('SELECT surat_rekomendasi FROM santri WHERE id = ?', [$id]);
        $baru = $lama;
        if ($surat && ($surat['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $baru = Berkas::simpan('r', $id, $surat);
        } elseif ($hapusSurat) {
            $baru = '';
        }
        Db::exec('UPDATE santri SET tgl_masuk = ?, status_masuk = ?, asal_sekolah = ?, surat_rekomendasi = ?, updated_at = ? WHERE id = ?',
            [$tgl ?: null, $status ?: null, $asal ?: null, $baru ?: null, date('Y-m-d H:i:s'), $id]);
        if ($lama !== '' && $lama !== $baru) {
            Berkas::hapus($lama);
        }
        Db::audit($userId, 'riwayat_masuk', 'santri', $id, ($status ?: '-') . ' / ' . ($tgl ?: '-'));
        return $baru;
    }

    public static function prestasi(int $santriId): array
    {
        return Db::all('SELECT * FROM prestasi WHERE santri_id = ? ORDER BY COALESCE(tahun, 0) DESC, id DESC', [$santriId]);
    }

    /** @throws InvalidArgumentException */
    public static function tambahPrestasi(int $santriId, string $jenis, string $nama, string $tahun, ?array $sertifikat, ?int $userId): int
    {
        if (!in_array($jenis, self::JENIS_PRESTASI, true)) {
            throw new InvalidArgumentException('Pilih jenis prestasi.');
        }
        $nama = mb_substr(trim($nama), 0, 150);
        if ($nama === '') {
            throw new InvalidArgumentException('Nama prestasi wajib diisi.');
        }
        $tahun = trim($tahun);
        if ($tahun !== '' && (!ctype_digit($tahun) || (int) $tahun < 1990 || (int) $tahun > (int) date('Y') + 1)) {
            throw new InvalidArgumentException('Tahun prestasi tidak valid.');
        }
        $berkas = null;
        if ($sertifikat && ($sertifikat['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $berkas = Berkas::simpan('c', $santriId, $sertifikat);
        }
        $id = Db::insert('INSERT INTO prestasi (santri_id, jenis, nama, tahun, sertifikat, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
            [$santriId, $jenis, $nama, $tahun === '' ? null : (int) $tahun, $berkas, $userId, date('Y-m-d H:i:s')]);
        Db::audit($userId, 'tambah', 'prestasi', $id, $jenis . ': ' . $nama);
        return $id;
    }

    public static function hapusPrestasi(int $id, int $santriId, ?int $userId): void
    {
        $r = Db::one('SELECT * FROM prestasi WHERE id = ? AND santri_id = ?', [$id, $santriId]);
        if (!$r) {
            return;
        }
        Db::exec('DELETE FROM prestasi WHERE id = ?', [$id]);
        Berkas::hapus($r['sertifikat'] ?? null);
        Db::audit($userId, 'hapus', 'prestasi', $id, $r['jenis'] . ': ' . $r['nama']);
    }
}
