<?php
declare(strict_types=1);

/** Kueri laporan LH Mart (penjualan, produk, kategori, kasir, stok, mutasi). Hanya membaca data. */
final class LaporanPos
{
    private const SAH = 'p.dibatalkan = 0 AND p.tanggal >= ? AND p.tanggal <= ?';

    private static function jenisSql(): string
    {
        return "CASE WHEN p.santri_id IS NOT NULL THEN 'santri' WHEN p.pelanggan LIKE 'Pegawai%' THEN 'pegawai' ELSE 'umum' END";
    }

    public static function ringkasan(string $dari, string $sampai): array
    {
        $p = [$dari, $sampai];
        $r = Db::one('SELECT COUNT(*) AS n, COALESCE(SUM(p.total),0) AS omzet FROM penjualan p WHERE ' . self::SAH, $p);
        $qty = (int) Db::val('SELECT COALESCE(SUM(i.qty),0) FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE ' . self::SAH, $p);
        $batal = Db::one('SELECT COUNT(*) AS n, COALESCE(SUM(p.total),0) AS nilai FROM penjualan p WHERE p.dibatalkan = 1 AND p.tanggal >= ? AND p.tanggal <= ?', $p);
        $diskon = 0;
        try { $diskon = (int) Db::val('SELECT COALESCE(SUM(p.diskon),0) FROM penjualan p WHERE ' . self::SAH, $p); } catch (Throwable $e) { /* kolom belum ada */ }
        $k = Pos::ringkas($dari, $sampai, null);
        $n = (int) $r['n'];
        return ['n' => $n, 'omzet' => (int) $r['omzet'], 'laba' => $k['laba'], 'qty' => $qty, 'diskon' => $diskon, 'rata' => $n > 0 ? intdiv((int) $r['omzet'], $n) : 0, 'batal_n' => (int) $batal['n'], 'batal_nilai' => (int) $batal['nilai']];
    }

    /** @return array<string,array{n:int,omzet:int}> tanggal => data, termasuk hari tanpa penjualan */
    public static function perHari(string $dari, string $sampai): array
    {
        $out = [];
        for ($t = strtotime($dari), $akhir = strtotime($sampai), $i = 0; $t <= $akhir && $i < 400; $t += 86400, $i++) { $out[date('Y-m-d', $t)] = ['n' => 0, 'omzet' => 0]; }
        foreach (Db::all('SELECT p.tanggal, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE ' . self::SAH . ' GROUP BY p.tanggal', [$dari, $sampai]) as $r) {
            if (isset($out[$r['tanggal']])) { $out[$r['tanggal']] = ['n' => (int) $r['n'], 'omzet' => (int) $r['omzet']]; }
        }
        return $out;
    }

    public static function perMetode(string $dari, string $sampai): array
    {
        return Db::all('SELECT p.metode, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE ' . self::SAH . ' GROUP BY p.metode ORDER BY omzet DESC', [$dari, $sampai]);
    }

    public static function perJenis(string $dari, string $sampai): array
    {
        $j = self::jenisSql();
        try {
            return Db::all("SELECT $j AS jenis, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE " . self::SAH . " GROUP BY $j ORDER BY omzet DESC", [$dari, $sampai]);
        } catch (Throwable $e) {
            return Db::all("SELECT CASE WHEN p.santri_id IS NOT NULL THEN 'santri' ELSE 'umum' END AS jenis, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p WHERE " . self::SAH . ' GROUP BY 1', [$dari, $sampai]);
        }
    }

    public static function transaksi(string $dari, string $sampai, string $metode = '', string $jenis = '', int $kasir = 0, string $status = 'sah'): array
    {
        $w = 'p.tanggal >= ? AND p.tanggal <= ?'; $a = [$dari, $sampai];
        if ($status === 'sah') { $w .= ' AND p.dibatalkan = 0'; } elseif ($status === 'batal') { $w .= ' AND p.dibatalkan = 1'; }
        if ($metode !== '') { $w .= ' AND p.metode = ?'; $a[] = $metode; }
        if ($kasir > 0) { $w .= ' AND p.user_id = ?'; $a[] = $kasir; }
        if ($jenis === 'santri') { $w .= ' AND p.santri_id IS NOT NULL'; }
        elseif ($jenis === 'pegawai') { $w .= " AND p.santri_id IS NULL AND p.pelanggan LIKE 'Pegawai%'"; }
        elseif ($jenis === 'umum') { $w .= " AND p.santri_id IS NULL AND (p.pelanggan IS NULL OR p.pelanggan NOT LIKE 'Pegawai%')"; }
        return Db::all("SELECT p.id, p.no_struk, p.tanggal, p.created_at, p.metode, p.total, p.dibatalkan, p.pelanggan, s.nama AS santri_nama, u.nama AS kasir FROM penjualan p LEFT JOIN santri s ON s.id = p.santri_id LEFT JOIN users u ON u.id = p.user_id WHERE $w ORDER BY p.id DESC LIMIT 2000", $a);
    }

