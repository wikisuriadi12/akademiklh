<?php
declare(strict_types=1);

/** Logika tarif, tagihan (SPP & biaya lain), dan pembayaran. */
final class Spp
{
    private const SUB_TAGIHAN = "SELECT t.id, t.santri_id, t.periode, t.nominal, t.keterangan, t.jenis_id,
            jb.kode AS jenis_kode, jb.nama AS jenis_nama, jb.tipe AS jenis_tipe,
            s.nis, s.nama, s.jenjang, s.kelas, s.unit, s.nama_wali, s.hp_wali, s.status AS santri_status,
            COALESCE((SELECT SUM(p.jumlah) FROM pembayaran p WHERE p.tagihan_id = t.id AND p.dibatalkan = 0), 0) AS dibayar
        FROM tagihan t JOIN santri s ON s.id = t.santri_id JOIN jenis_biaya jb ON jb.id = t.jenis_id";

    // ---------- Jenis biaya ----------

    public static function jenisList(bool $hanyaAktif = true): array
    {
        return Db::all('SELECT * FROM jenis_biaya' . ($hanyaAktif ? ' WHERE aktif = 1' : '') . ' ORDER BY tipe, nama');
    }

    public static function jenisId(string $kode): int
    {
        $id = Db::val('SELECT id FROM jenis_biaya WHERE kode = ?', [$kode]);
        if (!$id) {
            throw new RuntimeException("Jenis biaya $kode belum ada. Impor ulang database/schema.sql.");
        }
        return (int) $id;
    }

    public static function tambahJenis(string $kode, string $nama, ?int $userId): int
    {
        $kode = strtoupper(trim($kode));
        $nama = trim($nama);
        if (!preg_match('/^[A-Z0-9_]{2,20}$/', $kode) || $nama === '' || mb_strlen($nama) > 60) {
            throw new InvalidArgumentException('Kode 2-20 huruf/angka/garis bawah dan nama wajib diisi.');
        }
        if (Db::val('SELECT id FROM jenis_biaya WHERE kode = ?', [$kode])) {
            throw new InvalidArgumentException('Kode jenis biaya sudah ada.');
        }
        $id = Db::insert("INSERT INTO jenis_biaya (kode, nama, tipe, aktif) VALUES (?, ?, 'sekali', 1)", [$kode, $nama]);
        Db::audit($userId, 'tambah', 'jenis_biaya', $id, "$kode $nama");
        return $id;
    }

    public static function setJenisAktif(int $id, bool $aktif, ?int $userId): void
    {
        $row = Db::one('SELECT kode FROM jenis_biaya WHERE id = ?', [$id]);
        if (!$row) {
            throw new InvalidArgumentException('Jenis biaya tidak ditemukan.');
        }
        if ($row['kode'] === 'SPP') {
            throw new InvalidArgumentException('Jenis SPP tidak dapat dinonaktifkan.');
        }
        Db::exec('UPDATE jenis_biaya SET aktif = ? WHERE id = ?', [$aktif ? 1 : 0, $id]);
        Db::audit($userId, $aktif ? 'aktifkan' : 'nonaktifkan', 'jenis_biaya', $id, $row['kode']);
    }

    /** Label tagihan untuk tampilan, mis. "SPP Oktober 2026" atau "Seragam - Batik". */
    public static function label(array $t): string
    {
        if (($t['jenis_tipe'] ?? '') === 'bulanan') {
            return ($t['jenis_kode'] ?? '') === 'SPP' ? 'SPP ' . Domain::namaPeriode($t['periode']) : $t['jenis_nama'] . ' ' . Domain::namaPeriode($t['periode']);
        }
        $ket = (string) ($t['tagihan_ket'] ?? $t['keterangan'] ?? '');
        return $t['jenis_nama'] . ($ket !== '' ? ' - ' . $ket : '');
    }

    // ---------- Tarif ----------

    public static function tarifGrid(string $ta): array
    {
        $grid = [];
        foreach (Db::all('SELECT jenjang, unit, nominal FROM tarif_spp WHERE tahun_ajaran = ?', [$ta]) as $r) {
            $grid[$r['jenjang']][$r['unit']] = (int) $r['nominal'];
        }
        return $grid;
    }

