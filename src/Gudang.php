<?php
declare(strict_types=1);

/** Barang dan stok gudang. Stok dipisah per unit (ikhwan/akhwat); semua perubahan lewat mutasi. */
final class Gudang
{
    public const TIPE = ['masuk' => 'Barang masuk', 'keluar' => 'Keluar (rusak/hilang)', 'opname' => 'Opname', 'jual' => 'Terjual', 'batal_jual' => 'Batal jual'];

    private static function cekUnit(string $unit, ?string $scope): void
    {
        if (!isset(Domain::UNIT[$unit])) {
            throw new InvalidArgumentException('Unit tidak valid.');
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
        return [[
            'kode' => $kode, 'nama' => $nama, 'kategori' => mb_substr(trim((string) ($in['kategori'] ?? '')), 0, 40),
            'satuan' => mb_substr(trim((string) ($in['satuan'] ?? '')) ?: 'pcs', 0, 20),
            'harga_beli' => $beli, 'harga_jual' => $jual, 'stok_min' => $min,
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
            Db::exec('UPDATE barang SET kode=?, nama=?, kategori=?, satuan=?, harga_beli=?, harga_jual=?, stok_min=? WHERE id=?',
                [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], $c['stok_min'], $id]);
            Db::audit($userId, 'ubah', 'barang', $id, $c['kode']);
            return $id;
        }
        $id = Db::insert('INSERT INTO barang (kode, nama, kategori, satuan, harga_beli, harga_jual, stok_min, aktif, created_at) VALUES (?,?,?,?,?,?,?,1,?)',
            [$c['kode'], $c['nama'], $c['kategori'], $c['satuan'], $c['harga_beli'], $c['harga_jual'], $c['stok_min'], date('Y-m-d H:i:s')]);
        Db::audit($userId, 'tambah', 'barang', $id, $c['kode']);
        return $id;
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
    public static function daftar(string $unit, string $q = '', bool $rendah = false, bool $hanyaAktif = false): array
    {
        $sql = 'SELECT b.*, COALESCE(s.qty, 0) AS qty FROM barang b LEFT JOIN stok s ON s.barang_id = b.id AND s.unit = ? WHERE 1=1';
        $p = [$unit];
        if ($q !== '') { $sql .= ' AND (b.nama LIKE ? OR b.kode LIKE ? OR b.kategori LIKE ?)'; $p = array_merge($p, ["%$q%", "%$q%", "%$q%"]); }
        if ($hanyaAktif) { $sql .= ' AND b.aktif = 1'; }
        if ($rendah) { $sql .= ' AND b.aktif = 1 AND COALESCE(s.qty, 0) <= b.stok_min'; }
        return Db::all($sql . ' ORDER BY b.kategori, b.nama', $p);
    }

    /** Satu toko: pindahkan sisa stok unit Akhwat ke stok LH Mart (unit utama). Mengembalikan jumlah barang yang dipindah. */
    public static function gabungAkhwat(?int $userId): int
    {
        return Db::transaction(function () use ($userId) {
            $n = 0;
            foreach (Db::all('SELECT barang_id, qty FROM stok WHERE unit = ? AND qty > 0', ['akhwat']) as $r) {
                self::ubah((int) $r['barang_id'], Pos::TOKO, (int) $r['qty'], 'masuk', ['catatan' => 'Gabung stok unit Akhwat', 'user_id' => $userId]);
                self::ubah((int) $r['barang_id'], 'akhwat', -(int) $r['qty'], 'keluar', ['catatan' => 'Dipindah ke LH Mart', 'user_id' => $userId]);
                $n++;
            }
            Db::audit($userId, 'gabung', 'stok', null, "akhwat -> " . Pos::TOKO . " $n barang");
            return $n;
        });
    }

    public static function jumlahRendah(string $unit): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM barang b LEFT JOIN stok s ON s.barang_id = b.id AND s.unit = ? WHERE b.aktif = 1 AND COALESCE(s.qty,0) <= b.stok_min', [$unit]);
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
