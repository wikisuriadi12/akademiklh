<?php
declare(strict_types=1);

/** Pembatas laju berbasis basis data (aman walau penyerang membuang cookie sesi). */
final class RateLimit
{
    /** Catat satu percobaan. false = sudah melewati batas dalam jendela waktu. */
    public static function hit(string $name, string $id, int $max, int $windowSec): bool
    {
        $k = sha1($name . '|' . $id);
        $now = time();
        $r = Db::one('SELECT n, started FROM rate_limit WHERE k = ?', [$k]);
        if (!$r || $now - (int) $r['started'] >= $windowSec) {
            Db::exec('DELETE FROM rate_limit WHERE k = ?', [$k]);
            Db::exec('INSERT INTO rate_limit (k, n, started) VALUES (?, 1, ?)', [$k, $now]);
            return true;
        }
        if ((int) $r['n'] >= $max) {
            return false;
        }
        Db::exec('UPDATE rate_limit SET n = n + 1 WHERE k = ?', [$k]);
        return true;
    }

    public static function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }
}