    public static function setTarif(string $ta, string $jenjang, string $unit, int $nominal, ?int $userId): void
    {
        if (!preg_match('#^\d{4}/\d{4}$#', $ta) || !in_array($jenjang, Domain::JENJANG, true) || !isset(Domain::UNIT[$unit])) {
            throw new InvalidArgumentException('Data tarif tidak valid.');
        }
        if ($nominal < 0 || $nominal > 100000000) {
            throw new InvalidArgumentException('Nominal tarif tidak wajar.');
        }
        $now = date('Y-m-d H:i:s');
        $id = Db::val('SELECT id FROM tarif_spp WHERE tahun_ajaran=? AND jenjang=? AND unit=?', [$ta, $jenjang, $unit]);
        if ($id) {
            Db::exec('UPDATE tarif_spp SET nominal=?, updated_at=? WHERE id=?', [$nominal, $now, $id]);
        } else {
            $id = Db::insert('INSERT INTO tarif_spp (tahun_ajaran, jenjang, unit, nominal, updated_at) VALUES (?,?,?,?,?)', [$ta, $jenjang, $unit, $nominal, $now]);
        }
        Db::audit($userId, 'set', 'tarif', (int) $id, "$ta $jenjang $unit = $nominal");
    }

    /** Salin tarif dari tahun ajaran lain (hanya mengisi yang belum ada), dengan kenaikan persen opsional. */
    public static function salinTarif(string $dari, string $ke, float $naikPersen, ?int $userId): int
    {
        foreach ([$dari, $ke] as $ta) {
            if (!preg_match('#^\d{4}/\d{4}$#', $ta)) {
                throw new InvalidArgumentException('Format tahun ajaran tidak valid.');
            }
        }
        if ($dari === $ke) {
            throw new InvalidArgumentException('Tahun ajaran asal dan tujuan sama.');
        }
        if ($naikPersen < 0 || $naikPersen > 100) {
            throw new InvalidArgumentException('Kenaikan harus antara 0 dan 100 persen.');
        }
        $n = 0;
        foreach (Db::all('SELECT jenjang, unit, nominal FROM tarif_spp WHERE tahun_ajaran = ?', [$dari]) as $r) {
            if (Db::val('SELECT id FROM tarif_spp WHERE tahun_ajaran=? AND jenjang=? AND unit=?', [$ke, $r['jenjang'], $r['unit']])) {
                continue;
            }
            $nom = (int) (round((int) $r['nominal'] * (1 + $naikPersen / 100) / 1000) * 1000);
            self::setTarif($ke, $r['jenjang'], $r['unit'], $nom, $userId);
            $n++;
        }
        if ($n === 0) {
            throw new InvalidArgumentException('Tidak ada tarif yang disalin (asal kosong atau tujuan sudah terisi).');
        }
        return $n;
    }

    // ---------- Tagihan ----------

    /** Buat tagihan SPP bulanan untuk semua santri aktif (sesuai cakupan unit). Aman dijalankan ulang. */
    public static function generate(string $periode, ?string $scope, ?int $userId): array
    {
        if (!Domain::validPeriode($periode)) {
            throw new InvalidArgumentException('Periode harus berformat YYYY-MM.');
        }
        $jenisId = self::jenisId('SPP');
        $ta = Domain::tahunAjaran($periode);
        $sql = "SELECT id, jenjang, unit, potongan_spp FROM santri WHERE status = 'aktif'";
        $params = [];
        if ($scope) {
            $sql .= ' AND unit = ?';
            $params[] = $scope;
        }
        $santri = Db::all($sql, $params);
        $tarif = self::tarifGrid($ta);
        $dibuat = 0;
        $sudahAda = 0;
        $tanpaTarif = [];

        Db::transaction(function () use ($santri, $tarif, $periode, $userId, $jenisId, &$dibuat, &$sudahAda, &$tanpaTarif) {
            foreach ($santri as $s) {
                if (Db::val("SELECT id FROM tagihan WHERE santri_id=? AND jenis_id=? AND periode=? AND keterangan=''", [$s['id'], $jenisId, $periode])) {
                    $sudahAda++;
                    continue;
                }
                $t = $tarif[$s['jenjang']][$s['unit']] ?? null;
                if ($t === null) {
                    $k = $s['jenjang'] . ' ' . Domain::UNIT[$s['unit']];
                    $tanpaTarif[$k] = ($tanpaTarif[$k] ?? 0) + 1;
                    continue;
                }
                $nominal = max(0, $t - (int) $s['potongan_spp']);
                Db::exec(
                    "INSERT INTO tagihan (santri_id, jenis_id, periode, keterangan, nominal, created_by, created_at) VALUES (?,?,?,'',?,?,?)",
                    [$s['id'], $jenisId, $periode, $nominal, $userId, date('Y-m-d H:i:s')]
                );
                $dibuat++;
            }
        });
        Db::audit($userId, 'generate', 'tagihan', null, "$periode dibuat=$dibuat sudah_ada=$sudahAda");
        return ['dibuat' => $dibuat, 'sudah_ada' => $sudahAda, 'tanpa_tarif' => $tanpaTarif, 'tahun_ajaran' => $ta];
    }

