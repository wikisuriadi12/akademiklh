<?php
declare(strict_types=1);

/** Piutang pelanggan: pembelian hutang (bon) di kasir, dikelompokkan menurut nama pelanggan. */
final class Piutang
{
    /** Nama pelanggan dari isian kasir ("Umum: Budi", "Pegawai: Ani") atau nama santri. */
    public static function namaDari(?string $pelanggan, ?string $namaSantri): string
    {
        if ($namaSantri !== null && trim($namaSantri) !== '') { return mb_substr(trim($namaSantri), 0, 100); }
        $p = trim((string) $pelanggan);
        $n = str_contains($p, ':') ? trim(substr($p, strpos($p, ':') + 1)) : '';
        return mb_substr($n, 0, 100);
    }

    /** Dipanggil dari Pos::jual di dalam transaksi. */
    public static function buat(int $penjualanId, ?int $santriId, string $nama, int $total, ?string $tempo): int
    {
        if ($nama === '') { throw new InvalidArgumentException('Nama pelanggan wajib diisi untuk pembelian hutang.'); }
        if ($tempo !== null && $tempo !== '' && !Domain::validDate($tempo)) { throw new InvalidArgumentException('Tanggal jatuh tempo tidak valid.'); }
        return Db::insert('INSERT INTO piutang (penjualan_id, santri_id, nama, total, dibayar, jatuh_tempo, status, created_at) VALUES (?,?,?,?,0,?,?,?)',
            [$penjualanId, $santriId, $nama, $total, ($tempo ?: null), 'belum', date('Y-m-d H:i:s')]);
    }

    /** Dipanggil dari Pos::batalkan. Menolak bila sudah ada cicilan. */
    public static function batalkanPenjualan(int $penjualanId): void
    {
        $r = Db::one('SELECT id, dibayar FROM piutang WHERE penjualan_id = ?', [$penjualanId]);
        if (!$r) { return; }
        if ((int) $r['dibayar'] > 0) { throw new InvalidArgumentException('Hutang ini sudah dicicil ' . rp($r['dibayar']) . '. Penjualan tidak bisa dibatalkan.'); }
        Db::exec("UPDATE piutang SET status = 'batal' WHERE id = ?", [$r['id']]);
    }

    /** Ringkasan per nama pelanggan (hanya yang masih punya sisa). */
    public static function ringkasan(): array
    {
        $hari = date('Y-m-d'); $out = [];
        foreach (Db::all("SELECT nama, total, dibayar, jatuh_tempo FROM piutang WHERE status IN ('belum','sebagian')") as $r) {
            $n = $r['nama']; $sisa = (int) $r['total'] - (int) $r['dibayar'];
            $o = $out[$n] ?? ['nama' => $n, 'n' => 0, 'total' => 0, 'dibayar' => 0, 'sisa' => 0, 'tempo' => null, 'lewat' => 0];
            $o['n']++; $o['total'] += (int) $r['total']; $o['dibayar'] += (int) $r['dibayar']; $o['sisa'] += $sisa;
            if ($r['jatuh_tempo']) { if ($o['tempo'] === null || $r['jatuh_tempo'] < $o['tempo']) { $o['tempo'] = $r['jatuh_tempo']; } if ($r['jatuh_tempo'] < $hari) { $o['lewat'] += $sisa; } }
            $out[$n] = $o;
        }
        uasort($out, static fn($a, $b) => $b['sisa'] <=> $a['sisa'] ?: strcmp($a['nama'], $b['nama']));
        return $out;
    }

    public static function daftar(string $nama = '', string $status = 'belum'): array
    {
        $w = "p.status <> 'batal'"; $a = [];
        if ($nama !== '') { $w .= ' AND p.nama = ?'; $a[] = $nama; }
        if ($status === 'belum') { $w .= " AND p.status IN ('belum','sebagian')"; } elseif ($status === 'lunas') { $w .= " AND p.status = 'lunas'"; }
        return Db::all("SELECT p.*, j.no_struk, j.tanggal FROM piutang p JOIN penjualan j ON j.id = p.penjualan_id WHERE $w ORDER BY p.id DESC LIMIT 500", $a);
    }

    /** Rekap pembelian hutang pada rentang tanggal penjualan (untuk laporan). */
    public static function laporan(string $dari, string $sampai): array
    {
        return Db::all("SELECT p.*, j.no_struk, j.tanggal FROM piutang p JOIN penjualan j ON j.id = p.penjualan_id WHERE p.status <> 'batal' AND j.tanggal >= ? AND j.tanggal <= ? ORDER BY j.id DESC LIMIT 2000", [$dari, $sampai]);
    }

    public static function items(int $penjualanId): array { return Pos::items($penjualanId); }

    public static function pembayaran(string $nama = ''): array
    {
        $w = $nama !== '' ? 'WHERE p.nama = ?' : ''; $a = $nama !== '' ? [$nama] : [];
        return Db::all("SELECT b.*, p.nama, j.no_struk, u.nama AS petugas FROM piutang_bayar b JOIN piutang p ON p.id = b.piutang_id JOIN penjualan j ON j.id = p.penjualan_id LEFT JOIN users u ON u.id = b.user_id $w ORDER BY b.id DESC LIMIT 300", $a);
    }

    public static function bayar(int $piutangId, int $jumlah, string $tanggal, string $catatan, ?int $userId): int
    {
        if ($jumlah < 1) { throw new InvalidArgumentException('Jumlah bayar harus lebih dari 0.'); }
        if (!Domain::validDate($tanggal)) { throw new InvalidArgumentException('Tanggal bayar tidak valid.'); }
        return Db::transaction(function () use ($piutangId, $jumlah, $tanggal, $catatan, $userId) {
            $p = Db::one('SELECT * FROM piutang WHERE id = ?' . Db::forUpdate(), [$piutangId]);
            if (!$p || $p['status'] === 'batal') { throw new InvalidArgumentException('Data hutang tidak ditemukan.'); }
            $sisa = (int) $p['total'] - (int) $p['dibayar'];
            if ($jumlah > $sisa) { throw new InvalidArgumentException('Pembayaran ' . rp($jumlah) . ' melebihi sisa hutang ' . rp($sisa) . '.'); }
            $baru = (int) $p['dibayar'] + $jumlah;
            Db::exec('UPDATE piutang SET dibayar = ?, status = ? WHERE id = ?', [$baru, $baru >= (int) $p['total'] ? 'lunas' : 'sebagian', $p['id']]);
            $id = Db::insert('INSERT INTO piutang_bayar (piutang_id, jumlah, tanggal, catatan, user_id, created_at) VALUES (?,?,?,?,?,?)', [$piutangId, $jumlah, $tanggal, mb_substr($catatan, 0, 255), $userId, date('Y-m-d H:i:s')]);
            Db::audit($userId, 'bayar_piutang', 'piutang', $piutangId, $p['nama'] . ' ' . $jumlah);
            return $id;
        });
    }

    public static function totalSisa(): int { return (int) Db::val("SELECT COALESCE(SUM(total - dibayar),0) FROM piutang WHERE status IN ('belum','sebagian')"); }
}