    public static function produk(string $dari, string $sampai, string $urut = 'qty'): array
    {
        $o = ['qty' => 'qty', 'omzet' => 'omzet', 'laba' => 'laba'][$urut] ?? 'qty';
        return Db::all('SELECT i.barang_id, i.nama, SUM(i.qty) AS qty, SUM(i.subtotal) AS omzet, SUM(i.subtotal - i.qty * i.harga_beli) AS laba FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE ' . self::SAH . " GROUP BY i.barang_id, i.nama ORDER BY $o DESC, i.nama LIMIT 500", [$dari, $sampai]);
    }

    /** Barang di Market yang tidak terjual sama sekali pada rentang. */
    public static function tidakLaku(string $dari, string $sampai): array
    {
        return Db::all('SELECT b.kode, b.nama, b.kategori, s.qty FROM barang b JOIN stok s ON s.barang_id = b.id AND s.unit = ? WHERE b.aktif = 1 AND b.id NOT IN (SELECT i.barang_id FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE ' . self::SAH . ') ORDER BY s.qty DESC, b.nama LIMIT 300', array_merge([Pos::TOKO], [$dari, $sampai]));
    }

    public static function kategori(string $dari, string $sampai): array
    {
        return Db::all("SELECT COALESCE(NULLIF(b.kategori, ''), 'Lainnya') AS kategori, SUM(i.qty) AS qty, SUM(i.subtotal) AS omzet, SUM(i.subtotal - i.qty * i.harga_beli) AS laba FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id JOIN barang b ON b.id = i.barang_id WHERE " . self::SAH . ' GROUP BY 1 ORDER BY omzet DESC', [$dari, $sampai]);
    }

    public static function perKasir(string $dari, string $sampai): array
    {
        return Db::all("SELECT COALESCE(u.nama, '-') AS kasir, COUNT(*) AS n, SUM(p.total) AS omzet FROM penjualan p LEFT JOIN users u ON u.id = p.user_id WHERE " . self::SAH . ' GROUP BY u.id, u.nama ORDER BY omzet DESC', [$dari, $sampai]);
    }

    /** Posisi stok sekarang per lokasi dengan nilai modal & nilai jual. */
    public static function stok(string $unit, string $filter = ''): array
    {
        $rows = Gudang::daftar($unit, '', false, true, $unit === Pos::TOKO);
        $out = [];
        foreach ($rows as $b) {
            $q = (int) $b['qty'];
            if ($filter === 'habis' && $q > 0) { continue; }
            if ($filter === 'menipis' && !($q > 0 && $q <= (int) $b['stok_min'])) { continue; }
            if ($filter === 'ada' && $q <= 0) { continue; }
            $out[] = ['kode' => $b['kode'], 'nama' => $b['nama'], 'kategori' => $b['kategori'], 'satuan' => $b['satuan'], 'qty' => $q, 'stok_min' => (int) $b['stok_min'],
                'modal' => $q * (int) $b['harga_beli'], 'jual' => $q * (int) $b['harga_jual'], 'harga_beli' => (int) $b['harga_beli'], 'harga_jual' => (int) $b['harga_jual']];
        }
        return $out;
    }

    public static function mutasi(string $dari, string $sampai, string $tipe = ''): array
    {
        $w = "m.tipe IN ('transfer_masuk','transfer_keluar','opname','keluar') AND m.created_at >= ? AND m.created_at <= ?"; $a = [$dari . ' 00:00:00', $sampai . ' 23:59:59'];
        if ($tipe !== '') { $w .= ' AND m.tipe = ?'; $a[] = $tipe; }
        return Db::all("SELECT m.created_at, m.unit, m.tipe, m.qty, m.stok_setelah, m.catatan, b.kode, b.nama, u.nama AS petugas FROM stok_mutasi m JOIN barang b ON b.id = m.barang_id LEFT JOIN users u ON u.id = m.user_id WHERE $w ORDER BY m.id DESC LIMIT 1000", $a);
    }
}
