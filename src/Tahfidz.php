<?php
declare(strict_types=1);

/** Setoran hafalan Al-Qur'an. Tidak dihapus; salah input dibatalkan. 1 juz dihitung 20 halaman (mushaf standar). */
final class Tahfidz
{
    public const JENIS = ['ziyadah' => 'Ziyadah (hafalan baru)', 'murojaah' => 'Murojaah (mengulang)', 'tasmi' => "Tasmi' (memperdengarkan)"];
    public const KELANCARAN = ['lancar' => 'Lancar', 'kurang' => 'Kurang lancar', 'ulang' => 'Harus diulang'];
    public const HAL_PER_JUZ = 20;

    public static function grade(int $nilai): string
    {
        return $nilai >= 90 ? 'A' : ($nilai >= 80 ? 'B+' : ($nilai >= 70 ? 'B' : ($nilai >= 60 ? 'C' : 'D')));
    }

    public static function santriBoleh(int $santriId, ?string $scope): array
    {
        $s = Santri::find($santriId);
        if (!$s || $s['status'] !== 'aktif') { throw new InvalidArgumentException('Santri tidak ditemukan atau tidak aktif.'); }
        if ($scope !== null && $scope !== $s['unit']) { throw new InvalidArgumentException('Santri di luar cakupan unit akun Anda.'); }
        return $s;
    }

    public static function setor(array $in, ?int $userId, ?string $scope): int
    {
        $sid = (int) ($in['santri_id'] ?? 0);
        self::santriBoleh($sid, $scope);
        $tgl = (string) ($in['tanggal'] ?? date('Y-m-d'));
        if (!Domain::validDate($tgl) || $tgl > date('Y-m-d')) { throw new InvalidArgumentException('Tanggal setoran tidak valid.'); }
        $jenis = (string) ($in['jenis'] ?? '');
        if (!isset(self::JENIS[$jenis])) { throw new InvalidArgumentException('Pilih jenis setoran.'); }
        $juz = (int) ($in['juz'] ?? 0);
        if ($juz < 1 || $juz > 30) { throw new InvalidArgumentException('Juz harus 1 sampai 30.'); }
        $hal = round((float) str_replace(',', '.', (string) ($in['halaman'] ?? '0')) * 2) / 2;
        if ($hal < 0.5 || $hal > 40) { throw new InvalidArgumentException('Jumlah halaman 0,5 sampai 40.'); }
        $nilai = (int) ($in['nilai'] ?? -1);
        if ($nilai < 0 || $nilai > 100) { throw new InvalidArgumentException('Nilai 0 sampai 100.'); }
        $lan = (string) ($in['kelancaran'] ?? 'lancar');
        if (!isset(self::KELANCARAN[$lan])) { $lan = 'lancar'; }
        $surat = mb_substr(trim((string) ($in['surat'] ?? '')), 0, 40);
        $ayat = mb_substr(trim((string) ($in['ayat'] ?? '')), 0, 20);
        $id = Db::insert('INSERT INTO hafalan (santri_id, tanggal, jenis, juz, surat, ayat, halaman, kelancaran, nilai, grade, catatan, ustadz_id, dibatalkan, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)',
            [$sid, $tgl, $jenis, $juz, $surat, $ayat, $hal, $lan, $nilai, self::grade($nilai), mb_substr(trim((string) ($in['catatan'] ?? '')), 0, 255), $userId, date('Y-m-d H:i:s')]);
        Db::audit($userId, 'setor', 'hafalan', $id, "$sid $jenis juz $juz $hal hlm nilai $nilai");
        return $id;
    }

    public static function batalkan(int $id, string $alasan, ?int $userId, ?string $scope): void
    {
        $h = Db::one('SELECT h.*, s.unit FROM hafalan h JOIN santri s ON s.id = h.santri_id WHERE h.id = ?', [$id]);
        if (!$h || (int) $h['dibatalkan']) { throw new InvalidArgumentException('Setoran tidak ditemukan.'); }
        if ($scope !== null && $scope !== $h['unit']) { throw new InvalidArgumentException('Data di luar cakupan unit akun Anda.'); }
        if (trim($alasan) === '') { throw new InvalidArgumentException('Alasan pembatalan wajib diisi.'); }
        Db::exec('UPDATE hafalan SET dibatalkan = 1, catatan = ? WHERE id = ?', [mb_substr('BATAL: ' . $alasan . ' | ' . $h['catatan'], 0, 255), $id]);
        Db::audit($userId, 'batal', 'hafalan', $id, $alasan);
    }

