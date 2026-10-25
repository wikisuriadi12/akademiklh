<?php
declare(strict_types=1);

/** Barang dan stok gudang. Stok dipisah per unit (ikhwan/akhwat); semua perubahan lewat mutasi. */
final class Gudang
{
    public const TIPE = ['masuk' => 'Barang masuk', 'keluar' => 'Keluar (rusak/hilang)', 'opname' => 'Opname', 'jual' => 'Terjual', 'batal_jual' => 'Batal jual', 'transfer_masuk' => 'Transfer masuk', 'transfer_keluar' => 'Transfer keluar'];

    public const IMPOR_HEADER = ['kode', 'nama', 'kategori', 'satuan', 'harga_beli', 'harga_jual', 'harga_pegawai', 'stok_min', 'stok_gudang', 'stok_market'];

    /** Baris contoh template impor master barang. */
    public static function templateContoh(): array
    {
        return [
            ['KTN-001', 'Air Mineral 600 ml', 'Kantin', 'pcs', 2500, 3500, 3000, 12, 120, 24],
            ['KTN-002', 'Mie Instan Goreng', 'Kantin', 'pcs', 2800, 3800, 3500, 12, 96, 24],
            ['ATK-001', 'Buku Tulis 38 Lembar', 'Alat Tulis', 'pcs', 3500, 5000, '', 20, 200, 40],
        ];
    }

    /** Data master + stok saat ini, untuk diekspor/disunting lalu diimpor lagi. */
    public static function eksporBaris(): array
    {
        $out = [self::IMPOR_HEADER];
        foreach (Db::all('SELECT * FROM barang ORDER BY kategori, nama') as $b) {
            $out[] = [$b['kode'], $b['nama'], $b['kategori'], $b['satuan'], (int) $b['harga_beli'], (int) $b['harga_jual'], $b['harga_pegawai'] === null ? '' : (int) $b['harga_pegawai'], (int) $b['stok_min'], self::stok((int) $b['id'], Pos::GUDANG), self::stok((int) $b['id'], Pos::TOKO)];
        }
        return $out;
    }

