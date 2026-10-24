<?php
declare(strict_types=1);

/** Perizinan santri: wali/petugas mengajukan -> pembina menyetujui -> keluar -> kembali (terlambat tercatat). */
final class Izin
{
    public const JENIS = ['pulang' => 'Pulang', 'berobat' => 'Berobat', 'keperluan' => 'Keperluan keluarga', 'lainnya' => 'Lainnya'];
    public const STATUS = ['menunggu' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'keluar' => 'Sedang di luar', 'kembali' => 'Sudah kembali', 'batal' => 'Dibatalkan'];

    public static function dt(string $v): ?string
    {
        $v = str_replace('T', ' ', trim($v));
        $d = DateTime::createFromFormat('Y-m-d H:i', substr($v, 0, 16));
        return $d && $d->format('Y-m-d H:i') === substr($v, 0, 16) ? $d->format('Y-m-d H:i:00') : null;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT i.*, s.nama, s.nis, s.unit, s.jenjang, s.kelas, s.nama_wali, s.hp_wali, s.hubungan_wali, u.nama AS penyetuju FROM izin i JOIN santri s ON s.id = i.santri_id LEFT JOIN users u ON u.id = i.penyetuju_id WHERE i.id = ?', [$id]);
    }

    public static function ajukan(int $santriId, string $jenis, string $mulai, string $selesai, string $alasan, string $pengaju, ?int $userId, ?string $scope): int
    {
        $s = Santri::find($santriId);
        if (!$s || $s['status'] !== 'aktif') { throw new InvalidArgumentException('Santri tidak ditemukan atau tidak aktif.'); }
        if ($scope !== null && $scope !== $s['unit']) { throw new InvalidArgumentException('Santri di luar cakupan unit akun Anda.'); }
        if (!isset(self::JENIS[$jenis])) { throw new InvalidArgumentException('Pilih jenis izin.'); }
        $m = self::dt($mulai); $e = self::dt($selesai);
        if (!$m || !$e) { throw new InvalidArgumentException('Tanggal & jam izin tidak valid.'); }
        if ($e <= $m) { throw new InvalidArgumentException('Waktu kembali harus setelah waktu keluar.'); }
        if (strtotime($e) - strtotime($m) > 30 * 86400) { throw new InvalidArgumentException('Izin maksimal 30 hari.'); }
        if ($pengaju === 'wali' && strtotime($m) < time() - 3600) { throw new InvalidArgumentException('Waktu keluar tidak boleh di masa lalu.'); }
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < 5) { throw new InvalidArgumentException('Alasan minimal 5 karakter.'); }
        $bentrok = Db::val("SELECT id FROM izin WHERE santri_id = ? AND status IN ('menunggu','disetujui','keluar') AND mulai < ? AND selesai > ?", [$santriId, $e, $m]);
        if ($bentrok) { throw new InvalidArgumentException('Sudah ada izin aktif pada waktu tersebut.'); }
        $petugas = $pengaju === 'petugas';
        $id = Db::insert('INSERT INTO izin (santri_id, jenis, mulai, selesai, alasan, pengaju, status, penyetuju_id, dibuat_oleh, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$santriId, $jenis, $m, $e, mb_substr($alasan, 0, 255), $petugas ? 'petugas' : 'wali', $petugas ? 'disetujui' : 'menunggu', $petugas ? $userId : null, $userId, date('Y-m-d H:i:s')]);
        Db::audit($userId, 'ajukan', 'izin', $id, "$santriId $jenis $m-$e " . ($petugas ? 'petugas' : 'wali'));
        return $id;
    }

    private static function ubah(int $id, array $dari, string $ke, ?int $userId, ?string $scope, array $set = []): array
    {
        $i = self::find($id);
        if (!$i) { throw new InvalidArgumentException('Izin tidak ditemukan.'); }
        if ($scope !== null && $scope !== $i['unit']) { throw new InvalidArgumentException('Data di luar cakupan unit akun Anda.'); }
        if (!in_array($i['status'], $dari, true)) { throw new InvalidArgumentException('Status izin saat ini: ' . self::STATUS[$i['status']] . '.'); }
        $cols = ['status = ?']; $p = [$ke];
        foreach ($set as $k => $v) { $cols[] = "$k = ?"; $p[] = $v; }
        $p[] = $id; $p[] = $i['status'];
        if (Db::exec('UPDATE izin SET ' . implode(', ', $cols) . ' WHERE id = ? AND status = ?', $p) !== 1) { throw new InvalidArgumentException('Izin baru saja diubah orang lain. Muat ulang halaman.'); }
        Db::audit($userId, $ke, 'izin', $id, $i['status'] . '->' . $ke);
        return $i;
    }

