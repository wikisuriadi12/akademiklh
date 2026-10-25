<?php
declare(strict_types=1);

/** Hutang pembelian (faktur tempo) dan konsinyasi (titipan; terutang setelah terjual) per nama supplier. */
final class Hutang
{
    /** Ringkasan per supplier: sisa hutang faktur, sisa konsinyasi, jatuh tempo terdekat. */
    public static function ringkasan(): array
    {
        $out = [];
        $ambil = static function (string $nama) use (&$out): void {
            if (!isset($out[$nama])) { $out[$nama] = ['pemasok' => $nama, 'faktur_sisa' => 0, 'faktur_n' => 0, 'tempo' => null, 'lewat' => 0, 'kons_terjual' => 0, 'kons_bayar' => 0, 'kons_sisa' => 0, 'kons_stok_nilai' => 0]; }
        };
        $hari = date('Y-m-d');
        foreach (Db::all("SELECT pemasok, total - dibayar AS sisa, jatuh_tempo FROM hutang WHERE status <> 'lunas'") as $r) {
            $ambil($r['pemasok']); $o = &$out[$r['pemasok']];
            $o['faktur_sisa'] += (int) $r['sisa']; $o['faktur_n']++;
            if ($r['jatuh_tempo'] !== null && $r['jatuh_tempo'] !== '') {
                if ($o['tempo'] === null || $r['jatuh_tempo'] < $o['tempo']) { $o['tempo'] = $r['jatuh_tempo']; }
                if ($r['jatuh_tempo'] < $hari) { $o['lewat'] += (int) $r['sisa']; }
            }
            unset($o);
        }
        foreach (self::konsinyasiSemua() as $nama => $k) {
            $ambil($nama); $out[$nama]['kons_terjual'] = $k['terjual']; $out[$nama]['kons_bayar'] = $k['dibayar']; $out[$nama]['kons_sisa'] = $k['sisa']; $out[$nama]['kons_stok_nilai'] = $k['stok_nilai'];
        }
        foreach ($out as &$o) { $o['total_sisa'] = $o['faktur_sisa'] + max(0, $o['kons_sisa']); }
        unset($o);
        uasort($out, static fn($a, $b) => $b['total_sisa'] <=> $a['total_sisa'] ?: strcmp($a['pemasok'], $b['pemasok']));
        return $out;
    }

    /** @return array<string,array{terjual:int,dibayar:int,sisa:int,stok_nilai:int}> */
    public static function konsinyasiSemua(): array
    {
        $out = [];
        $ensure = static function (string $n) use (&$out): void { if (!isset($out[$n])) { $out[$n] = ['terjual' => 0, 'dibayar' => 0, 'sisa' => 0, 'stok_nilai' => 0]; } };
        try {
            foreach (Db::all("SELECT i.konsinyasi AS pemasok, SUM(i.qty * i.harga_beli) AS nilai FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE i.konsinyasi IS NOT NULL AND i.konsinyasi <> '' AND p.dibatalkan = 0 GROUP BY i.konsinyasi") as $r) { $ensure($r['pemasok']); $out[$r['pemasok']]['terjual'] = (int) $r['nilai']; }
            foreach (Db::all("SELECT b.konsinyasi_pemasok AS pemasok, SUM(s.qty * b.harga_beli) AS nilai FROM barang b JOIN stok s ON s.barang_id = b.id WHERE b.konsinyasi_pemasok IS NOT NULL AND b.konsinyasi_pemasok <> '' GROUP BY b.konsinyasi_pemasok") as $r) { $ensure($r['pemasok']); $out[$r['pemasok']]['stok_nilai'] = (int) $r['nilai']; }
        } catch (Throwable $e) { /* kolom konsinyasi belum dimigrasi */ }
        foreach (Db::all("SELECT pemasok, SUM(jumlah) AS jml FROM hutang_bayar WHERE jenis = 'konsinyasi' GROUP BY pemasok") as $r) { $ensure($r['pemasok']); $out[$r['pemasok']]['dibayar'] = (int) $r['jml']; }
        foreach ($out as &$o) { $o['sisa'] = $o['terjual'] - $o['dibayar']; }
        unset($o);
        return $out;
    }

    public static function konsinyasi(string $pemasok): array
    {
        return self::konsinyasiSemua()[$pemasok] ?? ['terjual' => 0, 'dibayar' => 0, 'sisa' => 0, 'stok_nilai' => 0];
    }

    /** Rincian per barang titipan supplier: stok sekarang, harga setor, terjual (qty & nilai). */
    public static function barangKonsinyasi(string $pemasok): array
    {
        $rows = Db::all("SELECT b.id, b.kode, b.nama, b.satuan, b.harga_beli, b.harga_jual, COALESCE((SELECT SUM(s.qty) FROM stok s WHERE s.barang_id = b.id), 0) AS stok FROM barang b WHERE b.konsinyasi_pemasok = ? ORDER BY b.nama", [$pemasok]);
        foreach ($rows as &$r) {
            $t = Db::one("SELECT COALESCE(SUM(i.qty),0) AS qty, COALESCE(SUM(i.qty * i.harga_beli),0) AS nilai FROM penjualan_item i JOIN penjualan p ON p.id = i.penjualan_id WHERE i.barang_id = ? AND i.konsinyasi = ? AND p.dibatalkan = 0", [$r['id'], $pemasok]);
            $r['terjual_qty'] = (int) $t['qty']; $r['terjual_nilai'] = (int) $t['nilai'];
        }
        unset($r);
        return $rows;
    }