    private static function kunciHeader(string $h): ?string
    {
        $h = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $h)));
        $peta = [
            'kode' => ['kode', 'kode barang', 'sku', 'barcode'],
            'nama' => ['nama', 'nama barang', 'barang'],
            'kategori' => ['kategori', 'jenis'],
            'satuan' => ['satuan', 'unit'],
            'harga_beli' => ['harga beli', 'harga modal', 'modal', 'hpp'],
            'harga_jual' => ['harga jual', 'harga', 'harga satuan', 'harga umum', 'harga jual umum'],
            'harga_pegawai' => ['harga pegawai', 'harga karyawan', 'harga staff', 'harga staf', 'harga jual pegawai', 'harga jual karyawan'],
            'stok_min' => ['stok min', 'stok minimum', 'batas stok', 'min stok'],
            'stok_gudang' => ['stok gudang', 'gudang', 'qty gudang'],
            'stok_market' => ['stok market', 'market', 'stok toko', 'qty market'],
        ];
        foreach ($peta as $k => $alias) { if (in_array($h, $alias, true)) { return $k; } }
        return null;
    }

    /**
     * Impor master barang + stok awal dari baris Excel/CSV. Semua-atau-tidak-sama-sekali.
     * Stok gudang/market diisi sebagai jumlah akhir (selisih dicatat sebagai mutasi). Kolom stok kosong = tidak diubah.
     */
    public static function imporBarang(array $rows, bool $perbarui, ?int $userId): array
    {
        $hasil = ['ok' => false, 'errors' => [], 'baru' => 0, 'ubah' => 0, 'jumlah_galat' => 0];
        if (count($rows) < 2) { $hasil['errors'][] = 'Berkas kosong atau hanya berisi judul kolom.'; return $hasil; }
        $kolom = [];
        foreach ($rows[0] as $i => $h) { $k = self::kunciHeader((string) $h); if ($k !== null && !isset($kolom[$k])) { $kolom[$k] = $i; } }
        foreach (['kode', 'nama', 'harga_jual'] as $wajib) {
            if (!isset($kolom[$wajib])) { $hasil['errors'][] = "Kolom wajib '$wajib' tidak ditemukan di baris judul."; }
        }
        if ($hasil['errors']) { $hasil['jumlah_galat'] = count($hasil['errors']); return $hasil; }
        if (count($rows) > 1001) { $hasil['errors'][] = 'Maksimal 1000 baris data per impor.'; $hasil['jumlah_galat'] = 1; return $hasil; }
        $ambil = static fn(array $r, string $k): string => isset($kolom[$k]) ? trim((string) ($r[$kolom[$k]] ?? '')) : '';
        $angka = static function (string $v): ?int {
            if ($v === '') { return null; }
            $v = str_replace(['.', ',', ' '], '', preg_replace('/^Rp\s*/i', '', $v));
            return ctype_digit($v) ? (int) $v : -1;
        };
        $plan = []; $dobel = [];
        foreach (array_slice($rows, 1) as $n => $r) {
            $baris = $n + 2;
            if (!array_filter($r, static fn($v) => trim((string) $v) !== '')) { continue; }
            $in = ['kode' => $ambil($r, 'kode'), 'nama' => $ambil($r, 'nama'), 'kategori' => $ambil($r, 'kategori'), 'satuan' => $ambil($r, 'satuan'),
                'harga_beli' => $ambil($r, 'harga_beli'), 'harga_jual' => $ambil($r, 'harga_jual'), 'harga_pegawai' => $ambil($r, 'harga_pegawai'), 'stok_min' => $ambil($r, 'stok_min')];
            [$c, $err] = self::validateBarang($in);
            $pesan = array_values($err);
            foreach (['harga_beli', 'harga_jual', 'harga_pegawai', 'stok_min'] as $k) { if ($in[$k] !== '' && $angka($in[$k]) === -1) { $pesan[] = "$k harus angka bulat"; } }
            $st = [];
            foreach (['stok_gudang' => Pos::GUDANG, 'stok_market' => Pos::TOKO] as $k => $lok) {
                $v = $angka($ambil($r, $k));
                if ($v === -1) { $pesan[] = "$k harus angka bulat 0 atau lebih"; } elseif ($v !== null) { $st[$lok] = $v; }
            }
            if (isset($dobel[$c['kode']])) { $pesan[] = 'kode ' . $c['kode'] . ' dobel dengan baris ' . $dobel[$c['kode']]; }
            $dobel[$c['kode']] = $baris;
            $ada = $c['kode'] !== '' ? Db::one('SELECT id FROM barang WHERE kode = ?', [$c['kode']]) : null;
            if ($ada && !$perbarui) { $pesan[] = 'kode ' . $c['kode'] . ' sudah ada (centang "Perbarui barang yang sudah ada" bila ingin menimpa)'; }
            if ($pesan) { $hasil['errors'][] = "Baris $baris: " . implode('; ', $pesan); continue; }
            $plan[] = [$c, $ada ? (int) $ada['id'] : null, $st];
        }
        if ($hasil['errors']) { $hasil['jumlah_galat'] = count($hasil['errors']); $hasil['errors'] = array_slice($hasil['errors'], 0, 50); return $hasil; }
        if (!$plan) { $hasil['errors'][] = 'Tidak ada baris data.'; $hasil['jumlah_galat'] = 1; return $hasil; }
        Db::transaction(function () use ($plan, $userId, &$hasil) {
            foreach ($plan as [$c, $id, $st]) {
                $baru = $id === null;
                $id = self::simpanBarang($c, $id, $userId);
                $baru ? $hasil['baru']++ : $hasil['ubah']++;
                foreach ($st as $lok => $target) {
                    $delta = $target - self::stok($id, $lok);
                    if ($delta !== 0) { self::ubah($id, $lok, $delta, $delta > 0 && $baru ? 'masuk' : 'opname', ['catatan' => 'Impor Excel', 'user_id' => $userId, 'harga_beli' => $c['harga_beli']]); }
                }
            }
            Db::audit($userId, 'impor', 'barang', null, $hasil['baru'] . ' baru, ' . $hasil['ubah'] . ' diperbarui');
        });
        $hasil['ok'] = true;
        return $hasil;
    }

    private static function cekUnit(string $unit, ?string $scope): void
    {
        if (!isset(Pos::LOKASI[$unit]) && !isset(Domain::UNIT[$unit])) {
            throw new InvalidArgumentException('Lokasi stok tidak valid.');
        }
        if ($scope !== null && $scope !== $unit) {
            throw new InvalidArgumentException('Unit di luar cakupan akun Anda.');
        }
    }

    public static function validateBarang(array $in): array
    {
        $err = [];
        $kode = strtoupper(trim((string) ($in['kode'] ?? '')));
        $nama = trim((string) ($in['nama'] ?? ''));
        if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $kode)) { $err['kode'] = 'Kode 2-30 karakter: huruf, angka, titik, strip.'; }
        if ($nama === '' || mb_strlen($nama) > 120) { $err['nama'] = 'Nama barang wajib diisi (maks. 120 karakter).'; }
        $num = static fn(string $k): int => (int) preg_replace('/\D/', '', (string) ($in[$k] ?? '0'));
        $beli = $num('harga_beli'); $jual = $num('harga_jual'); $min = $num('stok_min');
        if ($jual <= 0) { $err['harga_jual'] = 'Harga jual harus lebih dari 0.'; }
        $hpRaw = trim((string) ($in['harga_pegawai'] ?? ''));
        $hp = $hpRaw === '' ? null : (int) preg_replace('/\D/', '', $hpRaw);
        if ($hp !== null && $hp <= 0) { $err['harga_pegawai'] = 'Harga pegawai harus lebih dari 0 (kosongkan bila sama dengan harga umum).'; }
        return [[
            'kode' => $kode, 'nama' => $nama, 'kategori' => mb_substr(trim((string) ($in['kategori'] ?? '')), 0, 40),
            'satuan' => mb_substr(trim((string) ($in['satuan'] ?? '')) ?: 'pcs', 0, 20),
            'harga_beli' => $beli, 'harga_jual' => $jual, 'harga_pegawai' => $hp, 'stok_min' => $min,
        ], $err];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM barang WHERE id = ?', [$id]);
    }

    public static function simpanBarang(array $c, ?int $id, ?int $userId): int
    {
        $dup = Db::val('SELECT id FROM barang WHERE kode = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$c['kode'], $id] : [$c['kode']]);
        if ($dup) { throw new InvalidArgumentException('Kode barang sudah dipakai.'); }
        if ($id) {
            try {
                Db::exec('UPDATE barang SET kode=?, nama=?, kategori=?, satuan=?, harga_beli=?, harga_jual=?, harga_pegawai=?, stok_min=? WHERE id=?',
                    [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], ($c['harga_pegawai'] ?? null), $c['stok_min'], $id]);
            } catch (PDOException $e) { // kolom harga_pegawai belum dimigrasi
                Db::exec('UPDATE barang SET kode=?, nama=?, kategori=?, satuan=?, harga_beli=?, harga_jual=?, stok_min=? WHERE id=?',
                    [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], $c['stok_min'], $id]);
            }
            Db::audit($userId, 'ubah', 'barang', $id, $c['kode']);
            return $id;
        }
        try {
            $id = Db::insert('INSERT INTO barang (kode, nama, kategori, satuan, harga_beli, harga_jual, harga_pegawai, stok_min, aktif, created_at) VALUES (?,?,?,?,?,?,?,?,1,?)',
                [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], ($c['harga_pegawai'] ?? null), $c['stok_min'], date('Y-m-d H:i:s')]);
        } catch (PDOException $e) { // kolom harga_pegawai belum dimigrasi
            $id = Db::insert('INSERT INTO barang (kode, nama, kategori, satuan, harga_beli, harga_jual, stok_min, aktif, created_at) VALUES (?,?,?,?,?,?,?,1,?)',
                [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], $c['stok_min'], date('Y-m-d H:i:s')]);
        }
        Db::audit($userId, 'tambah', 'barang', $id, $c['kode']);
        return $id;
    }

    /** Daftarkan barang ke daftar stok Market (stok 0) agar tampil di Stok Market/kasir dan bisa menerima transfer. */
    public static function daftarkanMarket(int $barangId, ?int $userId): bool
    {
        if (!self::find($barangId)) { throw new InvalidArgumentException('Barang tidak ditemukan.'); }
        if (Db::val('SELECT 1 FROM stok WHERE barang_id = ? AND unit = ?', [$barangId, Pos::TOKO])) { return false; }
        Db::exec('INSERT INTO stok (barang_id, unit, qty) VALUES (?,?,0)', [$barangId, Pos::TOKO]);
        Db::audit($userId, 'daftar_market', 'stok', $barangId);
        return true;
    }

    public static function setAktif(int $id, bool $aktif, ?int $userId): void
    {
        Db::exec('UPDATE barang SET aktif = ? WHERE id = ?', [$aktif ? 1 : 0, $id]);
        Db::audit($userId, $aktif ? 'aktifkan' : 'nonaktifkan', 'barang', $id);
    }

    public static function stok(int $barangId, string $unit): int
    {
        return (int) Db::val('SELECT qty FROM stok WHERE barang_id = ? AND unit = ?', [$barangId, $unit]);
    }

    /**
     * Daftar barang + stok untuk satu unit.
     * @return array<int,array>
     */
    public static function daftar(string $unit, string $q = '', bool $rendah = false, bool $hanyaAktif = false, bool $hanyaAda = false): array
    {
        $sql = 'SELECT b.*, COALESCE(s.qty, 0) AS qty FROM barang b ' . ($hanyaAda ? 'INNER JOIN' : 'LEFT JOIN') . ' stok s ON s.barang_id = b.id AND s.unit = ? WHERE 1=1';
        $p = [$unit];
        if ($q !== '') { $sql .= ' AND (b.nama LIKE ? OR b.kode LIKE ? OR b.kategori LIKE ?)'; $p = array_merge($p, ["%$q%", "%$q%", "%$q%"]); }
        if ($hanyaAktif) { $sql .= ' AND b.aktif = 1'; }
        if ($rendah) { $sql .= ' AND b.aktif = 1 AND COALESCE(s.qty, 0) <= b.stok_min'; }
        return Db::all($sql . ' ORDER BY b.kategori, b.nama', $p);
    }

    /** Barang yang terbaca kasir: hanya yang tercatat di stok Market. */
    public static function daftarMarket(string $q = ''): array
    {
        return self::daftar(Pos::TOKO, $q, false, true, true);
    }

    /** Pindahkan stok antar lokasi (gudang <-> market). */
    public static function transfer(int $barangId, string $dari, string $ke, int $qty, string $catatan, ?int $userId): void
    {
        if (!isset(Pos::LOKASI[$dari]) || !isset(Pos::LOKASI[$ke]) || $dari === $ke) { throw new InvalidArgumentException('Lokasi asal dan tujuan harus berbeda.'); }
        if ($qty <= 0) { throw new InvalidArgumentException('Jumlah transfer harus lebih dari 0.'); }
        if (!self::find($barangId)) { throw new InvalidArgumentException('Barang tidak ditemukan.'); }
        if ($ke === Pos::TOKO && !Db::val('SELECT 1 FROM stok WHERE barang_id = ? AND unit = ?', [$barangId, Pos::TOKO])) {
            throw new InvalidArgumentException('Barang belum terdaftar di stok Market. Tambahkan dulu item ini lewat Stok Market (barang masuk), baru bisa ditransfer dari Gudang.');
        }
        Db::transaction(function () use ($barangId, $dari, $ke, $qty, $catatan, $userId) {
            $cat = trim($catatan) !== '' ? $catatan : (Pos::LOKASI[$dari] . ' ke ' . Pos::LOKASI[$ke]);
            self::ubah($barangId, $dari, -$qty, 'transfer_keluar', ['catatan' => $cat, 'user_id' => $userId]);
            self::ubah($barangId, $ke, $qty, 'transfer_masuk', ['catatan' => $cat, 'user_id' => $userId]);
            Db::audit($userId, 'transfer', 'stok', $barangId, "$dari -> $ke $qty");
        });
    }

    /** Pindahkan sisa stok lama unit Akhwat ke Gudang. Mengembalikan jumlah barang yang dipindah. */
    public static function gabungAkhwat(?int $userId): int
    {
        return Db::transaction(function () use ($userId) {
            $n = 0;
            foreach (Db::all('SELECT barang_id, qty FROM stok WHERE unit = ? AND qty > 0', ['akhwat']) as $r) {
                self::ubah((int) $r['barang_id'], Pos::GUDANG, (int) $r['qty'], 'transfer_masuk', ['catatan' => 'Dari stok lama unit Akhwat', 'user_id' => $userId]);
                self::ubah((int) $r['barang_id'], 'akhwat', -(int) $r['qty'], 'transfer_keluar', ['catatan' => 'Dipindah ke Gudang', 'user_id' => $userId]);
                $n++;
            }
            Db::audit($userId, 'gabung', 'stok', null, "akhwat -> gudang $n barang");
            return $n;
        });
    }

    public static function jumlahRendah(string $unit): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM barang b ' . ($unit === Pos::TOKO ? 'INNER JOIN' : 'LEFT JOIN') . ' stok s ON s.barang_id = b.id AND s.unit = ? WHERE b.aktif = 1 AND COALESCE(s.qty,0) <= b.stok_min', [$unit]);
    }

    /** Nilai persediaan (harga beli) per unit. */
    public static function nilaiPersediaan(string $unit): int
    {
        return (int) Db::val('SELECT COALESCE(SUM(s.qty * b.harga_beli),0) FROM stok s JOIN barang b ON b.id = s.barang_id WHERE s.unit = ? AND s.qty > 0', [$unit]);
    }

    /** Dipanggil di dalam transaksi. Menolak stok negatif. */
    public static function ubah(int $barangId, string $unit, int $delta, string $tipe, array $x = []): int
    {
        $row = Db::one('SELECT qty FROM stok WHERE barang_id = ? AND unit = ?' . Db::forUpdate(), [$barangId, $unit]);
        $lama = $row ? (int) $row['qty'] : 0;
        $baru = $lama + $delta;
        if ($baru < 0) {
            $b = self::find($barangId);
            throw new InvalidArgumentException('Stok ' . ($b['nama'] ?? 'barang') . ' tidak cukup (tersisa ' . $lama . ').');
        }
        if ($row) {
            Db::exec('UPDATE stok SET qty = ? WHERE barang_id = ? AND unit = ?', [$baru, $barangId, $unit]);
        } else {
            Db::exec('INSERT INTO stok (barang_id, unit, qty) VALUES (?,?,?)', [$barangId, $unit, $baru]);
        }
        Db::exec('INSERT INTO stok_mutasi (barang_id, unit, tipe, qty, stok_setelah, harga_beli, pemasok, ref_id, catatan, user_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$barangId, $unit, $tipe, $delta, $baru, $x['harga_beli'] ?? null, $x['pemasok'] ?? '', $x['ref_id'] ?? null, mb_substr((string) ($x['catatan'] ?? ''), 0, 255), $x['user_id'] ?? null, date('Y-m-d H:i:s')]);
        return $baru;
    }

    public static function masuk(int $barangId, string $unit, int $qty, int $hargaBeli, string $pemasok, string $catatan, ?int $userId, ?string $scope): int
    {
        self::cekUnit($unit, $scope);
        if ($qty < 1 || $qty > 1000000) { throw new InvalidArgumentException('Jumlah masuk harus 1 atau lebih.'); }
        if (!self::find($barangId)) { throw new InvalidArgumentException('Barang tidak ditemukan.'); }
        return Db::transaction(function () use ($barangId, $unit, $qty, $hargaBeli, $pemasok, $catatan, $userId) {
            $baru = self::ubah($barangId, $unit, $qty, 'masuk', ['harga_beli' => $hargaBeli, 'pemasok' => mb_substr($pemasok, 0, 100), 'catatan' => $catatan, 'user_id' => $userId]);
            if ($hargaBeli > 0) { Db::exec('UPDATE barang SET harga_beli = ? WHERE id = ?', [$hargaBeli, $barangId]); }
            Db::audit($userId, 'masuk', 'stok', $barangId, "$unit +$qty");
            return $baru;
        });
    }

    public static function keluar(int $barangId, string $unit, int $qty, string $alasan, ?int $userId, ?string $scope): int
    {
        self::cekUnit($unit, $scope);
        if ($qty < 1) { throw new InvalidArgumentException('Jumlah keluar harus 1 atau lebih.'); }
        if (trim($alasan) === '') { throw new InvalidArgumentException('Alasan wajib diisi (rusak, kadaluarsa, hilang, dll).'); }
        return Db::transaction(function () use ($barangId, $unit, $qty, $alasan, $userId) {
            $baru = self::ubah($barangId, $unit, -$qty, 'keluar', ['catatan' => $alasan, 'user_id' => $userId]);
            Db::audit($userId, 'keluar', 'stok', $barangId, "$unit -$qty $alasan");
            return $baru;
        });
    }

    /** Opname: samakan stok sistem dengan hasil hitung fisik. */
    public static function opname(int $barangId, string $unit, int $fisik, string $catatan, ?int $userId, ?string $scope): int
    {
        self::cekUnit($unit, $scope);
        if ($fisik < 0) { throw new InvalidArgumentException('Stok fisik tidak boleh negatif.'); }
        return Db::transaction(function () use ($barangId, $unit, $fisik, $catatan, $userId) {
            $selisih = $fisik - self::stok($barangId, $unit);
            $baru = self::ubah($barangId, $unit, $selisih, 'opname', ['catatan' => $catatan !== '' ? $catatan : 'opname', 'user_id' => $userId]);
            Db::audit($userId, 'opname', 'stok', $barangId, "$unit selisih $selisih");
            return $baru;
        });
    }

    public static function riwayat(int $barangId, ?string $scope, int $limit = 40): array
    {
        $sql = 'SELECT m.*, u.nama AS petugas FROM stok_mutasi m LEFT JOIN users u ON u.id = m.user_id WHERE m.barang_id = ?';
        $p = [$barangId];
        if ($scope !== null) { $sql .= ' AND m.unit = ?'; $p[] = $scope; }
        return Db::all($sql . ' ORDER BY m.id DESC LIMIT ' . (int) $limit, $p);
    }

    public static function kategori(): array
    {
        return array_column(Db::all("SELECT DISTINCT kategori FROM barang WHERE kategori <> '' ORDER BY kategori"), 'kategori');
    }
}