    public static function setujui(int $id, string $catatan, ?int $userId, ?string $scope): void
    {
        self::ubah($id, ['menunggu'], 'disetujui', $userId, $scope, ['penyetuju_id' => $userId, 'catatan' => mb_substr(trim($catatan), 0, 255)]);
    }

    public static function tolak(int $id, string $catatan, ?int $userId, ?string $scope): void
    {
        if (trim($catatan) === '') { throw new InvalidArgumentException('Tuliskan alasan penolakan untuk wali.'); }
        self::ubah($id, ['menunggu'], 'ditolak', $userId, $scope, ['penyetuju_id' => $userId, 'catatan' => mb_substr(trim($catatan), 0, 255)]);
    }

    public static function keluar(int $id, ?int $userId, ?string $scope): void
    {
        self::ubah($id, ['disetujui'], 'keluar', $userId, $scope, ['keluar_at' => date('Y-m-d H:i:s')]);
    }

    public static function kembali(int $id, ?int $userId, ?string $scope): bool
    {
        $i = self::find($id);
        $telat = $i && time() > strtotime($i['selesai']) + 15 * 60;
        self::ubah($id, ['keluar', 'disetujui'], 'kembali', $userId, $scope, ['kembali_at' => date('Y-m-d H:i:s'), 'terlambat' => $telat ? 1 : 0]);
        return $telat;
    }

    public static function batal(int $id, ?int $userId, ?string $scope): void
    {
        self::ubah($id, ['menunggu', 'disetujui'], 'batal', $userId, $scope);
    }

    public static function aktifPada(int $santriId, string $tanggal): ?array
    {
        return Db::one("SELECT * FROM izin WHERE santri_id = ? AND status IN ('disetujui','keluar') AND mulai <= ? AND selesai >= ? ORDER BY id DESC LIMIT 1",
            [$santriId, $tanggal . ' 23:59:59', $tanggal . ' 00:00:00']);
    }

    private static function tabSql(string $tab): string
    {
        $now = date('Y-m-d H:i:s');
        return match ($tab) {
            'menunggu' => "i.status = 'menunggu'",
            'aktif' => "i.status IN ('disetujui','keluar')",
            'terlambat' => "i.status = 'keluar' AND i.selesai < '$now'",
            default => "i.status IN ('kembali','ditolak','batal')",
        };
    }

    public static function daftar(string $tab, ?string $scope, int $limit = 100): array
    {
        $sql = 'SELECT i.*, s.nama, s.nis, s.unit, s.jenjang, s.kelas, s.nama_wali, s.hp_wali, s.hubungan_wali, u.nama AS penyetuju FROM izin i JOIN santri s ON s.id = i.santri_id LEFT JOIN users u ON u.id = i.penyetuju_id WHERE ' . self::tabSql($tab);
        $p = [];
        if ($scope !== null) { $sql .= ' AND s.unit = ?'; $p[] = $scope; }
        return Db::all($sql . ' ORDER BY i.mulai ' . ($tab === 'riwayat' ? 'DESC' : 'ASC') . ' LIMIT ' . (int) $limit, $p);
    }

    public static function hitung(string $tab, ?string $scope): int
    {
        $sql = 'SELECT COUNT(*) FROM izin i JOIN santri s ON s.id = i.santri_id WHERE ' . self::tabSql($tab);
        return (int) Db::val($sql . ($scope !== null ? ' AND s.unit = ?' : ''), $scope !== null ? [$scope] : []);
    }

    public static function untukSantri(int $santriId, int $limit = 10): array
    {
        return Db::all('SELECT * FROM izin WHERE santri_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$santriId]);
    }
}
