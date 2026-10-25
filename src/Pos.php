<?php
declare(strict_types=1);

/** Kasir (point of sales) dan dompet (uang saku) santri. Penjualan otomatis memotong stok gudang. */
final class Pos
{
    /** LH Mart satu toko untuk umum, pegawai, dan santri. Kunci stok internal (kompatibel data lama). */
    public const TOKO = 'ikhwan';
    /** Lokasi stok: market (kasir) dan gudang (penyimpanan). */
    public const GUDANG = 'gudang';
    public const LOKASI = ['gudang' => 'Gudang', 'ikhwan' => 'Market'];

    public static function saldo(int $santriId): int
    {
        return (int) Db::val('SELECT saldo FROM dompet WHERE santri_id = ?', [$santriId]);
    }

    private static function santriUnit(int $santriId, ?string $scope): array
    {
        $s = Santri::find($santriId);
        if (!$s) { throw new InvalidArgumentException('Santri tidak ditemukan.'); }
        if ($scope !== null && $scope !== $s['unit']) { throw new InvalidArgumentException('Santri di luar cakupan unit akun Anda.'); }
        return $s;
    }

    /** Dalam transaksi: ubah saldo dan catat mutasi. */
    private static function ubahSaldo(int $santriId, int $delta, string $tipe, ?int $ref, string $catatan, ?int $userId): int
    {
        $row = Db::one('SELECT saldo FROM dompet WHERE santri_id = ?' . Db::forUpdate(), [$santriId]);
        $lama = $row ? (int) $row['saldo'] : 0;
        $baru = $lama + $delta;
        if ($baru < 0) { throw new InvalidArgumentException('Saldo dompet tidak cukup (saldo ' . rp($lama) . ').'); }
        if ($row) { Db::exec('UPDATE dompet SET saldo = ? WHERE santri_id = ?', [$baru, $santriId]); }
        else { Db::exec('INSERT INTO dompet (santri_id, saldo) VALUES (?,?)', [$santriId, $baru]); }
        Db::exec('INSERT INTO dompet_mutasi (santri_id, tipe, jumlah, saldo_setelah, ref_id, catatan, user_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$santriId, $tipe, $delta, $baru, $ref, mb_substr($catatan, 0, 255), $userId, date('Y-m-d H:i:s')]);
        return $baru;
    }

    public static function topup(int $santriId, int $jumlah, string $catatan, ?int $userId, ?string $scope): int
    {
        self::santriUnit($santriId, $scope);
        if ($jumlah < 1000 || $jumlah > 50000000) { throw new InvalidArgumentException('Nominal top up minimal Rp 1.000 dan maksimal Rp 50.000.000.'); }
        return Db::transaction(function () use ($santriId, $jumlah, $catatan, $userId) {
            $baru = self::ubahSaldo($santriId, $jumlah, 'topup', null, $catatan !== '' ? $catatan : 'Top up', $userId);
            Db::audit($userId, 'topup', 'dompet', $santriId, '+' . $jumlah);
            return $baru;
        });
    }

    /** Koreksi saldo (+/-) wajib beralasan. */
    public static function koreksi(int $santriId, int $delta, string $alasan, ?int $userId, ?string $scope): int
    {
        self::santriUnit($santriId, $scope);
        if ($delta === 0) { throw new InvalidArgumentException('Nominal koreksi tidak boleh 0.'); }
        if (trim($alasan) === '') { throw new InvalidArgumentException('Alasan koreksi wajib diisi.'); }
        return Db::transaction(function () use ($santriId, $delta, $alasan, $userId) {
            $baru = self::ubahSaldo($santriId, $delta, 'koreksi', null, $alasan, $userId);
            Db::audit($userId, 'koreksi', 'dompet', $santriId, ($delta > 0 ? '+' : '') . $delta . ' ' . $alasan);
            return $baru;
        });
    }

    public static function riwayatDompet(int $santriId, int $limit = 30): array
    {
        return Db::all('SELECT m.*, u.nama AS petugas FROM dompet_mutasi m LEFT JOIN users u ON u.id = m.user_id WHERE m.santri_id = ? ORDER BY m.id DESC LIMIT ' . (int) $limit, [$santriId]);
    }

    /** Daftar santri bersaldo (untuk halaman dompet). */
    public static function daftarSaldo(?string $scope): array
    {
        $sql = 'SELECT s.id, s.nis, s.nama, s.unit, s.jenjang, s.kelas, d.saldo FROM dompet d JOIN santri s ON s.id = d.santri_id WHERE d.saldo > 0';
        $p = [];
        if ($scope !== null) { $sql .= ' AND s.unit = ?'; $p[] = $scope; }
        return Db::all($sql . ' ORDER BY d.saldo DESC, s.nama LIMIT 200', $p);
    }

    public static function totalSaldo(?string $scope): int
    {
        $sql = 'SELECT COALESCE(SUM(d.saldo),0) FROM dompet d JOIN santri s ON s.id = d.santri_id WHERE 1=1';
        $p = [];
        if ($scope !== null) { $sql .= ' AND s.unit = ?'; $p[] = $scope; }
        return (int) Db::val($sql, $p);
    }

    /**
     * Catat penjualan.
     * @param array<int,int> $items barang_id => qty
     */
    public static function jual(string $unit, ?int $santriId, array $items, string $metode, int $dibayar, ?int $userId, ?string $scope, int $diskon = 0, ?string $pelanggan = null): int
    {
        if (!isset(Domain::UNIT[$unit])) { throw new InvalidArgumentException('Unit tidak valid.'); }
        if ($scope !== null && $scope !== $unit) { throw new InvalidArgumentException('Unit di luar cakupan akun Anda.'); }
        if (!in_array($metode, ['tunai', 'dompet', 'qris'], true)) { throw new InvalidArgumentException('Metode pembayaran tidak valid.'); }
        if ($metode === 'qris' && !Qris::aktif()) { throw new InvalidArgumentException('Pembayaran QRIS belum diaktifkan di Pengaturan.'); }
        $items = array_filter($items, static fn($q) => (int) $q > 0);
        if (!$items) { throw new InvalidArgumentException('Belum ada barang yang dipilih.'); }
        if ($metode === 'dompet') {
            if (!$santriId) { throw new InvalidArgumentException('Pilih santri untuk pembayaran dengan dompet.'); }
        }
        if ($santriId) {
            $s = self::santriUnit($santriId, $scope);
        }
        if ($diskon < 0) { throw new InvalidArgumentException('Diskon tidak valid.'); }
        $pelanggan = $santriId ? null : mb_substr(trim((string) $pelanggan), 0, 80);
        $hargaPegawai = !$santriId && $pelanggan !== null && stripos($pelanggan, 'Pegawai') === 0;
        return Db::transaction(function () use ($unit, $santriId, $items, $metode, $dibayar, $userId, $diskon, $pelanggan, $hargaPegawai) {
            $baris = []; $total = 0;
            foreach ($items as $bid => $qty) {
                $b = Gudang::find((int) $bid);
                if (!$b || !(int) $b['aktif']) { throw new InvalidArgumentException('Ada barang yang tidak tersedia.'); }
                $qty = (int) $qty;
                $b['harga_pakai'] = $hargaPegawai && ($b['harga_pegawai'] ?? null) !== null ? (int) $b['harga_pegawai'] : (int) $b['harga_jual'];
                $baris[] = [$b, $qty];
                $total += $qty * $b['harga_pakai'];
            }
            if ($diskon > $total) { throw new InvalidArgumentException('Diskon tidak boleh melebihi subtotal ' . rp($total) . '.'); }
            $total -= $diskon;
            if ($metode === 'tunai') {
                if ($dibayar < $total) { throw new InvalidArgumentException('Uang diterima (' . rp($dibayar) . ') kurang dari total ' . rp($total) . '.'); }
            } else {
                $dibayar = $total;
            }
            $id = Db::insert('INSERT INTO penjualan (no_struk, tanggal, unit, santri_id, total, metode, dibayar, dibatalkan, user_id, created_at) VALUES (?,?,?,?,?,?,?,0,?,?)',
                ['', date('Y-m-d'), $unit, $santriId, $total, $metode, $dibayar, $userId, date('Y-m-d H:i:s')]);
            $no = 'PJ-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            Db::exec('UPDATE penjualan SET no_struk = ? WHERE id = ?', [$no, $id]);
            if ($diskon > 0) { Db::exec('UPDATE penjualan SET diskon = ? WHERE id = ?', [$diskon, $id]); }
            if ($pelanggan !== null && $pelanggan !== '') {
                try { Db::exec('UPDATE penjualan SET pelanggan = ? WHERE id = ?', [$pelanggan, $id]); } catch (PDOException $e) { /* kolom belum dimigrasi */ }
            }
            foreach ($baris as [$b, $qty]) {
                Gudang::ubah((int) $b['id'], $unit, -$qty, 'jual', ['ref_id' => $id, 'catatan' => $no, 'user_id' => $userId]);
                Db::exec('INSERT INTO penjualan_item (penjualan_id, barang_id, nama, qty, harga, harga_beli, subtotal) VALUES (?,?,?,?,?,?,?)',
                    [$id, $b['id'], $b['nama'], $qty, $b['harga_pakai'], $b['harga_beli'], $qty * $b['harga_pakai']]);
            }
            if ($metode === 'dompet') {
                self::ubahSaldo((int) $santriId, -$total, 'belanja', $id, $no, $userId);
            }
            Db::audit($userId, 'jual', 'penjualan', $id, "$no $unit $metode $total" . ($diskon > 0 ? " diskon $diskon" : ''));
            return $id;
        });
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT p.*, s.nama AS santri_nama, s.nis, u.nama AS kasir FROM penjualan p LEFT JOIN santri s ON s.id = p.santri_id LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$id]);
    }

    public static function items(int $id): array
    {
        return Db::all('SELECT * FROM penjualan_item WHERE penjualan_id = ? ORDER BY id', [$id]);
    }

    public static function batalkan(int $id, string $alasan, ?int $userId, ?string $scope): void
    {
        if (trim($alasan) === '') { throw new InvalidArgumentException('Alasan pembatalan wajib diisi.'); }
        Db::transaction(function () use ($id, $alasan, $userId, $scope) {
            $p = self::find($id);
            if (!$p) { throw new InvalidArgumentException('Penjualan tidak ditemukan.'); }
            if ($scope !== null && $scope !== $p['unit']) { throw new InvalidArgumentException('Data di luar cakupan unit akun Anda.'); }
            if ((int) $p['dibatalkan']) { throw new InvalidArgumentException('Penjualan sudah dibatalkan.'); }
            Db::exec('UPDATE penjualan SET dibatalkan = 1, alasan_batal = ? WHERE id = ?', [mb_substr($alasan, 0, 255), $id]);
            foreach (self::items($id) as $it) {
                Gudang::ubah((int) $it['barang_id'], $p['unit'], (int) $it['qty'], 'batal_jual', ['ref_id' => $id, 'catatan' => 'batal ' . $p['no_struk'], 'user_id' => $userId]);
            }
            if ($p['metode'] === 'dompet' && $p['santri_id']) {
                self::ubahSaldo((int) $p['santri_id'], (int) $p['total'], 'batal', $id, 'batal ' . $p['no_struk'], $userId);
            }
            Db::audit($userId, 'batal', 'penjualan', $id, $alasan);
        });
    }

    /** @param array{dari?:string,sampai?:string,unit?:string,metode?:string} $f */
    public static function daftar(array $f, ?string $scope, int $limit = 200): array
    {
        $sql = 'SELECT p.*, s.nama AS santri_nama, u.nama AS kasir FROM penjualan p LEFT JOIN santri s ON s.id = p.santri_id LEFT JOIN users u ON u.id = p.user_id WHERE p.tanggal >= ? AND p.tanggal <= ?';
        $p = [$f['dari'] ?? date('Y-m-01'), $f['sampai'] ?? date('Y-m-d')];
        $unit = $scope ?? ($f['unit'] ?? '');
        if ($unit !== '') { $sql .= ' AND p.unit = ?'; $p[] = $unit; }
        if (!empty($f['metode'])) { $sql .= ' AND p.metode = ?'; $p[] = $f['metode']; }
        return Db::all($sql . ' ORDER BY p.id DESC LIMIT ' . (int) $limit, $p);
    }

    /** Ringkasan: omzet, laba kotor, jumlah transaksi (tanpa yang dibatalkan). */
    public static function ringkas(string $dari, string $sampai, ?string $unit): array
    {
        $w = 'p.dibatalkan = 0 AND p.tanggal >= ? AND p.tanggal <= ?';
        $p = [$dari, $sampai];
        if ($unit) { $w .= ' AND p.unit = ?'; $p[] = $unit; }
        $r = Db::one("SELECT COUNT(*) AS n, COALESCE(SUM(p.total),0) AS omzet FROM penjualan p WHERE $w", $p);
        $laba = (int) Db::val("SELECT COALESCE(SUM(i.subtotal - i.qty * i.harga_beli),0) FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE $w", $p);
        try { $laba -= (int) Db::val("SELECT COALESCE(SUM(p.diskon),0) FROM penjualan p WHERE $w", $p); } catch (Throwable $e) { /* kolom diskon belum dimigrasi */ }
        return ['n' => (int) $r['n'], 'omzet' => (int) $r['omzet'], 'laba' => $laba];
    }

    public static function terlaris(string $dari, string $sampai, ?string $unit, int $limit = 8): array
    {
        $w = 'p.dibatalkan = 0 AND p.tanggal >= ? AND p.tanggal <= ?';
        $p = [$dari, $sampai];
        if ($unit) { $w .= ' AND p.unit = ?'; $p[] = $unit; }
        return Db::all("SELECT i.nama, SUM(i.qty) AS qty, SUM(i.subtotal) AS omzet FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE $w GROUP BY i.barang_id, i.nama ORDER BY qty DESC LIMIT " . (int) $limit, $p);
    }

    public static function perHari(int $hari, ?string $unit): array
    {
        $out = [];
        for ($i = $hari - 1; $i >= 0; $i--) { $out[date('Y-m-d', strtotime("-$i days"))] = 0; }
        $sql = 'SELECT tanggal, SUM(total) AS t FROM penjualan WHERE dibatalkan = 0 AND tanggal >= ?' . ($unit ? ' AND unit = ?' : '') . ' GROUP BY tanggal';
        foreach (Db::all($sql, $unit ? [array_key_first($out), $unit] : [array_key_first($out)]) as $r) { $out[$r['tanggal']] = (int) $r['t']; }
        return $out;
    }
}
