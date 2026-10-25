<?php
declare(strict_types=1);

/**
 * Pembagian subdomain. Konfigurasi di config.php:
 *   'domain' => 'contoh.sch.id',
 *   'subdomain' => ['pendidikan' => 'akademik', 'pos' => 'pos', 'wali' => 'wali'],
 * Tanpa 'domain' (mis. localhost), semua sistem terbuka dari satu alamat seperti biasa.
 */
final class Host
{
    public static ?array $override = null;

    public static function cfg(): array
    {
        if (self::$override !== null) { return self::$override; }
        $f = APP_ROOT . '/config.php';
        $c = is_file($f) ? (require $f) : [];
        return ['domain' => strtolower(trim((string) ($c['domain'] ?? ''))), 'subdomain' => (array) ($c['subdomain'] ?? []) + ['pendidikan' => 'akademik', 'pos' => 'pos', 'wali' => 'wali'],
            'portal_utama' => (bool) ($c['portal_utama'] ?? false)];
    }

    public static function aktif(): bool
    {
        return self::cfg()['domain'] !== '';
    }

    /** Sistem untuk host saat ini: 'pendidikan' | 'pos' | 'wali' | 'portal' | null (mode satu alamat). */
    public static function sistem(?string $host = null): ?string
    {
        $c = self::cfg();
        if ($c['domain'] === '') { return null; }
        $h = strtolower(preg_replace('/:\d+$/', '', (string) ($host ?? ($_SERVER['HTTP_HOST'] ?? ''))));
        if ($h === $c['domain'] || $h === 'www.' . $c['domain']) { return 'portal'; }
        foreach ($c['subdomain'] as $sis => $sub) {
            if ($h === $sub . '.' . $c['domain']) { return $sis; }
        }
        return 'portal';
    }

    /** Alamat lengkap untuk sebuah sistem (atau portal). */
    public static function url(string $sistem, string $query = ''): string
    {
        $c = self::cfg();
        if ($c['domain'] === '') {
            if ($sistem === 'portal') { return '?p=portal'; }
            if ($sistem === 'wali') { return '?' . ($query !== '' ? $query : 'p=cek'); }
            return '?' . ($query !== '' ? $query : 'p=login&sistem=' . $sistem);
        }
        if ($sistem === 'portal' && empty($c['portal_utama'])) { return self::url('pendidikan'); }
        $host = $sistem === 'portal' ? $c['domain'] : ($c['subdomain'][$sistem] ?? $sistem) . '.' . $c['domain'];
        return 'https://' . $host . '/' . ($query !== '' ? '?' . $query : '');
    }

    /** Halaman login untuk host saat ini (subdomain sistem) atau portal. */
    public static function masuk(): string
    {
        $h = self::sistem();
        return in_array($h, ['pendidikan', 'pos'], true) ? '?p=login&sistem=' . $h : self::url('portal');
    }

    /** Boleh dibuka di host ini? Mengembalikan null bila boleh, atau URL tujuan pengalihan. */
    public static function periksa(string $page, ?string $hostSis): ?string
    {
        if ($hostSis === null || in_array($page, ['bni_callback', 'qris_callback', 'captcha'], true)) { return null; }
        if ($hostSis === 'portal') { return $page === 'portal' ? null : self::tujuan($page); }
        if ($hostSis === 'wali') { return in_array($page, Domain::HALAMAN['wali'], true) ? null : (self::milik($page) ? self::tujuan($page) : self::url('wali', 'p=cek')); }
        if (in_array($page, Domain::HALAMAN_UMUM, true) || in_array($page, Domain::HALAMAN[$hostSis], true)) { return null; }
        if ($page === 'portal') { return empty(self::cfg()['portal_utama']) ? '?p=login&sistem=' . $hostSis : self::url('portal'); }
        return self::tujuan($page);
    }

    public static function milik(string $page): ?string
    {
        foreach (Domain::HALAMAN as $sis => $list) { if (in_array($page, $list, true)) { return $sis; } }
        return null;
    }

    private static function tujuan(string $page): string
    {
        $sis = self::milik($page);
        return $sis ? self::url($sis, 'p=' . $page) : self::url('portal');
    }
}