    private static function filterSql(array $f, ?string $scope, array &$p): string
    {
        $w = '';
        foreach (['jenjang', 'kelas', 'unit'] as $k) {
            if (!empty($f[$k])) { $w .= " AND s.$k = ?"; $p[] = $f[$k]; }
        }
        if ($scope !== null) { $w .= ' AND s.unit = ?'; $p[] = $scope; }
        return $w;
    }

    public static function harian(string $tanggal, array $f, ?string $scope): array
    {
        $p = [$tanggal];
        $w = self::filterSql($f, $scope, $p);
        return Db::all("SELECT h.*, s.nama, s.nis, s.unit, s.jenjang, s.kelas, u.nama AS ustadz FROM hafalan h JOIN santri s ON s.id = h.santri_id LEFT JOIN users u ON u.id = h.ustadz_id
            WHERE h.dibatalkan = 0 AND h.tanggal = ?$w ORDER BY h.id DESC", $p);
    }

    public static function belumSetor(string $tanggal, array $f, ?string $scope): array
    {
        $p = [$tanggal];
        $w = self::filterSql($f, $scope, $p);
        return Db::all("SELECT s.id, s.nama, s.nis, s.unit, s.jenjang, s.kelas FROM santri s WHERE s.status = 'aktif'
            AND NOT EXISTS (SELECT 1 FROM hafalan h WHERE h.santri_id = s.id AND h.dibatalkan = 0 AND h.tanggal = ?)$w ORDER BY s.jenjang, s.kelas, s.nama", $p);
    }

    /** Total hafalan baru (ziyadah) per juz, dalam halaman (maks. 20 per juz). */
    public static function progresJuz(int $santriId): array
    {
        $out = [];
        foreach (Db::all("SELECT juz, SUM(halaman) AS hal FROM hafalan WHERE santri_id = ? AND dibatalkan = 0 AND jenis = 'ziyadah' GROUP BY juz ORDER BY juz", [$santriId]) as $r) {
            $out[(int) $r['juz']] = min(self::HAL_PER_JUZ, (float) $r['hal']);
        }
        return $out;
    }

    public static function rekapSantri(int $santriId): array
    {
        $pj = self::progresJuz($santriId);
        $last = Db::one('SELECT * FROM hafalan WHERE santri_id = ? AND dibatalkan = 0 ORDER BY tanggal DESC, id DESC LIMIT 1', [$santriId]);
        $avg = Db::val('SELECT AVG(nilai) FROM hafalan WHERE santri_id = ? AND dibatalkan = 0 AND tanggal >= ?', [$santriId, date('Y-m-d', strtotime('-30 days'))]);
        $n = (int) Db::val('SELECT COUNT(*) FROM hafalan WHERE santri_id = ? AND dibatalkan = 0 AND tanggal >= ?', [$santriId, date('Y-m-01')]);
        return ['juz' => round(array_sum($pj) / self::HAL_PER_JUZ, 1), 'per_juz' => $pj, 'terakhir' => $last, 'rata' => $avg !== null ? (int) round((float) $avg) : null, 'bulan_ini' => $n];
    }

    public static function riwayat(int $santriId, int $limit = 20): array
    {
        return Db::all('SELECT h.*, u.nama AS ustadz FROM hafalan h LEFT JOIN users u ON u.id = h.ustadz_id WHERE h.santri_id = ? AND h.dibatalkan = 0 ORDER BY h.tanggal DESC, h.id DESC LIMIT ' . (int) $limit, [$santriId]);
    }

    /** Ringkasan kelompok untuk dashboard tahfidz. */
    public static function ringkas(string $tanggal, array $f, ?string $scope): array
    {
        $p = [];
        $w = self::filterSql($f, $scope, $p);
        $santri = Db::all("SELECT s.id FROM santri s WHERE s.status = 'aktif'$w", $p);
        $ids = array_map(static fn($r) => (int) $r['id'], $santri);
        $total = 0.0;
        foreach ($ids as $id) { $total += array_sum(self::progresJuz($id)); }
        $sudah = count($ids) - count(self::belumSetor($tanggal, $f, $scope));
        return ['santri' => count($ids), 'sudah' => $sudah, 'rata_juz' => $ids ? round($total / self::HAL_PER_JUZ / count($ids), 1) : 0];
    }
}