    public static function faktur(string $pemasok = '', string $status = 'belum'): array
    {
        $w = '1=1'; $a = [];
        if ($pemasok !== '') { $w .= ' AND pemasok = ?'; $a[] = $pemasok; }
        if ($status === 'belum') { $w .= " AND status <> 'lunas'"; } elseif ($status === 'lunas') { $w .= " AND status = 'lunas'"; }
        return Db::all("SELECT * FROM hutang WHERE $w ORDER BY CASE WHEN jatuh_tempo IS NULL THEN 1 ELSE 0 END, jatuh_tempo, id DESC LIMIT 500", $a);
    }

    public static function itemFaktur(int $hutangId): array
    {
        return Db::all("SELECT b.kode, b.nama, b.satuan, m.qty, m.harga_beli, m.unit FROM stok_mutasi m JOIN barang b ON b.id = m.barang_id WHERE m.tipe = 'masuk' AND m.ref_id = ? ORDER BY m.id", [$hutangId]);
    }

    public static function pembayaran(string $pemasok = ''): array
    {
        $w = $pemasok !== '' ? 'WHERE h.pemasok = ?' : ''; $a = $pemasok !== '' ? [$pemasok] : [];
        return Db::all("SELECT h.*, f.faktur, u.nama AS petugas FROM hutang_bayar h LEFT JOIN hutang f ON f.id = h.hutang_id LEFT JOIN users u ON u.id = h.user_id $w ORDER BY h.id DESC LIMIT 300", $a);
    }

    public static function daftarPemasok(): array
    {
        $n = array_column(Db::all("SELECT DISTINCT pemasok FROM hutang"), 'pemasok');
        foreach (Gudang::daftarPemasok() as $p) { $n[] = $p; }
        $n = array_values(array_unique($n)); sort($n, SORT_NATURAL | SORT_FLAG_CASE);
        return $n;
    }

    /** Catat pembayaran. Faktur: jumlah <= sisa faktur. Konsinyasi: jumlah <= sisa konsinyasi supplier. */
    public static function bayar(string $jenis, string $pemasok, ?int $hutangId, int $jumlah, string $tanggal, string $catatan, ?int $userId): int
    {
        if (!in_array($jenis, ['hutang', 'konsinyasi'], true)) { throw new InvalidArgumentException('Jenis pembayaran tidak valid.'); }
        if ($jumlah < 1) { throw new InvalidArgumentException('Jumlah bayar harus lebih dari 0.'); }
        if (!Domain::validDate($tanggal)) { throw new InvalidArgumentException('Tanggal bayar tidak valid.'); }
        return Db::transaction(function () use ($jenis, $pemasok, $hutangId, $jumlah, $tanggal, $catatan, $userId) {
            if ($jenis === 'hutang') {
                $h = Db::one('SELECT * FROM hutang WHERE id = ?' . Db::forUpdate(), [(int) $hutangId]);
                if (!$h) { throw new InvalidArgumentException('Faktur hutang tidak ditemukan.'); }
                $sisa = (int) $h['total'] - (int) $h['dibayar'];
                if ($jumlah > $sisa) { throw new InvalidArgumentException('Pembayaran ' . rp($jumlah) . ' melebihi sisa hutang ' . rp($sisa) . '.'); }
                $baru = (int) $h['dibayar'] + $jumlah;
                Db::exec('UPDATE hutang SET dibayar = ?, status = ? WHERE id = ?', [$baru, $baru >= (int) $h['total'] ? 'lunas' : 'sebagian', $h['id']]);
                $pemasok = $h['pemasok'];
            } else {
                $sisa = self::konsinyasi($pemasok)['sisa'];
                if ($jumlah > $sisa) { throw new InvalidArgumentException('Pembayaran ' . rp($jumlah) . ' melebihi sisa konsinyasi terjual ' . rp(max(0, $sisa)) . '.'); }
            }
            $id = Db::insert('INSERT INTO hutang_bayar (jenis, hutang_id, pemasok, jumlah, tanggal, catatan, user_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$jenis, $jenis === 'hutang' ? $hutangId : null, $pemasok, $jumlah, $tanggal, mb_substr($catatan, 0, 255), $userId, date('Y-m-d H:i:s')]);
            Db::audit($userId, 'bayar_' . $jenis, 'hutang', $id, "$pemasok " . $jumlah);
            return $id;
        });
    }

    /** Akhiri status konsinyasi barang bila stok sudah habis (kembali jadi barang biasa). */
    public static function akhiriKonsinyasi(int $barangId, ?int $userId): void
    {
        $b = Gudang::find($barangId);
        if (!$b || trim((string) ($b['konsinyasi_pemasok'] ?? '')) === '') { throw new InvalidArgumentException('Barang bukan konsinyasi.'); }
        if ((Gudang::stok($barangId, Pos::GUDANG) + Gudang::stok($barangId, Pos::TOKO)) > 0) { throw new InvalidArgumentException('Stok titipan masih ada. Jual habis atau kembalikan ke supplier (Keluar) dulu.'); }
        Db::exec('UPDATE barang SET konsinyasi_pemasok = NULL WHERE id = ?', [$barangId]);
        Db::audit($userId, 'akhiri_konsinyasi', 'barang', $barangId, (string) $b['konsinyasi_pemasok']);
    }

    public static function totalHutang(): int { return (int) Db::val("SELECT COALESCE(SUM(total - dibayar),0) FROM hutang WHERE status <> 'lunas'"); }
}
