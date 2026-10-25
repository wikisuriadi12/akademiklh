<?php
declare(strict_types=1);

/** Pemeriksaan dan penerapan migrasi skema database (idempoten) dari halaman admin. */
final class Skema
{
    /** @return array<int,array{label:string,ok:bool,file:string}> */
    public static function cek(): array
    {
        $kolom = static function (string $tabel, string $kol): bool {
            try { Db::pdo()->query("SELECT $kol FROM $tabel LIMIT 1")->closeCursor(); return true; } catch (Throwable $e) { return false; }
        };
        $tabel = static function (string $t) use ($kolom): bool { return $kolom($t, '1'); };
        $enum = static function (string $t, string $kol, string $nilai): bool {
            try {
                if (Db::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; }
                $r = Db::one("SHOW COLUMNS FROM $t LIKE '$kol'");
                return $r !== null && stripos((string) ($r['Type'] ?? ''), "'$nilai'") !== false;
            } catch (Throwable $e) { return false; }
        };
        return [
            ['label' => 'penjualan.diskon', 'ok' => $kolom('penjualan', 'diskon'), 'file' => 'migrasi-diskon.sql'],
            ['label' => 'penjualan.pelanggan (Umum/Pegawai)', 'ok' => $kolom('penjualan', 'pelanggan'), 'file' => 'migrasi-pelanggan.sql'],
            ['label' => 'penjualan.bukti_qris (foto bukti QRIS)', 'ok' => $kolom('penjualan', 'bukti_qris'), 'file' => 'migrasi-bukti-qris.sql'],
            ['label' => 'barang.harga_pegawai', 'ok' => $kolom('barang', 'harga_pegawai'), 'file' => 'migrasi-harga-pegawai.sql'],
            ['label' => 'barang.konsinyasi_pemasok', 'ok' => $kolom('barang', 'konsinyasi_pemasok'), 'file' => 'migrasi-hutang-konsinyasi.sql'],
            ['label' => 'penjualan_item.konsinyasi', 'ok' => $kolom('penjualan_item', 'konsinyasi'), 'file' => 'migrasi-hutang-konsinyasi.sql'],
            ['label' => 'tabel hutang', 'ok' => $tabel('hutang'), 'file' => 'migrasi-hutang-konsinyasi.sql'],
            ['label' => 'tabel hutang_bayar', 'ok' => $tabel('hutang_bayar'), 'file' => 'migrasi-hutang-konsinyasi.sql'],
            ['label' => 'tabel piutang (hutang pelanggan)', 'ok' => $tabel('piutang'), 'file' => 'migrasi-piutang.sql'],
            ['label' => 'tabel piutang_bayar', 'ok' => $tabel('piutang_bayar'), 'file' => 'migrasi-piutang.sql'],
            ['label' => 'penjualan.metode mengenal hutang', 'ok' => $enum('penjualan', 'metode', 'hutang'), 'file' => 'migrasi-piutang.sql'],
            ['label' => 'tabel qris_trx (QRIS dinamis)', 'ok' => $tabel('qris_trx'), 'file' => 'migrasi-qris-dinamis.sql'],
            ['label' => 'stok.unit mengenal Gudang', 'ok' => $enum('stok', 'unit', 'gudang'), 'file' => 'migrasi-gudang-market.sql'],
            ['label' => 'stok_mutasi.unit mengenal Gudang', 'ok' => $enum('stok_mutasi', 'unit', 'gudang'), 'file' => 'migrasi-gudang-market.sql'],
            ['label' => 'stok_mutasi.tipe mengenal transfer', 'ok' => $enum('stok_mutasi', 'tipe', 'transfer_masuk'), 'file' => 'migrasi-gudang-market.sql'],
        ];
    }

    /** Jalankan semua database/migrasi-*.sql. Galat "sudah ada" diabaikan. @return array{ok:int,gagal:string[]} */
    public static function terapkan(): array
    {
        $ok = 0; $gagal = [];
        $pdo = Db::pdo();
        foreach (glob(APP_ROOT . '/database/migrasi-*.sql') ?: [] as $mf) {
            $isi = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($mf));
            foreach (array_filter(array_map('trim', explode(';', (string) $isi))) as $sql) {
                try { $pdo->exec($sql); $ok++; } catch (PDOException $e) {
                    $m = $e->getMessage();
                    if (stripos($m, 'Duplicate column') !== false || stripos($m, 'already exists') !== false || stripos($m, 'Duplicate key name') !== false) { continue; }
                    $gagal[] = basename($mf) . ': ' . mb_substr($m, 0, 160);
                }
            }
        }
        return ['ok' => $ok, 'gagal' => $gagal];
    }
}
