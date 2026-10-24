<?php
declare(strict_types=1);

/** Absensi santri per sesi kegiatan. Satu catatan per santri + tanggal + sesi (bisa diperbarui). */
final class Kehadiran
{
    public const STATUS = ['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'];

    /** @return string[] */
    public static function sesiList(): array
    {
        $l = array_values(array_filter(array_map('trim', explode(',', Settings::get('sesi_hadir')))));
        return $l ?: ['Kegiatan'];
    }

    /**
     * Lembar absensi: santri aktif sesuai filter + status tersimpan; bila belum ada,
     * izin yang disetujui pada tanggal itu otomatis terisi "I".
     */
    public static function lembar(string $tanggal, string $sesi, array $f, ?string $scope): array
    {
        $f['status'] = 'aktif';
        [$rows] = Santri::daftar($f, $scope, 1, true);
        $ada = [];
        foreach (Db::all('SELECT santri_id, status, keterangan FROM kehadiran WHERE tanggal = ? AND sesi = ?', [$tanggal, $sesi]) as $r) { $ada[(int) $r['santri_id']] = $r; }
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            if (isset($ada[$id])) {
                $r['h_status'] = $ada[$id]['status']; $r['h_ket'] = $ada[$id]['keterangan']; $r['h_tersimpan'] = true;
            } else {
                $iz = Izin::aktifPada($id, $tanggal);
                $r['h_status'] = $iz ? 'I' : ''; $r['h_ket'] = $iz ? 'Izin ' . Izin::JENIS[$iz['jenis']] . ' (otomatis)' : ''; $r['h_tersimpan'] = false;
            }
        }
        return $rows;
    }

    /** @param array<int,array{status:string,ket?:string}> $data */
    public static function simpan(string $tanggal, string $sesi, array $data, ?int $userId, ?string $scope): int
    {
        if (!Domain::validDate($tanggal) || $tanggal > date('Y-m-d')) { throw new InvalidArgumentException('Tanggal absensi tidak valid.'); }
        $sesi = mb_substr(trim($sesi), 0, 40);
        if ($sesi === '') { throw new InvalidArgumentException('Sesi wajib dipilih.'); }
        $n = 0;
        Db::transaction(function () use ($tanggal, $sesi, $data, $userId, $scope, &$n) {
            foreach ($data as $sid => $d) {
                $st = (string) ($d['status'] ?? '');
                if ($st === '') { continue; }
                if (!isset(self::STATUS[$st])) { throw new InvalidArgumentException('Status kehadiran tidak valid.'); }
                $s = Santri::find((int) $sid);
                if (!$s) { throw new InvalidArgumentException('Santri tidak ditemukan.'); }
                if ($scope !== null && $s['unit'] !== $scope) { throw new InvalidArgumentException('Ada santri di luar cakupan unit akun Anda.'); }
                $ket = mb_substr(trim((string) ($d['ket'] ?? '')), 0, 150);
                $id = Db::val('SELECT id FROM kehadiran WHERE santri_id = ? AND tanggal = ? AND sesi = ?', [(int) $sid, $tanggal, $sesi]);
                if ($id) {
                    Db::exec('UPDATE kehadiran SET status = ?, keterangan = ?, dicatat_oleh = ?, updated_at = ? WHERE id = ?', [$st, $ket, $userId, date('Y-m-d H:i:s'), $id]);
                } else {
                    Db::exec('INSERT INTO kehadiran (santri_id, tanggal, sesi, status, keterangan, dicatat_oleh, updated_at) VALUES (?,?,?,?,?,?,?)', [(int) $sid, $tanggal, $sesi, $st, $ket, $userId, date('Y-m-d H:i:s')]);
                }
                $n++;
            }
        });
        if ($n === 0) { throw new InvalidArgumentException('Belum ada status yang diisi.'); }
        Db::audit($userId, 'absen', 'kehadiran', null, "$tanggal $sesi $n santri");
        return $n;
    }

    /** Jumlah per status untuk satu santri pada bulan YYYY-MM. */
    public static function rekapSantri(int $santriId, string $bulan): array
    {
        $out = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM kehadiran WHERE santri_id = ? AND tanggal >= ? AND tanggal <= ? GROUP BY status', [$santriId, $bulan . '-01', $bulan . '-31']) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        $tot = array_sum($out);
        $out['persen'] = $tot ? (int) round($out['H'] / $tot * 100) : null;
        return $out;
    }

    public static function rekapKelas(string $bulan, array $f, ?string $scope): array
    {
        $f['status'] = 'aktif';
        [$rows] = Santri::daftar($f, $scope, 1, true);
        foreach ($rows as &$r) { $r['rekap'] = self::rekapSantri((int) $r['id'], $bulan); }
        return $rows;
    }
}
