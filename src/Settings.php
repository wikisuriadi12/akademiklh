<?php
declare(strict_types=1);

/** Pengaturan lembaga (disimpan di tabel settings). */
final class Settings
{
    public const DEFAULTS = [
        'nama_lembaga' => 'Yayasan Luqmanul Hakim',
        'alamat' => '',
        'telepon' => '',
        'wa_admin' => '',
        'rekening' => '',
        'pendaftaran_buka' => '1',
        'biaya_pendaftaran' => '0',
        'va_aktif' => '0',
        'va_jam' => '72',
        'sesi_hadir' => 'Ngaji Subuh, Sekolah Pagi, Shalat Dzuhur, Ngaji Maghrib',
        'izin_wali' => '1',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Db::all('SELECT k, v FROM settings') as $r) {
                    if (array_key_exists($r['k'], self::DEFAULTS)) {
                        self::$cache[$r['k']] = (string) $r['v'];
                    }
                }
            } catch (Throwable $e) {
                // tabel belum ada: pakai bawaan
            }
        }
        return self::$cache;
    }

    public static function get(string $k, string $d = ''): string
    {
        return self::all()[$k] ?? $d;
    }

    public static function set(string $k, string $v): void
    {
        if (!array_key_exists($k, self::DEFAULTS)) {
            throw new InvalidArgumentException('Pengaturan tidak dikenal.');
        }
        if (Db::val('SELECT k FROM settings WHERE k = ?', [$k]) !== null) {
            Db::exec('UPDATE settings SET v = ? WHERE k = ?', [$v, $k]);
        } else {
            Db::exec('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
        }
        self::$cache = null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