    /**
     * Tagihan biaya lain (seragam, kitab, pendaftaran, dll.) untuk santri sesuai filter.
     * $f: unit, jenjang, kelas, santri_id. Santri berstatus aktif atau calon.
     */
    public static function buatTagihanLain(int $jenisId, string $periode, int $nominal, string $ket, array $f, ?string $scope, ?int $userId): array
    {
        $jenis = Db::one('SELECT * FROM jenis_biaya WHERE id = ?', [$jenisId]);
        if (!$jenis || !(int) $jenis['aktif']) {
            throw new InvalidArgumentException('Jenis biaya tidak ditemukan atau nonaktif.');
        }
        if ($jenis['tipe'] !== 'sekali') {
            throw new InvalidArgumentException('SPP dibuat lewat "Buat tagihan bulanan" menurut tarif.');
        }
        if (!Domain::validPeriode($periode)) {
            throw new InvalidArgumentException('Periode harus berformat YYYY-MM.');
        }
        if ($nominal <= 0 || $nominal > 100000000) {
            throw new InvalidArgumentException('Nominal harus lebih dari nol dan wajar.');
        }
        $ket = trim($ket);
        if (mb_strlen($ket) > 100) {
            throw new InvalidArgumentException('Keterangan maksimal 100 karakter.');
        }
        $where = ["status IN ('aktif','calon')"];
        $p = [];
        foreach (['unit', 'jenjang', 'kelas'] as $k) {
            if (!empty($f[$k])) { $where[] = "$k = ?"; $p[] = $f[$k]; }
        }
        if (!empty($f['santri_id'])) { $where[] = 'id = ?'; $p[] = (int) $f['santri_id']; }
        if ($scope) { $where[] = 'unit = ?'; $p[] = $scope; }
        $target = Db::all('SELECT id FROM santri WHERE ' . implode(' AND ', $where), $p);
        if (!$target) {
            throw new InvalidArgumentException('Tidak ada santri yang sesuai dengan pilihan tersebut.');
        }
        $dibuat = 0;
        $sudahAda = 0;
        Db::transaction(function () use ($target, $jenisId, $periode, $nominal, $ket, $userId, &$dibuat, &$sudahAda) {
            foreach ($target as $s) {
                if (Db::val('SELECT id FROM tagihan WHERE santri_id=? AND jenis_id=? AND periode=? AND keterangan=?', [$s['id'], $jenisId, $periode, $ket])) {
                    $sudahAda++;
                    continue;
                }
                Db::exec(
                    'INSERT INTO tagihan (santri_id, jenis_id, periode, keterangan, nominal, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
                    [$s['id'], $jenisId, $periode, $ket, $nominal, $userId, date('Y-m-d H:i:s')]
                );
                $dibuat++;
            }
        });
        Db::audit($userId, 'tagihan_lain', 'tagihan', null, $jenis['kode'] . " $periode $ket nominal=$nominal dibuat=$dibuat");
        return ['dibuat' => $dibuat, 'sudah_ada' => $sudahAda];
    }

    public static function daftarTagihan(array $f, ?string $scope, int $limit = 500): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['periode'])) { $where[] = 'x.periode = ?'; $p[] = $f['periode']; }
        if (!empty($f['unit'])) { $where[] = 'x.unit = ?'; $p[] = $f['unit']; }
        if (!empty($f['jenjang'])) { $where[] = 'x.jenjang = ?'; $p[] = $f['jenjang']; }
        if (!empty($f['jenis'])) { $where[] = 'x.jenis_id = ?'; $p[] = (int) $f['jenis']; }
        if (!empty($f['q'])) { $where[] = '(x.nama LIKE ? OR x.nis LIKE ?)'; $p[] = '%' . $f['q'] . '%'; $p[] = '%' . $f['q'] . '%'; }
        if ($scope) { $where[] = 'x.unit = ?'; $p[] = $scope; }
        $st = $f['status'] ?? '';
        if ($st === 'lunas') { $where[] = 'x.dibayar >= x.nominal'; }
        elseif ($st === 'belum') { $where[] = 'x.dibayar = 0 AND x.nominal > 0'; }
        elseif ($st === 'sebagian') { $where[] = 'x.dibayar > 0 AND x.dibayar < x.nominal'; }
        $sql = 'SELECT x.* FROM (' . self::SUB_TAGIHAN . ') x WHERE ' . implode(' AND ', $where)
            . ' ORDER BY x.periode DESC, x.unit, x.jenjang, x.nama, x.jenis_id LIMIT ' . (int) $limit;
        return Db::all($sql, $p);
    }

    public static function tagihanSantri(int $santriId): array
    {
        return Db::all('SELECT x.* FROM (' . self::SUB_TAGIHAN . ') x WHERE x.santri_id = ? ORDER BY x.periode DESC, x.id DESC', [$santriId]);
    }

    public static function getTagihan(int $id): ?array
    {
        return Db::one('SELECT x.* FROM (' . self::SUB_TAGIHAN . ') x WHERE x.id = ?', [$id]);
    }

    // ---------- Pembayaran ----------

    public static function bayar(int $tagihanId, string $tanggal, int $jumlah, string $metode, string $ket, ?int $userId, ?string $scope): int
    {
        if (!Domain::validDate($tanggal)) {
            throw new InvalidArgumentException('Tanggal pembayaran tidak valid.');
        }
        if ($tanggal > date('Y-m-d')) {
            throw new InvalidArgumentException('Tanggal pembayaran tidak boleh di masa depan.');
        }
        if ($jumlah <= 0) {
            throw new InvalidArgumentException('Jumlah harus lebih dari nol.');
        }
        if (!in_array($metode, ['tunai', 'transfer', 'bni_va'], true)) {
            throw new InvalidArgumentException('Metode pembayaran tidak valid.');
        }
        return Db::transaction(function () use ($tagihanId, $tanggal, $jumlah, $metode, $ket, $userId, $scope) {
            $tg = Db::one(
                'SELECT t.id, t.nominal, s.unit FROM tagihan t JOIN santri s ON s.id = t.santri_id WHERE t.id = ?' . Db::forUpdate(),
                [$tagihanId]
            );
            if (!$tg) {
                throw new InvalidArgumentException('Tagihan tidak ditemukan.');
            }
            if ($scope && $tg['unit'] !== $scope) {
                throw new InvalidArgumentException('Tagihan di luar cakupan akun Anda.');
            }
            $dibayar = (int) Db::val('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran WHERE tagihan_id=? AND dibatalkan=0', [$tagihanId]);
            $sisa = (int) $tg['nominal'] - $dibayar;
            if ($jumlah > $sisa) {
                throw new InvalidArgumentException('Jumlah melebihi sisa tagihan (sisa ' . rp($sisa) . ').');
            }
            $id = Db::insert(
                'INSERT INTO pembayaran (no_kuitansi, tagihan_id, tanggal, jumlah, metode, keterangan, dibuat_oleh, created_at) VALUES (?,?,?,?,?,?,?,?)',
                ['TMP' . bin2hex(random_bytes(8)), $tagihanId, $tanggal, $jumlah, $metode, $ket !== '' ? $ket : null, $userId, date('Y-m-d H:i:s')]
            );
            Db::exec('UPDATE pembayaran SET no_kuitansi=? WHERE id=?', [sprintf('KW-%s-%06d', substr($tanggal, 0, 4), $id), $id]);
            Db::audit($userId, 'bayar', 'pembayaran', $id, "tagihan=$tagihanId jumlah=$jumlah $metode");
            return $id;
        });
    }

    /** Pembayaran tidak dihapus; dibatalkan dengan alasan dan jejak audit. */
    public static function batalkan(int $pembayaranId, string $alasan, ?int $userId, ?string $scope): void
    {
        $alasan = trim($alasan);
        if (mb_strlen($alasan) < 5) {
            throw new InvalidArgumentException('Alasan pembatalan minimal 5 karakter.');
        }
        Db::transaction(function () use ($pembayaranId, $alasan, $userId, $scope) {
            $p = Db::one(
                'SELECT p.id, p.dibatalkan, s.unit FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN santri s ON s.id=t.santri_id WHERE p.id=?' . Db::forUpdate(),
                [$pembayaranId]
            );
            if (!$p) {
                throw new InvalidArgumentException('Pembayaran tidak ditemukan.');
            }
            if ($scope && $p['unit'] !== $scope) {
                throw new InvalidArgumentException('Pembayaran di luar cakupan akun Anda.');
            }
            if ((int) $p['dibatalkan'] === 1) {
                throw new InvalidArgumentException('Pembayaran sudah dibatalkan.');
            }
            Db::exec(
                'UPDATE pembayaran SET dibatalkan=1, alasan_batal=?, dibatalkan_oleh=?, dibatalkan_at=? WHERE id=?',
                [$alasan, $userId, date('Y-m-d H:i:s'), $pembayaranId]
            );
            Db::audit($userId, 'batal', 'pembayaran', $pembayaranId, $alasan);
        });
    }

    public static function kuitansi(int $pembayaranId): ?array
    {
        $r = Db::one(
            'SELECT p.*, t.periode, t.keterangan AS tagihan_ket, t.nominal AS tagihan_nominal, jb.kode AS jenis_kode, jb.nama AS jenis_nama, jb.tipe AS jenis_tipe,
                    s.nis, s.nama, s.jenjang, s.kelas, s.unit, s.nama_wali, u.nama AS petugas
             FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN jenis_biaya jb ON jb.id=t.jenis_id JOIN santri s ON s.id=t.santri_id
             LEFT JOIN users u ON u.id = p.dibuat_oleh WHERE p.id=?',
            [$pembayaranId]
        );
        if ($r) {
            $dibayar = (int) Db::val('SELECT COALESCE(SUM(jumlah),0) FROM pembayaran WHERE tagihan_id=? AND dibatalkan=0', [$r['tagihan_id']]);
            $r['sisa'] = (int) $r['tagihan_nominal'] - $dibayar;
            $r['label'] = self::label(['jenis_tipe' => $r['jenis_tipe'], 'jenis_kode' => $r['jenis_kode'], 'jenis_nama' => $r['jenis_nama'], 'periode' => $r['periode'], 'keterangan' => $r['tagihan_ket']]);
        }
        return $r;
    }

    public static function riwayatSantri(int $santriId, int $limit = 30): array
    {
        return Db::all(
            'SELECT p.*, t.periode, t.keterangan AS tagihan_ket, jb.kode AS jenis_kode, jb.nama AS jenis_nama, jb.tipe AS jenis_tipe
             FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN jenis_biaya jb ON jb.id=t.jenis_id
             WHERE t.santri_id=? ORDER BY p.id DESC LIMIT ' . (int) $limit,
            [$santriId]
        );
    }

    public static function terbaru(?string $scope, int $limit = 6): array
    {
        $sql = 'SELECT p.id, p.no_kuitansi, p.tanggal, p.jumlah, s.nama, s.jenjang, s.unit
                FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN santri s ON s.id=t.santri_id
                WHERE p.dibatalkan = 0' . ($scope ? ' AND s.unit = ?' : '') . ' ORDER BY p.id DESC LIMIT ' . (int) $limit;
        return Db::all($sql, $scope ? [$scope] : []);
    }

    // ---------- Laporan ----------

    public static function tunggakan(?string $scope, array $f = []): array
    {
        $where = ['x.dibayar < x.nominal'];
        $p = [];
        if ($scope) { $where[] = 'x.unit = ?'; $p[] = $scope; }
        if (!empty($f['unit'])) { $where[] = 'x.unit = ?'; $p[] = $f['unit']; }
        if (!empty($f['jenjang'])) { $where[] = 'x.jenjang = ?'; $p[] = $f['jenjang']; }
        if (!empty($f['sampai'])) { $where[] = 'x.periode <= ?'; $p[] = $f['sampai']; }
        $sql = 'SELECT x.santri_id, x.nis, x.nama, x.jenjang, x.kelas, x.unit, x.nama_wali, x.hp_wali,
                    COUNT(*) AS bulan, SUM(x.nominal - x.dibayar) AS total, MIN(x.periode) AS sejak
                FROM (' . self::SUB_TAGIHAN . ') x WHERE ' . implode(' AND ', $where)
            . ' GROUP BY x.santri_id, x.nis, x.nama, x.jenjang, x.kelas, x.unit, x.nama_wali, x.hp_wali ORDER BY total DESC, x.nama';
        return Db::all($sql, $p);
    }

    public static function penerimaan(string $dari, string $sampai, ?string $scope, array $f = []): array
    {
        $where = ['p.dibatalkan = 0', 'p.tanggal >= ?', 'p.tanggal <= ?'];
        $p = [$dari, $sampai];
        if ($scope) { $where[] = 's.unit = ?'; $p[] = $scope; }
        if (!empty($f['unit'])) { $where[] = 's.unit = ?'; $p[] = $f['unit']; }
        if (!empty($f['jenjang'])) { $where[] = 's.jenjang = ?'; $p[] = $f['jenjang']; }
        return Db::all(
            'SELECT p.id, p.no_kuitansi, p.tanggal, p.jumlah, p.metode, t.periode, t.keterangan AS tagihan_ket,
                    jb.kode AS jenis_kode, jb.nama AS jenis_nama, jb.tipe AS jenis_tipe, s.nis, s.nama, s.jenjang, s.unit
             FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN jenis_biaya jb ON jb.id=t.jenis_id JOIN santri s ON s.id=t.santri_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.tanggal, p.id',
            $p
        );
    }

    /** Penerimaan per bulan untuk n bulan terakhir (termasuk bulan berjalan), bulan kosong = 0. */
    public static function penerimaanPerBulan(int $n, ?string $scope): array
    {
        $bulan = [];
        $t = new DateTime('first day of this month');
        $t->modify('-' . ($n - 1) . ' months');
        for ($i = 0; $i < $n; $i++) {
            $bulan[$t->format('Y-m')] = 0;
            $t->modify('+1 month');
        }
        $dari = array_key_first($bulan) . '-01';
        $sql = 'SELECT SUBSTR(p.tanggal, 1, 7) AS bln, SUM(p.jumlah) AS total
                FROM pembayaran p JOIN tagihan t ON t.id=p.tagihan_id JOIN santri s ON s.id=t.santri_id
                WHERE p.dibatalkan = 0 AND p.tanggal >= ?' . ($scope ? ' AND s.unit = ?' : '') . ' GROUP BY SUBSTR(p.tanggal, 1, 7)';
        foreach (Db::all($sql, $scope ? [$dari, $scope] : [$dari]) as $r) {
            if (isset($bulan[$r['bln']])) {
                $bulan[$r['bln']] = (int) $r['total'];
            }
        }
        return $bulan;
    }

    public static function ringkasPeriode(string $periode, ?string $scope): array
    {
        $rows = self::daftarTagihan(['periode' => $periode], $scope, 100000);
        $tagih = 0; $bayar = 0; $lunas = 0;
        foreach ($rows as $r) {
            $tagih += (int) $r['nominal'];
            $bayar += (int) $r['dibayar'];
            if ((int) $r['dibayar'] >= (int) $r['nominal']) { $lunas++; }
        }
        return ['jumlah' => count($rows), 'lunas' => $lunas, 'ditagih' => $tagih, 'dibayar' => $bayar, 'sisa' => $tagih - $bayar];
    }
}
